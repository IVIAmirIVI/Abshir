<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialTransactionStatus;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancialAccountService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FinancialLedgerConcurrencyTest extends TestCase
{
    public function test_first_posting_and_precision_update_contend_on_the_currency_row(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $currency = Currency::create(['code' => 'RAC', 'name' => 'Race', 'decimal_places' => 4]);
            $actor = User::factory()->create();
            $role = Role::create(['code' => 'SUPER_ADMIN', 'name' => 'Super Admin']);
            $actor->roles()->attach($role);
            $accounts = app(FinancialAccountService::class);
            $owner = $accounts->ownerForCaptain(Captain::factory()->create());
            $account = $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit);
            $results = $this->race([
                ['operation' => 'event', 'currency_id' => $currency->id, 'account_id' => $account->id, 'source_type' => 'RACE', 'source_id' => 'precision', 'event_type' => 'FIRST', 'occurred_at' => now()->toIso8601String(), 'amount' => '1.2345'],
                ['operation' => 'precision', 'currency_id' => $currency->id, 'actor_id' => $actor->id],
            ], $currency->id);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $precision = (int) $currency->fresh()->decimal_places;
            if ($precision === 2) {
                $this->assertSame('ledger_error', $results[0]['status']);
                $this->assertDatabaseCount('financial_transactions', 0);
                $this->assertDatabaseCount('financial_entries', 0);
            } else {
                $this->assertSame(4, $precision);
                $this->assertSame('validation_error', $results[1]['status']);
                $this->assertSame('POSTED', DB::table('financial_transactions')->sole()->status);
                $this->assertSame('1.2345', DB::table('financial_entries')->sole()->amount);
            }
        });
    }

    public function test_real_services_are_safe_for_concurrent_account_creation_posting_and_reversal(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            Artisan::call('migrate', ['--force' => true]);

            $currency = Currency::create([
                'name' => 'Ledger Race '.bin2hex(random_bytes(3)),
                'code' => strtoupper(substr(bin2hex(random_bytes(3)), 0, 3)),
                'decimal_places' => 2,
            ]);
            $captain = Captain::factory()->create();
            $owner = app(FinancialAccountService::class)->ownerForCaptain($captain);

            $accountResults = $this->race([
                ['operation' => 'account', 'owner_id' => $owner->id, 'currency_id' => $currency->id, 'account_kind' => FinancialAccountKind::CaptainDeposit->value],
                ['operation' => 'account', 'owner_id' => $owner->id, 'currency_id' => $currency->id, 'account_kind' => FinancialAccountKind::CaptainDeposit->value],
            ]);
            $this->assertSame(['success' => 2], collect($accountResults)->countBy('status')->all(), json_encode($accountResults));
            $this->assertCount(1, collect($accountResults)->pluck('id')->unique());
            $account = $owner->accounts()->sole();

            $token = bin2hex(random_bytes(8));
            $occurredAt = now()->startOfSecond()->toIso8601String();
            $event = ['operation' => 'event', 'currency_id' => $currency->id, 'account_id' => $account->id, 'source_type' => 'CONCURRENCY_TEST', 'source_id' => $token, 'event_type' => 'IDENTICAL_EVENT', 'occurred_at' => $occurredAt, 'amount' => '15.25'];
            $postingResults = $this->race([$event, $event]);
            $this->assertSame(['success' => 2], collect($postingResults)->countBy('status')->all(), json_encode($postingResults));
            $this->assertCount(1, collect($postingResults)->pluck('id')->unique());
            $this->assertSame(['POSTED'], collect($postingResults)->pluck('transaction_status')->unique()->values()->all());
            $this->assertSame([1], collect($postingResults)->pluck('entry_count')->unique()->values()->all());
            $transactionId = $postingResults[0]['id'];
            $this->assertSame(1, DB::table('financial_transactions')->where(['source_type' => 'CONCURRENCY_TEST', 'source_id' => $token])->count());
            $this->assertSame(1, DB::table('financial_entries')->where('financial_transaction_id', $transactionId)->count());
            $this->assertSame(FinancialTransactionStatus::Posted, FinancialTransaction::findOrFail($transactionId)->status);

            $reversalToken = bin2hex(random_bytes(8));
            $reversal = ['operation' => 'reversal', 'transaction_id' => $transactionId, 'source_type' => 'CONCURRENCY_TEST', 'source_id' => $reversalToken, 'event_type' => 'REVERSAL', 'occurred_at' => $occurredAt, 'reason' => 'Concurrent reversal'];
            $reversalResults = $this->race([$reversal, $reversal], $currency->id);
            $this->assertSame(['ledger_error' => 1, 'success' => 1], collect($reversalResults)->countBy('status')->sortKeys()->all(), json_encode($reversalResults));
            $this->assertSame(1, DB::table('financial_transactions')->where('reversal_of_transaction_id', $transactionId)->count());
        });
    }

    private function withIsolatedSchema(callable $callback): void
    {
        $connectionName = (string) config('database.default');
        $configurationKey = "database.connections.{$connectionName}.search_path";
        $originalSearchPath = (string) config($configurationKey);
        $schema = 'financial_ledger_race_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');

        try {
            config([$configurationKey => $schema]);
            DB::purge($connectionName);
            DB::reconnect($connectionName);
            $callback();
        } finally {
            DB::purge($connectionName);
            config([$configurationKey => $originalSearchPath]);
            DB::reconnect($connectionName);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    private function race(array $jobs, ?int $lockedCurrencyId = null): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-ledger-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $goPath = $directory.DIRECTORY_SEPARATOR.'go';
        $workers = [];
        $tag = 'ledger-test-'.bin2hex(random_bytes(8));

        try {
            if ($lockedCurrencyId !== null) {
                DB::beginTransaction();
                DB::table('currencies')->where('id', $lockedCurrencyId)->lockForUpdate()->firstOrFail();
            }
            foreach ($jobs as $index => $job) {
                $job['worker_tag'] = $tag;
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$index}.json";
                $readyPath = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                file_put_contents($payloadPath, json_encode($job, JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/financial_ledger_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'resultPath');
            }

            $deadline = microtime(true) + 15;
            do {
                if (collect($workers)->every(fn (array $worker): bool => file_exists($worker['readyPath']))) {
                    break;
                }
                foreach ($workers as $worker) {
                    if ($worker['process']->isTerminated()) {
                        $this->fail('A ledger worker exited before the barrier. '.$worker['process']->getErrorOutput().$worker['process']->getOutput());
                    }
                }
                usleep(25_000);
            } while (microtime(true) < $deadline);
            $this->assertTrue(collect($workers)->every(fn (array $worker): bool => file_exists($worker['readyPath'])), 'Ledger workers did not reach the barrier.');

            touch($goPath);
            if ($lockedCurrencyId !== null) {
                $deadline = microtime(true) + 10;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $blocked = (int) DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$tag])->total;
                    if ($blocked === count($jobs)) {
                        break;
                    }
                    usleep(25_000);
                } while (microtime(true) < $deadline);
                $this->assertSame(count($jobs), $blocked, 'Both production workers must contend before the coordinator releases the Currency lock.');
                DB::commit();
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput().$worker['process']->getOutput());
                $results[] = json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if ($lockedCurrencyId !== null && DB::transactionLevel() > 0) {
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
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function workerEnvironment(): array
    {
        $database = config('database.connections.'.config('database.default'));

        return [
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'DB_SCHEMA' => (string) $database['search_path'],
        ];
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! class_exists(Process::class)) {
            $this->markTestSkipped('This concurrency test requires PostgreSQL and Symfony Process.');
        }
    }
}
