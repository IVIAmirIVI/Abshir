<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\ReceivableDispositionFixtures;
use Tests\TestCase;

class ReceivableDispositionConcurrencyTest extends TestCase
{
    use ReceivableDispositionFixtures;

    public function test_payout_commits_first_and_prepare_freezes_the_committed_payment(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD');
            $c = $this->correction($f, '70');
            $r = $this->race($this->payoutJob($f, $this->productEntry($f), '40'), $this->correctionJob($f, $c, 'prepare'));
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame('40.0000', $c->receivableDispositions()->sole()->historical_paid);
            $this->assertCount(1, $c->receivableDispositions()->sole()->allocations);
        });
    }

    public function test_prepare_commits_first_and_reserves_even_an_unpaid_source(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD');
            $c = $this->correction($f, '70');
            $r = $this->race($this->correctionJob($f, $c, 'prepare'), $this->payoutJob($f, $this->productEntry($f), '40'));
            $this->assertSame(['success', 'error'], array_column($r, 'status'), json_encode($r));
            $this->assertSame(ValidationException::class, $r[1]['class']);
            $this->assertSame('0', $c->receivableDispositions()->sole()->historical_paid);
            $this->assertDatabaseCount('captain_receivable_payouts', 0);
        });
    }

    public function test_finalize_commits_first_and_original_source_payout_is_rejected(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD');
            $e = $this->productEntry($f);
            $this->payout($f, $e, '40');
            $c = $this->prepareAndRefund($f, $this->correction($f, '70'));
            $r = $this->race($this->correctionJob($f, $c, 'finalize'), $this->payoutJob($f, $e, '1'));
            $this->assertSame(['success', 'error'], array_column($r, 'status'), json_encode($r));
            $this->assertSame('COMPLETED', $c->fresh()->state->value);
            $this->assertSame('40.0000', DB::table('captain_receivable_payout_allocations')->where('financial_entry_id', $e->id)->sum('amount'));
        });
    }

    public function test_payout_attempt_contends_first_but_cannot_slip_between_prepare_and_finalization(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD');
            $e = $this->productEntry($f);
            $this->payout($f, $e, '40');
            $c = $this->prepareAndRefund($f, $this->correction($f, '70'));
            $r = $this->race($this->payoutJob($f, $e, '1'), $this->correctionJob($f, $c, 'finalize'), $f['currency']->id);
            $this->assertSame(['error', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame(ValidationException::class, $r[0]['class']);
            $this->assertSame('COMPLETED', $c->fresh()->state->value);
            $this->assertDatabaseCount('captain_receivable_payouts', 1);
        });
    }

    public function test_same_account_other_source_remains_payable_while_correction_reserves_its_source(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD');
            $other = $this->fixture('USD', $f);
            $this->payout($f, $this->productEntry($f), '40');
            $c = $this->correction($f, '70');
            $this->assertSame($this->productEntry($f)->financial_account_id, $this->productEntry($other)->financial_account_id);
            $r = $this->race($this->correctionJob($f, $c, 'prepare'), $this->payoutJob($other, $this->productEntry($other), '20'));
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame('40.0000', $c->receivableDispositions()->sole()->historical_paid);
            $this->assertDatabaseCount('captain_receivable_payouts', 2);
        });
    }

    public function test_multi_captain_preparation_serializes_payout_and_freezes_each_exact_source(): void
    {
        $this->isolated(function (): void {
            $f = $this->fixture('USD', null, '0', 2);
            $captain = $f['captains'][1];
            $e = $this->productEntry($f, $captain);
            $c = $this->correction($f, '140');
            $job = $this->payoutJob($f, $e, '40');
            $job['captain_id'] = $captain->id;
            $r = $this->race($job, $this->correctionJob($f, $c, 'prepare'));
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame('0', $c->receivableDispositions()->where('captain_id', $f['captain']->id)->sole()->historical_paid);
            $this->assertSame('40.0000', $c->receivableDispositions()->where('captain_id', $captain->id)->sole()->historical_paid);
        });
    }

    private function correctionJob(array $f, object $c, string $operation): array
    {
        return ['operation' => $operation, 'actor_id' => $f['admin']->id, 'correction_id' => $c->id];
    }

    private function payoutJob(array $f, object $e, string $amount): array
    {
        return ['operation' => 'payout', 'actor_id' => $f['admin']->id, 'captain_id' => $f['captain']->id, 'branch_id' => $f['branch']->id, 'currency_id' => $f['currency']->id,
            'data' => ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'occurred_at' => now()->subSecond()->toIso8601String()],
            'allocations' => [['financial_entry_id' => $e->id, 'amount' => $amount]]];
    }

    /** Synchronization SQL only; both competing business operations run in production services. */
    private function race(array $first, array $second, ?int $currencyBarrier = null): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-disposition-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        $jobs = [];
        $probe = null;
        try {
            if ($currencyBarrier) {
                config(['database.connections.disposition_probe' => config('database.connections.'.config('database.default'))]);
                $probe = DB::connection('disposition_probe');
                $probe->beginTransaction();
                $probe->select('SELECT id FROM currencies WHERE id=? FOR UPDATE', [$currencyBarrier]);
            }
            foreach ([$first, $second] as $index => $job) {
                $prefix = $directory.DIRECTORY_SEPARATOR.$index;
                $job += ['tag' => 'disposition-worker-'.bin2hex(random_bytes(6)), 'ready' => $prefix.'.ready', 'held' => $prefix.'.held', 'release' => $prefix.'.release', 'result' => $prefix.'.result'];
                $jobs[] = $job;
                $path = $prefix.'.json';
                file_put_contents($path, json_encode($job, JSON_THROW_ON_ERROR));
                $config = config('database.connections.'.config('database.default'));
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/receivable_disposition_worker.php'), $path], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                    'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SCHEMA' => $config['search_path'],
                ]);
                $process->setTimeout(35);
                $process->start();
                $processes[] = $process;
                $this->await(fn () => file_exists($job['ready']), 'worker bootstrap');
                if ($index === 0) {
                    if ($probe) {
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
            }DB::purge('disposition_probe');
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
        $schema = 'receivable_race_'.Str::lower(Str::random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            Artisan::call('migrate', ['--force' => true]);
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
