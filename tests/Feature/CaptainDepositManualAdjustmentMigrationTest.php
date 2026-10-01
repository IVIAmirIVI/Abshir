<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ManualDepositFixtures;
use Tests\TestCase;

class CaptainDepositManualAdjustmentMigrationTest extends TestCase
{
    use ManualDepositFixtures;

    public function test_staggered_deployment_stays_closed_until_contract_activation_and_receipts_work(): void
    {
        $this->isolated(function () {
            $f = $this->manualFixture();
            foreach (['000430_create_captain_deposit_manual_adjustments', '000440_enforce_manual_deposit_adjustment_contracts'] as $name) {
                DB::transaction(fn () => (require database_path('migrations/2026_09_29_'.$name.'.php'))->up());
                $this->assertFalse(DB::scalar('SELECT captain_manual_deposits_active()'));
                try {
                    $this->adjust($f);
                    $this->fail('Intermediate deployment opened.');
                } catch (ValidationException $e) {
                    $this->assertNotEmpty($e->errors());
                }
                $this->deposit($f, '10');
                $this->actingAs($f['admin'])->get(route('captains.financials.show', $f['captain']))->assertOk()->assertDontSee('Manual Captain Deposit Adjustment');
            }
            DB::transaction(fn () => (require database_path('migrations/2026_09_29_000450_activate_manual_deposit_adjustments.php'))->up());
            $this->assertTrue(DB::scalar('SELECT captain_manual_deposits_active()'));
            $this->adjust($f, $this->manualData('25', 'DECREASE'));
            $this->assertSame('-5.0000', $this->depositBalance($f));
        });
    }

    public function test_activation_rejects_unposted_historical_source_with_id_and_no_repair(): void
    {
        $this->isolated(function () {
            $f = $this->manualFixture();
            foreach (['000430_create_captain_deposit_manual_adjustments', '000440_enforce_manual_deposit_adjustment_contracts'] as $name) {
                DB::transaction(fn () => (require database_path('migrations/2026_09_29_'.$name.'.php'))->up());
            }
            // Simulate pre-existing corrupt deployment history; no business SQL races here.
            $raw = $this->manualData() + ['captain_id' => $f['captain']->id, 'branch_id' => $f['branch']->id, 'currency_id' => $f['currency']->id,
                'adjusted_by_user_id' => $f['admin']->id, 'evidence_path' => 'proof/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.png', 'evidence_name' => 'proof.png',
                'evidence_mime' => 'image/png', 'evidence_size' => 68, 'evidence_sha256' => hash('sha256', 'corrupt fixture')];
            $raw['payload_hash'] = DB::scalar('SELECT manual_deposit_hash(?::jsonb)', [json_encode($raw)]);
            DB::statement('ALTER TABLE captain_deposit_manual_adjustments DISABLE TRIGGER USER');
            $id = DB::table('captain_deposit_manual_adjustments')->insertGetId($raw);
            DB::statement('ALTER TABLE captain_deposit_manual_adjustments ENABLE TRIGGER USER');
            $before = DB::table('captain_deposit_manual_adjustments')->get()->all();
            try {
                DB::transaction(fn () => (require database_path('migrations/2026_09_29_000450_activate_manual_deposit_adjustments.php'))->up());
                $this->fail('Malformed history activated.');
            } catch (\PDOException $e) {
                $this->assertStringContainsString((string) $id, $e->getMessage());
            }
            $this->assertFalse(DB::scalar('SELECT captain_manual_deposits_active()'));
            $this->assertEquals($before, DB::table('captain_deposit_manual_adjustments')->get()->all());
        });
    }

    private function isolated(callable $callback): void
    {
        $connection = config('database.default');
        $key = 'database.connections.'.$connection.'.search_path';
        $original = config($key);
        $schema = 'manual_deposit_upgrade_'.Str::lower(Str::random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            foreach (glob(database_path('migrations/*.php')) as $file) {
                if (substr(basename($file), 0, 17) <= '2026_09_29_000420') {
                    DB::transaction(fn () => (require $file)->up());
                }
            }
            $callback();
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
