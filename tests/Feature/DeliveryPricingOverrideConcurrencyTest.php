<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\DeliveryOverrideStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderPricingClass;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryFeeOverrideRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderService;
use App\Services\OrderShopService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PgSql\Connection;
use Tests\TestCase;

class DeliveryPricingOverrideConcurrencyTest extends TestCase
{
    public function test_override_threshold_uses_composition_committed_before_the_order_lock_is_acquired(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pg_send_query')) {
            $this->markTestSkipped('This locking regression test requires PostgreSQL async-query support.');
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['name' => 'Concurrency Currency', 'code' => 'CCY', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create(['name' => 'Concurrency Origin']);
        $to = Area::factory()->for($branch)->create(['name' => 'Concurrency Destination']);
        $customer = Customer::create(['name' => 'Override concurrency']);
        $phone = $customer->phones()->create(['phone' => '963900000099', 'normalized_phone' => '963900000099']);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Concurrency street']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $captainUser = User::factory()->create();
        $captain = Captain::create(['user_id' => $captainUser->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE', 'ordered_at' => now()]);
        app(OrderShopService::class)->add($employee, $order, ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'First']);
        app(OrderCaptainService::class)->assign($employee, $order, $captain, OrderCaptainRole::PrimaryCollection);
        foreach ([OrderPricingClass::SingleShop->value => '100', OrderPricingClass::MultiShop->value => '200'] as $class => $fee) {
            app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id, 'currency_id' => $currency->id, 'order_pricing_class' => $class, 'delivery_unit_fee' => $fee, 'effective_from' => now()->subDay()]);
        }
        app(DeliveryPricingSettings::class)->update($admin, '65', '10');
        $concurrent = $this->openConcurrentConnection();

        try {
            $sql = sprintf(
                "BEGIN; SELECT id FROM orders WHERE id = %d FOR UPDATE; INSERT INTO order_shops (order_id, shop_type, business_classification_snapshot, shop_name_snapshot, sort_order, created_at, updated_at) VALUES (%d, 'ONE_TIME', 'RESTAURANT', 'Concurrent second', 2, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP); UPDATE orders SET pricing_class = 'MULTI_SHOP', updated_at = CURRENT_TIMESTAMP WHERE id = %d; SELECT pg_sleep(0.5); COMMIT;",
                $order->id,
                $order->id,
                $order->id,
            );
            $this->assertTrue(pg_send_query($concurrent, $sql));
            usleep(150_000);
            pg_consume_input($concurrent);
            $this->assertTrue(pg_connection_busy($concurrent), 'The composition transaction did not hold the parent Order lock.');

            $result = app(OrderDeliveryPricingService::class)->requestOverride($employee, $order, $from, '105', 'Concurrent threshold check');
            $this->waitForConcurrentQuery($concurrent);

            $this->assertInstanceOf(DeliveryFeeOverrideRequest::class, $result);
            $this->assertSame(DeliveryOverrideStatus::Pending, $result->status);
            $this->assertSame(OrderPricingClass::MultiShop, $result->order_pricing_class_snapshot);
            $this->assertSame('200.0000', $result->suggested_unit_fee);
            $this->assertSame('47.5000', $result->difference_percent);
            $this->assertNull($order->currentDeliveryPricing()->first());
        } finally {
            while (pg_connection_busy($concurrent)) {
                pg_consume_input($concurrent);
                usleep(25_000);
            }
            while (pg_get_result($concurrent)) {
            }
            pg_close($concurrent);
            $this->deleteFixtures($order->id, $captain->id, [$admin->id, $employee->id, $captainUser->id], $customer->id, [$from->id, $to->id], $branch->id, $currency->id);
        }
    }

    private function openConcurrentConnection(): Connection
    {
        $connection = config('database.connections.'.config('database.default'));
        $postgres = pg_connect(sprintf('host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5', $connection['host'], $connection['port'], $connection['database'], $connection['username'], $connection['password']), PGSQL_CONNECT_FORCE_NEW);
        $this->assertInstanceOf(Connection::class, $postgres);
        $schema = str_replace('"', '""', (string) config('database.connections.'.config('database.default').'.search_path'));
        $this->assertNotFalse(pg_query($postgres, 'SET search_path TO "'.$schema.'"'));

        return $postgres;
    }

    private function waitForConcurrentQuery(Connection $connection): void
    {
        $deadline = microtime(true) + 10;
        do {
            pg_consume_input($connection);
            if (! pg_connection_busy($connection)) {
                break;
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);
        $this->assertFalse(pg_connection_busy($connection), 'Concurrent composition transaction did not finish.');
        while ($result = pg_get_result($connection)) {
            $this->assertNotSame(PGSQL_FATAL_ERROR, pg_result_status($result), pg_result_error($result));
        }
    }

    private function deleteFixtures(int $orderId, int $captainId, array $userIds, int $customerId, array $areaIds, int $branchId, int $currencyId): void
    {
        DB::table('delivery_fee_override_requests')->where('order_id', $orderId)->delete();
        DB::table('order_delivery_allocations')->whereIn('order_delivery_pricing_id', DB::table('order_delivery_pricings')->select('id')->where('order_id', $orderId))->delete();
        DB::table('order_delivery_pricings')->where('order_id', $orderId)->delete();
        DB::table('delivery_pricing_rules')->whereIn('from_area_id', $areaIds)->delete();
        DB::table('order_captains')->where('order_id', $orderId)->delete();
        DB::table('order_shops')->where('order_id', $orderId)->delete();
        DB::table('order_status_history')->where('order_id', $orderId)->delete();
        DB::table('orders')->where('id', $orderId)->delete();
        DB::table('captain_employment_periods')->where('captain_id', $captainId)->delete();
        DB::table('captains')->where('id', $captainId)->delete();
        DB::table('user_roles')->whereIn('user_id', $userIds)->delete();
        DB::table('user_branches')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        DB::table('customer_addresses')->where('customer_id', $customerId)->delete();
        DB::table('customer_phones')->where('customer_id', $customerId)->delete();
        DB::table('customers')->where('id', $customerId)->delete();
        DB::table('areas')->whereIn('id', $areaIds)->delete();
        DB::table('branches')->where('id', $branchId)->delete();
        DB::table('currencies')->where('id', $currencyId)->delete();
    }
}
