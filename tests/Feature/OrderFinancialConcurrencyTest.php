<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialTransaction;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OrderFinancialConcurrencyTest extends TestCase
{
    public function test_real_services_serialize_crystallization_and_cash_custody(): void
    {
        $this->withIsolatedSchema(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->context();
            $ready = app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
            $ready->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);

            $orderResults = $this->race('order', $c['order']->id, $c['order']->id);
            $this->assertSame(['success' => 2], collect($orderResults)->countBy('status')->all(), json_encode($orderResults));
            $this->assertSame(3, FinancialTransaction::where('source_type', 'CUSTOMER_INVOICE')->count());

            $cashResults = $this->race('collect', $ready->id, $c['order']->id, [
                'actor_id' => $c['employee']->id,
                'currency_id' => $c['currency']->id,
                'amount' => $ready->grand_total,
                'collected_at' => now()->toISOString(),
            ]);
            $this->assertSame(1, collect($cashResults)->where('status', 'success')->count(), json_encode($cashResults));
            $loser = collect($cashResults)->firstWhere('status', 'error');
            $this->assertSame(ValidationException::class, $loser['class'] ?? null, json_encode($cashResults));
            $this->assertSame(1, $ready->collection()->count());
            $this->assertSame(1, FinancialTransaction::where('source_type', 'INVOICE_COLLECTION')->count());
            $this->assertSame($ready->grand_total, FinancialTransaction::where('source_type', 'INVOICE_COLLECTION')->sole()->entries()->sole()->amount);
        });
    }

    private function context(): array
    {
        $currency = Currency::create(['name' => 'Dollar', 'code' => 'USD', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create();
        $to = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Race Customer']);
        $phone = $customer->phones()->create(['phone' => '991', 'normalized_phone' => '991']);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Race Street']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Race Shop', 'sort_order' => 1]);
        $order->update(['pricing_class' => OrderPricingClass::SingleShop]);
        OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime, 'product_name_snapshot' => 'Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1', 'current_quantity' => '1', 'actual_unit_price' => '10', 'actual_price_source' => ActualPriceSource::ManualVerified, 'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $employee->id]);
        app(OrderPricingService::class)->recalculate($order);
        $captainUser = User::factory()->create();
        $captain = Captain::create(['user_id' => $captainUser->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        app(OrderCaptainService::class)->assign($employee, $order, $captain, OrderCaptainRole::PrimaryCollection);
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id, 'currency_id' => $currency->id, 'order_pricing_class' => OrderPricingClass::SingleShop->value, 'delivery_unit_fee' => '5', 'effective_from' => now()->subDay()]);
        app(DeliveryPricingSettings::class)->update($admin, '65', '0');
        app(OrderDeliveryPricingService::class)->price($employee, $order, $from);

        return compact('currency', 'employee', 'order');
    }

    private function race(string $operation, int $id, int $orderId, array $extra = []): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-order-finance-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $goPath = $directory.DIRECTORY_SEPARATOR.'go';
        $tag = 'order-finance-'.bin2hex(random_bytes(6));
        $workers = [];
        DB::beginTransaction();
        DB::table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
        try {
            foreach ([0, 1] as $index) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$index}.json";
                $readyPath = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                file_put_contents($payloadPath, json_encode(compact('operation', 'id') + $extra + ['worker_tag' => $tag], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/order_financial_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'resultPath');
            }
            $deadline = microtime(true) + 10;
            while (! collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])) && microtime(true) < $deadline) {
                usleep(25_000);
            }
            touch($goPath);
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) total FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$tag])->total;
                if ($blocked === 2) {
                    break;
                }
                usleep(25_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both production workers must contend on the Order lock.');
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
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function withIsolatedSchema(callable $callback): void
    {
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'order_finance_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            $callback();
        } finally {
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    private function workerEnvironment(): array
    {
        $database = config('database.connections.'.config('database.default'));

        return ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SCHEMA' => (string) $database['search_path']];
    }
}
