<?php

namespace Tests\Feature;

use App\Models\OrderCaptainSelection;
use App\Services\OrderReturnEvidenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\SharedPurchaseFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class OrderCaptainSelectionConcurrencyTest extends TestCase
{
    use SharedPurchaseFixtures;

    public function test_identical_real_selection_requests_contend_and_reuse_one_audit(): void
    {
        $this->isolated(function (): void {
            $c = $this->sharedContext();
            $data = $this->selectionData($c);
            $results = $this->race($c, ['selection', 'selection'], [$data, $data]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertSame(1, OrderCaptainSelection::count());
            $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_same_key_with_different_collectors_produces_controlled_conflict(): void
    {
        $this->isolated(function (): void {
            $c = $this->sharedContext();
            $first = $this->selectionData($c) + ['reason' => 'Explicit collector'];
            $second = array_replace($first, ['collector_id' => $c['captains'][1]->id]);
            $results = $this->race($c, ['selection', 'selection'], [$first, $second]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $this->assertSame(1, OrderCaptainSelection::count());
            DB::select('SELECT assert_order_captain_selection(?)', [$c['order']->id]);
            $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
        });
    }

    public function test_distinct_collector_changes_keep_both_participants_and_full_audit(): void
    {
        $this->isolated(function (): void {
            $c = $this->sharedContext();
            $first = $this->selectionData($c) + ['reason' => 'Collector A'];
            $second = array_replace($this->selectionData($c), ['collector_id' => $c['captains'][1]->id, 'reason' => 'Collector B']);
            $results = $this->race($c, ['selection', 'selection'], [$first, $second]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame(2, OrderCaptainSelection::count());
            DB::select('SELECT assert_order_captain_selection(?)', [$c['order']->id]);
            $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_purchase_race_cannot_rewrite_payer_or_collector_attribution(): void
    {
        $this->isolated(function (): void {
            $c = $this->sharedContext();
            $results = $this->race($c, ['selection', 'purchase'], [$this->selectionData($c), $this->sharedData($c)]);
            $this->assertSame('success', $results[1]['status'], json_encode($results));
            if ($results[0]['status'] === 'error') {
                $this->assertSame(ValidationException::class, $results[0]['class']);
            } else {
                $this->assertSame(1, OrderCaptainSelection::count());
            }
            $purchase = $c['order']->purchaseConfirmations()->sole();
            $this->assertSame($c['assignments'][0]->id, $purchase->collecting_order_captain_id);
            $this->assertSame(2, $purchase->allocations()->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_global_retry_key_contends_across_two_orders_and_returns_controlled_conflict(): void
    {
        $this->isolated(function (): void {
            $first = $this->sharedContext();
            $second = $this->sharedContext();
            $data = $this->selectionData($first);
            $otherData = array_replace($this->selectionData($second), ['request_key' => strtoupper($data['request_key'])]);
            $results = $this->race($first, ['selection', 'selection'], [$data, $otherData], $second);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $this->assertSame(1, OrderCaptainSelection::count());
            foreach ([$first, $second] as $c) {
                $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
            }
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    private function selectionData(array $c): array
    {
        return ['captain_ids' => array_column($c['captains'], 'id'), 'collector_id' => $c['captains'][0]->id,
            'request_key' => (string) str()->uuid()];
    }

    private function race(array $c, array $operations, array $data, ?array $secondContext = null): array
    {
        $directory = sys_get_temp_dir().'/abshir-mc2-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $tag = 'mc2-'.bin2hex(random_bytes(6));
        $workers = [];
        $database = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_DATABASE' => 'abshir_testing',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'DB_SCHEMA' => $database['search_path']];
        DB::beginTransaction();
        if ($secondContext) {
            // Synchronization only: both real services must reach the same globally unique request-key lock.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['order-captain-selection:'.$data[0]['request_key']]);
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
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/order_captain_selection_worker.php'),
                    $payloadPath, $readyPath, $directory.'/go', $resultPath], base_path(), $environment);
                $process->setTimeout(35);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'resultPath');
            }
            $deadline = microtime(true) + 20;
            while (! collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])) && microtime(true) < $deadline) {
                usleep(25_000);
            }
            $this->assertTrue(collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])), 'Both real Laravel workers must bootstrap.');
            $pids = array_map(fn ($worker) => (int) file_get_contents($worker['readyPath']), $workers);
            $this->assertCount(2, array_unique($pids), 'Workers must use independent PostgreSQL connections.');
            touch($directory.'/go');
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::scalar('WITH RECURSIVE waiting(pid) AS (
                    SELECT CAST(? AS integer) UNION
                    SELECT a.pid FROM pg_stat_activity a JOIN waiting w ON w.pid=ANY(pg_blocking_pids(a.pid))
                ) SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND pid IN (SELECT pid FROM waiting)', [$parentPid, $tag]);
                if ($blocked === 2) {
                    break;
                }
                usleep(25_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both production services must demonstrably contend on the held Order or global request-key lock.');
            DB::commit();

            return array_map(function ($worker): array {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());

                return json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR);
            }, $workers);
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
        $schema = 'mc2_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
            TestDatabaseSafety::assertSafe($this->app);
            Artisan::call('migrate', ['--force' => true]);
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
