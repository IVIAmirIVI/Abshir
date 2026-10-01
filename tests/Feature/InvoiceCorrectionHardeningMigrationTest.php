<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
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
use App\Models\CaptainReceivablePayout;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\InvoiceFinancialCorrection;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CustomerRefundService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceCorrectionHardeningMigrationTest extends TestCase
{
    private const STATUS = '2026_09_29_000341_harden_collected_invoice_status.php';

    private const GATE = '2026_09_29_000342_gate_corrected_receivable_payouts.php';

    private const ACTIVATE = '2026_09_29_000365_activate_corrected_receivable_payouts.php';

    private const REPLACEMENT = '2026_09_29_000366_ensure_collected_invoice_replacement_guard.php';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function test_phase_9c3a_committed_refund_survives_rejected_invoice_void_and_finalizes(): void
    {
        $this->withSchema('2026_09_29_000365', [], function (): void {
            $f = $this->fixture('USD');
            $service = app(InvoiceFinancialCorrectionService::class);
            $correction = $service->request($f['employee'], $f['invoice'], InvoiceFinancialCorrectionKind::Reprice,
                $this->proposal($f), 'Committed refund regression', 'Reject void without stranding refund', (string) Str::uuid());
            $service->prepare($f['admin'], $correction);
            $refund = app(CustomerRefundService::class)->record($f['admin'], $correction, (string) Str::uuid(), now(),
                'COMMITTED-REFUND', $this->proof());
            $this->assertSame(0, DB::transactionLevel(), 'The production refund transaction must actually commit before the SQL attack.');
            $this->assertDatabaseHas('customer_refunds', ['id' => $refund->id, 'amount' => '30.0000']);
            try {
                DB::transaction(fn () => DB::table('invoices')->where('id', $f['invoice']->id)->update([
                    'status' => 'VOIDED', 'voided_at' => now(), 'voided_by' => $f['admin']->id,
                    'void_reason' => 'Attempt after committed refund', 'superseded_at' => now(),
                ]));
                $this->fail('Direct SQL stranded a committed customer refund.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('cannot transition to VOIDED', $exception->getMessage());
            }
            $this->assertSame('ISSUED', $f['invoice']->fresh()->status->value);
            $this->assertDatabaseHas('customer_refunds', ['id' => $refund->id, 'amount' => '30.0000']);
            $completed = $service->finalize($f['admin'], $correction);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('COMPLETED', $completed->state->value);
            $this->assertSame('REPLACED', $f['invoice']->fresh()->status->value);
            $this->assertDatabaseHas('invoices', ['id' => $completed->replacement_invoice_id, 'status' => 'ISSUED', 'grand_total' => '75.0000']);
        });
    }

    public function test_phase_9c3a_staged_gate_survives_345_350_and_360_then_activates_valid_sources(): void
    {
        $this->withSchema('2026_09_29_000342', [], function (): void {
            $normal = $this->fixture('USD');
            $normalEntry = $this->productEntry($normal);
            $normalPayout = $this->payout($normal, $normalEntry, '1.00');
            $this->assertDatabaseHas('captain_receivable_payout_allocations', ['captain_receivable_payout_id' => $normalPayout->id]);

            $f = $this->fixture('EUR');
            $correction = $this->completeCorrection($f);
            $entry = $this->productEntry($f, true);
            foreach ([null, '2026_09_29_000345_allow_corrected_receivable_payout_sources.php',
                '2026_09_29_000350_enforce_completed_invoice_correction.php',
                '2026_09_29_000360_enforce_complete_correction_financial_set.php'] as $stage) {
                if ($stage) {
                    $this->migration($stage)->up();
                }
                $this->assertFalse(DB::scalar('SELECT corrected_receivable_payouts_active()'));
                $this->assertCount(0, app(FinancialBalanceQueryService::class)->payableReceivableEntries($f['captain'], $f['branch'], $f['currency']));
                try {
                    $this->rawPayout($f, $entry, '1.00');
                    $this->fail('Corrected source became payable before activation: '.$stage);
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('gated until complete financial-contract activation', $exception->getMessage());
                }
                $this->assertDatabaseCount('captain_receivable_payouts', 1);
                if ($stage !== '2026_09_29_000360_enforce_complete_correction_financial_set.php') {
                    try {
                        $this->migration(self::ACTIVATE)->up();
                        $this->fail('Activation succeeded without the full 000350/000360 contracts.');
                    } catch (QueryException $exception) {
                        $this->assertStringContainsString('requires installed 000350/000360 completion contracts', $exception->getMessage());
                    }
                    $this->assertFalse(DB::scalar('SELECT corrected_receivable_payouts_active()'));
                }
            }
            $this->migration(self::ACTIVATE)->up();
            $this->assertTrue(DB::scalar('SELECT corrected_receivable_payouts_active()'));
            $payout = $this->payout($f, $entry, '1.00');
            $this->assertDatabaseHas('captain_receivable_payout_allocations', ['captain_receivable_payout_id' => $payout->id, 'financial_entry_id' => $entry->id]);
            $payable = app(FinancialBalanceQueryService::class)->payableReceivableEntries($f['captain'], $f['branch'], $f['currency']);
            $this->assertSame('69.0000', $payable->firstWhere('financial_entry_id', $entry->id)->remaining_amount);
            $this->assertSame('COMPLETED', $correction->fresh()->state->value);
        });
    }

    public function test_phase_9c3a_activation_rejects_invalid_weak_window_allocation_without_repair(): void
    {
        $this->withSchema('2026_09_29_000360', [self::STATUS, self::GATE], function (): void {
            $f = $this->fixture('USD');
            $correction = $this->completeCorrection($f);
            $entry = $this->productEntry($f, true);
            $payoutId = $this->rawPayout($f, $entry, '1.00');
            // Model malformed old data only in this throwaway schema, then restore the ordinary guard.
            DB::statement('ALTER TABLE captain_receivable_payout_allocations DISABLE TRIGGER captain_payout_allocation_immutable');
            DB::table('captain_receivable_payout_allocations')->where('captain_receivable_payout_id', $payoutId)->update(['amount' => '71.00']);
            DB::statement('ALTER TABLE captain_receivable_payout_allocations ENABLE TRIGGER captain_payout_allocation_immutable');
            $before = $this->history();
            $this->migration(self::STATUS)->up();
            $this->migration(self::GATE)->up();
            try {
                $this->migration(self::ACTIVATE)->up();
                $this->fail('Activation accepted an invalid weak-window payout allocation.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('payout_id='.$payoutId, $exception->getMessage());
                $this->assertStringContainsString('source_entry_id='.$entry->id, $exception->getMessage());
            }
            $this->assertFalse(DB::scalar('SELECT corrected_receivable_payouts_active()'));
            $this->assertEquals($before, $this->history());
            $this->assertSame('COMPLETED', $correction->fresh()->state->value);
        });
    }

    public function test_phase_9c3a_valid_non_empty_already_migrated_upgrade_preserves_real_money(): void
    {
        $this->withSchema('2026_09_29_000360', [self::STATUS, self::GATE], function (): void {
            $f = $this->fixture('USD');
            $this->completeCorrection($f);
            $entry = $this->productEntry($f, true);
            $payoutId = $this->rawPayout($f, $entry, '1.00');
            $before = $this->history();
            $this->migration(self::STATUS)->up();
            $this->migration(self::GATE)->up();
            $this->assertFalse(DB::scalar('SELECT corrected_receivable_payouts_active()'));
            $this->migration(self::ACTIVATE)->up();
            $this->assertTrue(DB::scalar('SELECT corrected_receivable_payouts_active()'));
            $this->assertEquals($before, $this->history());
            DB::select("SELECT validate_captain_financial_operation('PAYOUT',?)", [$payoutId]);
        });
    }

    public function test_phase_9c3a_status_upgrade_rejects_malformed_collected_history_without_repair(): void
    {
        $this->withSchema('2026_09_29_000340', [], function (): void {
            $f = $this->fixture('USD');
            DB::table('invoices')->where('id', $f['invoice']->id)->update([
                'status' => 'VOIDED', 'voided_at' => now(), 'voided_by' => $f['admin']->id,
                'void_reason' => 'Old weak schema exploit', 'superseded_at' => now(),
            ]);
            $before = $this->history();
            try {
                $this->migration(self::STATUS)->up();
                $this->fail('Migration accepted a VOIDED collected Invoice.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('invoice_id='.$f['invoice']->id, $exception->getMessage());
                $this->assertStringContainsString('collection_id='.$f['collection']->id, $exception->getMessage());
            }
            $this->assertEquals($before, $this->history());
        });
    }

    public function test_phase_9c3a_activation_serializes_validation_and_allocation_writes(): void
    {
        $this->withSchema('2026_09_29_000360', [self::STATUS, self::GATE], function (): void {
            $f = $this->fixture('USD');
            $this->completeCorrection($f);
            $entry = $this->productEntry($f, true);
            $payoutId = $this->rawPayout($f, $entry, '1.00');
            $this->migration(self::STATUS)->up();
            $this->migration(self::GATE)->up();
            $before = $this->history();
            $probeName = 'correction_activation_probe';
            config(['database.connections.'.$probeName => config('database.connections.'.config('database.default'))]);
            $probe = DB::connection($probeName);
            $probe->statement("SET lock_timeout='150ms'");
            $observed = false;
            $listening = true;
            DB::listen(function (QueryExecuted $query) use ($probe, $entry, $payoutId, &$observed, &$listening): void {
                if (! $listening || ! str_starts_with($query->sql, 'LOCK TABLE invoice_financial_corrections, invoice_correction_financial_snapshots')) {
                    return;
                }
                $listening = false;
                try {
                    $probe->table('captain_receivable_payout_allocations')->insert([
                        'captain_receivable_payout_id' => $payoutId, 'financial_entry_id' => $entry->id, 'amount' => '1.00',
                    ]);
                    $this->fail('An allocation write passed through the activation validation window.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('lock timeout', $exception->getMessage());
                    $observed = true;
                }
            });
            try {
                $this->migration(self::ACTIVATE)->up();
            } finally {
                $listening = false;
                DB::purge($probeName);
            }
            $this->assertTrue($observed, 'The independent connection must contend with actual activation table locks.');
            $this->assertTrue(DB::scalar('SELECT corrected_receivable_payouts_active()'));
            $this->assertEquals($before, $this->history());
        });
    }

    public function test_phase_9c3a_fresh_chain_and_prepared_corrected_source_fail_closed(): void
    {
        $this->withSchema('2026_09_29_000365', [], function (): void {
            $this->assertTrue(DB::scalar('SELECT corrected_receivable_payouts_active()'));
            $f = $this->fixture('USD');
            $service = app(InvoiceFinancialCorrectionService::class);
            $correction = $service->request($f['employee'], $f['invoice'], InvoiceFinancialCorrectionKind::Reprice,
                $this->proposal($f), 'Prepared source', 'Not payable before completion', (string) Str::uuid());
            $service->prepare($f['admin'], $correction);
            $original = $this->productEntry($f);
            foreach (['PREPARED', 'READY_TO_FINALIZE'] as $state) {
                if ($state === 'READY_TO_FINALIZE') {
                    app(CustomerRefundService::class)->record($f['admin'], $correction, (string) Str::uuid(), now(),
                        'READY-REFUND', $this->proof());
                }
                try {
                    DB::transaction(function () use ($f, $correction, $original): void {
                        $id = DB::table('financial_transactions')->insertGetId([
                            'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'source_type' => 'INVOICE_FINANCIAL_CORRECTION',
                            'source_id' => (string) $correction->id, 'source_version' => 1,
                            'event_type' => 'INVOICE_CORRECTION_SHAM_PRODUCT_DUE', 'branch_id' => $f['branch']->id,
                            'currency_id' => $f['currency']->id, 'occurred_at' => now(), 'payload_hash' => str_repeat('a', 64),
                        ]);
                        $entryId = DB::table('financial_entries')->insertGetId([
                            'financial_transaction_id' => $id, 'financial_account_id' => $original->financial_account_id,
                            'currency_id' => $f['currency']->id, 'direction' => 'INCREASE', 'amount' => '70.00',
                        ]);
                        DB::select('SELECT validate_corrected_receivable_payout_source(?,NULL)', [$entryId]);
                    });
                    $this->fail('A '.$state.' correction source was payout-eligible.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('requires a COMPLETED correction', $exception->getMessage());
                }
            }
            $this->assertDatabaseCount('captain_receivable_payouts', 0);
        });
    }

    public function test_phase_9c3a_missing_replacement_trigger_upgrade_runs_only_the_pending_migration(): void
    {
        $this->withSchema('2026_09_29_000365', [], function (): void {
            $f = $this->fixture('USD');
            DB::statement('DROP TRIGGER replaced_invoice_complete ON invoices');
            DB::statement('CREATE TABLE migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL)');
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (substr(basename($path), 0, 17) <= '2026_09_29_000365') {
                    DB::table('migrations')->insert(['migration' => basename($path, '.php'), 'batch' => 1]);
                }
            }
            $before = $this->history();
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->assertDatabaseHas('migrations', ['migration' => basename(self::REPLACEMENT, '.php'), 'batch' => 2]);
            $this->assertEquals($before, $this->history());
            $this->assertReplacementCatalog();
            $this->rejectArbitraryReplacement($f);
            $this->assertSame('COMPLETED', $this->completeCorrection($f)->state->value);
            DB::statement('ALTER TABLE invoices DISABLE TRIGGER replaced_invoice_complete');
            $this->migration(self::REPLACEMENT)->up();
            $this->assertReplacementCatalog();
        });
    }

    public function test_phase_9c3a_replacement_guard_upgrade_rejects_malformed_history_without_repair(): void
    {
        $this->withSchema('2026_09_29_000365', [], function (): void {
            $f = $this->fixture('USD');
            DB::statement('DROP TRIGGER replaced_invoice_complete ON invoices');
            DB::table('invoices')->where('id', $f['invoice']->id)->update([
                'status' => 'REPLACED', 'superseded_at' => now(), 'replaced_at' => now(),
                'replaced_by' => $f['admin']->id, 'replacement_reason' => 'Old schema bypass',
            ]);
            $before = $this->history();
            try {
                $this->migration(self::REPLACEMENT)->up();
                $this->fail('Malformed historical replacement was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('invoice_id='.$f['invoice']->id, $exception->getMessage());
                $this->assertStringContainsString('correction_ids=none', $exception->getMessage());
            }
            $this->assertEquals($before, $this->history());
            $this->assertFalse(DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='invoices'::regclass AND tgname='replaced_invoice_complete')"));
        });
    }

    public function test_phase_9c3a_fresh_replacement_guard_is_attached_and_accepts_only_real_correction(): void
    {
        $this->withSchema('2026_09_29_000366', [], function (): void {
            $f = $this->fixture('USD');
            $this->assertReplacementCatalog();
            $this->rejectArbitraryReplacement($f);
            $this->assertSame('COMPLETED', $this->completeCorrection($f)->state->value);
            $this->assertSame('REPLACED', $f['invoice']->fresh()->status->value);
        });
    }

    public function test_phase_9c3a_replacement_guard_installation_serializes_invoice_status_writes(): void
    {
        $this->withSchema('2026_09_29_000365', [], function (): void {
            $f = $this->fixture('USD');
            DB::statement('DROP TRIGGER replaced_invoice_complete ON invoices');
            $probeName = 'replacement_installation_probe';
            config(['database.connections.'.$probeName => config('database.connections.'.config('database.default'))]);
            $probe = DB::connection($probeName);
            $probe->statement("SET lock_timeout='150ms'");
            $before = $this->history();
            $observed = false;
            $listening = true;
            DB::listen(function (QueryExecuted $query) use ($probe, $f, &$observed, &$listening): void {
                if (! $listening || $query->sql !== 'LOCK TABLE invoices, invoice_collections, invoice_financial_corrections IN SHARE ROW EXCLUSIVE MODE') {
                    return;
                }
                $listening = false;
                try {
                    $probe->table('invoices')->where('id', $f['invoice']->id)->update([
                        'status' => 'REPLACED', 'superseded_at' => now(), 'replaced_at' => now(),
                        'replaced_by' => $f['admin']->id, 'replacement_reason' => 'Concurrent SQL attack',
                    ]);
                    $this->fail('Status write slipped into trigger installation.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString('lock timeout', $exception->getMessage());
                    $observed = true;
                }
            });
            try {
                $this->migration(self::REPLACEMENT)->up();
            } finally {
                $listening = false;
                DB::purge($probeName);
            }
            $this->assertTrue($observed);
            $this->assertEquals($before, $this->history());
            $this->assertReplacementCatalog();
            $this->rejectArbitraryReplacement($f);
        });
    }

    public function test_phase_9c3a_mixed_same_account_sources_allow_only_ordinary_payout_until_activation(): void
    {
        $this->withSchema('2026_09_29_000342', [], function (): void {
            $f = $this->fixture('USD', null, '0');
            $normal = $this->productEntry($f);
            $correctedOrder = $this->fixture('USD', $f, '0');
            $this->completeCorrection($correctedOrder);
            $corrected = $this->productEntry($correctedOrder, true);
            $this->assertSame($normal->financial_account_id, $corrected->financial_account_id);
            $balances = app(FinancialBalanceQueryService::class);
            foreach ([null, '2026_09_29_000345_allow_corrected_receivable_payout_sources.php',
                '2026_09_29_000350_enforce_completed_invoice_correction.php',
                '2026_09_29_000360_enforce_complete_correction_financial_set.php'] as $stage) {
                if ($stage) {
                    $this->migration($stage)->up();
                }
                $this->assertSame('170.0000', $balances->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
                $this->assertSame('100.0000', $balances->payableReceivableEntries($f['captain'], $f['branch'], $f['currency'])->sole()->remaining_amount);
                $this->assertSame('70.0000', $balances->gatedCorrectedReceivableEntries($f['captain'], $f['branch'], $f['currency'])->sole()->remaining_amount);
                $before = $this->history();
                try {
                    $this->mixedPayout($f, $normal, $corrected);
                    $this->fail('Gated corrected amount was consumed in a 170 payout.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('amount', $exception->errors());
                }
                $this->assertEquals($before, $this->history());
                DB::beginTransaction();
                try {
                    $payout = $this->payout($f, $normal, '100.00');
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                    $this->assertDatabaseHas('captain_receivable_payout_allocations', ['captain_receivable_payout_id' => $payout->id, 'financial_entry_id' => $normal->id, 'amount' => '100.0000']);
                    $this->assertSame('70.0000', $balances->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
                } finally {
                    DB::rollBack();
                }
                $this->assertEquals($before, $this->history());
            }
            $this->migration(self::ACTIVATE)->up();
            $this->assertCount(0, $balances->gatedCorrectedReceivableEntries($f['captain'], $f['branch'], $f['currency']));
            $this->assertCount(2, $balances->payableReceivableEntries($f['captain'], $f['branch'], $f['currency']));
            $this->mixedPayout($f, $normal, $corrected);
            $this->assertDatabaseCount('captain_receivable_payout_allocations', 2);
            $this->assertSame('0.0000', $balances->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        });
    }

    public function test_phase_9c3a_unknown_receivable_does_not_explain_the_gated_balance_difference(): void
    {
        $this->withSchema('2026_09_29_000342', [], function (): void {
            $f = $this->fixture('USD', null, '0');
            $normal = $this->productEntry($f);
            app(FinancialPostingService::class)->postEvent($f['currency'], 'UNRECOGNIZED', (string) Str::uuid(), 'UNRECOGNIZED', 1, now(),
                [['account' => FinancialAccount::findOrFail($normal->financial_account_id), 'direction' => FinancialEntryDirection::Increase, 'amount' => '70']], $f['branch']);
            $before = $this->history();
            try {
                $this->payout($f, $normal, '100.00');
                $this->fail('Unknown Receivable was ignored in reconciliation.');
            } catch (FinancialIdempotencyConflictException $exception) {
                $this->assertStringContainsString('unresolved or unattributed', $exception->getMessage());
            }
            $this->assertEquals($before, $this->history());
            $this->assertDatabaseCount('captain_receivable_payouts', 0);
        });
    }

    private function assertReplacementCatalog(): void
    {
        $triggers = DB::select("SELECT tgdeferrable,tginitdeferred,tgconstraint<>0 AS is_constraint,tgenabled,tgfoid='validate_replaced_invoice_at_commit()'::regprocedure AS correct_function FROM pg_trigger WHERE tgrelid='invoices'::regclass AND tgname='replaced_invoice_complete'");
        $this->assertCount(1, $triggers);
        $this->assertTrue($triggers[0]->tgdeferrable && $triggers[0]->tginitdeferred && $triggers[0]->is_constraint && $triggers[0]->correct_function);
        $this->assertSame('O', $triggers[0]->tgenabled);
    }

    private function rejectArbitraryReplacement(array $f): void
    {
        try {
            DB::transaction(fn () => DB::table('invoices')->where('id', $f['invoice']->id)->update([
                'status' => 'REPLACED', 'superseded_at' => now(), 'replaced_at' => now(),
                'replaced_by' => $f['admin']->id, 'replacement_reason' => 'Direct SQL attack',
            ]));
            $this->fail('Arbitrary collected replacement committed.');
        } catch (\PDOException $exception) {
            $this->assertStringContainsString('requires completed financial correction', $exception->getMessage());
        }
        $this->assertSame('ISSUED', $f['invoice']->fresh()->status->value);
    }

    private function mixedPayout(array $f, object $normal, object $corrected): CaptainReceivablePayout
    {
        return app(CaptainReceivablePayoutService::class)->record($f['admin'], $f['captain'], $f['branch'], $f['currency'],
            ['request_key' => (string) Str::uuid(), 'amount' => '170.00', 'occurred_at' => now()->subSecond()->toIso8601String()],
            [['financial_entry_id' => $normal->id, 'amount' => '100.00'], ['financial_entry_id' => $corrected->id, 'amount' => '70.00']], $this->proof());
    }

    private function fixture(string $code, ?array $shared = null, string $captainPercent = '65'): array
    {
        $this->seed(AuthorizationSeeder::class);
        foreach (['customer_refunds', 'order_receipts', 'captain_financial_evidence'] as $disk) {
            Storage::fake($disk);
        }
        $currency = $shared['currency'] ?? Currency::create(['name' => $code, 'code' => $code, 'decimal_places' => 2]);
        $branch = $shared['branch'] ?? Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create();
        $to = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Migration fixture']);
        $phone = $customer->phones()->create(['phone' => '123', 'normalized_phone' => '123']);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Fixture street']);
        $admin = $shared['admin'] ?? User::factory()->create();
        $employee = $shared['employee'] ?? User::factory()->create();
        if (! $shared) {
            $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
            $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
            $employee->branches()->attach($branch);
        }
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL',
            'entry_mode' => 'HISTORICAL_MANUAL', 'ordered_at' => now()->subDay()]);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime,
            'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Fixture Shop', 'sort_order' => 1]);
        $order->update(['pricing_class' => OrderPricingClass::SingleShop]);
        OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime,
            'product_name_snapshot' => 'Fixture Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1',
            'current_quantity' => '1', 'actual_unit_price' => '100.00', 'actual_price_source' => ActualPriceSource::ManualVerified,
            'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $employee->id]);
        app(OrderPricingService::class)->recalculate($order);
        $captain = $shared['captain'] ?? Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $branch->id,
            'operational_status' => CaptainOperationalStatus::Available]);
        if (! $shared) {
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        }
        app(OrderCaptainService::class)->assign($employee, $order, $captain, OrderCaptainRole::PrimaryCollection);
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id,
            'currency_id' => $currency->id, 'order_pricing_class' => OrderPricingClass::SingleShop->value,
            'delivery_unit_fee' => '5.00', 'effective_from' => now()->subDays(2), 'effective_until' => null]);
        app(DeliveryPricingSettings::class)->update($admin, $captainPercent, '0');
        app(OrderDeliveryPricingService::class)->price($employee, $order, $from);
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($employee, $order, $invoices->refresh($employee, $order, $invoices->createDraft($employee, $order)));
        $collection = app(InvoiceCollectionService::class)->record($employee, $invoice, InvoiceCollectionMethod::ShamCash, '105', $currency->id, now());
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops,
            OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($employee, $order->fresh(), $status, null);
        }
        app(OrderShopProgressService::class)->record($employee, $order->fresh(), $shop, 'picked_up_at');
        app(OrderShopReceiptService::class)->upload($employee, $order->fresh(), $shop, $this->proof(), null);
        app(OrderStatusService::class)->transition($employee, $order->fresh(), OrderOperationalStatus::Delivered, null);

        return compact('currency', 'branch', 'employee', 'admin', 'captain', 'order', 'invoice', 'collection');
    }

    private function proposal(array $f): array
    {
        return ['items' => [['invoice_item_id' => $f['invoice']->items()->sole()->id, 'unit_price' => '70.00']],
            'delivery' => [['order_captain_id' => $f['order']->captainAssignments()->sole()->id, 'customer_amount' => '5.00']]];
    }

    private function completeCorrection(array $f): InvoiceFinancialCorrection
    {
        $service = app(InvoiceFinancialCorrectionService::class);
        $correction = $service->request($f['employee'], $f['invoice'], InvoiceFinancialCorrectionKind::Reprice,
            $this->proposal($f), 'Correct price', 'Migration fixture', (string) Str::uuid());
        $service->prepare($f['admin'], $correction);
        app(CustomerRefundService::class)->record($f['admin'], $correction, (string) Str::uuid(), now(), 'MIGRATION-REFUND', $this->proof());

        return $service->finalize($f['admin'], $correction);
    }

    private function productEntry(array $f, bool $corrected = false): object
    {
        return DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->where('t.branch_id', $f['branch']->id)->where('t.event_type', $corrected
                ? 'INVOICE_CORRECTION_SHAM_PRODUCT_DUE' : 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE')
            ->when($corrected, fn ($query) => $query->whereIn('t.source_id', DB::table('invoice_financial_corrections')->where('order_id', $f['order']->id)->selectRaw('id::text')),
                fn ($query) => $query->where('t.source_id', (string) $f['collection']->id))
            ->select('e.*')->sole();
    }

    private function payout(array $f, object $entry, string $amount): CaptainReceivablePayout
    {
        return app(CaptainReceivablePayoutService::class)->record($f['admin'], $f['captain'], $f['branch'], $f['currency'],
            ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'occurred_at' => now()->subSecond()->toIso8601String(), 'reference' => 'MIGRATION-PAYOUT'],
            [['financial_entry_id' => $entry->id, 'amount' => $amount]], $this->proof());
    }

    /** Direct source setup models the old 000345 schema; production posting creates its real payout event. */
    private function rawPayout(array $f, object $entry, string $amount): int
    {
        return DB::transaction(function () use ($f, $entry, $amount): int {
            $when = now()->subSecond();
            $id = DB::table('captain_receivable_payouts')->insertGetId([
                'captain_id' => $f['captain']->id, 'branch_id' => $f['branch']->id, 'currency_id' => $f['currency']->id,
                'paid_by' => $f['admin']->id, 'request_key' => (string) Str::uuid(), 'amount' => $amount, 'occurred_at' => $when->format('Y-m-d H:i:s.uP'),
                'reference' => 'WEAK-WINDOW-PAYOUT', 'evidence_path' => 'fixture.png', 'evidence_name' => 'fixture.png',
                'evidence_mime' => 'image/png', 'evidence_size' => 68, 'evidence_sha256' => hash('sha256', base64_decode(self::PNG)),
            ]);
            DB::table('captain_receivable_payout_allocations')->insert([
                'captain_receivable_payout_id' => $id, 'financial_entry_id' => $entry->id, 'amount' => $amount,
            ]);
            app(FinancialPostingService::class)->postEvent($f['currency'], 'CAPTAIN_RECEIVABLE_PAYOUT', $id,
                FinancialEventType::CaptainReceivablePaid->value, 1, $when,
                [['account' => FinancialAccount::findOrFail($entry->financial_account_id), 'direction' => FinancialEntryDirection::Decrease, 'amount' => $amount]],
                $f['branch'], $f['admin']);

            return $id;
        });
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG));
    }

    private function history(): array
    {
        $rows = [];
        foreach (['invoices', 'invoice_collections', 'invoice_financial_corrections', 'customer_refunds',
            'invoice_correction_financial_snapshots', 'financial_transactions', 'financial_entries',
            'captain_receivable_payouts', 'captain_receivable_payout_allocations'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return $rows;
    }

    private function migration(string $filename): object
    {
        return require database_path('migrations/'.$filename);
    }

    private function withSchema(string $through, array $skip, callable $callback): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Migration staging requires isolated PostgreSQL schemas.');
        }
        $connection = (string) config('database.default');
        $key = 'database.connections.'.$connection.'.search_path';
        $original = config($key);
        $schema = 'invoice_correction_upgrade_'.Str::lower(Str::random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (substr(basename($path), 0, 17) <= $through && ! in_array(basename($path), $skip, true)) {
                    // Match Laravel's PostgreSQL migrator transaction around each migration.
                    DB::transaction(fn () => (require $path)->up());
                }
            }
            $callback();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }
}
