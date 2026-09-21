<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderEntryMode;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderSource;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainRatingService;
use App\Services\InvoiceService;
use App\Services\OrderCancellationService;
use App\Services\OrderCaptainService;
use App\Services\OrderService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopService;
use App\Services\OrderStatusService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase85OrderExceptionsTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function contentUpload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'abshir-receipt-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function context(array $orderOverrides = []): array
    {
        $this->seed(AuthorizationSeeder::class);
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Phase 8.5 Customer']);
        $phone = $customer->phones()->create(['phone' => '963900008500', 'normalized_phone' => '963900008500']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Evidence Street']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $data = array_merge([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id,
            'customer_address_id' => $address->id,
            'source' => OrderSource::Manual->value,
            'entry_mode' => OrderEntryMode::Live->value,
        ], $orderOverrides);
        $order = app(OrderService::class)->create($actor, $data);

        return compact('branch', 'area', 'customer', 'phone', 'address', 'actor', 'order');
    }

    private function captain(Branch $branch): Captain
    {
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $captain;
    }

    public function test_staff_sources_and_historical_entry_are_server_enforced(): void
    {
        $context = $this->context(['source' => 'WHATSAPP', 'ordered_at' => now()->subYear()]);
        $this->assertSame(OrderSource::WhatsApp, $context['order']->source);
        $this->assertSame(OrderEntryMode::Live, $context['order']->entry_mode);
        $this->assertTrue($context['order']->ordered_at->diffInSeconds(now()) < 10);

        foreach ([OrderSource::Phone, OrderSource::Manual] as $source) {
            $order = app(OrderService::class)->create($context['actor'], [
                'branch_id' => $context['branch']->id,
                'customer_id' => $context['customer']->id,
                'customer_phone_id' => $context['phone']->id,
                'customer_address_id' => $context['address']->id,
                'source' => $source->value,
                'entry_mode' => 'LIVE',
            ]);
            $this->assertSame($source, $order->source);
        }

        $past = now()->subDays(6)->startOfSecond();
        $historical = app(OrderService::class)->create($context['actor'], [
            'branch_id' => $context['branch']->id,
            'customer_id' => $context['customer']->id,
            'customer_phone_id' => $context['phone']->id,
            'customer_address_id' => $context['address']->id,
            'source' => 'PHONE',
            'entry_mode' => 'HISTORICAL_MANUAL',
            'ordered_at' => $past,
        ]);
        $this->assertTrue($historical->ordered_at->equalTo($past));
        $this->assertTrue($historical->created_at->greaterThan($past));

        $this->actingAs($context['actor'])->post(route('orders.store'), [
            'branch_id' => $context['branch']->id,
            'customer_id' => $context['customer']->id,
            'customer_phone_id' => $context['phone']->id,
            'customer_address_id' => $context['address']->id,
            'source' => 'MOBILE_APP',
            'entry_mode' => 'LIVE',
        ])->assertSessionHasErrors('source');

        $legacy = $context['order'];
        $legacy->forceFill(['source' => null, 'entry_mode' => null])->save();
        $this->assertNull($legacy->fresh()->source);
    }

    public function test_future_and_unauthorized_historical_entry_are_rejected(): void
    {
        $context = $this->context();
        try {
            app(OrderService::class)->create($context['actor'], [
                'branch_id' => $context['branch']->id,
                'customer_id' => $context['customer']->id,
                'customer_phone_id' => $context['phone']->id,
                'customer_address_id' => $context['address']->id,
                'source' => 'MANUAL',
                'entry_mode' => 'HISTORICAL_MANUAL',
                'ordered_at' => now()->addMinute(),
            ]);
            $this->fail('Future historical time was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ordered_at', $exception->errors());
        }

        $limitedRole = Role::create(['code' => 'LIMITED_ORDER_CREATOR', 'name' => 'Limited creator']);
        $limitedRole->permissions()->attach(Permission::where('code', 'orders.create')->sole());
        $limited = User::factory()->create();
        $limited->roles()->attach($limitedRole);
        $limited->branches()->attach($context['branch']);
        $this->expectException(AuthorizationException::class);
        app(OrderService::class)->create($limited, [
            'branch_id' => $context['branch']->id,
            'customer_id' => $context['customer']->id,
            'customer_phone_id' => $context['phone']->id,
            'customer_address_id' => $context['address']->id,
            'source' => 'MANUAL',
            'entry_mode' => 'HISTORICAL_MANUAL',
            'ordered_at' => now()->subDay(),
        ]);
    }

    public function test_duplicate_phone_warning_only_returns_visible_matches_and_acknowledgement_allows_save(): void
    {
        $context = $this->context();
        $visible = Customer::create(['name' => 'Visible Match']);
        $visible->addresses()->create(['area_id' => $context['area']->id, 'address_line_1' => 'Visible']);
        $visible->phones()->create(['phone' => '+963 944 111 222', 'normalized_phone' => '963944111222']);
        $foreignBranch = Branch::factory()->create();
        $hidden = Customer::create(['name' => 'Hidden Match']);
        $hidden->addresses()->create(['area_id' => Area::factory()->for($foreignBranch)->create()->id, 'address_line_1' => 'Hidden']);
        $hidden->phones()->create(['phone' => '963944111222', 'normalized_phone' => '963944111222']);

        $response = $this->actingAs($context['actor'])->getJson(route('customers.phone-matches', [$context['customer'], 'phone' => '963-944-111-222']))->assertOk();
        $response->assertJsonCount(1, 'matches')->assertJsonPath('matches.0.id', $visible->id)->assertJsonMissing(['name' => 'Hidden Match']);

        $this->post(route('customers.phones.store', $context['customer']), ['phone' => '963944111222'])->assertSessionHasErrors('phone');
        $this->post(route('customers.phones.store', $context['customer']), ['phone' => '963944111222', 'duplicate_acknowledged' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_phones', ['customer_id' => $context['customer']->id, 'normalized_phone' => '963944111222']);
        $this->assertDatabaseHas('customer_phones', ['customer_id' => $visible->id, 'normalized_phone' => '963944111222']);
    }

    public function test_progress_receipts_and_delivery_evidence_are_enforced(): void
    {
        Storage::fake('order_receipts');
        $context = $this->context();
        $shop = app(OrderShopService::class)->add($context['actor'], $context['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Evidence Shop']);
        $progress = app(OrderShopProgressService::class);
        $progress->record($context['actor'], $context['order'], $shop, 'picked_up_at');
        $this->assertNotNull($shop->fresh()->picked_up_at);
        $this->expectException(ValidationException::class);
        $progress->record($context['actor'], $context['order'], $shop, 'picked_up_at');
    }

    public function test_progress_nested_and_branch_tampering_are_rejected_and_finalized_orders_are_immutable(): void
    {
        $first = $this->context();
        $second = $this->context();
        $foreignShop = app(OrderShopService::class)->add($second['actor'], $second['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Foreign']);
        $this->actingAs($first['actor'])->patch(route('orders.shops.progress', [$first['order'], $foreignShop]), ['marker' => 'contacted_at'])->assertNotFound();
        $this->actingAs($first['actor'])->patch(route('orders.shops.progress', [$second['order'], $foreignShop]), ['marker' => 'contacted_at'])->assertForbidden();

        $ownShop = app(OrderShopService::class)->add($first['actor'], $first['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Own']);
        $first['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
        $this->actingAs($first['actor'])->patch(route('orders.shops.progress', [$first['order'], $ownShop]), ['marker' => 'contacted_at'])->assertSessionHasErrors('order');
    }

    public function test_valid_private_multiple_receipts_allow_delivery_and_invalid_file_is_rejected(): void
    {
        Storage::fake('order_receipts');
        $context = $this->context();
        $shop = app(OrderShopService::class)->add($context['actor'], $context['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Evidence Shop']);
        $context['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();
        try {
            app(OrderStatusService::class)->transition($context['actor'], $context['order'], OrderOperationalStatus::Delivered, null);
            $this->fail('Delivery without evidence was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operational_status', $exception->errors());
        }

        app(OrderShopProgressService::class)->record($context['actor'], $context['order'], $shop, 'picked_up_at');
        $receipts = app(OrderShopReceiptService::class);
        $first = $receipts->upload($context['actor'], $context['order'], $shop, UploadedFile::fake()->createWithContent('first.png', base64_decode(self::PNG)), 'First');
        $second = $receipts->upload($context['actor'], $context['order'], $shop, UploadedFile::fake()->createWithContent('second.png', base64_decode(self::PNG)), null);
        $this->assertCount(2, $shop->receipts()->get());
        Storage::disk('order_receipts')->assertExists($first->storage_path);
        $this->assertStringStartsNotWith('public/', $first->storage_path);
        $this->actingAs($context['actor'])->get(route('order-shop-receipts.show', [$context['order'], $shop, $second]))->assertOk();

        app(OrderStatusService::class)->transition($context['actor'], $context['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $this->assertSame(OrderOperationalStatus::Delivered, $context['order']->fresh()->operational_status);

        $other = $this->context();
        $otherShop = app(OrderShopService::class)->add($other['actor'], $other['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Invalid']);
        $this->actingAs($other['actor'])->post(route('orders.shops.receipts.store', [$other['order'], $otherShop]), ['receipt' => UploadedFile::fake()->create('fake.jpg', 1, 'text/plain')])->assertSessionHasErrors('receipt');
    }

    public function test_cancellation_is_immutable_blocks_normal_mutation_and_allows_after_purchase_evidence(): void
    {
        Storage::fake('order_receipts');
        $context = $this->context();
        $shop = app(OrderShopService::class)->add($context['actor'], $context['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Purchased Shop']);
        app(OrderShopProgressService::class)->record($context['actor'], $context['order'], $shop, 'picked_up_at');
        $draft = app(InvoiceService::class)->createDraft($context['actor'], $context['order']);
        $cancellation = app(OrderCancellationService::class)->cancel($context['actor'], $context['order'], OrderCancellationClassification::AfterPurchase, OrderCancellationReason::CustomerCancelled, 'Customer changed mind');
        $this->assertTrue($cancellation->requiresFinancialResolution());
        $this->assertSame(OrderOperationalStatus::Cancelled, $context['order']->fresh()->operational_status);
        $this->assertDatabaseHas('order_status_history', ['order_id' => $context['order']->id, 'new_status' => 'CANCELLED']);

        $receipt = app(OrderShopReceiptService::class)->upload($context['actor'], $context['order']->fresh(), $shop, UploadedFile::fake()->createWithContent('late.png', base64_decode(self::PNG)), 'Late evidence');
        Storage::disk('order_receipts')->assertExists($receipt->storage_path);
        try {
            app(OrderShopService::class)->add($context['actor'], $context['order']->fresh(), ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Forbidden']);
            $this->fail('Cancelled Order accepted a normal mutation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        try {
            app(OrderShopProgressService::class)->record($context['actor'], $context['order']->fresh(), $shop, 'ready_at');
            $this->fail('Cancelled Order accepted a progress mutation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        try {
            app(InvoiceService::class)->issue($context['actor'], $context['order']->fresh(), $draft);
            $this->fail('A cancelled Order Invoice was issued.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
        $this->assertDatabaseHas('invoices', ['id' => $draft->id, 'status' => 'DRAFT', 'superseded_at' => null]);
        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->createDraft($context['actor'], $context['order']->fresh());
    }

    public function test_before_purchase_conflicts_with_evidence_and_captain_ratings_are_per_participant(): void
    {
        $context = $this->context();
        $shop = app(OrderShopService::class)->add($context['actor'], $context['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Shop']);
        app(OrderShopProgressService::class)->record($context['actor'], $context['order'], $shop, 'picked_up_at');
        try {
            app(OrderCancellationService::class)->cancel($context['actor'], $context['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);
            $this->fail('BEFORE_PURCHASE accepted purchase evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('classification', $exception->errors());
        }

        $first = $this->captain($context['branch']);
        $second = $this->captain($context['branch']);
        app(OrderCaptainService::class)->assign($context['actor'], $context['order'], $first, OrderCaptainRole::PrimaryCollection);
        app(OrderCaptainService::class)->assign($context['actor'], $context['order'], $second, OrderCaptainRole::Support);
        $context['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
        $ratings = app(CaptainRatingService::class);
        $one = $ratings->recordManual($context['actor'], $context['order']->fresh(), $first, 5);
        $two = $ratings->recordManual($context['actor'], $context['order']->fresh(), $second, 3);
        $this->assertSame('WHATSAPP_MANUAL', $one->source->value);
        $this->assertSame($context['actor']->id, $one->entered_by);
        $this->assertSame([3, 5], $context['order']->captainRatings()->orderBy('rating')->pluck('rating')->all());
        $this->assertSame(4.0, (float) $context['order']->captainRatings()->avg('rating'));

        $secondOrder = app(OrderService::class)->create($context['actor'], [
            'branch_id' => $context['branch']->id,
            'customer_id' => $context['customer']->id,
            'customer_phone_id' => $context['phone']->id,
            'customer_address_id' => $context['address']->id,
            'source' => 'WHATSAPP',
            'entry_mode' => 'LIVE',
        ]);
        app(OrderCaptainService::class)->assign($context['actor'], $secondOrder, $first, OrderCaptainRole::PrimaryCollection);
        $secondOrder->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
        $ratings->recordManual($context['actor'], $secondOrder->fresh(), $first, 3);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', Role::SUPER_ADMIN)->sole());
        $this->actingAs($admin)->get(route('captains.index'))->assertOk()->assertSee('Ratings: 2')->assertSee('4.00');
        $this->get(route('captains.show', $first))->assertOk()->assertSee('2 ratings')->assertSee('4.00')->assertSee('5 stars: 1')->assertSee('3 stars: 1');

        $this->expectException(ValidationException::class);
        $ratings->recordManual($context['actor'], $context['order']->fresh(), $first, 4);
    }

    public function test_receipts_are_branch_authorized_and_before_purchase_cancellation_rejects_late_evidence(): void
    {
        Storage::fake('order_receipts');
        $context = $this->context();
        $shop = app(OrderShopService::class)->add($context['actor'], $context['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Private Shop']);
        $receipt = app(OrderShopReceiptService::class)->upload($context['actor'], $context['order'], $shop, UploadedFile::fake()->createWithContent('private.png', base64_decode(self::PNG)), null);
        $outsider = User::factory()->create();
        $outsider->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $outsider->branches()->attach(Branch::factory()->create());
        $this->actingAs($outsider)->get(route('order-shop-receipts.show', [$context['order'], $shop, $receipt]))->assertForbidden();

        $fresh = $this->context();
        $freshShop = app(OrderShopService::class)->add($fresh['actor'], $fresh['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'No Purchase']);
        app(OrderCancellationService::class)->cancel($fresh['actor'], $fresh['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);
        $this->expectException(ValidationException::class);
        app(OrderShopReceiptService::class)->upload($fresh['actor'], $fresh['order']->fresh(), $freshShop, UploadedFile::fake()->createWithContent('late.png', base64_decode(self::PNG)), null);
    }

    public function test_delivered_and_cancelled_orders_cannot_be_cancelled_again(): void
    {
        $delivered = $this->context();
        $delivered['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
        try {
            app(OrderCancellationService::class)->cancel($delivered['actor'], $delivered['order']->fresh(), OrderCancellationClassification::BeforePurchase, OrderCancellationReason::Other, null);
            $this->fail('Delivered Order was cancelled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $cancelled = $this->context();
        app(OrderCancellationService::class)->cancel($cancelled['actor'], $cancelled['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::Other, null);
        $this->expectException(ValidationException::class);
        app(OrderCancellationService::class)->cancel($cancelled['actor'], $cancelled['order']->fresh(), OrderCancellationClassification::BeforePurchase, OrderCancellationReason::Other, null);
    }

    public function test_issued_invoice_conflict_blocks_cancellation_without_rewriting_financial_history(): void
    {
        $context = $this->context();
        $invoice = app(InvoiceService::class)->createDraft($context['actor'], $context['order']);
        $invoice->update(['status' => 'ISSUED', 'issued_at' => now(), 'issued_by' => $context['actor']->id]);
        try {
            app(OrderCancellationService::class)->cancel($context['actor'], $context['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);
            $this->fail('Order with an issued Invoice was cancelled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'ISSUED', 'superseded_at' => null]);
        $this->assertDatabaseMissing('order_cancellations', ['order_id' => $context['order']->id]);
    }

    public function test_rating_route_cannot_forge_customer_app_and_rejects_nonparticipants(): void
    {
        $context = $this->context();
        $participant = $this->captain($context['branch']);
        $stranger = $this->captain($context['branch']);
        app(OrderCaptainService::class)->assign($context['actor'], $context['order'], $participant, OrderCaptainRole::PrimaryCollection);
        $context['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();

        $this->actingAs($context['actor'])->post(route('orders.captain-ratings.store', $context['order']), [
            'captain_id' => $participant->id,
            'rating' => 5,
            'source' => 'CUSTOMER_APP',
        ])->assertSessionHasErrors('source');
        $this->assertDatabaseCount('captain_ratings', 0);

        $this->post(route('orders.captain-ratings.store', $context['order']), ['captain_id' => $participant->id, 'rating' => 5])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('captain_ratings', ['captain_id' => $participant->id, 'source' => 'WHATSAPP_MANUAL', 'entered_by' => $context['actor']->id]);
        $this->post(route('orders.captain-ratings.store', $context['order']), ['captain_id' => $stranger->id, 'rating' => 4])->assertSessionHasErrors('captain_id');
        $this->post(route('orders.captain-ratings.store', $context['order']), ['captain_id' => $participant->id, 'rating' => 6])->assertSessionHasErrors('rating');
    }

    public function test_order_employee_cannot_see_management_rating_summary(): void
    {
        $context = $this->context();
        $captain = $this->captain($context['branch']);
        $this->actingAs($context['actor'])->get(route('captains.index'))->assertOk()->assertDontSee('Ratings:');
        $this->get(route('captains.show', $captain))->assertOk()->assertDontSee('Rating summary');
    }

    public function test_receipt_http_and_service_ingestion_reject_unsafe_content_and_alternate_serving(): void
    {
        Storage::fake('order_receipts');
        $context = $this->context();
        $shop = app(OrderShopService::class)->add($context['actor'], $context['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Secure Shop']);

        $this->actingAs($context['actor'])->post(route('orders.shops.receipts.store', [$context['order'], $shop]), [
            'receipt' => UploadedFile::fake()->createWithContent('valid.png', base64_decode(self::PNG)),
        ])->assertSessionHasNoErrors();
        $receipt = $shop->receipts()->sole();
        Storage::disk('order_receipts')->assertExists($receipt->storage_path);
        $this->assertFalse(Route::has('storage.order_receipts'));
        $this->assertFalse((bool) config('filesystems.disks.order_receipts.serve'));

        foreach ([
            ['vector.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            ['script.jpg', '<?php echo "unsafe"; ?><html></html>'],
        ] as [$filename, $contents]) {
            $this->post(route('orders.shops.receipts.store', [$context['order'], $shop]), ['receipt' => $this->contentUpload($filename, $contents)])->assertSessionHasErrors('receipt');
            try {
                app(OrderShopReceiptService::class)->upload($context['actor'], $context['order'], $shop, $this->contentUpload($filename, $contents), null);
                $this->fail('The reusable receipt service accepted unsafe content.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('receipt', $exception->errors());
            }
        }

        $large = $this->contentUpload('large.png', base64_decode(self::PNG).str_repeat("\0", (5 * 1024 * 1024) + 1));
        try {
            app(OrderShopReceiptService::class)->upload($context['actor'], $context['order'], $shop, $large, null);
            $this->fail('The reusable receipt service accepted a file larger than 5 MB.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt', $exception->errors());
        }

        $otherShop = app(OrderShopService::class)->add($context['actor'], $context['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Other Shop']);
        $this->get(route('order-shop-receipts.show', [$context['order'], $otherShop, $receipt]))->assertNotFound();
    }

    public function test_receipt_storage_is_physically_isolated_from_every_served_disk(): void
    {
        $context = $this->context();
        $shop = app(OrderShopService::class)->add($context['actor'], $context['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Isolated Receipt Shop']);
        $receipt = null;

        try {
            $receipt = app(OrderShopReceiptService::class)->upload($context['actor'], $context['order'], $shop, UploadedFile::fake()->createWithContent('isolated.png', base64_decode(self::PNG)), null);
            $this->actingAs($context['actor'])->get(route('order-shop-receipts.show', [$context['order'], $shop, $receipt]))->assertOk();
            $this->actingAs(User::factory()->create())->get(route('order-shop-receipts.show', [$context['order'], $shop, $receipt]))->assertForbidden();

            $receiptRoot = realpath((string) config('filesystems.disks.order_receipts.root'));
            $servedLocalRoot = realpath((string) config('filesystems.disks.local.root'));
            $this->assertNotFalse($receiptRoot);
            $this->assertNotFalse($servedLocalRoot);
            $this->assertFalse(str_starts_with(strtolower($receiptRoot).DIRECTORY_SEPARATOR, strtolower($servedLocalRoot).DIRECTORY_SEPARATOR));
            $this->assertFalse(Storage::disk('local')->exists('order-shop-receipts/'.$receipt->storage_path));
            $this->assertFalse(Storage::disk('local')->exists('order-receipts-private/'.$receipt->storage_path));
            $this->assertFalse(Route::has('storage.order_receipts'));
            $this->assertFalse((bool) config('filesystems.disks.order_receipts.serve'));
        } finally {
            if ($receipt) {
                Storage::disk('order_receipts')->delete($receipt->storage_path);
            }
        }
    }

    public function test_hidden_duplicate_phone_and_no_match_have_identical_observable_response(): void
    {
        $context = $this->context();
        $foreignBranch = Branch::factory()->create();
        $hidden = Customer::create(['name' => 'Never Disclose']);
        $hidden->addresses()->create(['area_id' => Area::factory()->for($foreignBranch)->create()->id, 'address_line_1' => 'Hidden']);
        $hidden->phones()->create(['phone' => '963955111222', 'normalized_phone' => '963955111222']);

        $hiddenResponse = $this->actingAs($context['actor'])->getJson(route('customers.phone-matches', [$context['customer'], 'phone' => '963-955-111-222']));
        $noneResponse = $this->getJson(route('customers.phone-matches', [$context['customer'], 'phone' => '963-955-333-444']));
        $hiddenResponse->assertOk()->assertExactJson(['matches' => []]);
        $noneResponse->assertOk()->assertExactJson(['matches' => []]);
        $this->assertSame($noneResponse->getContent(), $hiddenResponse->getContent());

        $this->getJson(route('customers.phone-matches', [$hidden, 'phone' => '963955111222']))->assertForbidden();
        $this->post(route('customers.phones.store', $hidden), ['phone' => '963955111222', 'duplicate_acknowledged' => true])->assertForbidden();
    }

    public function test_cancellation_classifications_cover_no_evidence_and_receipt_only_evidence(): void
    {
        Storage::fake('order_receipts');
        $withoutEvidence = $this->context();
        try {
            app(OrderCancellationService::class)->cancel($withoutEvidence['actor'], $withoutEvidence['order'], OrderCancellationClassification::AfterPurchase, OrderCancellationReason::Other, null);
            $this->fail('AFTER_PURCHASE cancellation was accepted without purchase evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('classification', $exception->errors());
        }

        $receiptOnly = $this->context();
        $shop = app(OrderShopService::class)->add($receiptOnly['actor'], $receiptOnly['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Receipt only']);
        app(OrderShopReceiptService::class)->upload($receiptOnly['actor'], $receiptOnly['order'], $shop, UploadedFile::fake()->createWithContent('receipt.png', base64_decode(self::PNG)), null);
        $this->assertNull($shop->fresh()->picked_up_at);
        $this->expectException(ValidationException::class);
        app(OrderCancellationService::class)->cancel($receiptOnly['actor'], $receiptOnly['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::Other, null);
    }

    public function test_zero_and_multiple_shop_delivery_requirements_are_enforced_independently(): void
    {
        Storage::fake('order_receipts');
        $zero = $this->context();
        $zero['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();
        try {
            app(OrderStatusService::class)->transition($zero['actor'], $zero['order']->fresh(), OrderOperationalStatus::Delivered, null);
            $this->fail('An Order without Shops was delivered.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operational_status', $exception->errors());
        }

        $missingPickup = $this->context();
        $first = app(OrderShopService::class)->add($missingPickup['actor'], $missingPickup['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'First']);
        $second = app(OrderShopService::class)->add($missingPickup['actor'], $missingPickup['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Second']);
        foreach ([$first, $second] as $shop) {
            app(OrderShopReceiptService::class)->upload($missingPickup['actor'], $missingPickup['order'], $shop, UploadedFile::fake()->createWithContent($shop->id.'.png', base64_decode(self::PNG)), null);
        }
        app(OrderShopProgressService::class)->record($missingPickup['actor'], $missingPickup['order'], $first, 'picked_up_at');
        $missingPickup['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();
        try {
            app(OrderStatusService::class)->transition($missingPickup['actor'], $missingPickup['order']->fresh(), OrderOperationalStatus::Delivered, null);
            $this->fail('An Order with one Shop missing pickup was delivered.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operational_status', $exception->errors());
        }
        app(OrderShopProgressService::class)->record($missingPickup['actor'], $missingPickup['order']->fresh(), $second, 'picked_up_at');
        app(OrderStatusService::class)->transition($missingPickup['actor'], $missingPickup['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $this->assertSame(OrderOperationalStatus::Delivered, $missingPickup['order']->fresh()->operational_status);

        $missingReceipt = $this->context();
        $one = app(OrderShopService::class)->add($missingReceipt['actor'], $missingReceipt['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'One']);
        $two = app(OrderShopService::class)->add($missingReceipt['actor'], $missingReceipt['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Two']);
        foreach ([$one, $two] as $shop) {
            app(OrderShopProgressService::class)->record($missingReceipt['actor'], $missingReceipt['order'], $shop, 'picked_up_at');
        }
        app(OrderShopReceiptService::class)->upload($missingReceipt['actor'], $missingReceipt['order'], $one, UploadedFile::fake()->createWithContent('one.png', base64_decode(self::PNG)), null);
        $missingReceipt['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();
        $this->expectException(ValidationException::class);
        app(OrderStatusService::class)->transition($missingReceipt['actor'], $missingReceipt['order']->fresh(), OrderOperationalStatus::Delivered, null);
    }

    public function test_only_final_active_captain_assignments_are_rateable(): void
    {
        $context = $this->context();
        $removed = $this->captain($context['branch']);
        $replacement = $this->captain($context['branch']);
        app(OrderCaptainService::class)->assign($context['actor'], $context['order'], $removed, OrderCaptainRole::PrimaryCollection);
        app(OrderCaptainService::class)->switchPrimary($context['actor'], $context['order'], $replacement, 'Final replacement');
        $context['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();

        try {
            app(CaptainRatingService::class)->recordManual($context['actor'], $context['order']->fresh(), $removed, 5);
            $this->fail('A removed Captain received a rating.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('captain_id', $exception->errors());
        }
        $rating = app(CaptainRatingService::class)->recordManual($context['actor'], $context['order']->fresh(), $replacement, 4);
        $this->assertSame($replacement->id, $rating->captain_id);
    }
}
