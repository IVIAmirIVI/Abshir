<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCaptainRole;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderCaptainService;
use App\Services\OrderService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PgSql\Connection;
use Tests\TestCase;

class OrderCaptainConcurrencyTest extends TestCase
{
    public function test_primary_removal_serializes_with_concurrent_support_assignment_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pg_send_query')) {
            $this->markTestSkipped('This locking regression test requires PostgreSQL async-query support.');
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->seed(AuthorizationSeeder::class);

        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Concurrency Customer']);
        $phone = $customer->phones()->create(['phone' => '963900000001', 'normalized_phone' => '963900000001']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Concurrency street']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $primaryCaptain = $this->createCaptain($branch);
        $supportCaptain = $this->createCaptain($branch);
        $order = app(OrderService::class)->create($actor, [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id,
            'customer_address_id' => $address->id,
            'source' => 'MANUAL',
            'entry_mode' => 'LIVE',
            'ordered_at' => now(),
        ]);
        $service = app(OrderCaptainService::class);
        $primary = $service->assign($actor, $order, $primaryCaptain, OrderCaptainRole::PrimaryCollection);
        $concurrent = $this->openConcurrentConnection();

        try {
            DB::beginTransaction();
            $service->remove($actor, $order, $primary, 'Concurrent removal');

            $sql = sprintf(
                "BEGIN; SELECT id FROM orders WHERE id = %d FOR UPDATE; INSERT INTO order_captains (order_id, captain_id, assignment_role, assigned_at, assigned_by, created_at, updated_at) SELECT %d, %d, 'SUPPORT', CURRENT_TIMESTAMP, %d, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP WHERE EXISTS (SELECT 1 FROM order_captains WHERE order_id = %d AND removed_at IS NULL AND assignment_role = 'PRIMARY_COLLECTION'); COMMIT;",
                $order->id,
                $order->id,
                $supportCaptain->id,
                $actor->id,
                $order->id,
            );
            $this->assertTrue(pg_send_query($concurrent, $sql));
            usleep(300_000);
            pg_consume_input($concurrent);
            $this->assertTrue(pg_connection_busy($concurrent), 'Concurrent Support assignment did not wait for the parent Order lock.');

            DB::commit();
            $this->waitForConcurrentQuery($concurrent);

            $this->assertSame(0, $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::Support->value)->count());
            $this->assertSame(0, $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            pg_close($concurrent);
            $this->deleteFixtures($order->id, [$primaryCaptain->id, $supportCaptain->id], [$actor->id, $primaryCaptain->user_id, $supportCaptain->user_id], $customer->id, $area->id, $branch->id);
        }
    }

    private function createCaptain(Branch $branch): Captain
    {
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $captain;
    }

    private function openConcurrentConnection(): Connection
    {
        $connection = config('database.connections.'.config('database.default'));
        $connectionString = sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5',
            $connection['host'],
            $connection['port'],
            $connection['database'],
            $connection['username'],
            $connection['password'],
        );
        $postgres = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
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
        $this->assertFalse(pg_connection_busy($connection), 'Concurrent PostgreSQL transaction did not finish after the Order lock was released.');

        while ($result = pg_get_result($connection)) {
            $this->assertNotSame(PGSQL_FATAL_ERROR, pg_result_status($result), pg_result_error($result));
        }
    }

    private function deleteFixtures(int $orderId, array $captainIds, array $userIds, int $customerId, int $areaId, int $branchId): void
    {
        DB::table('order_item_changes')->whereIn('order_item_id', DB::table('order_items')->select('id')->whereIn('order_shop_id', DB::table('order_shops')->select('id')->where('order_id', $orderId)))->delete();
        DB::table('order_items')->whereIn('order_shop_id', DB::table('order_shops')->select('id')->where('order_id', $orderId))->delete();
        DB::table('order_shops')->where('order_id', $orderId)->delete();
        DB::table('order_status_history')->where('order_id', $orderId)->delete();
        DB::table('order_captains')->where('order_id', $orderId)->delete();
        DB::table('orders')->where('id', $orderId)->delete();
        DB::table('captain_employment_periods')->whereIn('captain_id', $captainIds)->delete();
        DB::table('captain_branch_assignments')->whereIn('captain_id', $captainIds)->delete();
        DB::table('captains')->whereIn('id', $captainIds)->delete();
        DB::table('user_roles')->whereIn('user_id', $userIds)->delete();
        DB::table('user_branches')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        DB::table('customer_addresses')->where('customer_id', $customerId)->delete();
        DB::table('customer_phones')->where('customer_id', $customerId)->delete();
        DB::table('customers')->where('id', $customerId)->delete();
        DB::table('areas')->where('id', $areaId)->delete();
        DB::table('branches')->where('id', $branchId)->delete();
    }
}
