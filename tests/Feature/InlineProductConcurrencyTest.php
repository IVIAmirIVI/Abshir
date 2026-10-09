<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderService;
use App\Services\OrderShopService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InlineProductConcurrencyTest extends TestCase
{
    public function test_same_normalized_name_on_different_orders_waits_and_creates_one_product(): void
    {
        $this->race(true);
    }

    public function test_different_names_are_not_serialized_by_an_unrelated_identity_lock(): void
    {
        $this->race(false);
    }

    private function race(bool $sameIdentity): void
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->seed(AuthorizationSeeder::class);
        $tag = bin2hex(random_bytes(6));
        $name = 'Inline race '.$tag;
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $customer = Customer::create(['name' => $name]);
        $phone = $customer->phones()->create(['phone' => $tag, 'normalized_phone' => $tag]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Race']);
        $unit = Unit::create(['name' => $name, 'code' => $tag]);
        $type = ShopType::create(['name' => $name, 'business_classification' => 'SHOP']);
        $shop = Shop::create(['name' => $name, 'area_id' => $area->id, 'shop_type_id' => $type->id, 'business_classification' => 'SHOP']);
        $orders = [];
        $shops = [];
        $workers = [];
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-inline-'.$tag;
        mkdir($directory, 0700, true);
        $goPath = $directory.DIRECTORY_SEPARATOR.'go';
        $database = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_DATABASE' => 'abshir_testing',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'],
            'DB_SCHEMA' => (string) $database['search_path']];
        try {
            for ($i = 0; $i < 2; $i++) {
                $order = app(OrderService::class)->create($actor, ['branch_id' => $branch->id, 'customer_id' => $customer->id,
                    'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
                $orders[] = $order->id;
                $orderShop = app(OrderShopService::class)->add($actor, $order, ['shop_type' => 'REGISTERED', 'shop_id' => $shop->id]);
                $shops[] = $orderShop->id;
                $workerName = 'inline_'.$tag.'_'.$i;
                $payload = ['worker_name' => $workerName, 'actor_id' => $actor->id, 'order_id' => $order->id,
                    'order_shop_id' => $orderShop->id, 'data' => ['name' => $i === 0 ? $name : ($sameIdentity ? '  '.strtoupper($name).'  ' : $name.' other'), 'default_unit_id' => $unit->id]];
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$i}.json";
                $readyPath = $directory.DIRECTORY_SEPARATOR."ready-{$i}";
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$i}.json";
                file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/inline_product_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $environment);
                $process->setTimeout(35);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'resultPath', 'workerName');
            }
            $this->await(fn () => collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])), $workers);
            DB::beginTransaction();
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['abshir:inline-product:'.mb_strtolower($name)]);
            touch($goPath);
            $names = array_column($workers, 'workerName');
            $expectedWaiters = $sameIdentity ? 2 : 1;
            $this->await(fn () => (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name IN (?,?) AND wait_event_type='Lock' AND wait_event='advisory'", $names) === $expectedWaiters, $workers);
            $this->assertSame($expectedWaiters, (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name IN (?,?) AND wait_event_type='Lock' AND wait_event='advisory'", $names));
            if (! $sameIdentity) {
                $this->await(fn () => file_exists($workers[1]['resultPath']), $workers);
                $this->assertSame('success', json_decode(file_get_contents($workers[1]['resultPath']), true)['status']);
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());
                $this->assertFileExists($worker['resultPath']);
                $results[] = json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame($sameIdentity ? ['success' => 1, 'validation' => 1] : ['success' => 2], collect($results)->countBy('status')->sortKeys()->all(), json_encode($results));
            $this->assertSame($sameIdentity ? 1 : 2, Product::where('default_unit_id', $unit->id)->count());
            $this->assertSame($sameIdentity ? 1 : 2, DB::table('shop_products')->where('shop_id', $shop->id)->count());
            if ($sameIdentity) {
                $this->assertSame(__('orders.ui_product_already_exists'), collect($results)->firstWhere('status', 'validation')['errors']['name'][0]);
            }
            $this->assertSame(0, DB::table('order_items')->whereIn('order_shop_id', $shops)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop();
                }
            }
            DB::table('shop_products')->where('shop_id', $shop->id)->delete();
            DB::table('products')->where('default_unit_id', $unit->id)->delete();
            DB::table('order_shops')->whereIn('id', $shops)->delete();
            DB::table('order_status_history')->whereIn('order_id', $orders)->delete();
            DB::table('orders')->whereIn('id', $orders)->delete();
            $shop->delete();
            $type->delete();
            $unit->delete();
            $address->delete();
            $phone->delete();
            $customer->delete();
            $actor->roles()->detach();
            $actor->delete();
            $area->delete();
            $branch->delete();
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function await(callable $condition, array $workers): void
    {
        $deadline = microtime(true) + 20;
        do {
            if ($condition()) {
                return;
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isTerminated() && ! file_exists($worker['resultPath'])) {
                    $this->fail($worker['process']->getErrorOutput().$worker['process']->getOutput());
                }
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out waiting for real production workers/advisory lock contention.');
    }
}
