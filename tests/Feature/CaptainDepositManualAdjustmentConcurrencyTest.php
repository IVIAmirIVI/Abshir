<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Services\CaptainDepositManualAdjustmentEvidenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AccountingV1Fixtures;
use Tests\Support\ManualDepositFixtures;
use Tests\Support\ReceivableDispositionFixtures;
use Tests\TestCase;

class CaptainDepositManualAdjustmentConcurrencyTest extends TestCase
{
    use AccountingV1Fixtures;
    use ManualDepositFixtures, ReceivableDispositionFixtures;

    public function test_duplicate_workers_reuse_one_source_one_event_and_one_proof(): void
    {
        $this->isolated(function () {
            $f = $this->manualFixture();
            $job = $this->job($f, $this->manualData());
            $results = $this->race($job, $job);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertDatabaseCount('captain_deposit_manual_adjustments', 1);
            $this->assertDatabaseCount('financial_transactions', 1);
            $this->assertDatabaseCount('financial_entries', 1);
            $this->assertSame('25.0000', $this->depositBalance($f));
            $this->assertCount(1, Storage::disk(CaptainDepositManualAdjustmentEvidenceService::DISK)->allFiles());
        });
    }

    public function test_distinct_adjustments_on_same_account_serialize_to_exact_combined_math(): void
    {
        $this->isolated(function () {
            $f = $this->manualFixture();
            $this->deposit($f, '100');
            $results = $this->race($this->job($f, $this->manualData('30', 'DECREASE')), $this->job($f, $this->manualData('20')));
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame('90.0000', $this->depositBalance($f));
            $this->assertDatabaseCount('captain_deposit_manual_adjustments', 2);
            $this->assertDatabaseCount('financial_entries', 3);
        });
    }

    public function test_manual_adjustment_and_real_deposit_receipt_both_survive(): void
    {
        $this->isolated(function () {
            $f = $this->manualFixture();
            $this->deposit($f, '100');
            $receipt = $this->job($f, $this->manualData('20'));
            $receipt['operation'] = 'receipt';
            $receipt['actor_id'] = $f['employee']->id;
            $results = $this->race($this->job($f, $this->manualData('30', 'DECREASE')), $receipt);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame('90.0000', $this->depositBalance($f));
            $this->assertDatabaseCount('captain_deposit_receipts', 2);
            $this->assertDatabaseCount('captain_deposit_manual_adjustments', 1);
            $this->assertDatabaseCount('financial_entries', 3);
        });
    }

    public function test_manual_adjustment_and_real_cash_order_delivery_debit_serialize_without_inversion(): void
    {
        $this->isolated(function () {
            $f = $this->fixture('USD', method: InvoiceCollectionMethod::Cash, deliver: false);
            Storage::fake(CaptainDepositManualAdjustmentEvidenceService::DISK);
            $this->deposit($f, '100');
            $results = $this->race($this->job($f, $this->manualData('20')), ['operation' => 'deliver', 'actor_id' => $f['employee']->id, 'order_id' => $f['order']->id]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame('20.0000', $this->depositBalance($f));
            $this->assertSame('DELIVERED', $f['order']->fresh()->operational_status->value);
            $this->assertSame(1, DB::table('financial_transactions')->where('event_type', 'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT')->count());
            $this->assertSame(1, DB::table('financial_transactions')->where('source_type', 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT')->count());
        });
    }

    private function job(array $f, array $data): array
    {
        return ['operation' => 'manual', 'actor_id' => $f['admin']->id, 'captain_id' => $f['captain']->id, 'branch_id' => $f['branch']->id, 'currency_id' => $f['currency']->id, 'data' => $data];
    }

    /** Synchronization SQL only; both competing business operations run in production services. */
    private function race(array $first, array $second, ?int $currencyBarrier = null, string $barrierTable = 'currencies'): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-manual-deposit-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        $jobs = [];
        $probe = null;
        try {
            if ($currencyBarrier) {
                config(['database.connections.manual_deposit_probe' => config('database.connections.'.config('database.default'))]);
                $probe = DB::connection('manual_deposit_probe');
                $probe->beginTransaction();
                if (! in_array($barrierTable, ['currencies', 'orders', 'financial_accounts'], true)) {
                    throw new \LogicException('Invalid synchronization table.');
                }
                $probe->select('SELECT id FROM '.$barrierTable.' WHERE id=? FOR UPDATE', [$currencyBarrier]);
            }
            foreach ([$first, $second] as $index => $job) {
                $prefix = $directory.DIRECTORY_SEPARATOR.$index;
                $job['evidence_roots'] = collect(config('filesystems.disks'))->filter(fn ($disk) => isset($disk['root']))->map(fn ($disk, $name) => Storage::disk($name)->path(''))->all();
                $job += ['tag' => 'manual-deposit-worker-'.bin2hex(random_bytes(6)), 'ready' => $prefix.'.ready', 'held' => $prefix.'.held', 'release' => $prefix.'.release', 'result' => $prefix.'.result'];
                $jobs[] = $job;
                $path = $prefix.'.json';
                file_put_contents($path, json_encode($job, JSON_THROW_ON_ERROR));
                $config = config('database.connections.'.config('database.default'));
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/manual_deposit_worker.php'), $path], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                    'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SCHEMA' => $config['search_path'],
                ]);
                $process->setTimeout(35);
                $process->start();
                $processes[] = $process;
                $this->await(fn () => file_exists($job['ready']), 'worker bootstrap');
                if ($index === 0) {
                    if ($probe && $barrierTable !== 'financial_accounts') {
                        $this->awaitBlocked($job['tag']);
                    } else {
                        $this->await(fn () => file_exists($job['held']), 'first real service lock barrier');
                    }
                } else {
                    $this->awaitBlocked($job['tag']);
                }
            }
            if ($probe) {
                $probe->commit();
            }
            touch($jobs[0]['release']);
            touch($jobs[1]['release']);
            $results = [];
            foreach ($processes as $index => $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(file_get_contents($jobs[$index]['result']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if ($probe?->transactionLevel()) {
                $probe->rollBack();
            }DB::purge('manual_deposit_probe');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }rmdir($directory);
        }
    }

    private function awaitBlocked(string $tag): void
    {
        $this->await(function () use ($tag): bool {
            DB::select('SELECT pg_stat_clear_snapshot()');

            return (bool) DB::scalar("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock' AND cardinality(pg_blocking_pids(pid))>0)", [$tag]);
        }, 'independent PostgreSQL lock contention');
        $this->assertTrue(true, 'Observed actual PostgreSQL blocking, not merely simultaneous worker starts.');
    }

    private function await(callable $ready, string $description): void
    {
        $deadline = microtime(true) + 15;
        while (! $ready()) {
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for '.$description);
            }usleep(20000);
        }
    }

    private function isolated(callable $callback): void
    {
        $connection = config('database.default');
        $key = 'database.connections.'.$connection.'.search_path';
        $original = config($key);
        $schema = 'manual_deposit_race_'.Str::lower(Str::random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            if (str_contains($this->name(), 'real_cash_order_delivery_debit')) {
                $this->migrateAccountingV1Schema();
            } else {
                Artisan::call('migrate', ['--force' => true]);
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
