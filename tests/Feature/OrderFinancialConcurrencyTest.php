<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialTransaction;
use App\Models\OrderFinancialSourceSnapshot;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBranchService;
use App\Services\CaptainDepositReceiptService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialSourceSnapshotService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Support\AccountingV1Fixtures;
use Tests\TestCase;

class OrderFinancialConcurrencyTest extends TestCase
{
    use AccountingV1Fixtures;

    public function test_payout_and_official_source_reversal_contend_on_one_account_and_never_both_commit(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            OrderItem::query()->where('order_shop_id', $c['order']->shops()->sole()->id)
                ->update(['actual_unit_price' => '100']);
            app(OrderPricingService::class)->recalculate($c['order']);
            [$captain, $entry, $accountId] = $this->prepareShamReceivable($c);
            $this->assertSame('100.0000', (string) BigDecimal::of($entry->amount)->toScale(4));
            $job = ['actor_id' => $c['employee']->id, 'captain_id' => $captain->id,
                'branch_id' => $c['order']->branch_id, 'currency_id' => $c['currency']->id,
                'data' => ['request_key' => (string) str()->uuid(), 'amount' => '100',
                    'occurred_at' => now()->subMinute()->toIso8601String()],
                'allocations' => [['financial_entry_id' => $entry->financial_entry_id, 'amount' => '100']]];
            $results = $this->race(['captain_payout', 'reverse_receivable_source'],
                [$captain->id, $entry->financial_transaction_id], null, [$job, []], $accountId);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(1, collect($results)->where('status', 'error')->count(), json_encode($results));
            $paid = DB::table('captain_receivable_payout_allocations')->where('financial_entry_id', $entry->financial_entry_id)->exists();
            $reversed = DB::table('financial_transactions')->where('reversal_of_transaction_id', $entry->financial_transaction_id)->exists();
            $this->assertNotSame($paid, $reversed, 'Payout and source reversal must be mutually exclusive.');
            $this->assertSame($paid ? 1 : 0, DB::table('captain_receivable_payouts')->count());
            $this->assertSame($reversed ? 1 : 0,
                DB::table('financial_transactions')->where('reversal_of_transaction_id', $entry->financial_transaction_id)->count());
        });
    }

    public function test_staggered_captain_upgrade_rejects_preexisting_subcurrency_allocations_without_repair(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateCaptainIntermediateSchema();
            $c = $this->context();
            [$captain, $product] = $this->prepareShamReceivable($c);
            $delivery = app(FinancialBalanceQueryService::class)
                ->payableReceivableEntries($captain, $c['order']->branch, $c['currency'])
                ->firstWhere('event_type', FinancialEventType::OrderShamCashDeliveryEntitlementDue->value);
            $payoutId = $this->insertIntermediatePayout($c, $captain, [
                [$product->financial_entry_id, '0.001'], [$delivery->financial_entry_id, '0.999'],
            ]);
            $allocationIds = DB::table('captain_receivable_payout_allocations')
                ->where('captain_receivable_payout_id', $payoutId)->orderBy('id')->pluck('id')->all();
            $this->assertCount(2, $allocationIds);
            (require database_path('migrations/2026_09_28_120300_validate_captain_payout_allocation_precision.php'))->up();
            try {
                (require database_path('migrations/2026_09_28_120400_finalize_captain_financial_operation_integrity.php'))->up();
                $this->fail('The intermediate sub-Currency-precision allocations passed the final upgrade.');
            } catch (QueryException|PDOException $exception) {
                $this->assertStringContainsString("payout_id={$payoutId}", $exception->getMessage());
                $this->assertStringContainsString('allocation_id=', $exception->getMessage());
                $this->assertStringContainsString('precision', $exception->getMessage());
            }
            $this->assertSame(['0.001', '0.999'], DB::table('captain_receivable_payout_allocations')
                ->where('captain_receivable_payout_id', $payoutId)->orderBy('id')->pluck('amount')
                ->map(fn ($amount) => (string) $amount)->all());
        });
    }

    public function test_staggered_captain_upgrade_rejects_hidden_extra_entry_without_repair(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateCaptainIntermediateSchema();
            $currency = Currency::create(['name' => 'Dollar', 'code' => 'USD', 'decimal_places' => 2]);
            $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
            $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
            $actor = User::factory()->create();
            $accounts = app(FinancialAccountService::class);
            $owner = $accounts->ownerForCaptain($captain);
            $deposit = $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit);
            $receivable = $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCompanyReceivable);
            // Model a weaker intermediate deployment in this throwaway schema only.
            // Existing final-state triggers are re-enabled before applying 120400.
            DB::statement('ALTER TABLE captain_deposit_receipts DISABLE TRIGGER captain_deposit_receipt_complete');
            DB::statement('ALTER TABLE financial_transactions DISABLE TRIGGER captain_financial_event_complete');
            DB::statement('ALTER TABLE financial_entries DISABLE TRIGGER captain_financial_entry_complete');
            $occurredAt = now()->startOfSecond();
            [$receiptId, $transactionId] = DB::transaction(function () use ($currency, $branch, $captain, $actor, $deposit, $receivable, $occurredAt): array {
                $receiptId = DB::table('captain_deposit_receipts')->insertGetId([
                    'captain_id' => $captain->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                    'received_by' => $actor->id, 'request_key' => (string) str()->uuid(), 'amount' => '100',
                    'occurred_at' => $occurredAt, 'evidence_path' => 'private/proof.png',
                    'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png', 'evidence_size' => 100,
                    'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
                ]);
                $transactionId = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'currency_id' => $currency->id,
                    'branch_id' => $branch->id, 'source_type' => 'CAPTAIN_DEPOSIT_RECEIPT',
                    'source_id' => (string) $receiptId, 'event_type' => 'CAPTAIN_DEPOSIT_RECEIVED',
                    'source_version' => 1, 'payload_hash' => str_repeat('b', 64),
                    'occurred_at' => $occurredAt, 'created_at' => now(),
                ]);
                foreach ([[$deposit->id, 'INCREASE'], [$receivable->id, 'DECREASE']] as [$accountId, $direction]) {
                    DB::table('financial_entries')->insert([
                        'financial_transaction_id' => $transactionId, 'financial_account_id' => $accountId,
                        'currency_id' => $currency->id, 'direction' => $direction,
                        'amount' => '100', 'created_at' => now(),
                    ]);
                }
                DB::table('financial_transactions')->where('id', $transactionId)->update(['status' => 'POSTED', 'posted_at' => now()]);

                return [$receiptId, $transactionId];
            });
            DB::statement('ALTER TABLE captain_deposit_receipts ENABLE TRIGGER captain_deposit_receipt_complete');
            DB::statement('ALTER TABLE financial_transactions ENABLE TRIGGER captain_financial_event_complete');
            DB::statement('ALTER TABLE financial_entries ENABLE TRIGGER captain_financial_entry_complete');
            (require database_path('migrations/2026_09_28_120300_validate_captain_payout_allocation_precision.php'))->up();
            try {
                (require database_path('migrations/2026_09_28_120400_finalize_captain_financial_operation_integrity.php'))->up();
                $this->fail('The hidden extra entry passed final retrospective validation.');
            } catch (QueryException|PDOException $exception) {
                $this->assertStringContainsString("source_id={$receiptId}", $exception->getMessage());
                $this->assertStringContainsString('wrong account, direction, or exact amount', $exception->getMessage());
            }
            $this->assertSame(2, DB::table('financial_entries')->where('financial_transaction_id', $transactionId)->count());
        });
    }

    public function test_staggered_valid_captain_history_and_fresh_install_reach_one_final_guard(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateCaptainIntermediateSchema();
            $c = $this->context();
            [$captain, $product] = $this->prepareShamReceivable($c);
            Storage::fake('captain_financial_evidence');
            $receipt = app(CaptainDepositReceiptService::class)->record(
                $c['employee'], $captain, $c['order']->branch, $c['currency'],
                ['request_key' => (string) str()->uuid(), 'amount' => '2.00',
                    'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')],
                UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')),
            );
            $delivery = app(FinancialBalanceQueryService::class)
                ->payableReceivableEntries($captain, $c['order']->branch, $c['currency'])
                ->firstWhere('event_type', FinancialEventType::OrderShamCashDeliveryEntitlementDue->value);
            $payoutId = $this->insertIntermediatePayout($c, $captain, [
                [$product->financial_entry_id, '0.50'], [$delivery->financial_entry_id, '0.50'],
            ]);
            (require database_path('migrations/2026_09_28_120300_validate_captain_payout_allocation_precision.php'))->up();
            (require database_path('migrations/2026_09_28_120400_finalize_captain_financial_operation_integrity.php'))->up();
            $this->assertSame(2, DB::table('captain_receivable_payout_allocations')
                ->where('captain_receivable_payout_id', $payoutId)->count());
            $this->assertDatabaseHas('captain_deposit_receipts', ['id' => $receipt->id]);
            $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS count FROM pg_trigger WHERE tgrelid = 'financial_transactions'::regclass AND tgname = 'captain_paid_source_reversal_guard' AND tgenabled = 'O'")->count);
        });
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS count FROM pg_trigger WHERE tgrelid = 'financial_transactions'::regclass AND tgname = 'captain_paid_source_reversal_guard' AND tgenabled = 'O'")->count);
            $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS count FROM pg_trigger WHERE tgrelid = 'captain_receivable_payout_allocations'::regclass AND tgname = 'captain_payout_allocation_precision' AND tgenabled = 'O'")->count);
        });
    }

    public function test_captain_payout_workers_contend_on_one_receivable_account_and_cannot_overallocate_source(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $captain = $c['order']->captainAssignments()->sole()->captain;
            $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order'],
                app(InvoiceService::class)->refresh($c['employee'], $c['order'],
                    app(InvoiceService::class)->createDraft($c['employee'], $c['order'])));
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer,
                'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
                $invoice->grand_total, $c['currency']->id, now());
            $entry = app(FinancialBalanceQueryService::class)->payableReceivableEntries($captain, $c['order']->branch, $c['currency'])
                ->firstWhere('event_type', FinancialEventType::OrderShamCashProductReimbursementDue->value);
            $accountId = DB::table('financial_entries')->where('id', $entry->financial_entry_id)->value('financial_account_id');
            $common = ['actor_id' => $c['employee']->id, 'captain_id' => $captain->id,
                'branch_id' => $c['order']->branch_id, 'currency_id' => $c['currency']->id];
            $results = $this->race('captain_payout', $captain->id, null, [
                $common + ['data' => ['request_key' => (string) str()->uuid(), 'amount' => '7', 'occurred_at' => now()->subMinute()->toIso8601String()], 'allocations' => [['financial_entry_id' => $entry->financial_entry_id, 'amount' => '7']]],
                $common + ['data' => ['request_key' => (string) str()->uuid(), 'amount' => '6', 'occurred_at' => now()->subMinute()->toIso8601String()], 'allocations' => [['financial_entry_id' => $entry->financial_entry_id, 'amount' => '6']]],
            ], $accountId);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(1, collect($results)->where('status', 'error')->count(), json_encode($results));
            $this->assertSame(1, DB::table('captain_receivable_payouts')->count());
            $this->assertTrue(BigDecimal::of((string) DB::table('captain_receivable_payout_allocations')->sum('amount'))
                ->isLessThanOrEqualTo('10'));
        });
    }

    public function test_duplicate_deposit_workers_post_one_receipt_and_one_increase(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $captain = $c['order']->captainAssignments()->sole()->captain;
            $owner = app(FinancialAccountService::class)->ownerForCaptain($captain);
            $deposit = app(FinancialAccountService::class)->getOrCreate($owner, $c['currency'], FinancialAccountKind::CaptainDeposit);
            $job = ['actor_id' => $c['employee']->id, 'captain_id' => $captain->id,
                'branch_id' => $c['order']->branch_id, 'currency_id' => $c['currency']->id,
                'data' => ['request_key' => (string) str()->uuid(), 'amount' => '20', 'occurred_at' => now()->subMinute()->toIso8601String()]];
            $results = $this->race('captain_deposit', $captain->id, null, [$job, $job], $deposit->id);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $this->assertSame(1, DB::table('captain_deposit_receipts')->count());
            $this->assertSame(1, DB::table('financial_transactions')->where('event_type', 'CAPTAIN_DEPOSIT_RECEIVED')->count());
            $this->assertSame('20.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance(
                $captain, $c['order']->branch, $c['currency'], FinancialAccountKind::CaptainDeposit));
        });
    }

    public function test_duplicate_payout_workers_reuse_one_document_and_one_decrease(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            [$captain, $entry, $accountId] = $this->prepareShamReceivable($c);
            $job = ['actor_id' => $c['employee']->id, 'captain_id' => $captain->id,
                'branch_id' => $c['order']->branch_id, 'currency_id' => $c['currency']->id,
                'data' => ['request_key' => (string) str()->uuid(), 'amount' => '5',
                    'occurred_at' => now()->subMinute()->toIso8601String()],
                'allocations' => [['financial_entry_id' => $entry->financial_entry_id, 'amount' => '5']]];
            $results = $this->race('captain_payout', $captain->id, null, [$job, $job], $accountId);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $this->assertCount(1, collect($results)->pluck('result')->unique());
            $this->assertSame(1, DB::table('captain_receivable_payouts')->count());
            $this->assertSame(1, DB::table('financial_transactions')->where('event_type', 'CAPTAIN_RECEIVABLE_PAID')->count());
        });
    }

    public function test_two_branch_payouts_share_global_account_lock_but_keep_branch_balances_separate(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $a = $this->context();
            [$captain, $entryA, $accountId] = $this->prepareShamReceivable($a);
            $b = $this->context($a['currency'], $captain);
            [, $entryB] = $this->prepareShamReceivable($b);
            $jobs = [];
            foreach ([[$a, $entryA, '7'], [$b, $entryB, '6']] as [$context, $entry, $amount]) {
                $jobs[] = ['actor_id' => $context['employee']->id, 'captain_id' => $captain->id,
                    'branch_id' => $context['order']->branch_id, 'currency_id' => $context['currency']->id,
                    'data' => ['request_key' => (string) str()->uuid(), 'amount' => $amount,
                        'occurred_at' => now()->subMinute()->toIso8601String()],
                    'allocations' => [['financial_entry_id' => $entry->financial_entry_id, 'amount' => $amount]]];
            }
            $results = $this->race('captain_payout', $captain->id, null, $jobs, $accountId);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $balances = app(FinancialBalanceQueryService::class);
            $this->assertSame('6.2500', $balances->captainBranchBalance($captain, $a['order']->branch, $a['currency'], FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame('7.2500', $balances->captainBranchBalance($captain, $b['order']->branch, $b['currency'], FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame('13.5000', $balances->captainBalances($captain, $a['currency'])[FinancialAccountKind::CaptainCompanyReceivable->value]);
        });
    }

    public function test_canonical_direct_sql_payout_commits_and_paid_source_direct_reversal_fails(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            [$captain, $entry, $accountId] = $this->prepareShamReceivable($c);
            $occurredAt = now()->startOfSecond();
            DB::transaction(function () use ($c, $captain, $entry, $accountId, $occurredAt): void {
                $payoutId = DB::table('captain_receivable_payouts')->insertGetId([
                    'captain_id' => $captain->id, 'branch_id' => $c['order']->branch_id,
                    'currency_id' => $c['currency']->id, 'amount' => '5',
                    'paid_by' => $c['employee']->id, 'request_key' => (string) str()->uuid(),
                    'occurred_at' => $occurredAt, 'evidence_path' => 'private/evidence.png',
                    'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png',
                    'evidence_size' => 100, 'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
                ]);
                DB::table('captain_receivable_payout_allocations')->insert([
                    'captain_receivable_payout_id' => $payoutId,
                    'financial_entry_id' => $entry->financial_entry_id, 'amount' => '5', 'created_at' => now(),
                ]);
                $transactionId = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT',
                    'currency_id' => $c['currency']->id, 'branch_id' => $c['order']->branch_id,
                    'source_type' => 'CAPTAIN_RECEIVABLE_PAYOUT', 'source_id' => (string) $payoutId,
                    'event_type' => 'CAPTAIN_RECEIVABLE_PAID', 'source_version' => 1,
                    'payload_hash' => str_repeat('b', 64), 'occurred_at' => $occurredAt, 'created_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $transactionId, 'financial_account_id' => $accountId,
                    'currency_id' => $c['currency']->id, 'direction' => 'DECREASE', 'amount' => '5', 'created_at' => now(),
                ]);
                DB::table('financial_transactions')->where('id', $transactionId)->update(['status' => 'POSTED', 'posted_at' => now()]);
            });
            $this->assertSame('8.2500', app(FinancialBalanceQueryService::class)->captainBranchBalance(
                $captain, $c['order']->branch, $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame(1, DB::table('captain_receivable_payouts')->count());
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_transactions')->insert([
                'transaction_kind' => 'REVERSAL', 'status' => 'DRAFT',
                'currency_id' => $c['currency']->id, 'branch_id' => $c['order']->branch_id,
                'source_type' => 'DIRECT_SQL', 'source_id' => (string) str()->uuid(),
                'event_type' => 'REVERSE_PAID', 'source_version' => 1,
                'payload_hash' => str_repeat('a', 64), 'occurred_at' => now(),
                'reversal_of_transaction_id' => $entry->financial_transaction_id,
                'reason' => 'Bypass attempt', 'created_at' => now(),
            ])), QueryException::class);
        });
    }

    public function test_direct_sql_payout_contract_rejects_wrong_owner_branch_currency_kind_direction_amount_and_source(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            [$captain, $source, $receivableId] = $this->prepareShamReceivable($c);
            $other = $this->context($c['currency']);
            [, $otherSource, $otherReceivableId] = $this->prepareShamReceivable($other);
            $accounts = app(FinancialAccountService::class);
            $owner = $accounts->ownerForCaptain($captain);
            $depositId = $accounts->getOrCreate($owner, $c['currency'], FinancialAccountKind::CaptainDeposit)->id;
            $euro = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'decimal_places' => 2]);
            $euroAccountId = $accounts->getOrCreate($owner, $euro, FinancialAccountKind::CaptainCompanyReceivable)->id;
            $attempt = function (array $change = []) use ($c, $captain, $source, $receivableId): void {
                $facts = array_replace([
                    'source_entry_id' => $source->financial_entry_id,
                    'account_id' => $receivableId,
                    'transaction_branch_id' => $c['order']->branch_id,
                    'transaction_currency_id' => $c['currency']->id,
                    'entry_currency_id' => $c['currency']->id,
                    'direction' => 'DECREASE', 'amount' => '5', 'source_amount' => '5', 'allocation_amount' => '5',
                ], $change);
                DB::transaction(function () use ($c, $captain, $facts): void {
                    $occurredAt = now()->startOfSecond();
                    $payoutId = DB::table('captain_receivable_payouts')->insertGetId([
                        'captain_id' => $captain->id, 'branch_id' => $c['order']->branch_id,
                        'currency_id' => $c['currency']->id, 'amount' => $facts['source_amount'],
                        'paid_by' => $c['employee']->id, 'request_key' => (string) str()->uuid(),
                        'occurred_at' => $occurredAt, 'evidence_path' => 'private/proof.png',
                        'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png',
                        'evidence_size' => 100, 'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
                    ]);
                    DB::table('captain_receivable_payout_allocations')->insert([
                        'captain_receivable_payout_id' => $payoutId,
                        'financial_entry_id' => $facts['source_entry_id'],
                        'amount' => $facts['allocation_amount'], 'created_at' => now(),
                    ]);
                    $transactionId = DB::table('financial_transactions')->insertGetId([
                        'transaction_kind' => 'EVENT', 'status' => 'DRAFT',
                        'currency_id' => $facts['transaction_currency_id'],
                        'branch_id' => $facts['transaction_branch_id'],
                        'source_type' => 'CAPTAIN_RECEIVABLE_PAYOUT', 'source_id' => (string) $payoutId,
                        'event_type' => 'CAPTAIN_RECEIVABLE_PAID', 'source_version' => 1,
                        'payload_hash' => str_repeat('a', 64), 'occurred_at' => $occurredAt, 'created_at' => now(),
                    ]);
                    DB::table('financial_entries')->insert([
                        'financial_transaction_id' => $transactionId,
                        'financial_account_id' => $facts['account_id'],
                        'currency_id' => $facts['entry_currency_id'],
                        'direction' => $facts['direction'], 'amount' => $facts['amount'], 'created_at' => now(),
                    ]);
                    DB::table('financial_transactions')->where('id', $transactionId)->update(['status' => 'POSTED', 'posted_at' => now()]);
                });
            };
            foreach ([
                ['account_id' => $depositId],
                ['account_id' => $otherReceivableId],
                ['account_id' => $euroAccountId],
                ['transaction_branch_id' => $other['order']->branch_id],
                ['transaction_currency_id' => $euro->id],
                ['entry_currency_id' => $euro->id],
                ['direction' => 'INCREASE'],
                ['source_amount' => '6'],
                ['allocation_amount' => '4'],
                ['source_entry_id' => $otherSource->financial_entry_id],
            ] as $change) {
                try {
                    $attempt($change);
                    $this->fail('Malformed payout was accepted: '.json_encode($change));
                } catch (QueryException|PDOException) {
                    $this->assertTrue(true);
                }
            }
            $attempt(['amount' => '7', 'source_amount' => '7', 'allocation_amount' => '7']);
            try {
                $attempt(['amount' => '4', 'source_amount' => '4', 'allocation_amount' => '4']);
                $this->fail('Cumulative Receivable allocation exceeded the source amount.');
            } catch (QueryException|PDOException) {
                $this->assertTrue(true);
            }
            $this->assertSame('6.2500', app(FinancialBalanceQueryService::class)->captainBranchBalance(
                $captain, $c['order']->branch, $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame(1, DB::table('captain_receivable_payouts')->count());
        });
    }

    private function migrateCaptainIntermediateSchema(): void
    {
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (basename($path) <= '2026_09_28_120200_fix_captain_financial_deferred_trigger.php') {
                (require $path)->up();
            }
        }
        Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
    }

    /** @param array<int, array{0: int, 1: string}> $lines */
    private function insertIntermediatePayout(array $c, Captain $captain, array $lines): int
    {
        $accounts = app(FinancialAccountService::class);
        $receivable = $accounts->getOrCreate($accounts->ownerForCaptain($captain), $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);

        return DB::transaction(function () use ($c, $captain, $lines, $receivable): int {
            $occurredAt = now()->startOfSecond();
            $payoutId = DB::table('captain_receivable_payouts')->insertGetId([
                'captain_id' => $captain->id, 'branch_id' => $c['order']->branch_id,
                'currency_id' => $c['currency']->id, 'paid_by' => $c['employee']->id,
                'request_key' => (string) str()->uuid(), 'amount' => '1.00',
                'occurred_at' => $occurredAt, 'evidence_path' => 'private/proof.png',
                'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png',
                'evidence_size' => 100, 'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
            ]);
            foreach ($lines as [$entryId, $amount]) {
                DB::table('captain_receivable_payout_allocations')->insert([
                    'captain_receivable_payout_id' => $payoutId,
                    'financial_entry_id' => $entryId, 'amount' => $amount, 'created_at' => now(),
                ]);
            }
            $transactionId = DB::table('financial_transactions')->insertGetId([
                'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'currency_id' => $c['currency']->id,
                'branch_id' => $c['order']->branch_id, 'source_type' => 'CAPTAIN_RECEIVABLE_PAYOUT',
                'source_id' => (string) $payoutId, 'event_type' => 'CAPTAIN_RECEIVABLE_PAID',
                'source_version' => 1, 'payload_hash' => str_repeat('b', 64),
                'occurred_at' => $occurredAt, 'created_at' => now(),
            ]);
            DB::table('financial_entries')->insert([
                'financial_transaction_id' => $transactionId, 'financial_account_id' => $receivable->id,
                'currency_id' => $c['currency']->id, 'direction' => 'DECREASE',
                'amount' => '1.00', 'created_at' => now(),
            ]);
            DB::table('financial_transactions')->where('id', $transactionId)
                ->update(['status' => 'POSTED', 'posted_at' => now()]);

            return $payoutId;
        });
    }

    private function prepareShamReceivable(array $c): array
    {
        $captain = $c['order']->captainAssignments()->sole()->captain;
        $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order'],
            app(InvoiceService::class)->refresh($c['employee'], $c['order'],
                app(InvoiceService::class)->createDraft($c['employee'], $c['order'])));
        $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
        $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer,
            'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $entry = app(FinancialBalanceQueryService::class)->payableReceivableEntries($captain, $c['order']->branch, $c['currency'])
            ->firstWhere('event_type', FinancialEventType::OrderShamCashProductReimbursementDue->value);
        $accountId = DB::table('financial_entries')->where('id', $entry->financial_entry_id)->value('financial_account_id');

        return [$captain, $entry, $accountId];
    }

    public function test_real_services_serialize_corrected_posting_and_collection(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $ready->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);

            $assignment = $c['order']->captainAssignments()->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->sole();
            $collection = $ready->collection()->create([
                'order_id' => $c['order']->id, 'captain_id' => $assignment->captain_id,
                'order_captain_id' => $assignment->id, 'collection_method' => 'CASH',
                'currency_id' => $c['currency']->id, 'amount' => $ready->grand_total,
                'collected_at' => now(), 'recorded_by' => $c['employee']->id,
            ]);
            $orderResults = $this->race('post', $c['order']->id, $c['order']->id);
            $this->assertSame(['success' => 2], collect($orderResults)->countBy('status')->all(), json_encode($orderResults));
            $this->assertSame(1, FinancialTransaction::where('source_type', 'INVOICE_COLLECTION')->where('source_id', (string) $collection->id)->count());
            $this->assertSame('1.7500', FinancialTransaction::where('source_type', 'INVOICE_COLLECTION')->sole()->entries()->sole()->amount);

            $second = $this->context();
            $secondReady = app(InvoiceService::class)->refresh($second['employee'], $second['order'], app(InvoiceService::class)->createDraft($second['employee'], $second['order']));
            $secondReady->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $second['employee']->id]);
            $second['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $second['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $second['employee']->id, 'changed_at' => now()]);
            $cashResults = $this->race('collect', $secondReady->id, $second['order']->id, [
                'actor_id' => $second['employee']->id,
                'currency_id' => $second['currency']->id,
                'amount' => $secondReady->grand_total,
                'collected_at' => now()->toISOString(),
            ]);
            $this->assertSame(1, collect($cashResults)->where('status', 'success')->count(), json_encode($cashResults));
            $loser = collect($cashResults)->firstWhere('status', 'error');
            $this->assertSame(ValidationException::class, $loser['class'] ?? null, json_encode($cashResults));
            $this->assertSame(1, $secondReady->collection()->count());
            $this->assertSame(2, FinancialTransaction::where('source_type', 'INVOICE_COLLECTION')->count());
            $this->assertSame('1.7500', FinancialTransaction::where('source_id', (string) $secondReady->collection()->sole()->id)->sole()->entries()->sole()->amount);
        });
    }

    public function test_simultaneous_orders_for_same_captain_keep_distinct_branch_debits(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $first = $this->context();
            $captain = $first['order']->captainAssignments()->sole()->captain;
            $second = $this->context($first['currency'], $captain);
            foreach ([$first, $second] as $context) {
                $invoice = app(InvoiceService::class)->issue(
                    $context['employee'], $context['order'],
                    app(InvoiceService::class)->refresh($context['employee'], $context['order'],
                        app(InvoiceService::class)->createDraft($context['employee'], $context['order'])),
                );
                $context['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
                $context['order']->statusHistory()->create([
                    'previous_status' => OrderOperationalStatus::HeadingToCustomer,
                    'new_status' => OrderOperationalStatus::Delivered,
                    'changed_by' => $context['employee']->id, 'changed_at' => now(),
                ]);
                $assignment = $context['order']->captainAssignments()->sole();
                $invoice->collection()->create([
                    'order_id' => $context['order']->id, 'captain_id' => $captain->id,
                    'order_captain_id' => $assignment->id, 'collection_method' => 'CASH',
                    'currency_id' => $context['currency']->id, 'amount' => $invoice->grand_total,
                    'collected_at' => now(), 'recorded_by' => $context['employee']->id,
                ]);
            }
            $accountService = app(FinancialAccountService::class);
            $sharedDeposit = $accountService->getOrCreate($accountService->ownerForCaptain($captain),
                $first['currency'], FinancialAccountKind::CaptainDeposit);
            $results = $this->race('post', [$first['order']->id, $second['order']->id], null, [], $sharedDeposit->id);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $this->assertSame(2, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value)->count());
            $balance = app(FinancialBalanceQueryService::class);
            $this->assertSame('-1.7500', $balance->captainBranchBalance($captain, $first['order']->branch, $first['currency'], FinancialAccountKind::CaptainDeposit));
            $this->assertSame('-1.7500', $balance->captainBranchBalance($captain, $second['order']->branch, $first['currency'], FinancialAccountKind::CaptainDeposit));
            $this->assertSame('-3.5000', $balance->captainBalances($captain, $first['currency'])[FinancialAccountKind::CaptainDeposit->value]);
        });
    }

    public function test_collection_and_delivery_race_crystallizes_exactly_once(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $invoice = app(InvoiceService::class)->issue(
                $c['employee'], $c['order'],
                app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order'])),
            );
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();
            $shop = $c['order']->shops()->sole();
            $shop->update(['picked_up_at' => now()]);
            $shop->receipts()->create(['storage_path' => 'test/'.str()->uuid().'.png', 'original_filename' => 'test.png', 'mime_type' => 'image/png', 'file_size' => 1, 'uploaded_by' => $c['employee']->id, 'uploaded_at' => now()]);
            $results = $this->race(['collect', 'deliver'], [$invoice->id, $c['order']->id], $c['order']->id, [
                ['actor_id' => $c['employee']->id, 'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total, 'collected_at' => now()->toISOString()],
                ['actor_id' => $c['employee']->id],
            ]);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $this->assertSame(OrderOperationalStatus::Delivered, $c['order']->fresh()->operational_status);
            $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value)->count());
        });
    }

    public function test_issue_and_collection_race_respects_issue_prerequisite_and_retries_safely(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            $results = $this->race(['issue', 'collect'], $ready->id, $c['order']->id, [
                ['actor_id' => $c['employee']->id, 'order_id' => $c['order']->id],
                ['actor_id' => $c['employee']->id, 'currency_id' => $c['currency']->id, 'amount' => $ready->grand_total, 'collected_at' => now()->toISOString()],
            ]);
            $this->assertGreaterThanOrEqual(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(InvoiceStatus::Issued, $ready->fresh()->status);
            if (! $ready->collection()->exists()) {
                app(InvoiceCollectionService::class)->record($c['employee'], $ready->fresh(), InvoiceCollectionMethod::Cash, $ready->grand_total, $c['currency']->id, now());
            }
            $this->assertSame(1, $ready->collection()->count());
            $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value)->count());
        });
    }

    public function test_backfill_and_live_retry_share_one_corrected_event_identity(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order'], app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order'])));
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            $assignment = $c['order']->captainAssignments()->sole();
            $invoice->collection()->create(['order_id' => $c['order']->id, 'captain_id' => $assignment->captain_id, 'order_captain_id' => $assignment->id, 'collection_method' => 'CASH', 'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total, 'collected_at' => now(), 'recorded_by' => $c['employee']->id]);
            $results = $this->race(['post', 'backfill'], $c['order']->id, $c['order']->id);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value)->count());
        });
    }

    public function test_correction_and_normal_posting_race_never_duplicates_reversal_or_new_event(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order'], app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order'])));
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            $assignment = $c['order']->captainAssignments()->sole();
            $collection = $invoice->collection()->create(['order_id' => $c['order']->id, 'captain_id' => $assignment->captain_id, 'order_captain_id' => $assignment->id, 'collection_method' => 'CASH', 'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total, 'collected_at' => now(), 'recorded_by' => $c['employee']->id]);
            // The production reconciliation hashes persisted source precision, not the pre-save Carbon instance.
            $collection = $collection->fresh();
            $accounts = app(FinancialAccountService::class);
            $account = $accounts->getOrCreate($accounts->ownerForCaptain($assignment->captain), $c['currency'], FinancialAccountKind::CaptainCashCustody);
            app(FinancialPostingService::class)->postEvent($c['currency'], 'INVOICE_COLLECTION', $collection->id, FinancialEventType::InvoiceCashCustodyReceived->value, 1, $collection->collected_at, [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $collection->amount]], $c['order']->branch, $collection->recordedBy);
            $results = $this->race(['correct', 'post'], $c['order']->id, $c['order']->id);
            $this->assertGreaterThanOrEqual(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(1, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
            $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value)->count());
        });
    }

    public function test_invoice_issue_and_production_repricing_contend_on_one_order_before_snapshot(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $originId = $c['order']->currentDeliveryPricing()->sole()->from_area_id;

            $results = $this->race(['issue', 'reprice'], [$ready->id, $c['order']->id], $c['order']->id, [
                ['actor_id' => $c['employee']->id, 'order_id' => $c['order']->id],
                ['actor_id' => $c['employee']->id, 'origin_id' => $originId],
            ]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(1, collect($results)->where('status', 'error')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);

            if ($ready->fresh()->status !== InvoiceStatus::Issued) {
                $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], $ready->fresh());
                $ready = app(InvoiceService::class)->issue($c['employee'], $c['order'], $ready);
            }
            $snapshot = OrderFinancialSourceSnapshot::where('invoice_id', $ready->id)->sole();
            $this->assertSame($ready->source_order_delivery_pricing_id, $snapshot->order_delivery_pricing_id);
            $this->assertSame($c['order']->branch_id, $snapshot->branch_id);
            $this->assertSame($ready->delivery_total, $snapshot->lines()->sum('customer_delivery_amount_allocated'));
            $this->assertCount(1, $snapshot->lines);
        });
    }

    public function test_direct_sql_snapshot_insert_is_validated_at_commit(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $other = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $ready->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
            $payload = app(OrderFinancialSourceSnapshotService::class)->build($ready->fresh());
            $otherAssignment = $other['order']->captainAssignments()->sole();
            $otherPricing = $other['order']->currentDeliveryPricing()->sole();

            $cases = [
                'wrong Order' => fn (&$head, &$lines) => $head['order_id'] = $other['order']->id,
                'wrong Invoice' => fn (&$head, &$lines) => $head['invoice_id'] = 999999,
                'wrong Branch' => fn (&$head, &$lines) => $head['branch_id'] = $other['order']->branch_id,
                'wrong Currency' => fn (&$head, &$lines) => $head['currency_id'] = $other['currency']->id,
                'wrong pricing source' => fn (&$head, &$lines) => $head['order_delivery_pricing_id'] = $otherPricing->id,
                'foreign participant' => function (&$head, &$lines) use ($otherAssignment): void {
                    $lines[0]['order_captain_id'] = $otherAssignment->id;
                    $lines[0]['captain_id'] = $otherAssignment->captain_id;
                },
                'Captain mismatch' => fn (&$head, &$lines) => $lines[0]['captain_id'] = $otherAssignment->captain_id,
                'missing primary' => fn (&$head, &$lines) => $lines[0]['is_primary'] = false,
                'wrong primary identity' => fn (&$head, &$lines) => $head['primary_order_captain_id'] = $otherAssignment->id,
                'wrong entitlement' => fn (&$head, &$lines) => $lines[0]['captain_entitlement'] = '9.0000',
                'wrong collectible' => fn (&$head, &$lines) => $lines[0]['company_collectible'] = '9.0000',
                'wrong shortfall' => fn (&$head, &$lines) => $lines[0]['captain_shortfall'] = '9.0000',
                'delivery total drift' => function (&$head, &$lines): void {
                    $lines[0]['customer_delivery_amount_allocated'] = '6.0000';
                    $lines[0]['company_collectible'] = '2.7500';
                },
                'product total drift' => fn (&$head, &$lines) => $lines[0]['product_funding_share'] = '9.0000',
                'duplicate participant' => fn (&$head, &$lines) => $lines[] = $lines[0],
                'excess precision' => fn (&$head, &$lines) => $lines[0]['product_funding_share'] = '10.001',
                'tiny excess precision' => fn (&$head, &$lines) => $lines[0]['product_funding_share'] = '10.00004',
                'NaN' => fn (&$head, &$lines) => $head['items_subtotal'] = 'NaN',
                'Infinity' => fn (&$head, &$lines) => $head['items_subtotal'] = 'Infinity',
                '-Infinity' => fn (&$head, &$lines) => $head['items_subtotal'] = '-Infinity',
                'invalid payload hash' => fn (&$head, &$lines) => $head['payload_hash'] = str_repeat('x', 64),
            ];
            foreach ($cases as $label => $change) {
                $head = $payload;
                $lines = $head['captain_allocations'];
                unset($head['captain_allocations']);
                $change($head, $lines);
                try {
                    $this->insertSnapshotDirectly($head, $lines);
                    $this->fail("{$label} was accepted at COMMIT.");
                } catch (QueryException|PDOException $exception) {
                    $this->assertSame(0, OrderFinancialSourceSnapshot::count(), $label.' '.$exception->getMessage());
                }
            }
            $source = DB::table('order_delivery_allocations')
                ->where('order_delivery_pricing_id', $payload['order_delivery_pricing_id'])->first();
            $extra = (array) $source;
            unset($extra['id']);
            $extra['order_captain_id'] = $otherAssignment->id;
            $extra['captain_id'] = $otherAssignment->captain_id;
            $extraId = DB::table('order_delivery_allocations')->insertGetId($extra);
            try {
                $head = $payload;
                $lines = $head['captain_allocations'];
                unset($head['captain_allocations']);
                try {
                    $this->insertSnapshotDirectly($head, $lines);
                    $this->fail('An allocation for a nonparticipant was accepted.');
                } catch (QueryException|PDOException) {
                    $this->assertSame(0, OrderFinancialSourceSnapshot::count());
                }
            } finally {
                DB::table('order_delivery_allocations')->where('id', $extraId)->delete();
            }
            $head = $payload;
            $lines = $head['captain_allocations'];
            unset($head['captain_allocations']);
            $this->assertSame($c['order']->id, $head['order_id']);
            $columns = DB::select("SELECT table_name, column_name, numeric_precision FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name IN ('order_financial_source_snapshots', 'order_financial_source_snapshot_lines')
                AND data_type = 'numeric'");
            $this->assertCount(6, $columns);
            foreach ($columns as $column) {
                $this->assertNull($column->numeric_precision, $column->table_name.'.'.$column->column_name);
            }
            $this->insertSnapshotDirectly($head, $lines);
            $this->assertSame(1, OrderFinancialSourceSnapshot::count());
            $this->assertSame($payload['payload_hash'], app(OrderFinancialSourceSnapshotService::class)->read($ready->fresh())['payload_hash']);
            try {
                DB::table('currencies')->where('id', $c['currency']->id)->update(['decimal_places' => 3]);
                $this->fail('A frozen snapshot Currency changed precision.');
            } catch (QueryException|PDOException) {
                $this->assertSame(2, $c['currency']->fresh()->decimal_places);
            }
        });
    }

    public function test_direct_snapshot_money_obeys_zero_and_four_decimal_currency_precision(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            foreach ([0 => '10.1', 4 => '10.00004'] as $precision => $invalidShare) {
                $c = $this->context();
                $c['currency']->update(['decimal_places' => $precision]);
                if ($precision === 0) {
                    app(DeliveryPricingSettings::class)->update($c['admin'], '100', '0');
                    app(OrderDeliveryPricingService::class)->price($c['employee'], $c['order'], $c['from']);
                }
                $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
                $ready->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
                $payload = app(OrderFinancialSourceSnapshotService::class)->build($ready->fresh());
                $head = $payload;
                $lines = $head['captain_allocations'];
                unset($head['captain_allocations']);
                $lines[0]['product_funding_share'] = $invalidShare;
                try {
                    $this->insertSnapshotDirectly($head, $lines);
                    $this->fail("Currency precision {$precision} accepted {$invalidShare}.");
                } catch (QueryException|PDOException) {
                    $this->assertSame(0, OrderFinancialSourceSnapshot::where('invoice_id', $ready->id)->count());
                }
                $head['items_subtotal'] = $precision === 0 ? '10.0000' : '10.00';
                $lines[0]['product_funding_share'] = '10.0000';
                $this->insertSnapshotDirectly($head, $lines);
                $this->assertSame(1, OrderFinancialSourceSnapshot::where('invoice_id', $ready->id)->count());
            }
        });
    }

    public function test_valid_looking_wrong_hash_is_rejected_without_poisoning_the_invoice_snapshot_slot(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $ready->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
            $payload = app(OrderFinancialSourceSnapshotService::class)->build($ready->fresh());
            $lines = $payload['captain_allocations'];
            unset($payload['captain_allocations']);
            $payload['payload_hash'] = hash('sha256', 'unrelated valid-looking fingerprint');

            try {
                $this->insertSnapshotDirectly($payload, $lines);
                $this->fail('A valid-looking incorrect fingerprint committed.');
            } catch (QueryException|PDOException $exception) {
                $this->assertStringContainsString('payload fingerprint mismatch', $exception->getMessage());
                $this->assertSame(0, OrderFinancialSourceSnapshot::where('invoice_id', $ready->id)->count());
            }

            $snapshot = app(OrderFinancialSourceSnapshotService::class)->capture($ready->fresh());
            $this->assertSame(1, OrderFinancialSourceSnapshot::where('invoice_id', $ready->id)->count());
            $this->assertSame($snapshot->payload_hash, app(OrderFinancialSourceSnapshotService::class)->read($ready->fresh())['payload_hash']);
            $this->assertSame($snapshot->id, app(OrderFinancialSourceSnapshotService::class)->capture($ready->fresh())->id);
        });
    }

    public function test_valid_direct_sql_fingerprint_commits_and_corrected_posting_consumes_it(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $ready->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
            $payload = app(OrderFinancialSourceSnapshotService::class)->build($ready->fresh());
            $lines = $payload['captain_allocations'];
            unset($payload['captain_allocations']);
            $this->insertSnapshotDirectly($payload, $lines);
            $snapshot = OrderFinancialSourceSnapshot::where('invoice_id', $ready->id)->sole();
            $this->assertSame($snapshot->payload_hash, DB::scalar('SELECT financial_order_snapshot_expected_hash(?)', [$snapshot->id]));
            $this->assertSame($snapshot->payload_hash, app(OrderFinancialSourceSnapshotService::class)->read($ready->fresh())['payload_hash']);

            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer,
                'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            $assignment = $c['order']->captainAssignments()->sole();
            $ready->collection()->create(['order_id' => $c['order']->id, 'captain_id' => $assignment->captain_id,
                'order_captain_id' => $assignment->id, 'collection_method' => InvoiceCollectionMethod::Cash,
                'currency_id' => $c['currency']->id, 'amount' => $ready->grand_total,
                'collected_at' => now(), 'recorded_by' => $c['employee']->id]);
            $events = app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
            $this->assertCount(1, $events);
            $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value)->count());
        });
    }

    public function test_database_fingerprint_covers_every_frozen_financial_identity(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $ready->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
            $base = app(OrderFinancialSourceSnapshotService::class)->build($ready->fresh());
            $changes = [
                'OrderCaptain' => fn (&$p) => $p['captain_allocations'][0]['order_captain_id']++,
                'Captain' => fn (&$p) => $p['captain_allocations'][0]['captain_id']++,
                'primary role' => fn (&$p) => $p['captain_allocations'][0]['is_primary'] = false,
                'customer allocation' => fn (&$p) => $p['captain_allocations'][0]['customer_delivery_amount_allocated'] = '4.0000',
                'entitlement' => fn (&$p) => $p['captain_allocations'][0]['captain_entitlement'] = '4.0000',
                'company share' => fn (&$p) => $p['captain_allocations'][0]['company_collectible'] = '1.0000',
                'shortfall' => fn (&$p) => $p['captain_allocations'][0]['captain_shortfall'] = '1.0000',
                'product share' => fn (&$p) => $p['captain_allocations'][0]['product_funding_share'] = '9.0000',
                'primary assignment' => fn (&$p) => $p['primary_order_captain_id']++,
                'primary Captain' => fn (&$p) => $p['primary_captain_id']++,
                'Currency precision' => fn (&$p) => $p['currency_decimal_places']++,
                'Branch' => fn (&$p) => $p['branch_id']++,
                'pricing source' => fn (&$p) => $p['order_delivery_pricing_id']++,
                'issued microsecond' => fn (&$p) => $p['issued_at'] = CarbonImmutable::parse($p['issued_at'])->addMicrosecond()->format('Y-m-d\TH:i:s.uP'),
            ];
            foreach ($changes as $label => $change) {
                $altered = $base;
                $change($altered);
                $this->assertNotSame($base['payload_hash'], $this->snapshotHashFromParts($altered), $label);
            }
        });
    }

    public function test_direct_sql_under_and_over_allocation_fail_at_commit_while_exact_total_succeeds(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $pricing = $c['order']->currentDeliveryPricing()->sole();
            $pricing->update(['suggested_unit_fee' => '100.00', 'applied_unit_fee' => '100.00',
                'gross_delivery_total' => '100.00', 'customer_delivery_total' => '100.00']);
            $pricing->allocations()->sole()->update(['applied_unit_fee' => '100.00', 'captain_entitlement' => '65.00',
                'customer_delivery_amount_allocated' => '100.00', 'company_collectible' => '35.00']);
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $ready->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
            $base = app(OrderFinancialSourceSnapshotService::class)->build($ready->fresh());
            $this->assertSame('100.0000', $ready->delivery_total);
            foreach (['under' => ['90.0000', '25.0000'], 'over' => ['110.0000', '45.0000']] as $label => [$allocated, $company]) {
                $altered = $base;
                $altered['captain_allocations'][0]['customer_delivery_amount_allocated'] = $allocated;
                $altered['captain_allocations'][0]['company_collectible'] = $company;
                $altered['payload_hash'] = $this->snapshotHashFromParts($altered);
                $lines = $altered['captain_allocations'];
                unset($altered['captain_allocations']);
                try {
                    $this->insertSnapshotDirectly($altered, $lines);
                    $this->fail("{$label} allocation committed.");
                } catch (QueryException|PDOException $exception) {
                    $this->assertStringContainsString('unreconciled participants or totals', $exception->getMessage(), $label);
                    $this->assertSame(0, OrderFinancialSourceSnapshot::where('invoice_id', $ready->id)->count());
                }
            }
            $lines = $base['captain_allocations'];
            unset($base['captain_allocations']);
            $this->insertSnapshotDirectly($base, $lines);
            $this->assertSame(1, OrderFinancialSourceSnapshot::where('invoice_id', $ready->id)->count());
        });
    }

    public function test_staggered_allocation_upgrade_rejects_a_weak_snapshot_with_an_extra_source_allocation(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateThroughSnapshotStage('2026_09_27_120000_harden_order_financial_source_snapshots.php');
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $other = $this->context();
            [$head, $lines, $invoice] = $this->legacySnapshotFixture($c);
            $source = (array) $c['order']->currentDeliveryPricing()->sole()->allocations()->sole()->getAttributes();
            unset($source['id']);
            $otherAssignment = $other['order']->captainAssignments()->sole();
            $source['order_captain_id'] = $otherAssignment->id;
            $source['captain_id'] = $otherAssignment->captain_id;
            DB::table('order_delivery_allocations')->insert($source);
            $this->insertSnapshotDirectly($head, $lines);
            $this->assertSame(1, OrderFinancialSourceSnapshot::where('invoice_id', $invoice->id)->count(), 'The weak 120000 guard accepted the extra source allocation.');

            $this->applySnapshotMigration('2026_09_27_130000_preserve_order_financial_source_timestamp_precision.php');
            $this->applySnapshotMigration('2026_09_27_140000_verify_financial_snapshot_allocation_set.php');
            $this->applySnapshotMigration('2026_09_27_150000_freeze_financial_snapshot_currency.php');
            try {
                $this->applySnapshotMigration('2026_09_27_160000_finalize_order_financial_snapshot_integrity.php');
                $this->fail('An old snapshot with an extra source allocation passed final migration.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('unmatched allocation set', $exception->getMessage());
                $this->assertStringContainsString("snapshot_id=1, order_id={$c['order']->id}, invoice_id={$invoice->id}", $exception->getMessage());
            }
            $this->assertFalse(Schema::hasColumn('order_financial_source_snapshots', 'currency_decimal_places'));
        });
    }

    public function test_staggered_currency_upgrade_rejects_precision_that_was_not_historically_frozen(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateThroughSnapshotStage('2026_09_27_120000_harden_order_financial_source_snapshots.php');
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            [$head, $lines, $invoice] = $this->legacySnapshotFixture($c);
            $this->insertSnapshotDirectly($head, $lines);
            $this->applySnapshotMigration('2026_09_27_130000_preserve_order_financial_source_timestamp_precision.php');
            $this->applySnapshotMigration('2026_09_27_140000_verify_financial_snapshot_allocation_set.php');
            DB::table('currencies')->where('id', $c['currency']->id)->update(['decimal_places' => 4]);
            $this->assertSame(4, (int) $c['currency']->fresh()->decimal_places);

            $this->applySnapshotMigration('2026_09_27_150000_freeze_financial_snapshot_currency.php');
            try {
                $this->applySnapshotMigration('2026_09_27_160000_finalize_order_financial_snapshot_integrity.php');
                $this->fail('Unknown historical Currency precision passed final migration.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('historical precision cannot be proven', $exception->getMessage());
                $this->assertStringContainsString("snapshot_id=1, order_id={$c['order']->id}, invoice_id={$invoice->id}", $exception->getMessage());
            }
        });
    }

    public function test_staggered_upgrade_without_old_snapshots_completes_and_freezes_future_precision(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateThroughSnapshotStage('2026_09_27_120000_harden_order_financial_source_snapshots.php');
            foreach (['130000_preserve_order_financial_source_timestamp_precision',
                '140000_verify_financial_snapshot_allocation_set',
                '150000_freeze_financial_snapshot_currency',
                '160000_finalize_order_financial_snapshot_integrity'] as $suffix) {
                $this->applySnapshotMigration('2026_09_27_'.$suffix.'.php');
            }
            $this->assertTrue(Schema::hasColumn('order_financial_source_snapshots', 'currency_decimal_places'));
            $this->assertSame(0, OrderFinancialSourceSnapshot::count());
        });
    }

    public function test_direct_sql_allocation_writer_waits_for_issued_snapshot_and_cannot_change_its_history(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $allocation = $c['order']->currentDeliveryPricing()->sole()->allocations()->sole();
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-snapshot-race-'.bin2hex(random_bytes(8));
            mkdir($directory, 0700, true);
            $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload.json';
            $readyPath = $directory.DIRECTORY_SEPARATOR.'ready';
            $goPath = $directory.DIRECTORY_SEPARATOR.'go';
            $resultPath = $directory.DIRECTORY_SEPARATOR.'result.json';
            $tag = 'snapshot-sql-'.bin2hex(random_bytes(6));
            $process = null;
            DB::beginTransaction();
            try {
                $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order'], $ready);
                $snapshot = OrderFinancialSourceSnapshot::where('invoice_id', $invoice->id)->sole();
                $this->assertSame('1.7500', $snapshot->lines()->sole()->company_collectible);
                file_put_contents($payloadPath, json_encode(['operation' => 'direct-allocation-update', 'id' => $allocation->id, 'worker_tag' => $tag], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/order_financial_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $deadline = microtime(true) + 10;
                while (! file_exists($readyPath) && microtime(true) < $deadline) {
                    usleep(25_000);
                }
                $this->assertFileExists($readyPath);
                touch($goPath);
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $blocked = (int) DB::selectOne("SELECT count(*) total FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$tag])->total;
                    if ($blocked === 1) {
                        break;
                    }
                    usleep(25_000);
                } while (microtime(true) < $deadline);
                $this->assertSame(1, $blocked, 'Direct SQL must wait for the snapshot source table lock.');
                DB::commit();
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $this->assertSame('success', json_decode(file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR)['status']);
                $this->assertSame('1.0000', $allocation->fresh()->company_collectible);
                $this->assertSame('1.7500', $snapshot->lines()->sole()->company_collectible);
            } finally {
                if (DB::transactionLevel()) {
                    DB::rollBack();
                }
                if ($process?->isRunning()) {
                    $process->stop();
                }
                foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                    unlink($path);
                }
                rmdir($directory);
            }
        });
    }

    public function test_two_captain_snapshot_commits_with_exact_product_and_delivery_totals(): void
    {
        $this->withIsolatedSchema(function (): void {
            $this->migrateAccountingV1Schema();
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $captainUser = User::factory()->create();
            $captain = Captain::create(['user_id' => $captainUser->id, 'home_branch_id' => $c['order']->branch_id,
                'operational_status' => CaptainOperationalStatus::Available]);
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
            app(OrderCaptainService::class)->assign($c['employee'], $c['order'], $captain, OrderCaptainRole::Support);

            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order'], $ready);
            $snapshot = OrderFinancialSourceSnapshot::where('invoice_id', $invoice->id)->sole();
            $this->assertCount(2, $snapshot->lines);
            $this->assertSame('10.0000', $snapshot->lines()->sum('product_funding_share'));
            $this->assertSame($invoice->delivery_total, $snapshot->lines()->sum('customer_delivery_amount_allocated'));
            $this->assertSame(1, $snapshot->lines()->where('is_primary', true)->count());
        });
    }

    private function insertSnapshotDirectly(array $head, array $lines): void
    {
        DB::transaction(function () use ($head, $lines): void {
            unset($head['primary_captain_id']);
            $id = DB::table('order_financial_source_snapshots')->insertGetId($head + ['captured_at' => now()->format('Y-m-d H:i:s.uP')]);
            foreach ($lines as $line) {
                DB::table('order_financial_source_snapshot_lines')->insert(['order_financial_source_snapshot_id' => $id] + $line);
            }
        });
    }

    private function snapshotHashFromParts(array $payload): string
    {
        $lines = $payload['captain_allocations'];
        unset($payload['captain_allocations'], $payload['payload_hash']);

        return (string) DB::scalar('SELECT financial_order_snapshot_hash_parts(CAST(? AS jsonb), CAST(? AS jsonb))', [
            json_encode($payload, JSON_THROW_ON_ERROR), json_encode($lines, JSON_THROW_ON_ERROR),
        ]);
    }

    private function migrateThroughSnapshotStage(string $lastMigration): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            if (strcmp(basename($file), $lastMigration) > 0) {
                break;
            }
            $this->applySnapshotMigration(basename($file));
        }
    }

    private function applySnapshotMigration(string $filename): void
    {
        (require database_path('migrations/'.$filename))->up();
    }

    private function legacySnapshotFixture(array $c): array
    {
        $invoice = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
        $invoice->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
        $invoice = $invoice->fresh();
        $pricing = $c['order']->currentDeliveryPricing()->sole();
        $allocation = $pricing->allocations()->sole();
        $assignment = $c['order']->captainAssignments()->sole();
        $lines = [[
            'order_captain_id' => $assignment->id,
            'captain_id' => $assignment->captain_id,
            'is_primary' => true,
            'product_funding_share' => $invoice->items_subtotal,
            'captain_entitlement' => $allocation->captain_entitlement,
            'customer_delivery_amount_allocated' => $allocation->customer_delivery_amount_allocated,
            'company_collectible' => $allocation->company_collectible,
            'captain_shortfall' => $allocation->captain_shortfall,
        ]];
        $payload = [
            'invoice_id' => $invoice->id,
            'invoice_version' => $invoice->version,
            'order_id' => $c['order']->id,
            'branch_id' => $c['order']->branch_id,
            'issued_at' => CarbonImmutable::parse(DB::table('invoices')->where('id', $invoice->id)->value('issued_at'))->utc()->format('Y-m-d\TH:i:s.uP'),
            'order_delivery_pricing_id' => $pricing->id,
            'currency_id' => $c['currency']->id,
            'primary_order_captain_id' => $assignment->id,
            'items_subtotal' => $invoice->items_subtotal,
            'captain_allocations' => $lines,
        ];
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$entry) {
                $entry = $canonical($entry);
            }

            return $value;
        };
        $hash = hash('sha256', json_encode($canonical($payload), JSON_THROW_ON_ERROR));
        unset($payload['captain_allocations']);

        return [[...$payload, 'payload_hash' => $hash], $lines, $invoice];
    }

    private function context(?Currency $existingCurrency = null, ?Captain $existingCaptain = null): array
    {
        // Current pricing requires the non-financial Shop origin snapshot even when this fixture
        // deliberately leaves Captain financial guards at a weaker historical upgrade stage.
        if (! Schema::hasColumn('order_shops', 'origin_area_id')) {
            (require database_path('migrations/2026_10_06_000000_add_origin_area_to_order_shops.php'))->up();
        }
        if (! Schema::hasTable('order_delivery_pricing_routes')) {
            (require database_path('migrations/2026_10_06_010000_create_order_delivery_pricing_routes.php'))->up();
        }
        $currency = $existingCurrency ?? Currency::create(['name' => 'Dollar', 'code' => strtoupper(str()->random(3)), 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create();
        $to = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Race Customer']);
        $phone = $customer->phones()->create(['phone' => '991', 'normalized_phone' => '991']);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Race Street']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Race Shop', 'origin_area_id' => $from->id, 'sort_order' => 1]);
        $order->update(['pricing_class' => OrderPricingClass::SingleShop]);
        OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime, 'product_name_snapshot' => 'Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1', 'current_quantity' => '1', 'actual_unit_price' => '10', 'actual_price_source' => ActualPriceSource::ManualVerified, 'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $employee->id]);
        app(OrderPricingService::class)->recalculate($order);
        if ($existingCaptain) {
            $captain = $existingCaptain;
            app(CaptainBranchService::class)->assignTemporary($admin, $captain, [
                'branch_id' => $branch->id, 'starts_at' => now()->subMinute(), 'reason' => 'Concurrent Branch coverage',
            ]);
        } else {
            $captainUser = User::factory()->create();
            $captain = Captain::create(['user_id' => $captainUser->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        }
        app(OrderCaptainService::class)->assign($employee, $order, $captain, OrderCaptainRole::PrimaryCollection);
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id, 'currency_id' => $currency->id, 'order_pricing_class' => OrderPricingClass::SingleShop->value, 'delivery_unit_fee' => '5', 'effective_from' => now()->subDay()]);
        app(DeliveryPricingSettings::class)->update($admin, '65', '0');
        app(OrderDeliveryPricingService::class)->price($employee, $order, $from);

        return compact('currency', 'employee', 'order', 'admin', 'from');
    }

    private function race(string|array $operation, int|array $id, ?int $orderId, array $extra = [], ?int $accountId = null): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-order-finance-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $goPath = $directory.DIRECTORY_SEPARATOR.'go';
        $tag = 'order-finance-'.bin2hex(random_bytes(6));
        $workers = [];
        if ($orderId !== null) {
            DB::beginTransaction();
            DB::table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
        } elseif ($accountId !== null) {
            DB::beginTransaction();
            DB::table('financial_accounts')->where('id', $accountId)->lockForUpdate()->firstOrFail();
        }
        try {
            foreach ([0, 1] as $index) {
                $workerOperation = is_array($operation) ? $operation[$index] : $operation;
                $workerId = is_array($id) ? $id[$index] : $id;
                $workerExtra = isset($extra[$index]) ? $extra[$index] : $extra;
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$index}.json";
                $readyPath = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                file_put_contents($payloadPath, json_encode(['operation' => $workerOperation, 'id' => $workerId] + $workerExtra + ['worker_tag' => $tag], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/order_financial_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'resultPath');
            }
            $deadline = microtime(true) + 10;
            while (! collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])) && microtime(true) < $deadline) {
                usleep(25_000);
            }
            touch($goPath);
            if ($orderId !== null) {
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $blocked = (int) DB::selectOne("SELECT count(*) total FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$tag])->total;
                    if ($blocked === 2) {
                        break;
                    }
                    usleep(25_000);
                } while (microtime(true) < $deadline);
                $this->assertSame(2, $blocked, 'Both production workers must contend on the Order lock.');
                DB::commit();
            } elseif ($accountId !== null) {
                $parentPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $blocked = (int) DB::selectOne(
                        'SELECT count(*) AS total FROM pg_stat_activity WHERE application_name = ? AND CAST(? AS integer) = ANY(pg_blocking_pids(pid))',
                        [$tag, $parentPid],
                    )->total;
                    if ($blocked >= 1) {
                        break;
                    }
                    usleep(25_000);
                } while (microtime(true) < $deadline);
                $this->assertGreaterThanOrEqual(1, $blocked, 'A real posting worker must block on the shared FinancialAccount row.');
                DB::commit();
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());
                $results[] = json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop();
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function withIsolatedSchema(callable $callback): void
    {
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'order_finance_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            $callback();
        } finally {
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    private function workerEnvironment(): array
    {
        $database = config('database.connections.'.config('database.default'));

        return ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SCHEMA' => (string) $database['search_path']];
    }
}
