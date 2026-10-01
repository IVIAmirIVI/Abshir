<?php

namespace Tests\Feature;

use App\Services\CaptainReceivableRecoveryEvidenceService;
use App\Services\CaptainReceivableRecoveryService;
use App\Services\InvoiceFinancialCorrectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceivableDispositionFixtures;
use Tests\TestCase;

class CaptainReceivableRecoveryMigrationTest extends TestCase
{
    use ReceivableDispositionFixtures;

    public function test_intermediate_deployment_is_closed_and_preserves_unrelated_payout_and_zero_k_completion(): void
    {
        $this->isolated(function () {
            $f = $this->fixture('USD');
            $this->payout($f, $this->productEntry($f), '100');
            $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
            foreach (['2026_09_29_000400_create_captain_receivable_recoveries.php', '2026_09_29_000410_enforce_captain_receivable_recovery_contracts.php'] as $file) {
                DB::transaction(fn () => (require database_path('migrations/'.$file))->up());
                $this->assertFalse(DB::scalar('SELECT captain_receivable_recoveries_active()'));
                foreach ([fn () => app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c), fn () => app(CaptainReceivableRecoveryService::class)->record($f['employee'], $c, $f['captain'], $this->data(), $this->proof())] as $action) {
                    try {
                        $action();
                        $this->fail('Intermediate recovery deployment opened.');
                    } catch (ValidationException $e) {
                        $this->assertNotEmpty($e->errors());
                    }
                }
                $other = $this->fixture('USD', $f);
                $this->payout($other, $this->productEntry($other), '40');
                $zero = $this->prepareAndRefund($other, $this->correction($other, '70'));
                $this->assertSame('COMPLETED', app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $zero)->state->value);
                $this->assertDatabaseCount('captain_receivable_recoveries', 0);
            }
            DB::transaction(fn () => (require database_path('migrations/2026_09_29_000420_activate_captain_receivable_recoveries.php'))->up());
            Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
            $this->assertTrue(DB::scalar('SELECT captain_receivable_recoveries_active()'));
            app(CaptainReceivableRecoveryService::class)->record($f['employee'], $c, $f['captain'], $this->data(), $this->proof());
            $this->assertSame('COMPLETED', app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c)->state->value);
        });
    }

    public function test_activation_aborts_bad_disposition_history_with_ids_and_zero_repairs(): void
    {
        $this->isolated(function () {
            $f = $this->fixture('USD');
            $this->payout($f, $this->productEntry($f), '100');
            $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
            foreach (['2026_09_29_000400_create_captain_receivable_recoveries.php', '2026_09_29_000410_enforce_captain_receivable_recovery_contracts.php'] as $file) {
                DB::transaction(fn () => (require database_path('migrations/'.$file))->up());
            }
            DB::statement('ALTER TABLE invoice_correction_receivable_dispositions DISABLE TRIGGER USER');
            DB::table('invoice_correction_receivable_dispositions')->where('invoice_financial_correction_id', $c->id)->update(['payload_hash' => str_repeat('0', 64)]);
            DB::statement('ALTER TABLE invoice_correction_receivable_dispositions ENABLE TRIGGER USER');
            $history = DB::table('invoice_correction_receivable_dispositions')->get()->all();
            try {
                DB::transaction(fn () => (require database_path('migrations/2026_09_29_000420_activate_captain_receivable_recoveries.php'))->up());
                $this->fail('Invalid frozen financial history activated.');
            } catch (\PDOException $e) {
                $this->assertStringContainsString((string) $c->id, $e->getMessage());
            }
            $this->assertFalse(DB::scalar('SELECT captain_receivable_recoveries_active()'));
            $this->assertEquals($history, DB::table('invoice_correction_receivable_dispositions')->get()->all());
        });
    }

    private function data(): array
    {
        return ['request_key' => (string) Str::uuid(), 'received_at' => now()->format('Y-m-d H:i:s.uP')];
    }

    private function isolated(callable $callback): void
    {
        $connection = config('database.default');
        $key = 'database.connections.'.$connection.'.search_path';
        $original = config($key);
        $schema = 'recovery_upgrade_'.Str::lower(Str::random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            foreach (glob(database_path('migrations/*.php')) as $file) {
                if (substr(basename($file), 0, 17) <= '2026_09_29_000390') {
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
