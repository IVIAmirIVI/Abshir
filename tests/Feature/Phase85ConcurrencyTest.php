<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderPricingStatus;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class Phase85ConcurrencyTest extends TestCase
{
    public function test_two_real_captain_rating_services_serialize_and_return_a_controlled_conflict(): void
    {
        $this->requirePostgresConcurrency();
        $fixture = $this->fixture();
        $captain = $this->captain($fixture['branch']);
        app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $fixture['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();

        try {
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'rating', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'captain_id' => $captain->id, 'rating' => 5],
                ['operation' => 'rating', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'captain_id' => $captain->id, 'rating' => 4],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $this->assertSame(1, DB::table('captain_ratings')->where(['order_id' => $fixture['order']->id, 'captain_id' => $captain->id])->count());
            $conflict = collect($results)->firstWhere('status', 'validation');
            $this->assertSame('This Captain already has a rating for the Order.', $conflict['errors']['captain_id'][0] ?? null);
        } finally {
            DB::table('captain_ratings')->where('order_id', $fixture['order']->id)->delete();
            DB::table('order_captains')->where('order_id', $fixture['order']->id)->delete();
            $this->deleteCaptain($captain);
            $this->cleanup($fixture);
        }
    }

    public function test_real_cancellation_and_delivery_services_cannot_both_finalize_the_order(): void
    {
        $this->requirePostgresConcurrency();
        $fixture = $this->fixture();
        $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Purchased', 'sort_order' => 1, 'picked_up_at' => now()]);
        $receiptId = DB::table('order_shop_receipts')->insertGetId(['order_shop_id' => $shop->id, 'storage_path' => 'concurrency/'.$fixture['order']->id.'/receipt.png', 'original_filename' => 'receipt.png', 'mime_type' => 'image/png', 'file_size' => 68, 'uploaded_by' => $fixture['actor']->id, 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $fixture['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();

        try {
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_after', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
                ['operation' => 'deliver', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $status = $fixture['order']->fresh()->operational_status;
            $cancelled = DB::table('order_cancellations')->where('order_id', $fixture['order']->id)->exists();
            $this->assertContains($status, [OrderOperationalStatus::Cancelled, OrderOperationalStatus::Delivered]);
            $this->assertSame($status === OrderOperationalStatus::Cancelled, $cancelled);
        } finally {
            DB::table('order_cancellations')->where('order_id', $fixture['order']->id)->delete();
            DB::table('order_shop_receipts')->where('id', $receiptId)->delete();
            DB::table('order_shops')->where('id', $shop->id)->delete();
            $this->cleanup($fixture);
        }
    }

    public function test_real_cancellation_and_invoice_issue_services_cannot_both_succeed(): void
    {
        $this->requirePostgresConcurrency();
        $fixture = $this->fixture();
        $invoiceFixture = $this->readyInvoice($fixture);

        try {
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_before', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
                ['operation' => 'issue', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'invoice_id' => $invoiceFixture['invoice']->id],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $cancelled = $fixture['order']->fresh()->operational_status === OrderOperationalStatus::Cancelled;
            $issued = $invoiceFixture['invoice']->fresh()->status === InvoiceStatus::Issued;
            $this->assertNotSame($cancelled, $issued);
        } finally {
            $this->cleanupInvoiceFixture($fixture, $invoiceFixture);
            $this->cleanup($fixture);
        }
    }

    public function test_real_before_purchase_cancellation_and_receipt_services_cannot_both_succeed(): void
    {
        $this->requirePostgresConcurrency();
        $fixture = $this->fixture();
        $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Not purchased', 'sort_order' => 1]);

        try {
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_before', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
                ['operation' => 'receipt', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $beforePurchase = DB::table('order_cancellations')->where(['order_id' => $fixture['order']->id, 'classification' => 'BEFORE_PURCHASE'])->exists();
            $receiptExists = DB::table('order_shop_receipts')->where('order_shop_id', $shop->id)->exists();
            $this->assertNotSame($beforePurchase, $receiptExists);
        } finally {
            foreach (DB::table('order_shop_receipts')->where('order_shop_id', $shop->id)->pluck('storage_path') as $path) {
                Storage::disk('order_receipts')->delete($path);
            }
            DB::table('order_cancellations')->where('order_id', $fixture['order']->id)->delete();
            DB::table('order_shop_receipts')->where('order_shop_id', $shop->id)->delete();
            DB::table('order_shops')->where('id', $shop->id)->delete();
            $this->cleanup($fixture);
        }
    }

    private function raceServices(Order $order, array $jobs): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-phase85-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $goPath = $directory.DIRECTORY_SEPARATOR.'go';
        $processes = [];

        DB::beginTransaction();

        try {
            DB::table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();

            foreach ($jobs as $index => $job) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$index}.json";
                $readyPath = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                file_put_contents($payloadPath, json_encode($job, JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/phase85_service_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $processes[] = compact('process', 'readyPath', 'resultPath');
            }

            $this->waitForWorkersReady($processes);
            touch($goPath);
            usleep(500_000);
            foreach ($processes as $worker) {
                $this->assertTrue($worker['process']->isRunning(), 'A service worker did not wait for the locked Order. '.$worker['process']->getErrorOutput());
            }

            DB::commit();
            $results = [];
            foreach ($processes as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput().$worker['process']->getOutput());
                $this->assertFileExists($worker['resultPath']);
                $results[] = json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $worker) {
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

    private function waitForWorkersReady(array $workers): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (collect($workers)->every(fn (array $worker): bool => file_exists($worker['readyPath']))) {
                return;
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isTerminated()) {
                    $this->fail('A service worker exited before the start barrier. '.$worker['process']->getErrorOutput().$worker['process']->getOutput());
                }
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for real Laravel service workers.');
    }

    private function assertOneSuccessAndOneValidation(array $results): void
    {
        $this->assertSame(['success' => 1, 'validation' => 1], collect($results)->countBy('status')->sortKeys()->all(), json_encode($results, JSON_PRETTY_PRINT));
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

    private function readyInvoice(array $fixture): array
    {
        $currency = Currency::create(['name' => 'Race Currency', 'code' => 'R'.random_int(10, 99), 'decimal_places' => 2]);
        $fixture['branch']->update(['default_currency_id' => $currency->id]);
        $origin = Area::factory()->for($fixture['branch'])->create(['name' => 'Race Origin']);
        $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Invoice Race Shop', 'sort_order' => 1]);
        $item = OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime, 'product_name_snapshot' => 'Race Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1', 'current_quantity' => '1', 'actual_unit_price' => '10.00', 'actual_price_source' => ActualPriceSource::ManualVerified, 'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $fixture['actor']->id]);
        $fixture['order']->forceFill(['pricing_class' => OrderPricingClass::SingleShop, 'pricing_status' => OrderPricingStatus::Confirmed])->save();
        $captain = $this->captain($fixture['branch']);
        app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $pricing = $fixture['order']->deliveryPricings()->create(['version' => 1, 'from_area_id' => $origin->id, 'to_area_id' => $fixture['area']->id, 'currency_id' => $currency->id, 'order_pricing_class_snapshot' => OrderPricingClass::SingleShop, 'suggested_unit_fee' => '5.00', 'applied_unit_fee' => '5.00', 'captain_count_snapshot' => 1, 'captain_share_percent_snapshot' => '65.00', 'gross_delivery_total' => '5.00', 'discount_amount' => '0', 'customer_delivery_total' => '5.00', 'status' => 'PRICED', 'priced_at' => now(), 'created_by' => $fixture['actor']->id]);
        $invoice = app(InvoiceService::class)->createDraft($fixture['actor'], $fixture['order']);
        $invoice = app(InvoiceService::class)->refresh($fixture['actor'], $fixture['order'], $invoice);

        return compact('currency', 'origin', 'shop', 'item', 'captain', 'pricing', 'invoice');
    }

    private function cleanupInvoiceFixture(array $fixture, array $invoiceFixture): void
    {
        DB::table('order_cancellations')->where('order_id', $fixture['order']->id)->delete();
        DB::table('invoice_items')->where('invoice_id', $invoiceFixture['invoice']->id)->delete();
        Invoice::query()->whereKey($invoiceFixture['invoice']->id)->delete();
        DB::table('order_delivery_pricings')->where('order_id', $fixture['order']->id)->delete();
        DB::table('order_items')->where('order_shop_id', $invoiceFixture['shop']->id)->delete();
        DB::table('order_shops')->where('id', $invoiceFixture['shop']->id)->delete();
        DB::table('order_captains')->where('order_id', $fixture['order']->id)->delete();
        $this->deleteCaptain($invoiceFixture['captain']);
        $fixture['branch']->update(['default_currency_id' => null]);
        DB::table('areas')->where('id', $invoiceFixture['origin']->id)->delete();
        DB::table('currencies')->where('id', $invoiceFixture['currency']->id)->delete();
    }

    private function fixture(): array
    {
        Artisan::call('migrate', ['--force' => true]);
        $this->seed(AuthorizationSeeder::class);
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Phase 8.5 concurrency']);
        $phone = $customer->phones()->create(['phone' => uniqid('96'), 'normalized_phone' => uniqid('96')]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Concurrency street']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $order = app(OrderService::class)->create($actor, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);

        return compact('branch', 'area', 'customer', 'phone', 'address', 'actor', 'order');
    }

    private function captain(Branch $branch): Captain
    {
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $captain;
    }

    private function deleteCaptain(Captain $captain): void
    {
        $userId = $captain->user_id;
        DB::table('captain_employment_periods')->where('captain_id', $captain->id)->delete();
        DB::table('captains')->where('id', $captain->id)->delete();
        DB::table('users')->where('id', $userId)->delete();
    }

    private function cleanup(array $fixture): void
    {
        DB::table('order_status_history')->where('order_id', $fixture['order']->id)->delete();
        DB::table('orders')->where('id', $fixture['order']->id)->delete();
        DB::table('user_roles')->where('user_id', $fixture['actor']->id)->delete();
        DB::table('user_branches')->where('user_id', $fixture['actor']->id)->delete();
        DB::table('users')->where('id', $fixture['actor']->id)->delete();
        DB::table('customer_addresses')->where('customer_id', $fixture['customer']->id)->delete();
        DB::table('customer_phones')->where('customer_id', $fixture['customer']->id)->delete();
        DB::table('customers')->where('id', $fixture['customer']->id)->delete();
        DB::table('areas')->where('id', $fixture['area']->id)->delete();
        DB::table('branches')->where('id', $fixture['branch']->id)->delete();
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! class_exists(Process::class)) {
            $this->markTestSkipped('This locking regression test requires PostgreSQL and independent PHP worker processes.');
        }
    }
}
