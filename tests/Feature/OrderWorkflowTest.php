<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Livewire\Orders\CaptainAssignments;
use App\Livewire\Orders\CreateOrder;
use App\Livewire\Orders\Workflow;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryPricingRule;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillPaymentFeeRuleService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialAccountService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCancellationService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use App\Services\OrderService;
use App\Services\OrderShopEvidenceService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopService;
use App\Services\OrderStatusService;
use App\Services\OrderWorkflowState;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function context(bool $assign = true, bool $price = true): array
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('order_receipts');
        Storage::fake('order_returns');
        $currency = Currency::create(['code' => 'WF', 'name' => 'عملة', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create(['name' => 'سقبا']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $customer = Customer::create(['name' => 'زبون المسار']);
        $phone = $customer->phones()->create(['phone' => '0991112223', 'normalized_phone' => '0991112223']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'عنوان المسار']);
        $order = app(OrderService::class)->create($actor, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'PHONE', 'entry_mode' => 'LIVE']);
        $type = ShopType::create(['name' => 'نوع فعلي', 'business_classification' => 'SHOP']);
        $catalogShop = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل المسار', 'area_id' => $area->id, 'shop_type_id' => $type->id]);
        $shop = app(OrderShopService::class)->add($actor, $order, ['shop_type' => 'REGISTERED', 'shop_id' => $catalogShop->id]);
        $unit = Unit::create(['name' => 'قطعة', 'code' => 'WF', 'allows_decimal' => false]);
        $item = app(OrderItemService::class)->add($actor, $order, $shop, ['item_type' => 'ONE_TIME_ITEM', 'item_name' => 'منتج المسار', 'unit_id' => $unit->id, 'quantity' => '2']);
        app(OrderItemService::class)->confirmActual($actor, $order, $shop, $item, ['actual_unit_price' => '10', 'actual_currency_id' => $currency->id, 'actual_price_source' => 'MANUAL_VERIFIED']);
        $captain = $this->captain($branch, 'كابتن المسار');
        if ($assign) {
            app(OrderCaptainService::class)->assign($actor, $order, $captain, OrderCaptainRole::PrimaryCollection);
        }
        app(DeliveryPricingSettings::class)->update($admin, '65', '0');
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $area->id, 'to_area_id' => $area->id, 'currency_id' => $currency->id, 'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '5', 'effective_from' => now()->subDay()]);
        if ($price && $assign) {
            app(OrderDeliveryPricingService::class)->price($actor, $order, $area);
        }

        return compact('currency', 'branch', 'area', 'actor', 'admin', 'customer', 'phone', 'address', 'order', 'shop', 'catalogShop', 'type', 'unit', 'item', 'captain');
    }

    private function captain($branch, string $name): Captain
    {
        $u = User::factory()->create(['name' => $name]);
        $c = Captain::create(['user_id' => $u->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $c->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $c;
    }

    private function workflowComponent(array $c)
    {
        return Livewire::actingAs($c['actor'])->test(Workflow::class, ['order' => $c['order']]);
    }

    private function state(array $c): array
    {
        return app(OrderWorkflowState::class)->describe($c['actor'], $c['order']->fresh());
    }

    private function file()
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG));
    }

    private function dualData(array $c): array
    {
        return ['order_captain_id' => $c['order']->captainAssignments()->whereNull('removed_at')->sole()->id,
            'currency_id' => $c['currency']->id, 'amount' => '20', 'purchased_at' => DB::scalar("SELECT date_trunc('second', now() - interval '1 second')"),
            'request_key' => (string) Str::uuid(), 'also_shop_receipt' => true];
    }

    public function test_one_attested_upload_creates_two_private_records_without_pickup_status_or_posting_and_retry_is_exact(): void
    {
        $c = $this->context();
        $data = $this->dualData($c);
        $before = $c['order']->refresh()->getRawOriginal();
        $this->actingAs($c['actor'])->post(route('orders.shops.purchases.store', [$c['order'], $c['shop']]), $data + ['evidence' => $this->file()])
            ->assertSessionHasNoErrors()->assertRedirect(route('orders.workflow', [$c['order'], 'step' => 'shops']));
        $purchase = $c['shop']->purchaseConfirmation()->sole();
        $receipt = $c['shop']->receipts()->sole();
        $this->assertSame(base64_decode(self::PNG), Storage::disk('order_returns')->get($purchase->evidence_path));
        $this->assertSame(base64_decode(self::PNG), Storage::disk('order_receipts')->get($receipt->storage_path));
        $this->assertSame($c['actor']->id, $receipt->uploaded_by);
        $this->assertSame($c['actor']->id, $purchase->recorded_by);
        $this->assertNotSame($purchase->evidence_path, $receipt->storage_path);
        $this->assertNull($c['shop']->fresh()->picked_up_at);
        $this->assertNull($c['shop']->fresh()->contacted_at);
        $this->assertSame($before, $c['order']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->actingAs($c['actor'])->get(route('orders.shops.purchases.evidence', [$c['order'], $c['shop'], $purchase]))->assertOk();
        $this->get(route('order-shop-receipts.show', [$c['order'], $c['shop'], $receipt]))->assertOk();
        app(OrderShopEvidenceService::class)->record($c['actor'], $c['order'], $c['shop'], $data, $this->file());
        $this->assertSame(1, $c['shop']->receipts()->count());
        $this->assertSame(1, $c['shop']->purchaseConfirmation()->count());
        $this->assertCount(1, Storage::disk('order_returns')->allFiles());
        $this->assertCount(1, Storage::disk('order_receipts')->allFiles());
    }

    public function test_dual_evidence_rolls_back_purchase_and_private_file_if_receipt_action_fails(): void
    {
        $c = $this->context();
        $this->partialMock(OrderShopReceiptService::class, fn ($mock) => $mock->shouldReceive('upload')->once()
            ->andThrow(ValidationException::withMessages(['receipt' => 'Storage unavailable'])));
        try {
            app(OrderShopEvidenceService::class)->record($c['actor'], $c['order'], $c['shop'], $this->dualData($c), $this->file());
            $this->fail('Partial evidence operation unexpectedly succeeded');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('receipt', $exception->errors());
        }
        $this->assertNull($c['shop']->fresh()->purchaseConfirmation);
        $this->assertSame(0, $c['shop']->receipts()->count());
        $this->assertSame([], Storage::disk('order_returns')->allFiles());
        $this->assertSame([], Storage::disk('order_receipts')->allFiles());
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_dual_evidence_requires_both_permissions_branch_nested_ownership_and_actual_image(): void
    {
        $c = $this->context();
        $route = route('orders.shops.purchases.store', [$c['order'], $c['shop']]);
        $data = $this->dualData($c);
        $role = Role::create(['code' => 'PURCHASE_ONLY', 'name' => 'Purchase only']);
        $role->permissions()->attach(Permission::where('code', 'order_shop_purchases.confirm')->sole());
        $restricted = User::factory()->create();
        $restricted->roles()->attach($role);
        $restricted->branches()->attach($c['branch']);
        $this->actingAs($restricted)->post($route, $data + ['evidence' => $this->file()])->assertForbidden();
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->actingAs($foreign)->post($route, $data + ['evidence' => $this->file()])->assertForbidden();
        $otherOrder = app(OrderService::class)->create($c['actor'], ['branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id,
            'customer_phone_id' => $c['phone']->id, 'customer_address_id' => $c['address']->id, 'source' => 'PHONE', 'entry_mode' => 'LIVE']);
        $this->actingAs($c['actor'])->post(route('orders.shops.purchases.store', [$otherOrder, $c['shop']]), $data + ['evidence' => $this->file()])->assertNotFound();
        $this->post($route, $data + ['evidence' => UploadedFile::fake()->createWithContent('proof.png', '<svg><script>alert(1)</script></svg>')])->assertSessionHasErrors('evidence');
        $this->post($route, $data + ['evidence' => UploadedFile::fake()->create('large.png', 5121, 'image/png')])->assertSessionHasErrors('evidence');
        $this->assertNull($c['shop']->fresh()->purchaseConfirmation);
        $this->assertSame(0, $c['shop']->receipts()->count());
        $this->assertSame([], Storage::disk('order_returns')->allFiles());
    }

    public function test_native_postgresql_receipt_failure_rolls_back_both_records_and_private_files(): void
    {
        $c = $this->context();
        DB::unprepared("CREATE FUNCTION b2_reject_receipt() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''B2 receipt write rejected''; END';
            CREATE TRIGGER b2_reject_receipt BEFORE INSERT ON order_shop_receipts FOR EACH ROW EXECUTE FUNCTION b2_reject_receipt();");
        try {
            app(OrderShopEvidenceService::class)->record($c['actor'], $c['order'], $c['shop'], $this->dualData($c), $this->file());
            $this->fail('PostgreSQL failure was ignored');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('B2 receipt write rejected', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER b2_reject_receipt ON order_shop_receipts; DROP FUNCTION b2_reject_receipt();');
        }
        $this->assertNull($c['shop']->fresh()->purchaseConfirmation);
        $this->assertSame(0, $c['shop']->receipts()->count());
        $this->assertSame([], Storage::disk('order_returns')->allFiles());
        $this->assertSame([], Storage::disk('order_receipts')->allFiles());
    }

    public function test_dual_evidence_under_issued_invoice_preserves_snapshots_and_downloads_reauthorize(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $invoiceBefore = $invoice->refresh()->getRawOriginal();
        $linesBefore = DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->get()->toJson();
        app(OrderShopEvidenceService::class)->record($c['actor'], $c['order'], $c['shop'], $this->dualData($c), $this->file());
        $this->assertSame($invoiceBefore, $invoice->fresh()->getRawOriginal());
        $this->assertSame($linesBefore, DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('financial_transactions', 0);
        $purchase = $c['shop']->purchaseConfirmation()->sole();
        $receipt = $c['shop']->receipts()->sole();
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->actingAs($foreign)->get(route('orders.shops.purchases.evidence', [$c['order'], $c['shop'], $purchase]))->assertForbidden();
        $this->get(route('order-shop-receipts.show', [$c['order'], $c['shop'], $receipt]))->assertForbidden();
        $otherOrder = app(OrderService::class)->create($c['actor'], ['branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id,
            'customer_phone_id' => $c['phone']->id, 'customer_address_id' => $c['address']->id, 'source' => 'PHONE', 'entry_mode' => 'LIVE']);
        $this->actingAs($c['actor'])->get(route('orders.shops.purchases.evidence', [$otherOrder, $c['shop'], $purchase]))->assertNotFound();
        $this->get(route('order-shop-receipts.show', [$otherOrder, $c['shop'], $receipt]))->assertNotFound();
    }

    public function test_final_orders_reject_new_dual_evidence_and_failed_retry_never_deletes_existing_proof(): void
    {
        $c = $this->context();
        $data = $this->dualData($c);
        $purchase = app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], $data, $this->file());
        $this->partialMock(OrderShopReceiptService::class, fn ($mock) => $mock->shouldReceive('upload')->once()
            ->andThrow(ValidationException::withMessages(['receipt' => 'Rejected'])));
        try {
            app(OrderShopEvidenceService::class)->record($c['actor'], $c['order'], $c['shop'], $data, $this->file());
            $this->fail('Failed receipt accepted');
        } catch (ValidationException) {
            Storage::disk('order_returns')->assertExists($purchase->evidence_path);
            $this->assertSame($purchase->id, $c['shop']->purchaseConfirmation()->sole()->id);
        }
        $this->app->forgetInstance(OrderShopReceiptService::class);
        $this->app->forgetInstance(OrderShopEvidenceService::class);
        $this->delivered($c);
        $data['request_key'] = (string) Str::uuid();
        $this->actingAs($c['actor'])->post(route('orders.shops.purchases.store', [$c['order'], $c['shop']]), $data + ['evidence' => $this->file()])->assertSessionHasErrors('order');
        $this->assertSame(1, $c['shop']->receipts()->count());
        $this->assertSame(1, $c['shop']->purchaseConfirmation()->count());
    }

    public function test_purchase_only_does_not_infer_receipt_and_dual_reuse_requires_attestation(): void
    {
        $c = $this->context();
        $data = $this->dualData($c);
        $data['also_shop_receipt'] = false;
        $this->actingAs($c['actor'])->post(route('orders.shops.purchases.store', [$c['order'], $c['shop']]), $data + ['evidence' => $this->file()])->assertSessionHasNoErrors();
        $this->assertSame(0, $c['shop']->receipts()->count());
        $before = $c['shop']->purchaseConfirmation()->sole()->getRawOriginal();
        try {
            app(OrderShopEvidenceService::class)->record($c['actor'], $c['order'], $c['shop'], $data, $this->file());
            $this->fail('Dual evidence without attestation accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('also_shop_receipt', $exception->errors());
        }
        $this->assertSame($before, $c['shop']->purchaseConfirmation()->sole()->getRawOriginal());
    }

    public function test_smart_next_action_records_every_transition_and_never_backfills_optional_milestones(): void
    {
        $c = $this->context();
        $this->purchase($c);
        $this->evidence($c);
        $component = $this->workflowComponent($c);
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops,
            OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered] as $status) {
            $component->assertSee('data-workflow-next-action="status"', false)->call('advance')->assertHasNoErrors();
            $this->assertSame($status, $c['order']->fresh()->operational_status);
        }
        $this->assertSame(7, $c['order']->statusHistory()->count());
        foreach (['contacted_at', 'confirmed_at', 'ready_at'] as $marker) {
            $this->assertNull($c['shop']->fresh()->{$marker});
        }
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_shopping_next_action_follows_missing_authoritative_facts_and_summary_has_no_mutation_forms(): void
    {
        $c = $this->context();
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order']->fresh(), $status, null);
        }
        $this->assertSame('purchase', $this->state($c)['nextAction']['kind']);
        $this->purchase($c);
        $this->assertSame('receipt', $this->state($c)['nextAction']['kind']);
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->file(), null);
        $this->assertSame('pickup', $this->state($c)['nextAction']['kind']);
        $component = $this->workflowComponent($c)->call('openStep', 'shops')->assertDontSee(__('orders.workflow.optional'));
        $component->call('pickup', $c['shop']->id)->assertHasNoErrors();
        $this->assertSame('status', $this->state($c)['nextAction']['kind']);
        $html = $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()->assertSee(route('orders.workflow', $c['order']), false)->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//*[@data-order-summary]//form')->length);
    }

    private function purchase(array $c): void
    {
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], ['order_captain_id' => $c['order']->captainAssignments()->whereNull('removed_at')->sole()->id, 'currency_id' => $c['currency']->id, 'amount' => '20', 'purchased_at' => now()->subSecond()->format('Y-m-d H:i:s'), 'request_key' => (string) Str::uuid()], $this->file());
    }

    private function evidence(array $c): void
    {
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->file(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
    }

    private function issued(array $c)
    {
        $s = app(InvoiceService::class);
        $i = $s->createDraft($c['actor'], $c['order']);
        $i = $s->refresh($c['actor'], $c['order'], $i);

        return $s->issue($c['actor'], $c['order'], $i);
    }

    private function delivered(array $c): void
    {
        $this->evidence($c);
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order']->fresh(), $status, null);
        }
    }

    public function test_create_redirects_to_persisted_workflow_and_preserves_draft_facts(): void
    {
        $c = $this->context(false, false);
        $component = Livewire::actingAs($c['actor'])->test(CreateOrder::class)->set('branchId', (string) $c['branch']->id)->set('customerId', (string) $c['customer']->id)->set('source', 'PHONE')
            ->call('nextStep')->call('selectShop', $c['catalogShop']->id)->call('addDraftShop')->set('itemType', 'ONE_TIME_ITEM')->set('itemName', 'مسودة موحدة')->set('unitId', (string) $c['unit']->id)->call('addDraftItem')->call('goToStep', 4)->call('submit')->assertHasNoErrors();
        $order = Order::latest('id')->first();
        $component->assertRedirect(route('orders.workflow', $order));
        $this->actingAs($c['actor'])->get(route('orders.workflow', $order))->assertOk()->assertSee('زبون المسار')->assertSee('عنوان المسار');
        $this->get(route('orders.workflow', [$order, 'step' => 'summary']))->assertSee('مسودة موحدة');
    }

    public function test_wizard_form_uses_stable_submission_action_and_retry_reuses_committed_order(): void
    {
        $c = $this->context(false, false);
        $component = Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->assertSee('wire:submit="submitWizard"', false)
            ->set('branchId', (string) $c['branch']->id)->set('customerId', (string) $c['customer']->id)->set('source', 'PHONE')
            ->call('submitWizard')->assertSet('step', 2)
            ->call('selectShop', $c['catalogShop']->id)->call('addDraftShop')
            ->set('itemType', 'ONE_TIME_ITEM')->set('itemName', 'اختبار التنقل')->set('unitId', (string) $c['unit']->id)->call('addDraftItem')
            ->call('goToStep', 4)->assertSee('wire:click="submit"', false);
        $count = Order::count();
        $retry = clone $component;
        $component->call('submitWizard')->assertHasNoErrors();
        $order = Order::latest('id')->first();
        $component->assertRedirect(route('orders.workflow', $order))->call('submit')->assertRedirect(route('orders.workflow', $order));
        $this->assertSame($count + 1, Order::count());
        // A stale pre-success snapshot must reuse the committed result even if
        // its displayed step subsequently changes.
        $retry->call('goToStep', 1)->call('submit')
            ->assertHasNoErrors()->assertRedirect(route('orders.workflow', $order));
        $this->assertSame($count + 1, Order::count());
        $this->actingAs($c['actor'])->get(route('orders.workflow', $order))->assertOk()->assertSee($order->order_number);
    }

    public function test_http_issue_and_collection_return_to_workflow_without_delivering_order(): void
    {
        $c = $this->context();
        $service = app(InvoiceService::class);
        $invoice = $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order']));
        $return = route('orders.workflow', [$c['order'], 'step' => 'invoice']);
        $this->actingAs($c['actor'])->from(route('orders.invoices.show', [$c['order'], $invoice]))
            ->post(route('orders.invoices.issue', [$c['order'], $invoice]))->assertSessionHasNoErrors()->assertRedirect($return)
            ->assertSessionHas('status', __('financial.flash.invoice_issued'));
        $this->post(route('invoices.collection.store', $invoice), [
            'collection_method' => 'CASH', 'collected_at' => now()->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors()->assertRedirect($return)->assertSessionHas('status', __('financial.flash.collection_recorded'));
        $this->assertSame(OrderOperationalStatus::New, $c['order']->fresh()->operational_status);
        $this->get($return)->assertOk()->assertSee(route('orders.invoices.show', [$c['order'], $invoice]), false);
    }

    public function test_collected_invoice_has_prominent_return_and_closed_authorized_correction_action(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash,
            $invoice->grand_total, $invoice->currency_id, now(), null, null);
        $html = $this->actingAs($c['admin'])->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()
            ->assertSee(__('orders.workflow.return'))->assertSee(route('orders.workflow', [$c['order'], 'step' => 'invoice']), false)->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//details[@data-invoice-correction-request and not(@open)]//form')->length);
        $this->assertSame(0, $xpath->query('//details[@data-invoice-correction-request and @open]')->length);
        $role = Role::create(['code' => 'INVOICE_SUMMARY_ONLY', 'name' => 'Invoice viewer']);
        $role->permissions()->attach(Permission::whereIn('code', ['orders.view', 'invoices.view'])->pluck('id'));
        $viewer = User::factory()->create();
        $viewer->roles()->attach($role);
        $viewer->branches()->attach($c['branch']);
        $this->actingAs($viewer)->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertDontSee('data-invoice-correction-request', false);
    }

    public function test_workflow_cancellation_is_visible_eligible_and_still_enforces_financial_guards(): void
    {
        $c = $this->context();
        $this->workflowComponent($c)->assertSee('data-workflow-cancellation', false)->assertSee(route('orders.cancel', $c['order']), false);
        $this->purchase($c);
        $this->workflowComponent($c)->assertSee(route('orders.cancellation-intent.store', $c['order']), false)->assertDontSee('action="'.route('orders.cancel', $c['order']).'"', false);
        $this->issued($c);
        $this->workflowComponent($c)->assertSee('data-workflow-cancellation', false)
            ->assertSee(__('domain.financial_correction_required_settled_invoice_bill_payment_or_order_derived_financial_history_cannot_be_cancelled_in_phase_9c_2'))
            ->assertDontSee('action="'.route('orders.cancellation-intent.store', $c['order']).'"', false);
        $this->actingAs($c['actor'])->post(route('orders.cancellation-intent.store', $c['order']), [
            'reason' => OrderCancellationReason::CustomerCancelled->value, 'request_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('order');
        $this->assertNull($c['order']->fresh()->cancellationIntent);
        $this->assertSame(OrderOperationalStatus::New, $c['order']->fresh()->operational_status);
    }

    public function test_workflow_bill_payment_uses_existing_eligible_request_and_delivered_completion_paths(): void
    {
        $c = $this->context();
        $requestRoute = route('orders.bill-payment-requests.store', $c['order']);
        $this->workflowComponent($c)->assertSee('data-workflow-bill-payments', false)->assertDontSee('action="'.$requestRoute.'"', false);
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '3', now()->subDay());
        $this->workflowComponent($c)->assertSee('action="'.$requestRoute.'"', false);
        $this->actingAs($c['actor'])->from(route('orders.workflow', $c['order']))->post($requestRoute, ['currency_id' => $c['currency']->id])->assertSessionHasNoErrors();
        $request = $c['order']->billPaymentRequests()->sole();
        $completeRoute = route('orders.bill-payment-requests.complete', [$c['order'], $request]);
        $this->workflowComponent($c)->assertDontSee('action="'.$completeRoute.'"', false);
        $this->delivered($c);
        $this->workflowComponent($c)->assertSee('action="'.$completeRoute.'"', false)->assertDontSee('action="'.$requestRoute.'"', false);
        $this->assertNull($request->fresh()->payment);
    }

    public function test_optional_bill_service_empty_state_is_not_completed_and_summary_is_read_only(): void
    {
        $c = $this->context();
        $route = route('orders.bill-payment-requests.store', $c['order']);
        $this->assertSame(0, $c['order']->billPaymentRequests()->count());
        // No optional service still satisfies the existing "none pending" contract.
        $this->assertTrue($c['order']->additionalServicesComplete());
        $this->workflowComponent($c)->assertSee(__('orders.ui_bill_not_requested'))->assertDontSee(__('orders.ui_additional_complete'));
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '3', now()->subDay());
        $this->workflowComponent($c)->assertSee(__('orders.ui_request_bill'))->assertSee('action="'.$route.'"', false);
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee(__('orders.ui_bill_not_requested'))->assertDontSee(__('orders.ui_additional_complete'))
            ->assertDontSee('action="'.$route.'"', false)->assertSee('#workflow-bill-payments', false);
    }

    public function test_missing_or_future_bill_fee_explains_unavailability_and_no_configuration_is_created(): void
    {
        $c = $this->context();
        $route = route('orders.bill-payment-requests.store', $c['order']);
        $this->workflowComponent($c)->assertSee(__('orders.ui_bill_fee_unavailable'))->assertDontSee('action="'.$route.'"', false)
            ->assertDontSee(route('admin.bill-payment-fees.index'), false);
        Livewire::actingAs($c['admin'])->test(Workflow::class, ['order' => $c['order']])
            ->assertSee(__('orders.ui_bill_fee_unavailable'))->assertSee(route('admin.bill-payment-fees.index'), false);
        $this->assertDatabaseCount('bill_payment_fee_rules', 0);
        $this->actingAs($c['actor'])->post($route, ['currency_id' => $c['currency']->id])->assertSessionHasErrors('currency_id');
        $this->assertSame(0, $c['order']->billPaymentRequests()->count());
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '3', now()->addDay());
        $this->workflowComponent($c)->assertSee(__('orders.ui_bill_fee_unavailable'))->assertDontSee('action="'.$route.'"', false);
    }

    public function test_existing_bill_request_is_pending_then_cancelled_and_summary_has_no_operational_forms(): void
    {
        $c = $this->context();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '3', now()->subDay());
        $this->actingAs($c['actor'])->from(route('orders.workflow', $c['order']))
            ->post(route('orders.bill-payment-requests.store', $c['order']), ['currency_id' => $c['currency']->id])->assertSessionHasNoErrors();
        $request = $c['order']->billPaymentRequests()->sole();
        $this->workflowComponent($c)->assertSee(__('orders.ui_bill_pending'))->assertDontSee(__('orders.ui_bill_not_requested'))
            ->assertDontSee(__('orders.ui_additional_complete'));
        $cancelRoute = route('orders.bill-payment-requests.cancel', [$c['order'], $request]);
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertDontSee('action="'.$cancelRoute.'"', false);
        app(OrderBillPaymentService::class)->cancel($c['actor'], $c['order'], $request, 'Not needed');
        $this->workflowComponent($c)->assertSee(__('orders.ui_bill_all_cancelled'))->assertDontSee(__('orders.ui_additional_complete'));
        $this->assertDatabaseCount('order_bill_payments', 0);
    }

    public function test_bill_initiation_respects_delivered_cancelled_intent_and_cross_branch_guards(): void
    {
        $c = $this->context();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '3', now()->subDay());
        $route = route('orders.bill-payment-requests.store', $c['order']);
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $foreign->branches()->attach(Branch::factory()->create());
        $this->actingAs($foreign)->get(route('orders.workflow', $c['order']))->assertForbidden();
        $this->post($route, ['currency_id' => $c['currency']->id])->assertForbidden();
        $this->assertSame(0, $c['order']->billPaymentRequests()->count());
        $this->delivered($c);
        $this->workflowComponent($c)->assertSee(__('domain.new_additional_service_requests_require_an_open_order'))->assertDontSee('action="'.$route.'"', false);
        $this->actingAs($c['actor'])->post($route, ['currency_id' => $c['currency']->id])->assertSessionHasErrors('order');

        $open = $c;
        $open['order'] = app(OrderService::class)->create($c['actor'], [
            'branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id,
            'customer_phone_id' => $c['phone']->id, 'customer_address_id' => $c['address']->id,
            'source' => 'PHONE', 'entry_mode' => 'LIVE',
        ]);
        app(OrderCancellationService::class)->cancel($open['actor'], $open['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);
        $this->workflowComponent($open)->assertSee(__('domain.new_additional_service_requests_require_an_open_order'))
            ->assertDontSee('action="'.route('orders.bill-payment-requests.store', $open['order']).'"', false);
        $this->actingAs($open['actor'])->post(route('orders.bill-payment-requests.store', $open['order']), ['currency_id' => $open['currency']->id])->assertSessionHasErrors('order');
    }

    public function test_back_navigation_changes_no_status_history_or_facts(): void
    {
        $c = $this->context();
        $component = $this->workflowComponent($c)->call('advance')->assertHasNoErrors();
        $before = DB::table('orders')->where('id', $c['order']->id)->first();
        $history = $c['order']->statusHistory()->count();
        $component->call('openStep', 'shops')->call('openStep', 'summary')->call('openStep', 'confirm')->assertSee('عنوان المسار');
        $this->assertEquals($before, DB::table('orders')->where('id', $c['order']->id)->first());
        $this->assertSame($history, $c['order']->statusHistory()->count());
    }

    public function test_future_panel_is_only_a_view_and_never_fakes_completion(): void
    {
        $c = $this->context();
        $this->workflowComponent($c)->call('openStep', 'complete')->assertSee(__('orders.workflow.not_complete'))->assertDontSee('wire:click="collectInvoice"', false);
        $this->assertSame(OrderOperationalStatus::New, $c['order']->fresh()->operational_status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_invalid_step_and_locked_panel_tampering_are_rejected(): void
    {
        $c = $this->context();
        $this->workflowComponent($c)->call('openStep', 'invented')->assertStatus(422);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->workflowComponent($c)->set('panel', 'complete');
    }

    public function test_shop_type_source_matches_admin_and_wizard_and_is_optional(): void
    {
        $c = $this->context();
        $restaurant = ShopType::create(['name' => 'مطعم فعلي', 'business_classification' => 'RESTAURANT']);
        $inactive = ShopType::create(['name' => 'TYPE_INACTIVE_HIDDEN']);
        $inactive->forceFill(['is_active' => false])->save();
        $component = Livewire::actingAs($c['admin'])->test(CreateOrder::class)->call('openShopCreate')->assertSee('مطعم فعلي')->assertDontSee('TYPE_INACTIVE_HIDDEN')->assertSee(__('orders.wizard.optional_type'));
        $this->assertSame(ShopType::selectable()->modelKeys(), $component->instance()->shopTypes->modelKeys());
        $this->actingAs($c['admin'])->get(route('shops.create'))->assertOk()->assertSee('مطعم فعلي')->assertDontSee('TYPE_INACTIVE_HIDDEN');
        $this->assertContains($inactive->id, ShopType::selectable($inactive->id)->modelKeys());
        $component->set('branchId', (string) $c['branch']->id)->set('newShopClassification', 'SHOP')->set('newShopName', 'مطعم جديد من المسار')->set('newShopAreaId', (string) $c['area']->id)->set('newShopTypeId', (string) $restaurant->id)->call('createPermanentShop')->assertHasNoErrors();
        $this->assertSame($restaurant->id, Shop::where('name', 'مطعم جديد من المسار')->sole()->shop_type_id);
    }

    public function test_captain_sole_select_and_multiple_explicit_selection_and_support_are_preserved(): void
    {
        $c = $this->context(false, false);
        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])->assertSet('captainId', (string) $c['captain']->id);
        $second = $this->captain($c['branch'], 'ثان');
        Livewire::test(CaptainAssignments::class, ['order' => $c['order']])->assertSet('captainId', '')->call('assign')->assertHasErrors('captainId')
            ->set('captainId', (string) $c['captain']->id)->call('assign')->assertHasNoErrors()->assertRedirect(route('orders.workflow', $c['order']));
        Livewire::test(CaptainAssignments::class, ['order' => $c['order']])->assertSet('assignmentRole', 'SUPPORT')->assertSet('captainId', (string) $second->id)->call('assign')->assertHasNoErrors();
        $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
    }

    public function test_pricing_is_not_offered_without_captain_and_missing_routes_fail_closed(): void
    {
        $c = $this->context(false, false);
        $this->workflowComponent($c)->call('openStep', 'captain')->assertSee(__('orders.workflow.pricing_requires'))->assertDontSee(route('orders.delivery-pricing.apply', $c['order']), false);
        app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $c['captain'], OrderCaptainRole::PrimaryCollection);
        DeliveryPricingRule::query()->update(['is_active' => false]);
        $this->workflowComponent($c)->call('openStep', 'captain')->assertSee(__('orders.ui_multi_shop_route_missing_rule'))->assertDontSee(route('orders.delivery-pricing.apply', $c['order']), false);
        $this->actingAs($c['actor'])->post(route('orders.delivery-pricing.apply', $c['order']), ['from_area_id' => $c['area']->id])->assertSessionHasErrors();
        $this->assertDatabaseCount('order_delivery_pricings', 0);
    }

    public function test_purchase_is_later_fact_and_missing_contact_ready_never_regress_workflow(): void
    {
        $c = $this->context();
        $this->purchase($c);
        $this->evidence($c);
        app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::Confirmed, null);
        $this->assertSame('delivery', $this->state($c)['step']);
        $this->assertNull($c['shop']->fresh()->contacted_at);
        $this->assertNull($c['shop']->fresh()->ready_at);
        $this->workflowComponent($c)->call('openStep', 'shops')->assertSee(__('orders.workflow.not_recorded'))->assertDontSee(__('orders.workflow.optional'))->assertDontSee('action="'.route('orders.shops.purchases.store', [$c['order'], $c['shop']]).'"', false)->assertDontSee('wire:click="pickup(', false)->assertDontSee('action="'.route('orders.shops.receipts.store', [$c['order'], $c['shop']]).'"', false);
    }

    public function test_real_delivery_blocker_remains_until_every_shop_has_receipt_and_pickup(): void
    {
        $c = $this->context();
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order']->fresh(), $status, null);
        }
        $this->workflowComponent($c)->call('openStep', 'delivery')->assertSee(__('domain.every_shop_must_be_picked_up_and_have_receipt_evidence_before_delivery'))->call('advance')->assertHasErrors('operationalStatus');
        $this->workflowComponent($c)->call('pickup', $c['shop']->id)->assertHasNoErrors();
        $this->assertFalse($this->state($c)['canTransition']);
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->file(), null);
        $this->workflowComponent($c)->call('advance')->assertHasNoErrors();
        $this->assertSame(OrderOperationalStatus::Delivered, $c['order']->fresh()->operational_status);
    }

    public function test_issued_financial_facts_frozen_but_evidence_and_delivery_remain_available(): void
    {
        $c = $this->context();
        $i = $this->issued($c);
        $before = (array) DB::table('invoices')->where('id', $i->id)->first();
        $this->workflowComponent($c)->call('openStep', 'captain')->assertSee(__('orders.workflow.locked'))->assertDontSee('wire:submit="assign"', false);
        $this->workflowComponent($c)->call('openStep', 'shops')->assertDontSee('wire:submit="add"', false)->assertSee('wire:click="pickup(', false)->assertSee(route('orders.shops.receipts.store', [$c['order'], $c['shop']]), false);
        $this->delivered($c);
        $this->assertEquals($before, (array) DB::table('invoices')->where('id', $i->id)->first());
        $this->assertFalse($this->state($c)['complete']);
        $this->expectException(ValidationException::class);
        app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['catalogShop']->id]);
    }

    public function test_invoice_actions_are_available_before_delivery_and_stale_ready_requires_refresh(): void
    {
        $c = $this->context();
        $component = $this->workflowComponent($c)->call('openStep', 'invoice')->assertSee('wire:click="createInvoice"', false)->call('createInvoice')->assertHasNoErrors()->call('refreshInvoice')->assertHasNoErrors()->assertSee('wire:click="issueInvoice"', false);
        app(OrderItemService::class)->confirmActual($c['actor'], $c['order'], $c['shop'], $c['item'], ['actual_unit_price' => '11', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED']);
        $component->dispatch('order-updated')->assertSee('wire:click="refreshInvoice"', false)->assertDontSee('wire:click="issueInvoice"', false)->call('issueInvoice')->assertHasErrors('invoice')->call('refreshInvoice')->call('issueInvoice')->assertHasNoErrors()->assertSee('wire:submit="collectInvoice"', false);
        $this->assertSame(InvoiceStatus::Issued, $c['order']->currentInvoice()->sole()->status);
        $this->assertSame(OrderOperationalStatus::New, $c['order']->fresh()->operational_status);
    }

    public static function collectionMethods(): array
    {
        return [['CASH'], ['SHAM_CASH']];
    }

    #[DataProvider('collectionMethods')]
    public function test_delivery_issue_full_collection_completes_and_posts_exactly_once(string $method): void
    {
        $c = $this->context();
        $this->delivered($c);
        $this->assertFalse($this->state($c)['complete']);
        $this->assertSame('invoice', $this->state($c)['step']);
        $this->workflowComponent($c)->call('openStep', 'invoice')->call('createInvoice')->call('refreshInvoice')->call('issueInvoice')->set('collectionMethod', $method)->set('collectedAt', now()->addSecond()->format('Y-m-d H:i:s'));
        // Freeze clock after issuance so collection time is valid and deterministic.
        $this->travel(2)->seconds();
        $i = $c['order']->currentInvoice()->sole();
        $component = $this->workflowComponent($c)->call('openStep', 'invoice')->set('collectionMethod', $method)->call('collectInvoice')->assertHasNoErrors()->assertSee(__('orders.workflow.complete'))->assertDontSee('wire:submit="collectInvoice"', false);
        $component->call('openStep', 'complete')
            ->assertSee('wire:name="orders.captain-assignments"', false)
            ->assertDontSee("livewire('orders.captain-assignments'", false)
            ->assertSee('action="'.route('orders.captain-ratings.store', $c['order']).'"', false);
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertDontSee('action="'.route('orders.captain-ratings.store', $c['order']).'"', false);
        $this->assertTrue($this->state($c)['complete']);
        $count = FinancialTransaction::count();
        $this->assertGreaterThan(0, $count);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($count, FinancialTransaction::count());
        $component->call('collectInvoice')->assertHasErrors('invoice');
        $this->assertSame(1, $i->collection()->count());
        $this->assertSame($count, FinancialTransaction::count());
        $accounts = app(FinancialAccountService::class);
        $ids = [];
        foreach (FinancialAccountKind::cases() as $kind) {
            $ids[] = $accounts->getOrCreate($accounts->ownerForCaptain($c['captain']), $c['currency'], $kind)->id;
        }$this->assertCount(3, array_unique($ids));
    }

    public function test_cancelled_and_intent_workflows_hide_and_reject_normal_actions(): void
    {
        $c = $this->context();
        $this->purchase($c);
        $component = $this->workflowComponent($c)->call('openStep', 'invoice');
        app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'], OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $this->workflowComponent($c)->assertSee(__('domain.bill_payment_is_blocked_by_a_cancellation_intent'))
            ->assertDontSee('action="'.route('orders.bill-payment-requests.store', $c['order']).'"', false);
        $component->dispatch('order-updated')->assertViewHas('activePanel', 'exception')->assertDontSee('wire:click="advance"', false)->assertDontSee('wire:click="createInvoice"', false)->assertSee(route('orders.shops.returns.store', [$c['order'], $c['shop']]), false)->call('createInvoice')->assertHasErrors('invoice')->call('advance')->assertHasErrors('order');
    }

    public function test_before_purchase_cancelled_summary_preserves_history_without_normal_controls(): void
    {
        $c = $this->context();
        app(OrderCancellationService::class)->cancel($c['actor'], $c['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, 'حقيقة تاريخية');
        $this->workflowComponent($c)->assertViewHas('activePanel', 'exception')->assertDontSee('wire:click="advance"', false)->call('pickup', $c['shop']->id)->assertHasErrors('order');
        $page = $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()->assertSee('حقيقة تاريخية')->assertSee('زبون المسار');
        // The read-only bill-service return link may share words with Continue;
        // assert the actual normal continuation action is absent.
        $this->assertStringNotContainsString('>'.__('orders.workflow.continue').'</a>', $page->getContent());
    }

    public function test_summary_preserves_history_and_evidence_but_has_no_duplicate_normal_forms(): void
    {
        $c = $this->context();
        $this->purchase($c);
        $this->evidence($c);
        $this->issued($c);
        $page = $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()->assertSee('data-order-summary', false)->assertSee(__('orders.workflow.continue'))->assertSee('منتج المسار')->assertSee(__('orders.ui_operational_history'));
        foreach (['wire:submit="add"', 'wire:submit="assign"', 'wire:click="advance"', 'wire:click="pickup(', 'wire:submit="collectInvoice"', 'action="'.route('orders.invoices.store', $c['order']).'"', 'action="'.route('orders.shops.purchases.store', [$c['order'], $c['shop']]).'"', route('orders.shops.progress', [$c['order'], $c['shop']])] as $control) {
            $page->assertDontSee($control, false);
        }
        $receipt = $c['shop']->receipts()->sole();
        $page->assertSee(route('order-shop-receipts.show', [$c['order'], $c['shop'], $receipt]), false);
        $this->get(route('order-shop-receipts.show', [$c['order'], $c['shop'], $receipt]))->assertOk();
    }

    public function test_cross_branch_route_and_direct_livewire_access_are_denied(): void
    {
        $c = $this->context();
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $foreign->branches()->attach(Branch::factory()->create());
        $this->actingAs($foreign)->get(route('orders.workflow', $c['order']))->assertForbidden();
        Livewire::actingAs($foreign)->test(Workflow::class, ['order' => $c['order']])->assertForbidden();
    }

    public function test_viewer_cannot_force_actions_or_financial_links(): void
    {
        $c = $this->context();
        $role = Role::create(['code' => 'WF_VIEW', 'name' => 'Viewer']);
        $role->permissions()->attach(Permission::where('code', 'orders.view')->sole());
        $viewer = User::factory()->create();
        $viewer->roles()->attach($role);
        $viewer->branches()->attach($c['branch']);
        $component = Livewire::actingAs($viewer)->test(Workflow::class, ['order' => $c['order']])->call('openStep', 'invoice')->assertDontSee('wire:click="createInvoice"', false)->call('createInvoice')->assertForbidden();
        $this->actingAs($viewer)->get(route('orders.workflow', $c['order']))->assertDontSee('data-workflow-cancellation', false)->assertDontSee('data-workflow-bill-payments', false);
        $this->post(route('orders.cancel', $c['order']), ['classification' => 'BEFORE_PURCHASE', 'reason' => 'CUSTOMER_CANCELLED'])->assertForbidden();
        $this->post(route('orders.bill-payment-requests.store', $c['order']), ['currency_id' => $c['currency']->id])->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
        $this->actingAs($viewer)->get(route('orders.show', $c['order']))->assertDontSee(route('captains.financials.show', $c['captain']), false);
    }

    public function test_nested_shop_tampering_is_rejected_before_writes(): void
    {
        $c = $this->context();
        $shop = OrderShop::create(['order_id' => app(OrderService::class)->create($c['actor'], ['branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id, 'customer_phone_id' => $c['phone']->id, 'customer_address_id' => $c['address']->id, 'source' => 'PHONE', 'entry_mode' => 'LIVE'])->id, 'shop_type' => 'ONE_TIME', 'shop_name_snapshot' => 'خارج الطلب']);
        $this->workflowComponent($c)->call('pickup', $shop->id)->assertNotFound();
        $this->assertNull($shop->fresh()->picked_up_at);
    }

    public function test_step_markup_has_no_nested_forms_duplicate_ids_or_raw_status_dropdown(): void
    {
        $c = $this->context();
        foreach (['confirm', 'captain', 'shops', 'delivery', 'invoice', 'complete', 'summary'] as $step) {
            $html = $this->workflowComponent($c)->call('openStep', $step)->html();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(0, $xpath->query('//form//form')->length);
            $ids = [];
            foreach ($xpath->query('//*[@id]') as $node) {
                $ids[] = $node->getAttribute('id');
            }$this->assertCount(count(array_unique($ids)), $ids);
            $this->assertStringNotContainsString('name="operational_status"', $html);
            $this->assertStringContainsString('grid-cols-2', $html);
            $this->assertStringContainsString('bdi dir="ltr"', $html);
        }
    }

    public function test_multi_shop_workflow_proposal_uses_max_without_changing_financial_formula(): void
    {
        $c = $this->context(true, false);
        $origin = Area::factory()->for($c['branch'])->create(['name' => 'أصل ثان']);
        app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'محل ثان', 'origin_area_id' => $origin->id]);
        foreach ([[$c['area'], '7'], [$origin, '12']] as [$from,$fee]) {
            app(DeliveryPricingRuleService::class)->create($c['admin'], ['from_area_id' => $from->id, 'to_area_id' => $c['area']->id, 'currency_id' => $c['currency']->id, 'order_pricing_class' => 'MULTI_SHOP', 'delivery_unit_fee' => $fee, 'effective_from' => now()->subDay()]);
        }
        $this->workflowComponent($c)->call('openStep', 'captain')->assertSee('12')->assertSee(__('orders.ui_multi_shop_highest_route_used'));
        $this->actingAs($c['actor'])->from(route('orders.workflow', [$c['order'], 'step' => 'captain']))->post(route('orders.delivery-pricing.apply', $c['order']), ['from_area_id' => $origin->id])->assertSessionHasNoErrors()->assertRedirect(route('orders.workflow', [$c['order'], 'step' => 'captain']));
        $pricing = $c['order']->currentDeliveryPricing()->sole();
        $this->assertSame('12.0000', $pricing->suggested_unit_fee);
        $this->assertSame(2, $pricing->routes()->count());
        $this->assertSame('MULTI_SHOP', $pricing->order_pricing_class_snapshot->value);
    }

    public function test_override_and_discount_remain_permission_protected_secondary_actions(): void
    {
        $c = $this->context();
        $role = Role::create(['code' => 'WF_NO_OVERRIDE', 'name' => 'Without override']);
        $role->permissions()->attach(Permission::whereIn('code', ['orders.view', 'delivery_pricing.view'])->pluck('id'));
        $restricted = User::factory()->create();
        $restricted->roles()->attach($role);
        $restricted->branches()->attach($c['branch']);
        Livewire::actingAs($restricted)->test(Workflow::class, ['order' => $c['order']])->call('openStep', 'captain')->assertDontSee(route('orders.delivery-pricing.override', $c['order']), false);
        $this->actingAs($restricted)->post(route('orders.delivery-pricing.override', $c['order']), ['from_area_id' => $c['area']->id, 'requested_unit_fee' => '9', 'reason' => 'audit'])->assertForbidden();
        Livewire::actingAs($c['admin'])->test(Workflow::class, ['order' => $c['order']])->call('openStep', 'captain')->assertSee(route('orders.delivery-pricing.override', $c['order']), false);
        $this->issued($c);
        $this->actingAs($c['admin'])->post(route('orders.delivery-pricing.override', $c['order']), ['from_area_id' => $c['area']->id, 'requested_unit_fee' => '9', 'reason' => 'audit'])->assertSessionHasErrors();
    }

    public function test_multi_shop_missing_pickup_or_receipt_on_one_shop_blocks_delivery(): void
    {
        $c = $this->context();
        $second = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'ثان', 'origin_area_id' => $c['area']->id]);
        $this->evidence($c);
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order']->fresh(), $status, null);
        }
        $this->assertFalse($this->state($c)['canTransition']);
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $second, $this->file(), null);
        $this->assertFalse($this->state($c)['canTransition']);
        $this->workflowComponent($c)->call('pickup', $second->id)->assertHasNoErrors();
        $this->assertTrue($this->state($c)['canTransition']);
    }

    public function test_u3_workflow_visual_navigation_preserves_history_financials_and_guarded_evidence(): void
    {
        $c = $this->context();
        $before = $c['order']->fresh()->getRawOriginal();
        $history = $c['order']->statusHistory()->get()->toArray();
        $component = $this->workflowComponent($c)->assertSee('mary-surface order-ux', false)
            ->assertSee('data-workflow-overview', false)->assertSee(__('orders.statuses.NEW'))
            ->assertSee(__('order_design.collector'))->assertSee($c['captain']->user->name);
        foreach (['shops', 'captain', 'invoice', 'summary'] as $panel) {
            $component->call('openStep', $panel)->assertHasNoErrors();
        }
        $component->call('openStep', 'shops')->assertSee(__('order_design.evidence_help'))
            ->assertSee('name="also_shop_receipt"', false)->assertSee('data-evidence-upload', false)
            ->assertSee(__('order_ux.line_actual'))->assertSee(__('order_ux.shop_actual'));
        $this->assertSame($before, $c['order']->fresh()->getRawOriginal());
        $this->assertSame($history, $c['order']->statusHistory()->get()->toArray());
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('invoice_collections', 0);
    }
}
