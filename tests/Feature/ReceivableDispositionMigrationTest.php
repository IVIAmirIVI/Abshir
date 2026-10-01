<?php

namespace Tests\Feature;

use App\Services\InvoiceFinancialCorrectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceivableDispositionFixtures;
use Tests\TestCase;

class ReceivableDispositionMigrationTest extends TestCase
{
    use ReceivableDispositionFixtures;

    public function test_staged_deployment_blocks_paid_correction_but_preserves_ordinary_payouts(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD');
            $entry = $this->productEntry($f);
            $this->payout($f, $entry, '40');
            $c = $this->correction($f, '70');
            foreach (['2026_09_29_000370_create_receivable_correction_dispositions.php', '2026_09_29_000380_enforce_receivable_disposition_contracts.php'] as $file) {
                DB::transaction(fn () => (require database_path('migrations/'.$file))->up());
                $this->assertFalse(DB::scalar('SELECT receivable_dispositions_active()'));
                try {
                    app(InvoiceFinancialCorrectionService::class)->prepare($f['admin'], $c);
                    $this->fail('Partial deployment enabled paid correction.');
                } catch (ValidationException $e) {
                    $this->assertStringContainsString('PAID_RECEIVABLE_CORRECTION_REQUIRED', $e->errors()['correction'][0]);
                }
                $this->payout($f, $entry, '1');
            }
            DB::transaction(fn () => (require database_path('migrations/2026_09_29_000390_activate_receivable_dispositions.php'))->up());
            $c = $this->prepareAndRefund($f, $c);
            $this->assertSame('42.0000', $c->receivableDispositions()->sole()->historical_paid);
            $this->assertSame('COMPLETED', app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c)->state->value);
        });
    }

    public function test_historical_completed_v1_correction_with_later_payout_is_preserved(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD');
            $c = $this->prepareAndRefund($f, $this->correction($f, '70'));
            app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
            $entry = DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
                ->where('t.event_type', 'INVOICE_CORRECTION_SHAM_PRODUCT_DUE')->select('e.*')->sole();
            $this->payout($f, $entry, '5');
            $history = DB::table('captain_receivable_payout_allocations')->get()->all();
            foreach (glob(database_path('migrations/2026_09_29_0003[789]*.php')) as $file) {
                DB::transaction(fn () => (require $file)->up());
            }
            $this->assertEquals($history, DB::table('captain_receivable_payout_allocations')->get()->all());
            $this->assertSame(1, $c->fresh()->receivable_contract_version);
            $this->assertFalse($c->fresh()->receivable_dispositions_captured);
            DB::select('SELECT validate_complete_correction_financial_set(?)', [$c->id]);
            $this->assertDatabaseCount('invoice_correction_receivable_dispositions', 0);
        });
    }

    public function test_activation_aborts_invalid_paid_history_without_repair(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD');
            $entry = $this->productEntry($f);
            $this->payout($f, $entry, '40');
            DB::statement('ALTER TABLE captain_receivable_payout_allocations DISABLE TRIGGER captain_payout_allocation_immutable');
            DB::table('captain_receivable_payout_allocations')->update(['amount' => '101']);
            DB::statement('ALTER TABLE captain_receivable_payout_allocations ENABLE TRIGGER captain_payout_allocation_immutable');
            foreach (['2026_09_29_000370_create_receivable_correction_dispositions.php', '2026_09_29_000380_enforce_receivable_disposition_contracts.php'] as $file) {
                DB::transaction(fn () => (require database_path('migrations/'.$file))->up());
            }
            try {
                DB::transaction(fn () => (require database_path('migrations/2026_09_29_000390_activate_receivable_dispositions.php'))->up());
                $this->fail('Malformed history activated.');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('source_entry_id='.$entry->id, $e->getMessage());
            }
            $this->assertFalse(DB::scalar('SELECT receivable_dispositions_active()'));
            $this->assertSame('101', DB::table('captain_receivable_payout_allocations')->value('amount'));
        });
    }

    private function isolated(callable $callback): void
    {
        $connection = config('database.default');
        $key = 'database.connections.'.$connection.'.search_path';
        $original = config($key);
        $schema = 'receivable_upgrade_'.Str::lower(Str::random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            foreach (glob(database_path('migrations/*.php')) as $file) {
                if (substr(basename($file), 0, 17) <= '2026_09_29_000366') {
                    DB::transaction(fn () => (require $file)->up());
                }
            }
            $callback();
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
