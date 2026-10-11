<?php

namespace Tests\Feature;

use App\Models\CaptainReceivableRecovery;
use App\Services\CaptainReceivableRecoveryEvidenceService;
use App\Services\InvoiceFinancialCorrectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\AccountingV1Fixtures;
use Tests\Support\ReceivableDispositionFixtures;
use Tests\TestCase;

class CaptainReceivableRecoveryConcurrencyTest extends TestCase
{
    use AccountingV1Fixtures;
    use ReceivableDispositionFixtures, \Tests\Support\CaptainRecoveryFixtures;

    public function test_cancellation_cannot_outrun_missing_recovery_and_retry_completes_after_cash(): void
    {
        $this->isolated(function () {
            [$f,$c] = $this->readyRecoveryCancellation();
            $cancel = ['operation' => 'cancel', 'actor_id' => $f['admin']->id, 'order_id' => $f['order']->id];
            $r = $this->race($cancel, $this->recoveryJob($f, $c), $f['order']->id, 'orders');
            $this->assertSame(['error', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame(ValidationException::class, $r[0]['class']);
            $this->assertSame('DELIVERED', $f['order']->fresh()->operational_status->value);
            $this->assertDatabaseCount('order_cancellations', 0);
            app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
            $this->assertSame('CANCELLED', $f['order']->fresh()->operational_status->value);
        });
    }

    public function test_recovery_first_allows_waiting_financial_cancellation_finalizer(): void
    {
        $this->isolated(function () {
            [$f,$c] = $this->readyRecoveryCancellation();
            $r = $this->race($this->recoveryJob($f, $c), $this->finalizeJob($f, $c));
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame('CANCELLED', $f['order']->fresh()->operational_status->value);
            $this->assertSame('COMPLETED', $c->fresh()->state->value);
        });
    }

    public function test_recovery_first_makes_waiting_financial_finalizer_succeed(): void
    {
        $this->isolated(function () {
            [$f,$c] = $this->ready();
            $r = $this->race($this->recoveryJob($f, $c), $this->finalizeJob($f, $c));
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame('COMPLETED', $c->fresh()->state->value);
            $this->assertDatabaseCount('captain_receivable_recoveries', 1);
        });
    }

    public function test_finalizer_first_fails_cleanly_then_recovery_allows_retry(): void
    {
        $this->isolated(function () {
            [$f,$c] = $this->ready();
            $r = $this->race($this->finalizeJob($f, $c), $this->recoveryJob($f, $c), $f['currency']->id);
            $this->assertSame(['error', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame(ValidationException::class, $r[0]['class']);
            $this->assertSame('READY_TO_FINALIZE', $c->fresh()->state->value);
            app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
            $this->assertSame('COMPLETED', $c->fresh()->state->value);
        });
    }

    public function test_same_request_duplicate_workers_reuse_one_exact_receipt(): void
    {
        $this->isolated(function () {
            [$f,$c] = $this->ready();
            $job = $this->recoveryJob($f, $c);
            $r = $this->race($job, $job);
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame($r[0]['id'], $r[1]['id']);
            $this->assertDatabaseCount('captain_receivable_recoveries', 1);
            $this->assertCount(1, Storage::disk(CaptainReceivableRecoveryEvidenceService::DISK)->allFiles());
        });
    }

    public function test_different_request_duplicate_workers_cannot_record_cash_twice(): void
    {
        $this->isolated(function () {
            [$f,$c] = $this->ready();
            $r = $this->race($this->recoveryJob($f, $c), $this->recoveryJob($f, $c));
            $this->assertSame(['success', 'error'], array_column($r, 'status'), json_encode($r));
            $this->assertSame(ValidationException::class, $r[1]['class']);
            $this->assertDatabaseCount('captain_receivable_recoveries', 1);
            $this->assertCount(1, Storage::disk(CaptainReceivableRecoveryEvidenceService::DISK)->allFiles());
        });
    }

    public function test_two_captains_record_independent_full_recoveries_without_deadlock(): void
    {
        $this->isolated(function () {
            $f = $this->fixture('USD', captainCount: 2);
            Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
            $this->payout($f, $this->productEntry($f), '80');
            $this->payout($f, $this->productEntry($f, $f['captains'][1]), '85', $f['captains'][1]);
            $c = $this->prepareAndRefund($f, $this->correction($f, '140'));
            $second = $this->recoveryJob($f, $c);
            $second['captain_id'] = $f['captains'][1]->id;
            $r = $this->race($this->recoveryJob($f, $c), $second);
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertDatabaseCount('captain_receivable_recoveries', 2);
            app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
            $this->assertSame('COMPLETED', $c->fresh()->state->value);
        });
    }

    public function test_unrelated_same_account_payout_remains_payable_during_recovery(): void
    {
        $this->isolated(function () {
            [$f,$c] = $this->ready();
            $other = $this->fixture('USD', $f);
            $e = $this->productEntry($other);
            $this->assertSame($this->productEntry($f)->financial_account_id, $e->financial_account_id);
            $r = $this->race($this->recoveryJob($f, $c), $this->payoutJob($other, $e, '20'));
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertSame('READY_TO_FINALIZE', $c->fresh()->state->value);
            $this->assertDatabaseCount('captain_receivable_payouts', 2);
            $r = $this->race($this->recoveryJob($f, $c, CaptainReceivableRecovery::sole()->request_key, CaptainReceivableRecovery::sole()->received_at->format('Y-m-d H:i:s.uP')), $this->payoutJob($f, $this->productEntry($f), '1'));
            $this->assertSame(['success', 'error'], array_column($r, 'status'), json_encode($r));
            $this->assertSame(ValidationException::class, $r[1]['class']);
        });
    }

    public function test_receipt_recording_does_not_acquire_an_unnecessary_financial_account_lock(): void
    {
        $this->isolated(function () {
            [$f,$c] = $this->ready();
            $other = $this->fixture('USD', $f);
            $entry = $this->productEntry($other);
            // A third connection holds the account while real recording must still reach its held barrier.
            // The unrelated payout then waits on Currency. No test SQL supplies any business decision.
            $r = $this->race($this->recoveryJob($f, $c), $this->payoutJob($other, $entry, '20'), $entry->financial_account_id, 'financial_accounts');
            $this->assertSame(['success', 'success'], array_column($r, 'status'), json_encode($r));
            $this->assertDatabaseCount('captain_receivable_recoveries', 1);
        });
    }

    private function ready(): array
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '100');

        return [$f, $this->prepareAndRefund($f, $this->correction($f, '60'))];
    }

    private function recoveryJob(array $f, object $c, ?string $key = null, ?string $when = null): array
    {
        return ['operation' => 'recovery', 'actor_id' => $f['employee']->id, 'captain_id' => $f['captain']->id, 'correction_id' => $c->id,
            'data' => ['request_key' => $key ?? (string) Str::uuid(), 'received_at' => $when ?? now()->format('Y-m-d H:i:s.uP'), 'reference' => 'RACE']];
    }

    private function finalizeJob(array $f, object $c): array
    {
        return ['operation' => 'finalize', 'actor_id' => $f['admin']->id, 'correction_id' => $c->id];
    }

    private function payoutJob(array $f, object $e, string $amount): array
    {
        return ['operation' => 'payout', 'actor_id' => $f['admin']->id, 'captain_id' => $f['captain']->id, 'branch_id' => $f['branch']->id, 'currency_id' => $f['currency']->id,
            'data' => ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'occurred_at' => now()->subSecond()->toIso8601String()],
            'allocations' => [['financial_entry_id' => $e->id, 'amount' => $amount]]];
    }

    /** Synchronization SQL only; both competing business operations run in production services. */
    private function race(array $first, array $second, ?int $currencyBarrier = null, string $barrierTable = 'currencies'): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-recovery-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        $jobs = [];
        $probe = null;
        try {
            if ($currencyBarrier) {
                config(['database.connections.recovery_probe' => config('database.connections.'.config('database.default'))]);
                $probe = DB::connection('recovery_probe');
                $probe->beginTransaction();
                if (! in_array($barrierTable, ['currencies', 'orders', 'financial_accounts'], true)) {
                    throw new \LogicException('Invalid synchronization table.');
                }
                $probe->select('SELECT id FROM '.$barrierTable.' WHERE id=? FOR UPDATE', [$currencyBarrier]);
            }
            foreach ([$first, $second] as $index => $job) {
                $prefix = $directory.DIRECTORY_SEPARATOR.$index;
                $job['evidence_roots'] = collect(config('filesystems.disks'))->filter(fn ($disk) => isset($disk['root']))->map(fn ($disk, $name) => Storage::disk($name)->path(''))->all();
                $job += ['tag' => 'recovery-worker-'.bin2hex(random_bytes(6)), 'ready' => $prefix.'.ready', 'held' => $prefix.'.held', 'release' => $prefix.'.release', 'result' => $prefix.'.result'];
                $jobs[] = $job;
                $path = $prefix.'.json';
                file_put_contents($path, json_encode($job, JSON_THROW_ON_ERROR));
                $config = config('database.connections.'.config('database.default'));
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/captain_recovery_worker.php'), $path], base_path(), [
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
            }DB::purge('recovery_probe');
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
        $schema = 'recovery_race_'.Str::lower(Str::random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            $this->migrateAccountingV1Schema();
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
