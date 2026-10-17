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
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\BillPaymentFeeRuleService;
use App\Services\CaptainBranchService;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialPostingService;
use App\Services\OrderFinancialSemanticsCorrectionService;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshAccountingV1Database;
use Tests\TestCase;

class OrderFinancialPostingTest extends TestCase
{
    use RefreshAccountingV1Database;

    public function test_fuel_migration_preserves_existing_captains_and_posted_v1_money(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $allocation = $c['order']->currentDeliveryPricing->allocations()->sole();
        $before = $allocation->getAttributes();
        $snapshot = app(OrderFinancialSourceSnapshotService::class)->read($invoice);
        $entries = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $transactions = DB::table('financial_transactions')->orderBy('id')->get()->toJson();

        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_17_000000_add_captain_fuel_allowance.php', '--force' => true]));

        $this->assertSame('0.0000', $c['captains'][0]->fresh()->fuel_allowance_percentage);
        $after = $allocation->fresh();
        $this->assertSame($before, array_intersect_key($after->getAttributes(), $before));
        $this->assertNull($after->base_captain_share_percent_snapshot);
        $this->assertSame('0.0000', $after->fuel_allowance_amount);
        $this->assertSame($snapshot, app(OrderFinancialSourceSnapshotService::class)->read($invoice));
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($entries, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertSame($transactions, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
    }

    public function test_completed_payout_cannot_be_reversed_by_service_or_direct_sql(): void
    {
        Storage::fake('captain_financial_evidence');
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $captain = $c['captains'][0];
        $balances = app(FinancialBalanceQueryService::class);
        $source = $balances->payableReceivableEntries($captain, $c['branch'], $c['currency'])->first();
        $payout = app(CaptainReceivablePayoutService::class)->record(
            $c['employee'], $captain, $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '5',
                'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')],
            [['financial_entry_id' => $source->financial_entry_id, 'amount' => '5']],
            UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')),
        );
        $original = FinancialTransaction::where('source_type', 'CAPTAIN_RECEIVABLE_PAYOUT')->sole();
        $before = $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        $count = FinancialTransaction::count();
        $this->assertThrows(fn () => app(FinancialPostingService::class)->reverse($original,
            'TEST_REVERSAL', (string) str()->uuid(), 'REVERSE_PAYOUT', 1, now(), 'Attempt'),
            FinancialIdempotencyConflictException::class);
        try {
            DB::transaction(fn () => DB::table('financial_transactions')->insert([
                'transaction_kind' => 'REVERSAL', 'status' => 'DRAFT', 'currency_id' => $c['currency']->id,
                'branch_id' => $c['branch']->id, 'source_type' => 'DIRECT_SQL', 'source_id' => (string) str()->uuid(),
                'event_type' => 'REVERSE_PAYOUT', 'source_version' => 1,
                'payload_hash' => str_repeat('a', 64), 'occurred_at' => now(),
                'reversal_of_transaction_id' => $original->id, 'reason' => 'Attempt', 'created_at' => now(),
            ]));
            $this->fail('Direct SQL reversed a completed payout.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('FINANCIAL_SOURCE_REQUIRES_CONTROLLED_CORRECTION', $exception->getMessage());
        }
        $this->assertSame($count, FinancialTransaction::count());
        $this->assertSame(0, FinancialTransaction::where('reversal_of_transaction_id', $original->id)->count());
        $this->assertDatabaseHas('captain_receivable_payouts', ['id' => $payout->id]);
        $this->assertDatabaseCount('captain_receivable_payout_allocations', 1);
        $this->assertSame($before, $balances->captainBranchBalance(
            $captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
    }

    public function test_direct_sql_hidden_deposit_entry_cannot_join_a_receivable_payout(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $captain = $c['captains'][0];
        $source = app(FinancialBalanceQueryService::class)
            ->payableReceivableEntries($captain, $c['branch'], $c['currency'])->first();
        $accounts = app(FinancialAccountService::class);
        $owner = $accounts->ownerForCaptain($captain);
        $receivable = $accounts->getOrCreate($owner, $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        $deposit = $accounts->getOrCreate($owner, $c['currency'], FinancialAccountKind::CaptainDeposit);
        $before = FinancialTransaction::count();
        try {
            DB::transaction(function () use ($c, $captain, $source, $receivable, $deposit): void {
                $occurredAt = now()->startOfSecond();
                $payoutId = DB::table('captain_receivable_payouts')->insertGetId([
                    'captain_id' => $captain->id, 'branch_id' => $c['branch']->id,
                    'currency_id' => $c['currency']->id, 'paid_by' => $c['employee']->id,
                    'request_key' => (string) str()->uuid(), 'amount' => '5', 'occurred_at' => $occurredAt,
                    'evidence_path' => 'private/proof.png', 'evidence_name' => 'proof.png',
                    'evidence_mime' => 'image/png', 'evidence_size' => 100,
                    'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
                ]);
                DB::table('captain_receivable_payout_allocations')->insert([
                    'captain_receivable_payout_id' => $payoutId,
                    'financial_entry_id' => $source->financial_entry_id, 'amount' => '5', 'created_at' => now(),
                ]);
                $id = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'currency_id' => $c['currency']->id,
                    'branch_id' => $c['branch']->id, 'source_type' => 'CAPTAIN_RECEIVABLE_PAYOUT',
                    'source_id' => (string) $payoutId, 'event_type' => 'CAPTAIN_RECEIVABLE_PAID',
                    'source_version' => 1, 'payload_hash' => str_repeat('b', 64),
                    'occurred_at' => $occurredAt, 'created_at' => now(),
                ]);
                foreach ([[$receivable->id, 'DECREASE', '5'], [$deposit->id, 'INCREASE', '5']] as [$accountId, $direction, $amount]) {
                    DB::table('financial_entries')->insert([
                        'financial_transaction_id' => $id, 'financial_account_id' => $accountId,
                        'currency_id' => $c['currency']->id, 'direction' => $direction,
                        'amount' => $amount, 'created_at' => now(),
                    ]);
                }
                DB::table('financial_transactions')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now()]);
                DB::statement('SET CONSTRAINTS captain_receivable_payout_complete, captain_financial_event_complete, captain_financial_entry_complete IMMEDIATE');
            });
            $this->fail('The hidden Deposit entry committed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('wrong account, direction, or exact amount', $exception->getMessage());
        }
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertSame($before, FinancialTransaction::count());
    }

    public function test_attributed_payout_reduces_only_selected_final_receivable_and_blocks_reversal(): void
    {
        Storage::fake('captain_financial_evidence');
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $captain = $c['captains'][0];
        $balances = app(FinancialBalanceQueryService::class);
        $sources = $balances->payableReceivableEntries($captain, $c['branch'], $c['currency']);
        $this->assertCount(2, $sources);
        $first = $sources->first();
        $before = $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        $key = (string) str()->uuid();
        $data = ['request_key' => $key, 'amount' => '10.00',
            'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP'), 'reference' => 'Bank receipt'];
        $allocations = [['financial_entry_id' => $first->financial_entry_id, 'amount' => '10.00']];
        $image = fn () => UploadedFile::fake()->createWithContent('payout.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $service = app(CaptainReceivablePayoutService::class);
        $payout = $service->record($c['employee'], $captain, $c['branch'], $c['currency'], $data, $allocations, $image());
        $this->assertSame($payout->id, $service->record($c['employee'], $captain, $c['branch'], $c['currency'], $data, $allocations, $image())->id);
        $this->assertThrows(fn () => $service->record($c['employee'], $captain, $c['branch'], $c['currency'],
            array_replace($data, ['amount' => '11.00']), $allocations, $image()), FinancialIdempotencyConflictException::class);
        $this->assertThrows(fn () => $service->record($c['employee'], $captain, $c['branch'], $c['currency'],
            $data, [['financial_entry_id' => $sources->last()->financial_entry_id, 'amount' => '10.00']], $image()),
            FinancialIdempotencyConflictException::class);
        $this->assertSame(1, $payout->allocations()->count());
        $this->assertSame((string) BigDecimal::of($before)->minus('10')->toScale(4),
            $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('10.0000', $balances->payableReceivableEntries($captain, $c['branch'], $c['currency'])
            ->firstWhere('financial_entry_id', $first->financial_entry_id)->paid_amount);
        $original = FinancialTransaction::findOrFail($first->financial_transaction_id);
        $this->assertThrows(fn () => app(FinancialPostingService::class)->reverse($original, 'TEST', str()->uuid(),
            'REVERSE_PAID', 1, now(), 'Should fail'), FinancialIdempotencyConflictException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('captain_receivable_payout_allocations')
            ->where('captain_receivable_payout_id', $payout->id)->update(['amount' => '1'])), QueryException::class);
        $this->actingAs($c['employee'])->get(route('captains.financials.payouts.evidence', [$captain, $payout]))->assertOk();
        $this->actingAs($captain->user)->get(route('captains.financials.payouts.evidence', [$captain, $payout]))->assertForbidden();
    }

    public function test_postgresql_rejects_unallocated_and_overallocated_direct_payouts(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $source = app(FinancialBalanceQueryService::class)->payableReceivableEntries($c['captains'][0], $c['branch'], $c['currency'])->first();
        $accountId = DB::table('financial_entries')->where('id', $source->financial_entry_id)->value('financial_account_id');

        foreach ([[], [['financial_entry_id' => $source->financial_entry_id, 'amount' => '26.00']]] as $allocations) {
            $this->assertThrows(fn () => DB::transaction(function () use ($c, $accountId, $allocations): void {
                $occurredAt = now()->startOfSecond();
                $payoutId = DB::table('captain_receivable_payouts')->insertGetId([
                    'captain_id' => $c['captains'][0]->id, 'branch_id' => $c['branch']->id,
                    'currency_id' => $c['currency']->id, 'paid_by' => $c['employee']->id,
                    'request_key' => (string) str()->uuid(), 'amount' => '26.00',
                    'occurred_at' => $occurredAt, 'evidence_path' => 'private/proof.png',
                    'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png',
                    'evidence_size' => 100, 'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
                ]);
                foreach ($allocations as $allocation) {
                    DB::table('captain_receivable_payout_allocations')->insert($allocation + [
                        'captain_receivable_payout_id' => $payoutId, 'created_at' => now(),
                    ]);
                }
                $transactionId = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT',
                    'currency_id' => $c['currency']->id, 'branch_id' => $c['branch']->id,
                    'source_type' => 'CAPTAIN_RECEIVABLE_PAYOUT', 'source_id' => (string) $payoutId,
                    'event_type' => 'CAPTAIN_RECEIVABLE_PAID', 'source_version' => 1,
                    'payload_hash' => str_repeat('a', 64), 'occurred_at' => $occurredAt, 'created_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $transactionId, 'financial_account_id' => $accountId,
                    'currency_id' => $c['currency']->id, 'direction' => 'DECREASE',
                    'amount' => '26.00', 'created_at' => now(),
                ]);
                DB::table('financial_transactions')->where('id', $transactionId)->update(['status' => 'POSTED', 'posted_at' => now()]);
                DB::statement('SET CONSTRAINTS captain_receivable_payout_complete, captain_financial_event_complete, captain_financial_entry_complete IMMEDIATE');
            }), QueryException::class);
        }
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertThrows(fn () => DB::transaction(function () use ($c, $source): void {
            $payoutId = DB::table('captain_receivable_payouts')->insertGetId([
                'captain_id' => $c['captains'][0]->id, 'branch_id' => $c['branch']->id,
                'currency_id' => $c['currency']->id, 'paid_by' => $c['employee']->id,
                'request_key' => (string) str()->uuid(), 'amount' => '1', 'occurred_at' => now(),
                'evidence_path' => 'private/proof.png', 'evidence_name' => 'proof.png',
                'evidence_mime' => 'image/png', 'evidence_size' => 100,
                'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
            ]);
            DB::table('captain_receivable_payout_allocations')->insert([
                'captain_receivable_payout_id' => $payoutId,
                'financial_entry_id' => $source->financial_entry_id,
                'amount' => '0.001', 'created_at' => now(),
            ]);
        }), QueryException::class);
    }

    public function test_payout_fails_closed_when_branch_receivable_includes_unattributed_history(): void
    {
        Storage::fake('captain_financial_evidence');
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $captain = $c['captains'][0];
        $source = app(FinancialBalanceQueryService::class)->payableReceivableEntries($captain, $c['branch'], $c['currency'])->first();
        $accounts = app(FinancialAccountService::class);
        $receivable = $accounts->getOrCreate($accounts->ownerForCaptain($captain), $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        app(FinancialPostingService::class)->postEvent($c['currency'], 'TEST_LEGACY', str()->uuid(), 'UNRESOLVED_RECEIVABLE', 1,
            now(), [['account' => $receivable, 'direction' => FinancialEntryDirection::Increase, 'amount' => '2']], $c['branch']);
        $before = DB::table('financial_transactions')->count();
        $this->assertThrows(fn () => app(CaptainReceivablePayoutService::class)->record(
            $c['employee'], $captain, $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '5',
                'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')],
            [['financial_entry_id' => $source->financial_entry_id, 'amount' => '5']],
            UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')),
        ), FinancialIdempotencyConflictException::class);
        $this->assertSame($before, DB::table('financial_transactions')->count());
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
    }

    public function test_one_payout_spans_two_exact_sources_and_later_payout_partially_settles_one_again(): void
    {
        Storage::fake('captain_financial_evidence');
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $captain = $c['captains'][0];
        $balances = app(FinancialBalanceQueryService::class);
        $sources = $balances->payableReceivableEntries($captain, $c['branch'], $c['currency'])->keyBy('event_type');
        $product = $sources->get(FinancialEventType::OrderShamCashProductReimbursementDue->value);
        $delivery = $sources->get(FinancialEventType::OrderShamCashDeliveryEntitlementDue->value);
        $service = app(CaptainReceivablePayoutService::class);
        $image = fn () => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $data = fn (string $amount): array => ['request_key' => (string) str()->uuid(), 'amount' => $amount,
            'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')];
        $first = $service->record($c['employee'], $captain, $c['branch'], $c['currency'], $data('12'), [
            ['financial_entry_id' => $product->financial_entry_id, 'amount' => '10'],
            ['financial_entry_id' => $delivery->financial_entry_id, 'amount' => '2'],
        ], $image());
        $second = $service->record($c['employee'], $captain, $c['branch'], $c['currency'], $data('5'), [
            ['financial_entry_id' => $product->financial_entry_id, 'amount' => '5'],
        ], $image());
        $this->assertSame(2, $first->allocations()->count());
        $this->assertSame(1, $second->allocations()->count());
        $remaining = $balances->payableReceivableEntries($captain, $c['branch'], $c['currency'])->keyBy('financial_entry_id');
        $this->assertSame('15.0000', $remaining->get($product->financial_entry_id)->paid_amount);
        $this->assertSame('2.0000', $remaining->get($delivery->financial_entry_id)->paid_amount);
        $this->assertSame('11.2500', $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCashCustody));
    }

    public function test_reversed_receivable_is_not_payable_and_full_remaining_source_can_be_settled(): void
    {
        Storage::fake('captain_financial_evidence');
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            $invoice->grand_total, $c['currency']->id, now());
        $captain = $c['captains'][0];
        $balances = app(FinancialBalanceQueryService::class);
        $sources = $balances->payableReceivableEntries($captain, $c['branch'], $c['currency'])->keyBy('event_type');
        $product = $sources->get(FinancialEventType::OrderShamCashProductReimbursementDue->value);
        $delivery = $sources->get(FinancialEventType::OrderShamCashDeliveryEntitlementDue->value);
        app(FinancialPostingService::class)->reverse(FinancialTransaction::findOrFail($product->financial_transaction_id),
            'TEST_CORRECTION', (string) str()->uuid(), 'REVERSE_UNPAID_SOURCE', 1, now(), 'Verified source correction');
        $this->assertSame('3.2500', $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertNull($balances->payableReceivableEntries($captain, $c['branch'], $c['currency'])
            ->firstWhere('financial_entry_id', $product->financial_entry_id));
        $service = app(CaptainReceivablePayoutService::class);
        $image = fn () => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $data = fn (string $amount): array => ['request_key' => (string) str()->uuid(), 'amount' => $amount,
            'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')];
        $this->assertThrows(fn () => $service->record($c['employee'], $captain, $c['branch'], $c['currency'],
            $data('4'), [['financial_entry_id' => $delivery->financial_entry_id, 'amount' => '4']], $image()), ValidationException::class);
        $this->assertThrows(fn () => $service->record($c['employee'], $captain, $c['branch'], $c['currency'],
            $data('1'), [['financial_entry_id' => $product->financial_entry_id, 'amount' => '1']], $image()), ValidationException::class);
        $payout = $service->record($c['employee'], $captain, $c['branch'], $c['currency'],
            $data('3.25'), [['financial_entry_id' => $delivery->financial_entry_id, 'amount' => '3.25']], $image());
        $this->assertSame('3.2500', $payout->amount);
        $this->assertSame('0.0000', $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
    }

    public function test_missing_shop_origin_still_rejects_pricing_without_creating_a_version(): void
    {
        $c = $this->context();
        $c['shop']->update(['origin_area_id' => null]);
        $before = $c['order']->deliveryPricings()->count();
        try {
            app(OrderDeliveryPricingService::class)->price($c['employee'], $c['order'], $c['from']);
            $this->fail('Pricing accepted an origin outside the OrderShop source contract.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('domain.the_delivery_origin_must_match_the_order_shop_snapshot'), $exception->errors()['from_area_id'][0]);
        }
        $this->assertSame($before, $c['order']->deliveryPricings()->count());
    }

    public function test_cash_waits_for_delivery_then_debits_primary_deposit_without_custody(): void
    {
        $c = $this->context(2, '100.00');
        $invoice = $this->issue($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->markDelivered($c);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame(1, FinancialTransaction::count());
        $event = FinancialTransaction::sole();
        $this->assertSame(FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value, $event->event_type);
        $this->assertSame((string) $collection->id, $event->source_id);
        $deliveredAt = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->sole()->changed_at;
        $this->assertSame($deliveredAt->timestamp, $event->occurred_at->timestamp);
        $this->assertStringContainsString('"collection_method":"CASH"', $event->notes);
        $this->assertSame('-3.5000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][1]->id, FinancialAccountKind::CaptainCashCustody));
        foreach ($c['captains'] as $captain) {
            $this->assertSame('0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame($captain->id === $c['captains'][0]->id ? '-3.5000' : '0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainDeposit));
        }
        $this->assertSame(0, FinancialTransaction::whereIn('event_type', collect(FinancialEventType::orderDerived())->map->value)->count());
        $this->assertSame('100.0000', $invoice->items_subtotal);
    }

    public function test_cash_shortfall_is_company_receivable_without_fake_custody(): void
    {
        $c = $this->context();
        $c['order']->currentDeliveryPricing->allocations()->sole()->update([
            'captain_entitlement' => '7.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '2.0000',
        ]);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame('2.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
        $this->assertSame(FinancialEventType::OrderCashDeliveryShortfallDue->value, FinancialTransaction::sole()->event_type);
    }

    public function test_multi_captain_cash_assigns_company_share_to_primary_and_shortfall_to_its_captain(): void
    {
        $c = $this->context(2, '50.00');
        $allocations = $c['order']->currentDeliveryPricing->allocations()->orderBy('order_captain_id')->get();
        $allocations[1]->update([
            'captain_entitlement' => '7.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '2.0000',
        ]);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame('-1.7500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $this->balance($c['captains'][1]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('2.0000', $this->balance($c['captains'][1]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame(2, FinancialTransaction::count());
    }

    public function test_sham_cash_reimburses_equal_product_shares_and_full_entitlements(): void
    {
        $c = $this->context(2, '100.00');
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame(2, FinancialTransaction::count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashProductReimbursementDue->value)->count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashDeliveryEntitlementDue->value)->count());
        $this->assertSame(0, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareHeld->value)->count());
        $this->assertSame($collection->id, $invoice->collection()->sole()->id);
        foreach ($c['captains'] as $captain) {
            $this->assertSame('53.2500', $this->balance($captain->id, FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame('0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainCashCustody));
            $this->assertSame('0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainDeposit));
        }
    }

    public static function splitCases(): array
    {
        return [
            'zero decimals' => [0, '501', ['251.0000', '250.0000']],
            'two decimals' => [2, '10.01', ['5.0100', '5.0000']],
            'four decimals' => [4, '0.0001', ['0.0001']],
        ];
    }

    #[DataProvider('splitCases')]
    public function test_sham_product_split_uses_exact_minor_units_and_no_zero_entries(int $decimalPlaces, string $subtotal, array $expected): void
    {
        $c = $this->context(2, $subtotal, $decimalPlaces);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total, $c['currency']->id, now());
        $entries = FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashProductReimbursementDue->value)
            ->sole()->entries()->orderBy('financial_account_id')->pluck('amount')->all();
        $this->assertSame($expected, $entries);
        $this->assertSame((string) BigDecimal::of($subtotal)->toScale(4), (string) collect($entries)->reduce(fn (BigDecimal $sum, string $amount) => $sum->plus($amount), BigDecimal::zero())->toScale(4));
    }

    public function test_backfill_waits_without_collection_then_dry_runs_and_applies_once(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertStringContainsString('WAITING_COLLECTION', Artisan::output());
        $this->assertSame(0, FinancialTransaction::count());
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $tables = ['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries', 'order_financial_source_snapshots'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        Artisan::call('financial:backfill-order-events');
        $this->assertStringContainsString('WOULD POST', Artisan::output());
        $this->assertSame($before, collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all());
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(1, FinancialTransaction::where('source_id', (string) $collection->id)->count());
        $this->assertSame('-1.7500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
    }

    public function test_legacy_correction_reverses_exact_old_cash_events_then_posts_company_share(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postLegacyEvents($c, $invoice, $collection);
        $before = collect(['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries', 'order_financial_source_snapshots'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        Artisan::call('financial:correct-order-financial-semantics');
        $this->assertStringContainsString('LEGACY_9B_CORRECTION_REQUIRED', Artisan::output());
        $this->assertSame($before, collect(array_keys($before))->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all());
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertStringContainsString('LEGACY_CORRECTION_REQUIRED', Artisan::output());
        $this->assertSame(4, FinancialTransaction::count());
        $this->assertSame(0, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame(9, FinancialTransaction::count());
        $this->assertSame(4, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('-1.7500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(9, FinancialTransaction::count());
    }

    public function test_obsolete_9b6_custody_is_reversed_once_and_replaced_by_branch_deposit_debit(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $held = $this->postObsoleteHeld($c, $collection);
        $tables = ['financial_transactions', 'financial_entries', 'financial_accounts', 'financial_owners'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        Artisan::call('financial:correct-order-financial-semantics');
        $this->assertStringContainsString('PHASE_9B6_CORRECTION_REQUIRED', Artisan::output());
        $this->assertSame($before, collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all());
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertStringContainsString('LEGACY_CORRECTION_REQUIRED', Artisan::output());
        $this->assertSame(1, FinancialTransaction::count());
        $this->assertSame(0, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame(3, FinancialTransaction::count());
        $this->assertSame($c['branch']->id, $held->reversal()->sole()->branch_id);
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value)->count());
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('-1.7500', app(FinancialBalanceQueryService::class)->captainBranchBalance(
            $c['captains'][0], $c['branch'], $c['currency'], FinancialAccountKind::CaptainDeposit,
        ));
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(3, FinancialTransaction::count());
    }

    public function test_correcting_one_obsolete_custody_event_does_not_consume_another_order(): void
    {
        $first = $this->context();
        $second = $this->context(1, '25.00', 2, $first['currency'], $first['branch'], $first['captains'][0]);
        foreach ([$first, $second] as $context) {
            $invoice = $this->issue($context);
            $this->markDelivered($context);
            $collection = $this->historicalCollection($context, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
            $this->postObsoleteHeld($context, $collection);
        }

        $correction = app(OrderFinancialSemanticsCorrectionService::class);
        $this->assertSame('corrected', $correction->correct($first['order'], true)['state']);
        $this->assertSame('corrected', $correction->correct($second['order'], true)['state']);
        $this->assertSame(2, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareHeld->value)->count());
        $this->assertSame(2, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value)->count());
        $this->assertSame(2, FinancialTransaction::where('event_type', 'REVERSE_OBSOLETE_CASH_CUSTODY')->count());
        $this->assertSame('0.0000', $this->balance($first['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
    }

    public function test_earlier_unrelated_custody_decrease_does_not_block_later_order_correction(): void
    {
        $c = $this->context();
        $accountService = app(FinancialAccountService::class);
        $custody = $accountService->getOrCreate($accountService->ownerForCaptain($c['captains'][0]),
            $c['currency'], FinancialAccountKind::CaptainCashCustody);
        foreach (['INCREASE' => 'UNRELATED_RECEIPT', 'DECREASE' => 'UNRELATED_HANDOVER'] as $direction => $eventType) {
            app(FinancialPostingService::class)->postEvent(
                $c['currency'], 'UNRELATED_CASH_FLOW', $eventType, $eventType, 1, now()->subDay(),
                [['account' => $custody, 'direction' => $direction, 'amount' => '1.00']], $c['branch'],
            );
        }
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postObsoleteHeld($c, $collection);

        $this->assertSame('corrected', app(OrderFinancialSemanticsCorrectionService::class)
            ->correct($c['order'], true)['state']);
        $this->assertSame(1, FinancialTransaction::where('event_type', 'REVERSE_OBSOLETE_CASH_CUSTODY')->count());
    }

    public function test_bill_principal_handover_on_same_account_does_not_consume_obsolete_order_custody(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $normal = $this->context();
        $invoice = $this->issue($normal);
        $this->markDelivered($normal);
        $collection = $this->historicalCollection($normal, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postObsoleteHeld($normal, $collection);

        $bill = $this->context(1, '25.00', 2, $normal['currency'], $normal['branch'], $normal['captains'][0]);
        app(BillPaymentFeeRuleService::class)->create($bill['admin'], $bill['currency']->id, '2.00', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($bill['employee'], $bill['order'], $bill['currency']->id);
        $this->markDelivered($bill);
        $assignment = $bill['order']->captainAssignments()->sole();
        $image = UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        app(OrderBillPaymentService::class)->complete($bill['employee'], $bill['order'], $request, [
            'order_captain_id' => $assignment->id, 'currency_id' => $bill['currency']->id,
            'bill_number' => 'B-9B7', 'bill_date' => today()->toDateString(),
            'principal_amount' => '10.00', 'payment_reference' => '9B7-HANDOVER',
        ], [$image]);
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::BillPaymentPrincipalHandedOver->value)->count());
        $this->assertSame('corrected', app(OrderFinancialSemanticsCorrectionService::class)
            ->correct($normal['order'], true)['state']);
    }

    public function test_permanent_captain_transfer_keeps_old_debit_in_original_branch_and_new_order_in_new_branch(): void
    {
        $first = $this->context();
        $invoice = $this->issue($first);
        $this->markDelivered($first);
        app(InvoiceCollectionService::class)->record($first['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $first['currency']->id, now());
        $captain = $first['captains'][0];
        $branchB = Branch::factory()->create(['default_currency_id' => $first['currency']->id]);
        app(CaptainBranchService::class)->transfer($first['admin'], $captain, [
            'branch_id' => $branchB->id, 'starts_at' => now()->subMinute(), 'reason' => 'Operational transfer',
        ]);
        $second = $this->context(1, '25.00', 2, $first['currency'], $branchB, $captain);
        $secondInvoice = $this->issue($second);
        $this->markDelivered($second);
        app(InvoiceCollectionService::class)->record($second['employee'], $secondInvoice, InvoiceCollectionMethod::Cash, $secondInvoice->grand_total, $second['currency']->id, now());
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('-1.7500', $balances->captainBranchBalance($captain, $first['branch'], $first['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('-1.7500', $balances->captainBranchBalance($captain, $branchB, $first['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('-3.5000', $balances->captainBalances($captain, $first['currency'])[FinancialAccountKind::CaptainDeposit->value]);
        $this->assertSame(0, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareHeld->value)->count());
    }

    public function test_cash_shortfall_and_sham_reimbursements_stay_in_their_source_branches(): void
    {
        $cash = $this->context();
        $cash['order']->currentDeliveryPricing->allocations()->sole()->update([
            'captain_entitlement' => '25.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '20.0000',
        ]);
        $cashInvoice = $this->issue($cash);
        $this->markDelivered($cash);
        app(InvoiceCollectionService::class)->record($cash['employee'], $cashInvoice, InvoiceCollectionMethod::Cash,
            $cashInvoice->grand_total, $cash['currency']->id, now());

        $captain = $cash['captains'][0];
        $branchB = Branch::factory()->create(['default_currency_id' => $cash['currency']->id]);
        app(CaptainBranchService::class)->transfer($cash['admin'], $captain, [
            'branch_id' => $branchB->id, 'starts_at' => now()->subMinute(), 'reason' => 'Cross-Branch receivable example',
        ]);
        $sham = $this->context(1, '50.00', 2, $cash['currency'], $branchB, $captain);
        $sham['order']->currentDeliveryPricing->allocations()->sole()->update([
            'captain_entitlement' => '30.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '25.0000',
        ]);
        $shamInvoice = $this->issue($sham);
        $this->markDelivered($sham);
        app(InvoiceCollectionService::class)->record($sham['employee'], $shamInvoice, InvoiceCollectionMethod::ShamCash,
            $shamInvoice->grand_total, $sham['currency']->id, now());

        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('20.0000', $balances->captainBranchBalance($captain, $cash['branch'], $cash['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('80.0000', $balances->captainBranchBalance($captain, $branchB, $cash['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('100.0000', $balances->captainBalances($captain, $cash['currency'])[FinancialAccountKind::CaptainCompanyReceivable->value]);
        $this->assertSame('0.0000', $balances->captainBranchBalance($captain, $cash['branch'], $cash['currency'], FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $balances->captainBranchBalance($captain, $branchB, $cash['currency'], FinancialAccountKind::CaptainCashCustody));
    }

    public function test_consumed_obsolete_custody_is_a_zero_write_manual_conflict(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $held = $this->postObsoleteHeld($c, $collection);
        app(FinancialPostingService::class)->postEvent(
            $c['currency'], 'HISTORICAL_HANDOVER', 'unattributed', 'CASH_HANDOVER', 1, now(),
            [['account' => $held->entries()->sole()->account, 'direction' => FinancialEntryDirection::Decrease, 'amount' => '1.00']],
            $c['branch'],
        );
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertStringContainsString('manual review', Artisan::output());
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
    }

    public function test_late_deposit_failure_rolls_back_obsolete_custody_reversal(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postObsoleteHeld($c, $collection);
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_test_9b7_deposit() RETURNS trigger AS $$
BEGIN
    IF NEW.event_type = 'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT' THEN
        RAISE EXCEPTION 'Injected late Deposit failure' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER reject_test_9b7_deposit BEFORE INSERT ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION reject_test_9b7_deposit();
SQL);
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
    }

    public function test_partial_9b6_correction_with_final_deposit_already_present_is_atomic_conflict(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postObsoleteHeld($c, $collection);
        $assessment = app(CorrectedOrderFinancialService::class)->assess($c['order'], true);
        $accountService = app(FinancialAccountService::class);
        $deposit = $accountService->getOrCreate($accountService->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainDeposit);
        app(FinancialPostingService::class)->postEvent(
            $c['currency'], CorrectedOrderFinancialService::SOURCE, $collection->id,
            FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value, 1,
            CarbonImmutable::parse($assessment['occurred_at']),
            [['account' => $deposit, 'direction' => FinancialEntryDirection::Decrease, 'amount' => '1.7500']],
            $c['branch'], $collection->recordedBy, null, $assessment['notes'],
        );
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
    }

    public function test_uncollected_legacy_invoice_events_are_reversed_without_replacement(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $this->postLegacyEvents($c, $invoice, null);
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(6, FinancialTransaction::count());
        $this->assertSame(3, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
        $this->assertSame(0, FinancialTransaction::whereIn('event_type', collect(FinancialEventType::correctedOrderDerived())->map->value)->count());
        $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(8, FinancialTransaction::count());
    }

    public function test_collection_before_delivery_reverses_old_full_custody_and_waits(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postLegacyCustody($c, $collection);
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(2, FinancialTransaction::count());
        $this->markDelivered($c);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame(3, FinancialTransaction::count());
        $this->assertSame('-1.7500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
    }

    public function test_invoice_snapshot_is_immutable_and_financial_drift_is_rejected(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->assertDatabaseHas('order_financial_source_snapshots', ['invoice_id' => $invoice->id, 'items_subtotal' => '25.0000']);
        $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total);
        $this->legacyInvoiceDrift($invoice, ['items_subtotal' => '26.0000', 'grand_total' => '31.0000']);
        $this->markDelivered($c);
        $this->assertNotSame('complete', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_collected_invoice_remains_guarded_against_replacement(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->replace($c['admin'], $c['order'], $invoice, 'Correction');
    }

    public function test_sham_cash_legacy_events_reverse_to_product_and_full_entitlement_due(): void
    {
        $c = $this->context();
        $allocation = $c['order']->currentDeliveryPricing->allocations()->sole();
        $allocation->update([
            'captain_entitlement' => '7.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '2.0000',
        ]);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total);
        $this->postExpectedProductEvent($c, $invoice);
        $receivable = app(FinancialAccountService::class)->getOrCreate(
            app(FinancialAccountService::class)->ownerForCaptain($c['captains'][0]),
            $c['currency'], FinancialAccountKind::CaptainCompanyReceivable,
        );
        $deliveredAt = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->latest('changed_at')->value('changed_at');
        $occurred = $invoice->issued_at->greaterThan($deliveredAt) ? $invoice->issued_at : $deliveredAt;
        app(FinancialPostingService::class)->postEvent(
            $c['currency'], 'CUSTOMER_INVOICE', $invoice->id, FinancialEventType::OrderDeliveryEntitlement->value,
            $invoice->version, $occurred,
            [['account' => $receivable, 'direction' => FinancialEntryDirection::Increase, 'amount' => '7.0000']],
            $c['branch'], $invoice->issuer,
        );
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(2, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashProductReimbursementDue->value)->count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashDeliveryEntitlementDue->value)->count());
        $this->assertSame('32.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
    }

    public function test_primary_promotion_reprices_changed_participants_and_deposit_follows_collector(): void
    {
        $c = $this->context(2);
        $version = $c['order']->currentDeliveryPricing()->sole()->version;
        app(OrderCaptainService::class)->switchPrimary($c['employee'], $c['order'], $c['captains'][1], 'Promote support');
        $this->assertSame($version + 1, $c['order']->currentDeliveryPricing()->sole()->version);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame($c['captains'][1]->id, $collection->captain_id);
        $this->assertSame('-1.7500', $this->balance($c['captains'][1]->id, FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
    }

    public function test_conflicting_legacy_payload_causes_no_partial_correction_writes(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postLegacyEvents($c, $invoice, $collection);
        $this->legacyInvoiceDrift($invoice, ['items_subtotal' => '26.0000', 'grand_total' => '31.0000']);
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics'));
        $this->assertStringContainsString('CONFLICT', Artisan::output());
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
    }

    public function test_collection_method_drift_cannot_post_the_opposite_event_matrix(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = app(InvoiceCollectionService::class)->record(
            $c['employee'], $invoice, InvoiceCollectionMethod::Cash,
            $invoice->grand_total, $c['currency']->id, now(),
        );
        $this->withHistoricalTriggerDisabled('invoice_collections', 'invoice_collection_immutable', fn () => DB::table('invoice_collections')->where('id', $collection->id)->update([
            'collection_method' => InvoiceCollectionMethod::ShamCash->value,
            'captain_id' => null,
            'order_captain_id' => null,
        ]));
        $this->assertSame('conflict', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        $this->assertSame(1, FinancialTransaction::count());
    }

    public function test_corrected_event_preserves_authoritative_microseconds_and_latest_timestamp(): void
    {
        $c = $this->context();
        $invoice = $this->ready($c);
        $invoice->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
        $this->legacyInvoiceDrift($invoice, ['issued_at' => '2026-09-27 12:34:56.123456+00']);
        $invoice = $invoice->fresh();
        app(OrderFinancialSourceSnapshotService::class)->capture($invoice);
        $this->markDelivered($c);
        $history = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->sole();
        DB::table('order_status_history')->where('id', $history->id)->update(['changed_at' => '2026-09-27 12:34:56.234567+00']);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->withHistoricalTriggerDisabled('invoice_collections', 'invoice_collection_immutable', fn () => DB::table('invoice_collections')->where('id', $collection->id)->update(['collected_at' => '2026-09-27 12:34:56.345678+00']));

        $rawCollected = DB::selectOne("SELECT to_char(collected_at, 'YYYY-MM-DD HH24:MI:SS.US') AS collected_at FROM invoice_collections WHERE id = ?", [$collection->id])->collected_at;
        $this->assertStringContainsString('.345678', $rawCollected);

        $assessment = app(CorrectedOrderFinancialService::class)->assess($c['order']);
        $this->assertStringContainsString('.345678', $assessment['occurred_at'] ?? json_encode($assessment));
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $event = FinancialTransaction::sole();
        $stored = DB::selectOne("SELECT to_char(occurred_at, 'YYYY-MM-DD HH24:MI:SS.US') AS occurred_at FROM financial_transactions WHERE id = ?", [$event->id])->occurred_at;
        $this->assertStringContainsString('.345678', $stored);
        $this->assertStringContainsString('12:34:56.123456', $event->notes);
        $this->assertStringContainsString('12:34:56.234567', $event->notes);
        $this->assertStringContainsString('12:34:56.345678', $event->notes);
    }

    public function test_mixed_historical_dry_runs_leave_all_source_and_ledger_rows_unchanged(): void
    {
        $waiting = $this->context();
        $this->issue($waiting);
        $this->markDelivered($waiting);

        $eligible = $this->context();
        $eligibleInvoice = $this->issue($eligible);
        $this->markDelivered($eligible);
        $this->historicalCollection($eligible, $eligibleInvoice, InvoiceCollectionMethod::Cash, $eligibleInvoice->grand_total);

        $legacy = $this->context();
        $legacyInvoice = $this->issue($legacy);
        $this->markDelivered($legacy);
        $this->postLegacyEvents($legacy, $legacyInvoice, null);

        $conflict = $this->context();
        $conflictInvoice = $this->issue($conflict);
        $this->legacyInvoiceDrift($conflictInvoice, ['items_subtotal' => '26.0000', 'grand_total' => '31.0000']);

        $tables = ['orders', 'invoices', 'invoice_collections', 'order_financial_source_snapshots',
            'order_financial_source_snapshot_lines', 'financial_owners', 'financial_accounts',
            'financial_transactions', 'financial_entries'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        Artisan::call('financial:backfill-order-events');
        $backfillOutput = Artisan::output();
        $this->assertStringContainsString('WAITING_COLLECTION', $backfillOutput);
        $this->assertStringContainsString('WOULD POST', $backfillOutput);
        $this->assertStringContainsString('LEGACY_CORRECTION_REQUIRED', $backfillOutput);
        Artisan::call('financial:correct-order-financial-semantics');
        $this->assertStringContainsString('LEGACY_9B_CORRECTION_REQUIRED', Artisan::output());
        $this->assertSame($before, collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all());
    }

    public function test_malformed_legacy_cash_collection_rejects_correction_without_writes(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->withHistoricalTriggerDisabled('invoice_collections', 'invoice_collection_history_guard',
            fn () => $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, '1.0000'));
        $this->postLegacyEvents($c, $invoice, $collection);
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();

        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics'));
        $this->assertStringContainsString('CONFLICT', Artisan::output());
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
    }

    public function test_partial_corrected_event_set_is_completed_but_wrong_version_conflicts(): void
    {
        $c = $this->context(2);
        $allocations = $c['order']->currentDeliveryPricing->allocations()->orderBy('order_captain_id')->get();
        $allocations[1]->update(['captain_entitlement' => '7.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '2.0000']);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $primary = $c['order']->captainAssignments()->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->sole();
        $collection = $invoice->collection()->create([
            'order_id' => $c['order']->id, 'captain_id' => $primary->captain_id,
            'order_captain_id' => $primary->id, 'collection_method' => InvoiceCollectionMethod::Cash,
            'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total,
            'collected_at' => now(), 'recorded_by' => $c['employee']->id,
        ]);
        $assessment = app(CorrectedOrderFinancialService::class)->assess($c['order']);
        $this->assertSame('eligible', $assessment['state']);
        $accounts = app(FinancialAccountService::class);
        $account = $accounts->getOrCreate($accounts->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainDeposit);
        app(FinancialPostingService::class)->postEvent($c['currency'], 'INVOICE_COLLECTION', $collection->id,
            FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value, 1, CarbonImmutable::parse($assessment['occurred_at']),
            [['account' => $account, 'direction' => FinancialEntryDirection::Decrease, 'amount' => $allocations[0]->company_collectible]],
            $c['branch'], $collection->recordedBy, null, $assessment['notes']);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame(2, FinancialTransaction::count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderCashDeliveryShortfallDue->value)->count());
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame(2, FinancialTransaction::count());

        $wrong = $this->context();
        $wrongInvoice = $this->issue($wrong);
        $this->markDelivered($wrong);
        $wrongCollection = $this->historicalCollection($wrong, $wrongInvoice, InvoiceCollectionMethod::Cash, $wrongInvoice->grand_total);
        $wrongAssessment = app(CorrectedOrderFinancialService::class)->assess($wrong['order']);
        $wrongAccount = $accounts->getOrCreate($accounts->ownerForCaptain($wrong['captains'][0]), $wrong['currency'], FinancialAccountKind::CaptainDeposit);
        app(FinancialPostingService::class)->postEvent($wrong['currency'], 'INVOICE_COLLECTION', $wrongCollection->id,
            FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value, 2, CarbonImmutable::parse($wrongAssessment['occurred_at']),
            [['account' => $wrongAccount, 'direction' => FinancialEntryDirection::Decrease, 'amount' => '1.7500']],
            $wrong['branch'], $wrongCollection->recordedBy, null, $wrongAssessment['notes']);
        $this->assertSame('conflict', app(CorrectedOrderFinancialService::class)->assess($wrong['order'])['state']);
        $this->assertSame(0, FinancialTransaction::where('source_id', (string) $wrongCollection->id)->where('source_version', 1)->count());
    }

    public function test_late_database_rejection_rolls_back_all_legacy_reversals(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postLegacyEvents($c, $invoice, $collection);
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_test_corrected_event() RETURNS trigger AS $$
BEGIN
    IF NEW.event_type = 'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT' THEN
        RAISE EXCEPTION 'Injected late ledger rejection' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER reject_test_corrected_event BEFORE INSERT ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION reject_test_corrected_event();
SQL);
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
    }

    private function postLegacyEvents(array $c, $invoice, $collection): void
    {
        $this->postExpectedProductEvent($c, $invoice);
        $accountService = app(FinancialAccountService::class);
        $receivable = $accountService->getOrCreate($accountService->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        $deposit = $accountService->getOrCreate($accountService->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainDeposit);
        $allocation = $c['order']->currentDeliveryPricing->allocations()->sole();
        $deliveredAt = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->latest('changed_at')->value('changed_at');
        $occurred = $invoice->issued_at->greaterThan($deliveredAt) ? $invoice->issued_at : $deliveredAt;
        app(FinancialPostingService::class)->postEvent($c['currency'], 'CUSTOMER_INVOICE', $invoice->id, FinancialEventType::OrderDeliveryEntitlement->value, $invoice->version, $occurred, [['account' => $receivable, 'direction' => FinancialEntryDirection::Increase, 'amount' => $allocation->captain_entitlement]], $c['branch'], $invoice->issuer);
        app(FinancialPostingService::class)->postEvent($c['currency'], 'CUSTOMER_INVOICE', $invoice->id, FinancialEventType::OrderCompanyDeliveryShare->value, $invoice->version, $occurred, [['account' => $deposit, 'direction' => FinancialEntryDirection::Decrease, 'amount' => $allocation->company_collectible]], $c['branch'], $invoice->issuer);
        if ($collection) {
            $this->postLegacyCustody($c, $collection);
        }
    }

    private function postObsoleteHeld(array $c, $collection): FinancialTransaction
    {
        $assessment = app(CorrectedOrderFinancialService::class)->assess($c['order']);
        $allocation = $c['order']->currentDeliveryPricing->allocations()->sole();
        $accounts = app(FinancialAccountService::class);
        $custody = $accounts->getOrCreate($accounts->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainCashCustody);

        return app(FinancialPostingService::class)->postEvent(
            $c['currency'], CorrectedOrderFinancialService::SOURCE, $collection->id,
            FinancialEventType::OrderCashCompanyDeliveryShareHeld->value, 1,
            CarbonImmutable::parse($assessment['occurred_at']),
            [['account' => $custody, 'direction' => FinancialEntryDirection::Increase, 'amount' => $allocation->company_collectible]],
            $c['branch'], $collection->recordedBy, null, $assessment['notes'],
        );
    }

    private function postLegacyCustody(array $c, $collection): void
    {
        $accountService = app(FinancialAccountService::class);
        $custody = $accountService->getOrCreate($accountService->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainCashCustody);
        app(FinancialPostingService::class)->postEvent($c['currency'], 'INVOICE_COLLECTION', $collection->id, FinancialEventType::InvoiceCashCustodyReceived->value, 1, $collection->collected_at, [['account' => $custody, 'direction' => FinancialEntryDirection::Increase, 'amount' => $collection->amount]], $c['branch'], $collection->recordedBy);
    }

    private function context(int $captainCount = 1, string $itemsSubtotal = '25.00', int $decimalPlaces = 2,
        ?Currency $existingCurrency = null, ?Branch $existingBranch = null, ?Captain $existingCaptain = null): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = $existingCurrency ?? Currency::create(['name' => 'Dollar', 'code' => strtoupper(str()->random(3)), 'decimal_places' => $decimalPlaces]);
        $branch = $existingBranch ?? Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create(['name' => 'Origin']);
        $to = Area::factory()->for($branch)->create(['name' => 'Destination']);
        $customer = Customer::create(['name' => 'Financial Customer']);
        $phone = $customer->phones()->create(['phone' => str()->random(10), 'normalized_phone' => str()->random(10)]);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Street']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Shop', 'origin_area_id' => $from->id, 'sort_order' => 1]);
        $order->update(['pricing_class' => OrderPricingClass::SingleShop]);
        OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime, 'product_name_snapshot' => 'Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1', 'current_quantity' => '1', 'actual_unit_price' => $itemsSubtotal, 'actual_price_source' => ActualPriceSource::ManualVerified, 'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $employee->id]);
        app(OrderPricingService::class)->recalculate($order);
        $captains = [];
        for ($index = 0; $index < $captainCount; $index++) {
            if ($index === 0 && $existingCaptain) {
                $captain = $existingCaptain->fresh();
            } else {
                $user = User::factory()->create();
                $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
                $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
            }
            app(OrderCaptainService::class)->assign($employee, $order, $captain, $index === 0 ? OrderCaptainRole::PrimaryCollection : OrderCaptainRole::Support);
            $captains[] = $captain;
        }
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id, 'currency_id' => $currency->id, 'order_pricing_class' => OrderPricingClass::SingleShop->value, 'delivery_unit_fee' => '5.00', 'effective_from' => now()->subDay(), 'effective_until' => null]);
        app(DeliveryPricingSettings::class)->update($admin, $decimalPlaces === 0 ? '100' : '65', '0');
        app(OrderDeliveryPricingService::class)->price($employee, $order, $from);

        return compact('currency', 'branch', 'from', 'to', 'admin', 'employee', 'order', 'shop', 'captains');
    }

    private function ready(array $c)
    {
        return app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
    }

    private function issue(array $c)
    {
        return app(InvoiceService::class)->issue($c['employee'], $c['order'], $this->ready($c));
    }

    private function markDelivered(array $c): void
    {
        $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
        $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
    }

    private function prepareDeliveryTransition(array $c): void
    {
        $c['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();
        $c['shop']->update(['picked_up_at' => now()]);
        $c['shop']->receipts()->create(['storage_path' => 'test/'.str()->uuid().'.png', 'original_filename' => 'test.png', 'mime_type' => 'image/png', 'file_size' => 1, 'uploaded_by' => $c['employee']->id, 'uploaded_at' => now()]);
    }

    private function balance(int $captainId, FinancialAccountKind $kind): string
    {
        $account = FinancialAccount::query()->whereHas('owner', fn ($query) => $query->where('captain_id', $captainId))->where('account_kind', $kind->value)->first();

        return $account ? app(FinancialBalanceQueryService::class)->balance($account) : '0.0000';
    }

    private function postExpectedProductEvent(array $c, $invoice, ?int $sourceVersion = null): void
    {
        $captain = $c['captains'][0];
        $accountService = app(FinancialAccountService::class);
        $account = $accountService->getOrCreate($accountService->ownerForCaptain($captain), $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        $deliveredAt = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->latest('changed_at')->value('changed_at');
        app(FinancialPostingService::class)->postEvent(
            $c['currency'], OrderFinancialPostingService::INVOICE_SOURCE, $invoice->id,
            FinancialEventType::OrderProductReimbursement->value, $sourceVersion ?? $invoice->version,
            $invoice->issued_at->greaterThan($deliveredAt) ? $invoice->issued_at : $deliveredAt,
            [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $invoice->items_subtotal]],
            $c['branch'], $invoice->issuer,
        );
    }

    private function historicalCollection(array $c, $invoice, InvoiceCollectionMethod $method, string $amount)
    {
        $assignment = $c['order']->captainAssignments()->sole();

        // Validate legacy payloads against persisted sources, including database timestamp precision.
        return $invoice->collection()->create([
            'order_id' => $c['order']->id,
            'captain_id' => $method === InvoiceCollectionMethod::Cash ? $assignment->captain_id : null,
            'order_captain_id' => $method === InvoiceCollectionMethod::Cash ? $assignment->id : null,
            'collection_method' => $method,
            'currency_id' => $c['currency']->id,
            'amount' => $amount,
            'collected_at' => now(),
            'recorded_by' => $c['employee']->id,
        ])->fresh();
    }

    private function legacyInvoiceDrift(Invoice $invoice, array $changes): void
    {
        // These regressions intentionally construct pre-hardening history; current SQL rejects the same writes.
        $this->withHistoricalTriggerDisabled('invoices', 'issued_invoice_facts_guard',
            fn () => DB::table('invoices')->where('id', $invoice->id)->update($changes));
    }

    private function withHistoricalTriggerDisabled(string $table, string $trigger, callable $action): mixed
    {
        // These fixtures represent rows from before the new immutable-history trigger.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
        try {
            return $action();
        } finally {
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
    }
}
