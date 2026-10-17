<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Services\InvoiceCollectionService;
use App\Services\OrderStatusService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ShopCommissionFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class ShopCommissionConcurrencyTest extends TestCase
{
    use ShopCommissionFixtures;

    public function test_real_delivery_and_collection_contend_and_accrue_one_shared_commission(): void
    {
        $this->isolated(function (): void {
            $c = $this->commissionOrder(2);
            foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER'] as $status) {
                app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
            }
            $results = $this->race('orders', $c['order']->id, [
                ['operation' => 'deliver', 'actor' => $c['actor']->id, 'order' => $c['order']->id],
                ['operation' => 'collect', 'actor' => $c['actor']->id, 'invoice' => $c['invoice']->id, 'amount' => $c['invoice']->grand_total, 'currency' => $c['currency']->id],
            ]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame(1, DB::table('shop_commission_transactions')->where('kind', 'EARNING')->count());
            $this->assertSame('8.00', DB::table('shop_commission_transactions')->sole()->amount);
        });
    }

    public function test_real_duplicate_shop_payment_is_reused_after_actual_lock_contention(): void
    {
        $this->isolated(function (): void {
            $c = $this->commissionOrder();
            $this->deliverCommissionOrder($c);
            app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $c['invoice']->grand_total, $c['currency']->id, now());
            $account = DB::table('shop_commission_accounts')->sole();
            $job = ['operation' => 'payment', 'actor' => $c['admin']->id, 'shop' => $c['master']->id, 'account' => $account->id,
                'data' => ['amount' => '8', 'payment_method' => 'CASH', 'request_key' => (string) str()->uuid(), 'occurred_at' => now()->toIso8601String()]];
            $results = $this->race('shop_commission_accounts', $account->id, [$job, $job]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertSame(1, DB::table('shop_commission_transactions')->where('kind', 'PAYMENT')->count());
        });
    }

    public function test_distinct_concurrent_payments_cannot_both_spend_the_same_outstanding(): void
    {
        $this->isolated(function (): void {
            $c = $this->commissionOrder();
            $this->deliverCommissionOrder($c);
            app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $c['invoice']->grand_total, $c['currency']->id, now());
            $account = DB::table('shop_commission_accounts')->sole();
            $job = ['operation' => 'payment', 'actor' => $c['admin']->id, 'shop' => $c['master']->id, 'account' => $account->id,
                'data' => ['amount' => '6', 'payment_method' => 'TRANSFER', 'request_key' => (string) str()->uuid(), 'occurred_at' => now()->toIso8601String()]];
            $other = $job;
            $other['data']['request_key'] = (string) str()->uuid();
            $results = $this->race('shop_commission_accounts', $account->id, [$job, $other]);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertSame(1, DB::table('shop_commission_transactions')->where('kind', 'PAYMENT')->count());
        });
    }

    private function race(string $table, int $id, array $jobs): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table($table)->where('id', $id)->lockForUpdate()->sole();
            foreach ($jobs as $job) {
                $job['tag'] = 'commission_'.bin2hex(random_bytes(6));
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'memory_limit=512M', base_path('tests/Support/shop_commission_worker.php'), base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'abshir_testing', 'DB_URL' => '', 'DB_SCHEMA' => config('database.connections.pgsql.search_path'),
                ]);
                $process->setTimeout(40);
                $process->start();
                $workers[] = ['process' => $process, 'tag' => $job['tag']];
            }
            $deadline = microtime(true) + 15;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = DB::table('pg_stat_activity')->whereIn('application_name', array_column($workers, 'tag'))->where('wait_event_type', 'Lock')->count();
                if ($blocked === 2) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both independent real services must actually contend before the parent releases the row.');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());
                $results[] = json_decode($worker['process']->getOutput(), true, flags: JSON_THROW_ON_ERROR);
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
        }
    }

    private function isolated(callable $action): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        $key = 'database.connections.pgsql.search_path';
        $original = config($key);
        $schema = 'mc_commission_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge('pgsql');
            Artisan::call('migrate', ['--force' => true]);
            $action();
        } finally {
            TestDatabaseSafety::assertSafe($this->app);
            DB::purge('pgsql');
            config([$key => $original]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
