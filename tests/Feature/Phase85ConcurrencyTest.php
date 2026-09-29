<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCancellationReason;
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
use App\Models\OrderShopPurchaseConfirmation;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\OrderCancellationFinalizationService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCaptainService;
use App\Services\OrderService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Purchased', 'sort_order' => 1, 'picked_up_at' => now()]);
            DB::table('order_shop_receipts')->insert(['order_shop_id' => $shop->id, 'storage_path' => 'concurrency/'.$fixture['order']->id.'/receipt.png', 'original_filename' => 'receipt.png', 'mime_type' => 'image/png', 'file_size' => 68, 'uploaded_by' => $fixture['actor']->id, 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $currency = Currency::create(['name' => 'Purchase currency', 'code' => 'PCR', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            OrderShopPurchaseConfirmation::create(['order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id,
                'order_captain_id' => $assignment->id, 'branch_id' => $fixture['branch']->id, 'currency_id' => $currency->id,
                'amount' => '10.00', 'purchased_at' => now()->subMinute(), 'recorded_by' => $fixture['actor']->id,
                'request_key' => (string) Str::uuid(), 'evidence_path' => 'test/purchase.png', 'evidence_name' => 'purchase.png',
                'evidence_mime' => 'image/png', 'evidence_size' => 68, 'evidence_sha256' => str_repeat('a', 64)]);
            $fixture['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();

            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_after', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'request_key' => (string) Str::uuid()],
                ['operation' => 'deliver', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $status = $fixture['order']->fresh()->operational_status;
            $intent = DB::table('order_cancellation_intents')->where('order_id', $fixture['order']->id)->exists();
            $this->assertContains($status, [OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered]);
            $this->assertSame($status !== OrderOperationalStatus::Delivered, $intent);
        });
    }

    public function test_real_cancellation_and_invoice_issue_services_cannot_both_succeed(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $invoiceFixture = $this->readyInvoice($fixture);
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_before', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
                ['operation' => 'issue', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'invoice_id' => $invoiceFixture['invoice']->id],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $cancelled = $fixture['order']->fresh()->operational_status === OrderOperationalStatus::Cancelled;
            $issued = $invoiceFixture['invoice']->fresh()->status === InvoiceStatus::Issued;
            $this->assertNotSame($cancelled, $issued);
        });
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

    public function test_purchase_vs_intent_freezes_the_exact_committed_purchase_set(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $currency = Currency::create(['name' => 'Return currency', 'code' => 'RTC', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            $first = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'First', 'sort_order' => 1]);
            $second = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Second', 'sort_order' => 2]);
            $this->confirmedPurchase($fixture, $first, $assignment->id, $currency->id);

            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'purchase', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'order_shop_id' => $second->id, 'order_captain_id' => $assignment->id, 'currency_id' => $currency->id,
                    'request_key' => (string) Str::uuid()],
                ['operation' => 'cancel_after', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'request_key' => (string) Str::uuid()],
            ]);
            $this->assertNotContains('error', array_column($results, 'status'), json_encode($results));
            $this->assertSame('success', $results[1]['status']);
            $intent = DB::table('order_cancellation_intents')->where('order_id', $fixture['order']->id)->first();
            $this->assertNotNull($intent);
            $purchases = DB::table('order_shop_purchase_confirmations')->where('order_id', $fixture['order']->id)->pluck('id')->sort()->values()->all();
            $required = DB::table('order_cancellation_intent_shops')->where('order_cancellation_intent_id', $intent->id)
                ->pluck('purchase_confirmation_id')->sort()->values()->all();
            $this->assertSame($purchases, $required);
            $this->assertSame($results[0]['status'] === 'success' ? 2 : 1, count($purchases));
            foreach (DB::table('order_shop_purchase_confirmations')->where('order_shop_id', $second->id)->pluck('evidence_path') as $path) {
                Storage::disk('order_returns')->delete($path);
            }
        });
    }

    public function test_duplicate_purchase_confirmation_uses_one_authoritative_source(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $currency = Currency::create(['name' => 'Purchase currency', 'code' => 'PCD', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'One purchase', 'sort_order' => 1]);
            $job = fn (): array => ['operation' => 'purchase', 'actor_id' => $fixture['actor']->id,
                'order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id,
                'order_captain_id' => $assignment->id, 'currency_id' => $currency->id,
                'request_key' => (string) Str::uuid()];
            $results = $this->raceServices($fixture['order'], [$job(), $job()]);
            $this->assertOneSuccessAndOneValidation($results);
            $this->assertSame(1, DB::table('order_shop_purchase_confirmations')->where('order_shop_id', $shop->id)->count());
            foreach (DB::table('order_shop_purchase_confirmations')->where('order_shop_id', $shop->id)->pluck('evidence_path') as $path) {
                Storage::disk('order_returns')->delete($path);
            }
        });
    }

    public function test_real_after_purchase_intent_and_invoice_issue_cannot_both_succeed(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $ready = $this->readyInvoice($fixture);
            $assignmentId = $fixture['order']->captainAssignments()->value('id');
            $this->confirmedPurchase($fixture, $ready['shop'], $assignmentId, $ready['currency']->id);
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_after', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'request_key' => (string) Str::uuid()],
                ['operation' => 'issue', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'invoice_id' => $ready['invoice']->id],
            ]);
            $this->assertOneSuccessAndOneValidation($results);
            $intent = DB::table('order_cancellation_intents')->where('order_id', $fixture['order']->id)->exists();
            $issued = $ready['invoice']->fresh()->status === InvoiceStatus::Issued;
            $this->assertNotSame($intent, $issued);
        });
    }

    public function test_duplicate_return_and_last_return_vs_finalization_use_real_services(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $currency = Currency::create(['name' => 'Return currency', 'code' => 'RTD', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Purchased', 'sort_order' => 1]);
            $this->confirmedPurchase($fixture, $shop, $assignment->id, $currency->id);
            app(OrderCancellationIntentService::class)->create($fixture['actor'], $fixture['order'],
                OrderCancellationReason::CustomerCancelled, 'Concurrent cancellation', (string) Str::uuid());

            $returnJob = fn (): array => ['operation' => 'return', 'actor_id' => $fixture['actor']->id,
                'order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id,
                'order_captain_id' => $assignment->id, 'request_key' => (string) Str::uuid()];
            $results = $this->raceServices($fixture['order'], [$returnJob(), $returnJob()]);
            $this->assertOneSuccessAndOneValidation($results);
            $this->assertSame(1, DB::table('order_shop_returns')->where('order_id', $fixture['order']->id)->count());
            app(OrderCancellationFinalizationService::class)->finalize($fixture['actor'], $fixture['order']);
            $this->assertSame(OrderOperationalStatus::Cancelled, $fixture['order']->fresh()->operational_status);
            $this->deleteReturnEvidence($fixture['order']);
        });

        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $currency = Currency::create(['name' => 'Return currency', 'code' => 'RTL', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Purchased', 'sort_order' => 1]);
            $this->confirmedPurchase($fixture, $shop, $assignment->id, $currency->id);
            app(OrderCancellationIntentService::class)->create($fixture['actor'], $fixture['order'],
                OrderCancellationReason::CustomerCancelled, 'Concurrent cancellation', (string) Str::uuid());
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'return', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'order_shop_id' => $shop->id, 'order_captain_id' => $assignment->id, 'request_key' => (string) Str::uuid()],
                ['operation' => 'finalize', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
            ]);
            $this->assertSame('success', $results[0]['status'], json_encode($results));
            $this->assertContains($results[1]['status'], ['success', 'validation'], json_encode($results));
            $this->assertSame(1, DB::table('order_shop_returns')->where('order_id', $fixture['order']->id)->count());
            if ($results[1]['status'] === 'validation') {
                app(OrderCancellationFinalizationService::class)->finalize($fixture['actor'], $fixture['order']);
            }
            $this->assertSame(OrderOperationalStatus::Cancelled, $fixture['order']->fresh()->operational_status);
            $this->deleteReturnEvidence($fixture['order']);
        });
    }

    private function deleteReturnEvidence(Order $order): void
    {
        foreach (DB::table('order_shop_return_evidence as e')->join('order_shop_returns as r', 'r.id', '=', 'e.order_shop_return_id')
            ->where('r.order_id', $order->id)->pluck('e.storage_path') as $path) {
            Storage::disk('order_returns')->delete($path);
        }
    }

    private function confirmedPurchase(array $fixture, OrderShop $shop, int $assignmentId, int $currencyId): void
    {
        DB::table('order_shop_purchase_confirmations')->insert([
            'order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id, 'order_captain_id' => $assignmentId,
            'branch_id' => $fixture['branch']->id, 'currency_id' => $currencyId, 'amount' => '10.00',
            'purchased_at' => DB::raw("clock_timestamp() - interval '5 minutes'"), 'recorded_by' => $fixture['actor']->id,
            'request_key' => (string) Str::uuid(), 'evidence_path' => 'test/'.Str::uuid().'.png',
            'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png', 'evidence_size' => 68,
            'evidence_sha256' => str_repeat('a', 64), 'created_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
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
        $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $pricing = $fixture['order']->deliveryPricings()->create(['version' => 1, 'from_area_id' => $origin->id, 'to_area_id' => $fixture['area']->id, 'currency_id' => $currency->id, 'order_pricing_class_snapshot' => OrderPricingClass::SingleShop, 'suggested_unit_fee' => '5.00', 'applied_unit_fee' => '5.00', 'captain_count_snapshot' => 1, 'captain_share_percent_snapshot' => '65.00', 'gross_delivery_total' => '5.00', 'discount_amount' => '0', 'customer_delivery_total' => '5.00', 'status' => 'PRICED', 'priced_at' => now(), 'created_by' => $fixture['actor']->id]);
        $pricing->allocations()->create(['order_captain_id' => $assignment->id, 'captain_id' => $captain->id,
            'currency_id' => $currency->id, 'applied_unit_fee' => '5.00', 'captain_share_percent_snapshot' => '65.00',
            'captain_entitlement' => '3.25', 'discount_allocated' => '0', 'customer_delivery_amount_allocated' => '5.00',
            'company_collectible' => '1.75', 'captain_shortfall' => '0']);
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

    private function withIsolatedSchema(callable $callback): void
    {
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'phase85_finance_'.str()->lower(str()->random(12));
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
}
