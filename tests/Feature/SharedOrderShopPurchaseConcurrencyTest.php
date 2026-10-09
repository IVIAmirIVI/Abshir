<?php

namespace Tests\Feature;

use App\Models\OrderShopPurchaseConfirmation;
use App\Services\OrderReturnEvidenceService;
use App\Services\OrderShopPurchaseConfirmationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\SharedPurchaseFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class SharedOrderShopPurchaseConcurrencyTest extends TestCase
{
    use SharedPurchaseFixtures;

    public function test_real_shared_purchase_retries_contend_and_create_one_complete_source(): void
    {
        $this->isolated(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $c = $this->sharedContext();
            $data = $this->sharedData($c);
            $results = $this->race($c, ['shared', 'shared'], [$data, $data]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertSame(1, DB::table('order_shop_purchase_confirmations')->count());
            $this->assertSame(2, DB::table('order_shop_purchase_allocations')->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_real_shared_and_legacy_confirmations_cannot_both_win(): void
    {
        $this->isolated(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $c = $this->sharedContext();
            $results = $this->race($c, ['shared', 'single'], [$this->sharedData($c),
                $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id]]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $loser = collect($results)->firstWhere('status', 'error');
            $this->assertSame(ValidationException::class, $loser['class'], json_encode($results));
            $purchase = OrderShopPurchaseConfirmation::sole();
            $this->assertSame($purchase->purchase_mode === 'SINGLE_PAYER' ? 0 : 2, DB::table('order_shop_purchase_allocations')->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_additive_upgrade_preserves_existing_single_payer_source_and_refuses_rollback(): void
    {
        $this->isolated(function (): void {
            $path = 'database/migrations/2026_10_09_000000_add_shared_shop_purchase_foundation.php';
            $paths = collect(glob(database_path('migrations/*.php')))->filter(fn ($file) => basename($file) !== basename($path))
                ->map(fn ($file) => 'database/migrations/'.basename($file))->all();
            Artisan::call('migrate', ['--path' => $paths, '--force' => true]);
            Storage::fake('order_returns');
            $c = $this->sharedContext(2, 1);
            $purchase = app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'],
                $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
            $before = $purchase->getAttributes();
            Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $after = $purchase->fresh()->getAttributes();
            $this->assertSame('SINGLE_PAYER', $after['purchase_mode']);
            $this->assertNull($after['collecting_order_captain_id']);
            unset($after['purchase_mode'], $after['collecting_order_captain_id']);
            $this->assertSame($before, $after);
            $this->assertSame(0, DB::table('order_shop_purchase_allocations')->count());
            $this->assertThrows(fn () => (require base_path($path))->down(), \RuntimeException::class);
            $this->assertSame($before['amount'], $purchase->fresh()->amount);
        });
    }

    public function test_global_purchase_key_contends_across_orders_with_a_controlled_conflict(): void
    {
        $this->isolated(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $first = $this->sharedContext();
            $second = $this->sharedContext();
            $data = $this->sharedData($first);
            $other = array_replace($this->sharedData($second), ['request_key' => strtoupper($data['request_key'])]);
            $results = $this->race($first, ['shared', 'shared'], [$data, $other], $second);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $this->assertSame(1, DB::table('order_shop_purchase_confirmations')->count());
            $this->assertSame(2, DB::table('order_shop_purchase_allocations')->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_shared_and_single_payer_global_key_race_is_a_controlled_conflict(): void
    {
        $this->isolated(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $first = $this->sharedContext();
            $second = $this->sharedContext(2, 1);
            $data = $this->sharedData($first);
            $other = array_replace($this->sharedData($second), ['request_key' => strtoupper($data['request_key']),
                'order_captain_id' => $second['assignments'][0]->id]);
            $results = $this->race($first, ['shared', 'single'], [$data, $other], $second);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $purchase = OrderShopPurchaseConfirmation::sole();
            $this->assertSame($purchase->purchase_mode === 'SINGLE_PAYER' ? 0 : 2, DB::table('order_shop_purchase_allocations')->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    private function race(array $c, array $operations, array $data, ?array $secondContext = null): array
    {
        $directory = sys_get_temp_dir().'/abshir-mc1-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $tag = 'mc1-'.bin2hex(random_bytes(6));
        $workers = [];
        $database = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_DATABASE' => 'abshir_testing',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'DB_SCHEMA' => $database['search_path']];
        DB::beginTransaction();
        if ($secondContext) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['order-shop-purchase:'.$data[0]['request_key']]);
        } else {
            DB::table('orders')->where('id', $c['order']->id)->lockForUpdate()->sole();
        }
        $parentPid = (int) DB::scalar('SELECT pg_backend_pid()');
        try {
            foreach ([0, 1] as $index) {
                $context = $index === 1 && $secondContext ? $secondContext : $c;
                $payloadPath = $directory.'/payload-'.$index.'.json';
                $readyPath = $directory.'/ready-'.$index;
                $resultPath = $directory.'/result-'.$index.'.json';
                file_put_contents($payloadPath, json_encode(['tag' => $tag, 'operation' => $operations[$index],
                    'actor_id' => $context['actor']->id, 'order_id' => $context['order']->id, 'shop_id' => $context['shop']->id, 'data' => $data[$index]], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/shared_purchase_worker.php'),
                    $payloadPath, $readyPath, $directory.'/go', $resultPath], base_path(), $environment);
                $process->setTimeout(30);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'resultPath');
            }
            $deadline = microtime(true) + 15;
            while (! collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])) && microtime(true) < $deadline) {
                usleep(25_000);
            }
            $this->assertTrue(collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])), 'Both independent Laravel processes must bootstrap.');
            touch($directory.'/go');
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                // PostgreSQL may queue the second tuple-lock waiter behind the first worker,
                // rather than report the parent as its immediate blocker. Follow the real lock graph.
                $blocked = (int) DB::scalar('WITH RECURSIVE waiting(pid) AS (
                    SELECT CAST(? AS integer) UNION
                    SELECT a.pid FROM pg_stat_activity a JOIN waiting w ON w.pid=ANY(pg_blocking_pids(a.pid))
                ) SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND pid IN (SELECT pid FROM waiting)', [$parentPid, $tag]);
                if ($blocked === 2) {
                    break;
                }
                usleep(25_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both real services must contend on the parent Order lock before either can commit.');
            DB::commit();
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
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function isolated(callable $action): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        $key = 'database.connections.pgsql.search_path';
        $original = config($key);
        $schema = 'mc1_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
            $action();
        } finally {
            if (Schema::hasTable('order_shop_purchase_confirmations')) {
                foreach (DB::table('order_shop_purchase_confirmations')->pluck('evidence_path') as $path) {
                    app(OrderReturnEvidenceService::class)->delete($path);
                }
            }
            DB::purge('pgsql');
            config([$key => $original]);
            DB::reconnect('pgsql');
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
