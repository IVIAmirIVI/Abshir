<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PgSql\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceConcurrencyTest extends TestCase
{
    public function test_current_invoice_creation_serializes_on_the_order_row_in_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pg_send_query')) {
            $this->markTestSkipped('This locking regression test requires PostgreSQL async-query support.');
        }
        Artisan::call('migrate', ['--force' => true]);
        $this->seed(AuthorizationSeeder::class);
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Invoice concurrency']);
        $phone = $customer->phones()->create(['phone' => '963900000002', 'normalized_phone' => '963900000002']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Concurrency street']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $order = app(OrderService::class)->create($actor, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE', 'ordered_at' => now()]);
        $concurrent = $this->openConcurrentConnection();

        try {
            DB::beginTransaction();
            app(InvoiceService::class)->createDraft($actor, $order);
            $sql = sprintf(
                "BEGIN; SELECT id FROM orders WHERE id = %d FOR UPDATE; INSERT INTO invoices (order_id, invoice_number, version, status, customer_name_snapshot, customer_phone_snapshot, address_snapshot, order_number_snapshot, ordered_at_snapshot, created_at, updated_at) SELECT %d, 'INV-CONCURRENT-%d', 2, 'DRAFT', 'Concurrent', '1', 'Address', '%s', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP WHERE NOT EXISTS (SELECT 1 FROM invoices WHERE order_id = %d AND superseded_at IS NULL); COMMIT;",
                $order->id,
                $order->id,
                $order->id,
                $order->order_number,
                $order->id,
            );
            $this->assertTrue(pg_send_query($concurrent, $sql));
            usleep(300_000);
            pg_consume_input($concurrent);
            $this->assertTrue(pg_connection_busy($concurrent), 'Concurrent Invoice creation did not wait for the Order lock.');
            DB::commit();
            $this->waitForConcurrentQuery($concurrent);
            $this->assertSame(1, Invoice::where('order_id', $order->id)->whereNull('superseded_at')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            pg_close($concurrent);
            DB::table('invoices')->where('order_id', $order->id)->delete();
            DB::table('order_status_history')->where('order_id', $order->id)->delete();
            DB::table('orders')->where('id', $order->id)->delete();
            DB::table('user_roles')->where('user_id', $actor->id)->delete();
            DB::table('user_branches')->where('user_id', $actor->id)->delete();
            DB::table('users')->where('id', $actor->id)->delete();
            DB::table('customer_addresses')->where('customer_id', $customer->id)->delete();
            DB::table('customer_phones')->where('customer_id', $customer->id)->delete();
            DB::table('customers')->where('id', $customer->id)->delete();
            DB::table('areas')->where('id', $area->id)->delete();
            DB::table('branches')->where('id', $branch->id)->delete();
        }
    }

    public static function collectionMethods(): array
    {
        return [[InvoiceCollectionMethod::Cash], [InvoiceCollectionMethod::ShamCash]];
    }

    #[DataProvider('collectionMethods')]
    public function test_concurrent_collection_method_cannot_create_two_collections(InvoiceCollectionMethod $method): void
    {
        $this->requirePostgresConcurrency();
        $fixture = $this->issuedFixture();
        $concurrent = $this->openConcurrentConnection();

        try {
            DB::beginTransaction();
            app(InvoiceCollectionService::class)->record($fixture['actor'], $fixture['invoice'], $method, '30', $fixture['currency']->id, now());
            $captainId = $method === InvoiceCollectionMethod::Cash ? (string) $fixture['assignment']->captain_id : 'NULL';
            $assignmentId = $method === InvoiceCollectionMethod::Cash ? (string) $fixture['assignment']->id : 'NULL';
            $sql = sprintf(
                "BEGIN; SELECT id FROM orders WHERE id = %d FOR UPDATE; SELECT id FROM invoices WHERE id = %d FOR UPDATE; INSERT INTO invoice_collections (invoice_id, order_id, captain_id, order_captain_id, collection_method, currency_id, amount, collected_at, recorded_by, created_at, updated_at) SELECT %d, %d, %s, %s, '%s', %d, 30, CURRENT_TIMESTAMP, %d, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP WHERE NOT EXISTS (SELECT 1 FROM invoice_collections WHERE invoice_id = %d); COMMIT;",
                $fixture['order']->id,
                $fixture['invoice']->id,
                $fixture['invoice']->id,
                $fixture['order']->id,
                $captainId,
                $assignmentId,
                $method->value,
                $fixture['currency']->id,
                $fixture['actor']->id,
                $fixture['invoice']->id,
            );
            $this->assertTrue(pg_send_query($concurrent, $sql));
            usleep(300_000);
            pg_consume_input($concurrent);
            $this->assertTrue(pg_connection_busy($concurrent), 'Concurrent collection did not wait for the Order lock.');
            DB::commit();
            $this->waitForConcurrentQuery($concurrent);
            $this->assertSame(1, DB::table('invoice_collections')->where('invoice_id', $fixture['invoice']->id)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            pg_close($concurrent);
            $this->cleanupFixture($fixture);
        }
    }

    public static function invoiceConflicts(): array
    {
        return [['VOIDED'], ['REPLACED']];
    }

    #[DataProvider('invoiceConflicts')]
    public function test_concurrent_collection_prevents_void_or_replacement(string $targetStatus): void
    {
        $this->requirePostgresConcurrency();
        $fixture = $this->issuedFixture();
        $concurrent = $this->openConcurrentConnection();

        try {
            DB::beginTransaction();
            app(InvoiceCollectionService::class)->record($fixture['actor'], $fixture['invoice'], InvoiceCollectionMethod::Cash, '30', $fixture['currency']->id, now());
            $sql = sprintf(
                "BEGIN; SELECT id FROM orders WHERE id = %d FOR UPDATE; SELECT id FROM invoices WHERE id = %d FOR UPDATE; SELECT id FROM invoice_collections WHERE invoice_id = %d FOR UPDATE; UPDATE invoices SET status = '%s', superseded_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = %d AND NOT EXISTS (SELECT 1 FROM invoice_collections WHERE invoice_id = %d); COMMIT;",
                $fixture['order']->id,
                $fixture['invoice']->id,
                $fixture['invoice']->id,
                $targetStatus,
                $fixture['invoice']->id,
                $fixture['invoice']->id,
            );
            $this->assertTrue(pg_send_query($concurrent, $sql));
            usleep(300_000);
            pg_consume_input($concurrent);
            $this->assertTrue(pg_connection_busy($concurrent), 'Concurrent lifecycle change did not wait for the Order lock.');
            DB::commit();
            $this->waitForConcurrentQuery($concurrent);
            $this->assertSame(InvoiceStatus::Issued, $fixture['invoice']->fresh()->status);
            $this->assertSame(1, DB::table('invoice_collections')->where('invoice_id', $fixture['invoice']->id)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            pg_close($concurrent);
            $this->cleanupFixture($fixture);
        }
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pg_send_query')) {
            $this->markTestSkipped('This locking regression test requires PostgreSQL async-query support.');
        }
    }

    private function issuedFixture(): array
    {
        $connection = (string) config('database.default');
        $configurationKey = "database.connections.{$connection}.search_path";
        $originalSearchPath = (string) config($configurationKey);
        $schema = 'invoice_concurrency_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        config([$configurationKey => $schema]);
        DB::purge($connection);
        DB::reconnect($connection);
        Artisan::call('migrate', ['--force' => true]);
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['name' => 'Concurrency currency', 'code' => strtoupper(substr(bin2hex(random_bytes(2)), 0, 3)), 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Collection concurrency']);
        $phone = $customer->phones()->create(['phone' => uniqid('9'), 'normalized_phone' => uniqid('9')]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Concurrency street']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $order = app(OrderService::class)->create($actor, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE', 'ordered_at' => now()]);
        $captainUser = User::factory()->create();
        $captain = Captain::create(['user_id' => $captainUser->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $assignment = app(OrderCaptainService::class)->assign($actor, $order, $captain, OrderCaptainRole::PrimaryCollection);
        $invoice = $order->invoices()->create([
            'invoice_number' => 'INV-CONCURRENCY-'.strtoupper(bin2hex(random_bytes(4))),
            'version' => 1,
            'status' => InvoiceStatus::Issued,
            'currency_id' => $currency->id,
            'items_subtotal' => '25',
            'delivery_total' => '5',
            'grand_total' => '30',
            'customer_name_snapshot' => $customer->name,
            'customer_phone_snapshot' => $phone->phone,
            'address_snapshot' => $address->address_line_1,
            'order_number_snapshot' => $order->order_number,
            'ordered_at_snapshot' => $order->ordered_at,
            'issued_at' => now()->subMinute(),
            'issued_by' => $actor->id,
            'created_by' => $actor->id,
        ]);

        return compact('currency', 'branch', 'area', 'customer', 'phone', 'address', 'actor', 'order', 'captainUser', 'captain', 'assignment', 'invoice', 'connection', 'configurationKey', 'originalSearchPath', 'schema');
    }

    private function cleanupFixture(array $fixture): void
    {
        DB::purge($fixture['connection']);
        config([$fixture['configurationKey'] => $fixture['originalSearchPath']]);
        DB::reconnect($fixture['connection']);
        DB::statement('DROP SCHEMA IF EXISTS "'.$fixture['schema'].'" CASCADE');
    }

    private function openConcurrentConnection(): Connection
    {
        $configuration = config('database.connections.'.config('database.default'));
        $postgres = pg_connect(sprintf('host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5', $configuration['host'], $configuration['port'], $configuration['database'], $configuration['username'], $configuration['password']), PGSQL_CONNECT_FORCE_NEW);
        $this->assertInstanceOf(Connection::class, $postgres);
        $schema = str_replace('"', '""', (string) $configuration['search_path']);
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
        $this->assertFalse(pg_connection_busy($connection), 'Concurrent PostgreSQL transaction did not finish.');
        while ($result = pg_get_result($connection)) {
            $this->assertNotSame(PGSQL_FATAL_ERROR, pg_result_status($result), pg_result_error($result));
        }
    }
}
