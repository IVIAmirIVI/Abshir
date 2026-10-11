<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\InvoiceFinancialCorrectionState;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Livewire\Orders\NextAction;
use App\Livewire\Orders\OrderShopItems;
use App\Livewire\Orders\Workflow;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceFinancialCorrection;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopProduct;
use App\Models\Unit;
use App\Models\User;
use App\Services\BillPaymentFeeRuleService;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CustomerRefundEvidenceService;
use App\Services\CustomerRefundService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCancellationService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopReturnService;
use App\Services\OrderShopService;
use App\Services\OrderStatusService;
use App\Services\ShopProductService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\RefreshAccountingV1Database;
use Tests\TestCase;

class InvoiceAndCollectionTest extends TestCase
{
    protected function usesAccountingV1Fixture(): bool
    {
        return str_contains($this->name(), 'phase_9c3a_')
            || in_array($this->name(), ['test_uat_corrected_current_invoice_is_settled_not_collectible_and_open_order_can_finish',
                'test_uat_pending_correction_blocks_delivery_assessment_until_explicit_resolution'], true);
    }

    use RefreshAccountingV1Database;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function context(string $currencyCode = 'USD'): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['name' => $currencyCode, 'code' => $currencyCode, 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create(['name' => 'Origin']);
        $to = Area::factory()->for($branch)->create(['name' => 'Destination']);
        $customer = Customer::create(['name' => 'Snapshot Customer']);
        $phone = $customer->phones()->create(['phone' => '111', 'normalized_phone' => '111']);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Snapshot Street']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'HISTORICAL_MANUAL', 'ordered_at' => now()->subDay()]);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'One-time Shop', 'origin_area_id' => $from->id, 'sort_order' => 1]);
        $order->update(['pricing_class' => OrderPricingClass::SingleShop]);
        $item = OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime, 'product_name_snapshot' => 'Snapshot Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '2.5', 'current_quantity' => '2.5', 'actual_unit_price' => '10.00', 'actual_price_source' => ActualPriceSource::ManualVerified, 'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $employee->id]);
        app(OrderPricingService::class)->recalculate($order);
        $captain = $this->captain($branch);
        app(OrderCaptainService::class)->assign($employee, $order, $captain, OrderCaptainRole::PrimaryCollection);
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id, 'currency_id' => $currency->id, 'order_pricing_class' => OrderPricingClass::SingleShop->value, 'delivery_unit_fee' => '5.00', 'effective_from' => now()->subDays(2), 'effective_until' => null]);
        app(DeliveryPricingSettings::class)->update($admin, '65', '0');
        app(OrderDeliveryPricingService::class)->price($employee, $order, $from);

        return compact('currency', 'branch', 'from', 'to', 'customer', 'admin', 'employee', 'order', 'shop', 'item', 'captain');
    }

    private function captain(Branch $branch): Captain
    {
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $captain;
    }

    private function ready(array $context): Invoice
    {
        $invoice = app(InvoiceService::class)->createDraft($context['employee'], $context['order']);

        return app(InvoiceService::class)->refresh($context['employee'], $context['order'], $invoice);
    }

    public function test_arabic_invoice_lifecycle_print_and_historical_versions_preserve_values(): void
    {
        $c = $this->context();
        $service = app(InvoiceService::class);
        $draft = $service->createDraft($c['employee'], $c['order']);
        $this->actingAs($c['employee'])->get(route('orders.invoices.show', [$c['order'], $draft]))
            ->assertOk()->assertSee('مسودة')->assertSee('الفاتورة الحالية')->assertSee(__('financial.ui.refresh_help'));
        $ready = $service->refresh($c['employee'], $c['order'], $draft);
        $this->get(route('orders.invoices.show', [$c['order'], $ready]))->assertOk()
            ->assertSee('جاهزة للإصدار')->assertSee('إصدار الفاتورة')->assertSee('30.0000');
        $issued = $service->issue($c['employee'], $c['order'], $ready);
        $this->get(route('invoices.index'))->assertOk()->assertSee('فواتير')->assertSee('صادرة')->assertSee($issued->invoice_number);
        $print = $this->get(route('orders.invoices.print', [$c['order'], $issued]))->assertOk()
            ->assertSee('أبشر')->assertSee('فاتورة الزبون')->assertSee('إجمالي الفاتورة')
            ->assertSee('صادرة')->assertSee('الفاتورة الحالية')
            ->assertSee('dir="rtl"', false)->assertSee('30.0000')->assertSee('25.0000')->assertSee('USD')
            ->assertSee('Snapshot Customer')->assertSee('Snapshot Street');
        $this->assertStringContainsString('<bdi dir="ltr"', $print->getContent());
        $print->assertSee('flex-wrap:wrap', false);
        $replacement = $service->replace($c['admin'], $c['order'], $issued, 'Historical replacement reason');
        $this->get(route('orders.invoices.show', [$c['order'], $issued]))->assertOk()
            ->assertSee('مستبدلة')->assertSee('فاتورة تاريخية')->assertSee('Historical replacement reason');
        $this->get(route('orders.invoices.print', [$c['order'], $issued]))->assertOk()
            ->assertSee('مستبدلة')->assertSee('فاتورة تاريخية')->assertSee('30.0000');
        $this->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee('فاتورة تاريخية')->assertSee('الفاتورة الحالية');
        $replacement = $service->issue($c['admin'], $c['order'], $replacement);
        $service->void($c['admin'], $c['order'], $replacement, 'Preserved void reason');
        $this->get(route('orders.invoices.show', [$c['order'], $replacement]))->assertOk()
            ->assertSee('ملغاة')->assertSee('فاتورة تاريخية')->assertSee('Preserved void reason');
        $this->assertSame('30.0000', $issued->fresh()->grand_total);
        $this->assertSame('Snapshot Customer', $issued->fresh()->customer_name_snapshot);
    }

    private function issued(array $context): Invoice
    {
        return app(InvoiceService::class)->issue($context['employee'], $context['order'], $this->ready($context));
    }

    public function test_issued_invoice_and_delivery_duration_survive_multiple_external_bill_requests(): void
    {
        Storage::fake('order_receipts');
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->context();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDays(2));
        $first = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $second = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $invoice = $this->issued($c);
        $beforeInvoice = (array) DB::table('invoices')->where('id', $invoice->id)->first();
        $beforeCollections = DB::table('invoice_collections')->where('invoice_id', $invoice->id)->count();

        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), $status, null);
        }
        app(OrderShopProgressService::class)->record($c['employee'], $c['order']->fresh(), $c['shop'], 'picked_up_at');
        app(OrderShopReceiptService::class)->upload($c['employee'], $c['order']->fresh(), $c['shop'], UploadedFile::fake()->createWithContent('invoice-proof.png', base64_decode(self::PNG)), null);
        app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $deliveredAt = $c['order']->statusHistory()->where('new_status', 'DELIVERED')->sole()->changed_at;
        $duration = $c['order']->ordered_at->diffInSeconds($deliveredAt);
        $this->assertTrue($c['order']->hasPendingBillPaymentRequests());

        $assignment = $c['order']->captainAssignments()->whereNull('removed_at')->firstOrFail();
        app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $first, [
            'order_captain_id' => $assignment->id, 'currency_id' => $c['currency']->id,
            'bill_number' => 'EXT-100', 'bill_date' => today()->toDateString(),
            'principal_amount' => '100', 'payment_reference' => 'SHAM-EXT-100',
        ], [UploadedFile::fake()->createWithContent('external-proof.png', base64_decode(self::PNG))]);
        $this->assertTrue($c['order']->hasPendingBillPaymentRequests());
        app(OrderBillPaymentService::class)->cancel($c['employee'], $c['order'], $second, 'No longer needed');
        $this->assertTrue($c['order']->additionalServicesComplete());
        $this->assertSame('DELIVERED', $c['order']->fresh()->operational_status->value);
        $this->assertSame($deliveredAt->toISOString(), $c['order']->statusHistory()->where('new_status', 'DELIVERED')->sole()->changed_at->toISOString());
        $this->assertSame($duration, $c['order']->ordered_at->diffInSeconds($c['order']->statusHistory()->where('new_status', 'DELIVERED')->sole()->changed_at));
        $this->assertEquals($beforeInvoice, (array) DB::table('invoices')->where('id', $invoice->id)->first());
        $this->assertSame($beforeCollections, DB::table('invoice_collections')->where('invoice_id', $invoice->id)->count());
    }

    public function test_issued_invoice_allows_operational_evidence_then_delivery_but_keeps_financial_facts_frozen(): void
    {
        Storage::fake('order_receipts');
        $context = $this->context();
        $invoice = $this->issued($context);
        $invoiceBefore = (array) DB::table('invoices')->where('id', $invoice->id)->first();
        $pricingBefore = (array) DB::table('order_delivery_pricings')->where('id', $invoice->source_order_delivery_pricing_id)->first();
        $this->actingAs($context['employee'])->get(route('orders.workflow', [$context['order'], 'step' => 'shops']))->assertOk()
            ->assertSee(route('orders.shops.progress', [$context['order'], $context['shop']]), false)
            ->assertSee(route('orders.shops.receipts.store', [$context['order'], $context['shop']]), false)
            ->assertDontSee('wire:submit="add"', false)
            ->assertDontSee('wire:submit="assign"', false)
            ->assertDontSee(route('orders.delivery-pricing.override', $context['order']), false)
            ->assertDontSee(route('orders.delivery-pricing.discount', $context['order']), false);
        $statuses = app(OrderStatusService::class);
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer] as $status) {
            $statuses->transition($context['employee'], $context['order']->fresh(), $status, null);
        }

        $this->patch(route('orders.shops.progress', [$context['order'], $context['shop']]), ['marker' => 'picked_up_at'])->assertRedirect()->assertSessionHasNoErrors();
        $pickedUpAt = $context['shop']->fresh()->picked_up_at;
        $this->patch(route('orders.shops.progress', [$context['order'], $context['shop']]), ['marker' => 'picked_up_at'])->assertSessionHasErrors('marker');
        $this->assertTrue($pickedUpAt->equalTo($context['shop']->fresh()->picked_up_at));
        app(OrderShopReceiptService::class)->upload($context['employee'], $context['order']->fresh(), $context['shop'], UploadedFile::fake()->createWithContent('issued.png', base64_decode(self::PNG)), null);
        try {
            app(OrderShopService::class)->add($context['employee'], $context['order']->fresh(), ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Financial mutation']);
            $this->fail('An issued Invoice allowed a financial Order mutation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        $statuses->transition($context['employee'], $context['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $this->assertSame(OrderOperationalStatus::Delivered, $context['order']->fresh()->operational_status);
        $this->assertEquals($invoiceBefore, (array) DB::table('invoices')->where('id', $invoice->id)->first());
        $this->assertEquals($pricingBefore, (array) DB::table('order_delivery_pricings')->where('id', $invoice->source_order_delivery_pricing_id)->first());
        $this->actingAs($context['employee'])->get(route('orders.show', $context['order']))->assertOk()
            ->assertDontSee(route('orders.shops.progress', [$context['order'], $context['shop']]), false)
            ->assertDontSee('wire:submit="add"', false)
            ->assertSee(__('orders.workflow.continue'));
        $this->patch(route('orders.shops.progress', [$context['order'], $context['shop']]), ['marker' => 'ready_at'])->assertSessionHasErrors('order');
    }

    public function test_ready_invoice_becomes_non_issuable_specifically_because_order_is_cancelled(): void
    {
        $context = $this->context();
        $invoice = $this->ready($context);
        $this->assertSame(InvoiceStatus::Ready, $invoice->status);
        app(OrderCancellationService::class)->cancel($context['employee'], $context['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);

        try {
            app(InvoiceService::class)->issue($context['employee'], $context['order']->fresh(), $invoice);
            $this->fail('A READY Invoice was issued after Order cancellation.');
        } catch (ValidationException $exception) {
            $this->assertSame('لا يمكن إنشاء فواتير للطلبات الملغاة أو تحديثها أو إصدارها.', $exception->errors()['invoice'][0]);
        }
        $this->assertSame(InvoiceStatus::Ready, $invoice->fresh()->status);
    }

    public function test_shop_composition_change_invalidates_pricing_and_makes_ready_invoice_stale(): void
    {
        $c = $this->context();
        $pricing = $c['order']->currentDeliveryPricing()->firstOrFail();
        $invoice = $this->ready($c);

        app(OrderShopService::class)->add($c['employee'], $c['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'RESTAURANT', 'shop_name' => 'Second Shop', 'origin_area_id' => $c['from']->id]);

        $this->assertSame(OrderPricingClass::MultiShop, $c['order']->fresh()->pricing_class);
        $this->assertNotNull($pricing->fresh()->superseded_at);
        $this->assertNull($c['order']->currentDeliveryPricing()->first());
        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->issue($c['employee'], $c['order'], $invoice);
    }

    public function test_issued_invoice_blocks_shop_composition_changes(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);

        try {
            app(OrderShopService::class)->add($c['employee'], $c['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Too late']);
            $this->fail('An issued Order accepted a new Shop.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(1, $c['order']->shops()->count());
        $this->assertNotNull($c['order']->currentDeliveryPricing()->first());
    }

    public function test_issued_invoice_blocks_all_order_fact_mutation_paths(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $unit = Unit::create(['name' => 'Guard Unit', 'code' => 'guard-unit']);
        $captain = $this->captain($c['branch']);
        $pricingCount = $c['order']->deliveryPricings()->count();
        $captainCount = $c['order']->captainAssignments()->count();
        $itemCount = $c['shop']->items()->count();

        $this->expectValidation(fn () => app(OrderItemService::class)->add($c['employee'], $c['order'], $c['shop'], ['item_type' => 'ONE_TIME_ITEM', 'item_name' => 'Too late', 'unit_id' => $unit->id, 'quantity' => '1']));
        $this->expectValidation(fn () => app(OrderItemService::class)->confirmActual($c['employee'], $c['order'], $c['shop'], $c['item'], ['actual_unit_price' => '12', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED']));
        $this->expectValidation(fn () => app(OrderCaptainService::class)->assign($c['employee'], $c['order'], $captain, OrderCaptainRole::Support));
        $this->expectValidation(fn () => app(OrderDeliveryPricingService::class)->price($c['employee'], $c['order'], $c['from']));
        $this->expectValidation(fn () => app(OrderDeliveryPricingService::class)->requestOverride($c['employee'], $c['order'], $c['from'], '4.50', 'Too late'));
        $this->expectValidation(fn () => app(OrderDeliveryPricingService::class)->applyDiscount($c['admin'], $c['order'], '1', 'Too late'));

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame($pricingCount, $c['order']->deliveryPricings()->count());
        $this->assertSame($captainCount, $c['order']->captainAssignments()->count());
        $this->assertSame($itemCount, $c['shop']->items()->count());
    }

    public function test_delivered_order_blocks_mutations_even_without_an_issued_invoice(): void
    {
        $c = $this->context();
        $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();

        $this->expectValidation(fn () => app(OrderDeliveryPricingService::class)->applyDiscount($c['admin'], $c['order']->fresh(), '1', 'Too late'));
        $this->assertSame(1, $c['order']->deliveryPricings()->count());
    }

    public function test_employee_creates_one_current_draft_with_customer_and_order_snapshots(): void
    {
        $c = $this->context();
        $invoice = app(InvoiceService::class)->createDraft($c['employee'], $c['order']);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{8}$/', $invoice->invoice_number);
        $this->assertSame($c['order']->id, $invoice->order_id);
        $this->assertSame('Snapshot Customer', $invoice->customer_name_snapshot);
        $this->assertSame($c['order']->order_number, $invoice->order_number_snapshot);
        $this->assertSame('Snapshot Street', $invoice->address_snapshot);
        try {
            app(InvoiceService::class)->createDraft($c['employee'], $c['order']);
            $this->fail('Second current Invoice created.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_readiness_requires_confirmed_complete_prices_delivery_currency_and_primary(): void
    {
        $c = $this->context();
        $invoice = app(InvoiceService::class)->createDraft($c['employee'], $c['order']);
        $c['item']->update(['actual_unit_price' => null, 'actual_price_source' => null, 'actual_currency_id' => null, 'actual_price_confirmed_at' => null, 'actual_price_confirmed_by' => null]);
        app(OrderPricingService::class)->recalculate($c['order']);
        $this->expectValidation(fn () => app(InvoiceService::class)->refresh($c['employee'], $c['order'], $invoice));
        $c['item']->update(['actual_unit_price' => '10', 'actual_price_source' => ActualPriceSource::ManualVerified, 'actual_currency_id' => $c['currency']->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $c['employee']->id]);
        app(OrderPricingService::class)->recalculate($c['order']);
        $c['order']->currentDeliveryPricing()->update(['superseded_at' => now()]);
        $this->expectValidation(fn () => app(InvoiceService::class)->refresh($c['employee'], $c['order'], $invoice));
    }

    public function test_ready_snapshot_has_exact_item_delivery_and_grand_totals(): void
    {
        $c = $this->context();
        $invoice = $this->ready($c);
        $this->assertSame(InvoiceStatus::Ready, $invoice->status);
        $this->assertSame('25.0000', $invoice->items_subtotal);
        $this->assertSame('5.0000', $invoice->delivery_total);
        $this->assertSame('30.0000', $invoice->grand_total);
        $this->assertSame($c['currency']->id, $invoice->currency_id);
        $this->assertSame('Snapshot Item', $invoice->items->sole()->description);
        $this->assertSame('2.5000', $invoice->items->sole()->quantity);
        $this->assertSame('10.0000', $invoice->items->sole()->unit_price);
        $this->assertSame('25.0000', $invoice->items->sole()->line_total);
        $this->assertNotNull($invoice->source_items_fingerprint);
    }

    public function test_currency_mismatch_blocks_ready(): void
    {
        $c = $this->context();
        $euro = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'decimal_places' => 2]);
        $c['item']->update(['actual_currency_id' => $euro->id]);
        $invoice = app(InvoiceService::class)->createDraft($c['employee'], $c['order']);
        $this->expectValidation(fn () => app(InvoiceService::class)->refresh($c['employee'], $c['order'], $invoice));
    }

    public function test_stale_ready_invoice_cannot_issue_then_refresh_can_issue_once(): void
    {
        $c = $this->context();
        $invoice = $this->ready($c);
        $c['item']->update(['actual_unit_price' => '12', 'actual_price_confirmed_at' => now()->addSecond()]);
        $this->expectValidation(fn () => app(InvoiceService::class)->issue($c['employee'], $c['order'], $invoice));
        $invoice = app(InvoiceService::class)->refresh($c['employee'], $c['order'], $invoice);
        $issued = app(InvoiceService::class)->issue($c['employee'], $c['order'], $invoice);
        $this->assertSame(InvoiceStatus::Issued, $issued->status);
        $this->assertNotNull($issued->issued_at);
        $this->assertSame($c['employee']->id, $issued->issued_by);
        $this->expectValidation(fn () => app(InvoiceService::class)->issue($c['employee'], $c['order'], $issued));
    }

    public function test_issued_snapshots_remain_immutable_when_sources_change(): void
    {
        $c = $this->context();
        $unit = Unit::create(['name' => 'Piece', 'code' => 'piece-invoice']);
        $product = Product::create(['name' => 'Current Product', 'default_unit_id' => $unit->id]);
        $c['item']->update(['item_type' => OrderItemType::Catalog, 'product_id' => $product->id]);
        $invoice = $this->issued($c)->load('items');
        $c['item']->update(['product_name_snapshot' => 'Corrected Item', 'actual_unit_price' => '20']);
        $product->update(['name' => 'Renamed Product']);
        $c['customer']->update(['name' => 'Changed Customer']);
        $this->expectValidation(fn () => app(OrderDeliveryPricingService::class)->applyDiscount($c['admin'], $c['order'], '1', 'Later correction'));
        $this->assertSame('Snapshot Item', $invoice->fresh()->items()->sole()->description);
        $this->assertSame('10.0000', $invoice->fresh()->items()->sole()->unit_price);
        $this->assertSame('Snapshot Customer', $invoice->fresh()->customer_name_snapshot);
        $this->assertSame('5.0000', $invoice->fresh()->delivery_total);
    }

    public function test_void_and_replacement_are_admin_only_and_preserve_history(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        try {
            app(InvoiceService::class)->void($c['employee'], $c['order'], $invoice, 'No permission');
            $this->fail('Employee voided an Invoice.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $c['item']->update(['actual_unit_price' => '12', 'actual_price_confirmed_at' => now()->addSecond()]);
        $replacement = app(InvoiceService::class)->replace($c['admin'], $c['order'], $invoice, 'Corrected price');
        $this->assertSame(InvoiceStatus::Replaced, $invoice->fresh()->status);
        $this->assertSame($replacement->id, $invoice->fresh()->replaced_by_invoice_id);
        $this->assertSame(2, $replacement->version);
        $this->assertSame(InvoiceStatus::Ready, $replacement->status);
        $this->assertSame('35.0000', $replacement->grand_total);
        $this->assertSame('30.0000', $invoice->grand_total);
        $this->expectValidation(fn () => app(InvoiceService::class)->replace($c['admin'], $c['order'], $invoice, 'Again'));
    }

    public function test_admin_void_requires_reason_and_cannot_repeat(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $this->expectValidation(fn () => app(InvoiceService::class)->void($c['admin'], $c['order'], $invoice, ''));
        app(InvoiceService::class)->void($c['admin'], $c['order'], $invoice, 'Customer cancellation');
        $this->assertSame(InvoiceStatus::Voided, $invoice->fresh()->status);
        $this->assertSame($c['admin']->id, $invoice->fresh()->voided_by);
        $this->assertNotNull($invoice->fresh()->voided_at);
        $this->expectValidation(fn () => app(InvoiceService::class)->void($c['admin'], $c['order'], $invoice, 'Again'));
    }

    public function test_collection_requires_issued_full_amount_currency_and_is_unique(): void
    {
        $c = $this->context();
        $ready = $this->ready($c);
        $service = app(InvoiceCollectionService::class);
        $this->expectValidation(fn () => $service->record($c['employee'], $ready, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now()));
        $issued = app(InvoiceService::class)->issue($c['employee'], $c['order'], $ready);
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::Cash, '29.99', $c['currency']->id, now()));
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::Cash, '30.01', $c['currency']->id, now()));
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now()->subDay()));
        $other = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'decimal_places' => 2]);
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::Cash, '30', $other->id, now()));
        $support = $this->captain($c['branch']);
        $this->actingAs($c['employee'])->post(route('invoices.collection.store', $issued), ['collection_method' => 'CASH', 'collected_at' => now()->format('Y-m-d H:i:s'), 'captain_id' => $support->id, 'amount' => '1', 'currency_id' => $other->id])->assertSessionHasNoErrors();
        $collection = $issued->collection()->sole();
        $this->assertSame($c['captain']->id, $collection->captain_id);
        $this->assertSame($c['order']->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->sole()->id, $collection->order_captain_id);
        $this->assertSame(InvoiceCollectionMethod::Cash, $collection->collection_method);
        $this->assertSame('30.0000', $collection->amount);
        $this->assertSame($c['employee']->id, $collection->recorded_by);
        $this->assertNotNull($collection->collected_at);
        $this->assertTrue($c['order']->isFinanciallyComplete());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseHas('financial_transactions', ['event_type' => 'ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED', 'source_id' => (string) $collection->id]);
        $this->assertDatabaseHas('financial_entries', ['amount' => $issued->delivery_total, 'direction' => 'INCREASE']);
        try {
            app(OrderCancellationIntentService::class)->create($c['employee'], $c['order'],
                OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
            $this->fail('A collected Invoice allowed cancellation intent.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('FINANCIAL_CORRECTION_REQUIRED', $exception->errors()['order'][0]);
        }
        $this->assertDatabaseMissing('order_cancellation_intents', ['order_id' => $c['order']->id]);
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now()));
    }

    public function test_collection_uses_primary_snapshotted_at_issue_and_issued_order_rejects_switch(): void
    {
        $c = $this->context();
        $second = $this->captain($c['branch']);
        app(OrderCaptainService::class)->switchPrimary($c['employee'], $c['order'], $second, 'Collection handoff');
        $issued = $this->issued($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $issued, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        $this->assertSame($second->id, $collection->captain_id);
        $third = $this->captain($c['branch']);
        $this->expectValidation(fn () => app(OrderCaptainService::class)->switchPrimary($c['employee'], $c['order'], $third, 'After issue'));
        $this->assertSame($second->id, $collection->fresh()->captain_id);
        $this->assertSame($collection->order_captain_id, $collection->fresh()->order_captain_id);
    }

    public function test_sham_cash_collection_has_no_captain_and_preserves_optional_reference(): void
    {
        $c = $this->context();
        $issued = $this->issued($c);
        $service = app(InvoiceCollectionService::class);
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::ShamCash, '29.99', $c['currency']->id, now()));
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::ShamCash, '30.01', $c['currency']->id, now()));
        $other = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'decimal_places' => 2]);
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::ShamCash, '30', $other->id, now()));

        $collection = $service->record($c['employee'], $issued, InvoiceCollectionMethod::ShamCash, '30', $c['currency']->id, now(), null, 'SC-123');
        $this->assertSame(InvoiceCollectionMethod::ShamCash, $collection->collection_method);
        $this->assertNull($collection->captain_id);
        $this->assertNull($collection->order_captain_id);
        $this->assertSame('SC-123', $collection->payment_reference);
        $this->actingAs($c['employee'])->get(route('orders.invoices.show', [$c['order'], $issued]))
            ->assertOk()->assertSee('محصّلة بالكامل')->assertSee('شام كاش')
            ->assertSee(__('financial.ui.direct_sham'))->assertSee('SC-123')->assertSee('30.0000');
        $this->get(route('invoice-collections.index'))->assertOk()->assertSee('تحصيلات الزبائن')
            ->assertSee('شام كاش')->assertSee('SC-123')->assertSee($c['employee']->name);
        $this->assertSame($c['employee']->id, $collection->recorded_by);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->expectValidation(fn () => $service->record($c['employee'], $issued, InvoiceCollectionMethod::ShamCash, '30', $c['currency']->id, now()));
    }

    public function test_collection_method_is_required_valid_and_sham_cash_reference_is_optional(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $payload = ['collected_at' => now()->format('Y-m-d H:i:s')];

        $this->actingAs($c['employee'])->post(route('invoices.collection.store', $invoice), $payload)->assertSessionHasErrors('collection_method');
        $this->actingAs($c['employee'])->post(route('invoices.collection.store', $invoice), $payload + ['collection_method' => 'CARD'])->assertSessionHasErrors('collection_method');
        $this->actingAs($c['employee'])->post(route('invoices.collection.store', $invoice), $payload + ['collection_method' => 'SHAM_CASH'])->assertSessionHasNoErrors();
        $this->assertNull($invoice->collection()->sole()->payment_reference);
    }

    public function test_sham_cash_reconciliation_is_owner_only_filtered_and_currency_separated(): void
    {
        $usd = $this->context('USD');
        $usdInvoice = $this->issued($usd);
        app(InvoiceCollectionService::class)->record($usd['employee'], $usdInvoice, InvoiceCollectionMethod::ShamCash, '30', $usd['currency']->id, now(), null, 'USD-REF');

        $eur = $this->context('EUR');
        $eurInvoice = $this->issued($eur);
        app(InvoiceCollectionService::class)->record($eur['employee'], $eurInvoice, InvoiceCollectionMethod::ShamCash, '30', $eur['currency']->id, now());

        $cash = $this->context('SYP');
        $cashInvoice = $this->issued($cash);
        app(InvoiceCollectionService::class)->record($cash['employee'], $cashInvoice, InvoiceCollectionMethod::Cash, '30', $cash['currency']->id, now());

        $this->actingAs($usd['employee'])->get(route('invoice-collections.sham-cash'))->assertForbidden();
        $captainUser = $usd['captain']->user;
        $captainUser->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->actingAs($captainUser)->get(route('invoice-collections.sham-cash'))->assertForbidden();

        $response = $this->actingAs($usd['admin'])->get(route('invoice-collections.sham-cash', ['from' => today()->toDateString(), 'to' => today()->toDateString()]));
        $response->assertOk()->assertSee($usdInvoice->invoice_number)->assertSee($eurInvoice->invoice_number)->assertDontSee($cashInvoice->invoice_number)->assertSee('USD-REF')
            ->assertSee('مطابقة شام كاش')->assertSee(__('financial.ui.sham_help'));
        $totals = $response->viewData('totals')->pluck('total', 'code')->all();
        $this->assertEquals(['EUR' => '30.0000', 'USD' => '30.0000'], $totals);
        $this->actingAs($usd['admin'])->get(route('invoice-collections.sham-cash', ['from' => today()->addDay()->toDateString()]))->assertDontSee($usdInvoice->invoice_number);
    }

    public function test_collected_invoice_cannot_be_voided_or_replaced_and_collection_is_immutable(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now(), 'Original', 'CASH-REF');
        $original = $collection->fresh()->getAttributes();

        $this->expectValidation(fn () => app(InvoiceService::class)->void($c['admin'], $c['order'], $invoice, 'Cannot void paid Invoice'));
        $this->expectValidation(fn () => app(InvoiceService::class)->replace($c['admin'], $c['order'], $invoice, 'Cannot replace paid Invoice'));

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertEquals($original, $collection->fresh()->getAttributes());
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route) => str_contains((string) $route->getName(), 'collection') && in_array('PUT', $route->methods(), true)));
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route) => str_contains((string) $route->getName(), 'collection') && in_array('PATCH', $route->methods(), true)));
    }

    public function test_branch_scope_nested_ownership_permissions_and_customer_privacy(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $outsider = User::factory()->create();
        $outsider->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->actingAs($outsider)->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertForbidden();
        $otherOrder = app(OrderService::class)->create($c['employee'], ['branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id, 'customer_phone_id' => $c['customer']->phones()->first()->id, 'customer_address_id' => $c['customer']->addresses()->first()->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE', 'ordered_at' => now()]);
        $this->actingAs($c['employee'])->get(route('orders.invoices.show', [$otherOrder, $invoice]))->assertNotFound();
        $response = $this->actingAs($c['employee'])->get(route('orders.invoices.print', [$c['order'], $invoice]));
        $response->assertOk()->assertDontSee('Captain entitlement')->assertDontSee('company collectible')->assertDontSee('shortfall')->assertDontSee('override threshold');
        $codes = Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->where(fn ($q) => $q->where('code', 'like', 'invoices.%')->orWhere('code', 'like', 'invoice_collections.%'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['invoices.view', 'invoices.create', 'invoices.issue', 'invoice_collections.view', 'invoice_collections.create'], $codes);
        $this->assertFalse($c['employee']->hasPermission('invoices.void'));
        $this->assertFalse($c['employee']->hasPermission('invoices.replace'));
        $this->assertFalse($c['employee']->hasPermission('invoice_collections.sham_cash.view'));
        $this->assertCount(0, Role::where('code', 'CAPTAIN')->sole()->permissions()->where(fn ($q) => $q->where('code', 'like', 'invoices.%')->orWhere('code', 'like', 'invoice_collections.%'))->get());
    }

    public function test_database_constraints_protect_current_invoice_and_collection(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $attributes = $invoice->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        $attributes['invoice_number'] = 'INV-DUPLICATE';
        $attributes['version'] = 2;
        try {
            DB::transaction(fn () => Invoice::create($attributes));
            $this->fail('Partial unique current-Invoice index failed.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        $attributes = $collection->getAttributes();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        try {
            DB::transaction(fn () => $collection->newQuery()->create($attributes));
            $this->fail('Unique collection constraint failed.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_phase_nine_a_core_exists_without_order_financial_integration_tables(): void
    {
        $this->context();
        $this->assertDatabaseCount('financial_transactions', 0);
        foreach (['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        foreach (['captain_deposits', 'captain_receivables', 'cash_handovers', 'settlement_sessions', 'commissions', 'incentives', 'salaries'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_phase_9c3a_collected_invoice_cannot_be_voided_or_arbitrarily_replaced_by_sql(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice,
            InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        foreach (['VOIDED', 'DRAFT', 'READY', 'REPLACED'] as $status) {
            try {
                DB::transaction(function () use ($invoice, $c, $status): void {
                    DB::table('invoices')->where('id', $invoice->id)->update([
                        'status' => $status, 'voided_at' => now(), 'voided_by' => $c['admin']->id,
                        'void_reason' => 'SQL bypass', 'replaced_at' => now(), 'replaced_by' => $c['admin']->id,
                        'replacement_reason' => 'SQL bypass', 'superseded_at' => now(),
                    ]);
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('Direct SQL changed collected Invoice status to '.$status);
            } catch (QueryException $exception) {
                $this->assertStringContainsString($status === 'REPLACED'
                    ? 'requires completed financial correction' : 'cannot transition', $exception->getMessage());
            }
        }
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertNull($invoice->fresh()->superseded_at);
        $this->assertDatabaseHas('invoice_collections', ['id' => $collection->id, 'amount' => '30.0000']);
    }

    public function test_phase_9c3a_committed_refund_cannot_be_stranded_by_direct_invoice_void(): void
    {
        Storage::fake(CustomerRefundEvidenceService::DISK);
        $c = $this->context();
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice,
            InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        $service = app(InvoiceFinancialCorrectionService::class);
        $source = $service->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::Reprice,
            ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                'delivery' => [['order_captain_id' => $c['order']->captainAssignments()->sole()->id, 'customer_amount' => '5.00']]],
            'Wrong price', 'Refund must remain finalizable', (string) Str::uuid());
        $service->prepare($c['admin'], $source);
        $refund = app(CustomerRefundService::class)->record($c['admin'], $source, (string) Str::uuid(), now(),
            'REFUND-BEFORE-VOID', UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG)));
        try {
            DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update([
                'status' => 'VOIDED', 'voided_at' => now(), 'voided_by' => $c['admin']->id,
                'void_reason' => 'Attempt to strand refund', 'superseded_at' => now(),
            ]));
            $this->fail('A collected Invoice was voided after its real refund.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('cannot transition to VOIDED', $exception->getMessage());
        }
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertDatabaseHas('customer_refunds', ['id' => $refund->id, 'amount' => '5.0000']);
        $completed = $service->finalize($c['admin'], $source);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(InvoiceFinancialCorrectionState::Completed, $completed->state);
        $this->assertSame(InvoiceStatus::Replaced, $invoice->fresh()->status);
        $this->assertSame('25.0000', Invoice::findOrFail($completed->replacement_invoice_id)->grand_total);
    }

    public function test_phase_9c3a_uncollected_invoice_normal_void_remains_valid(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        // RefreshDatabase keeps one outer transaction; first validate the already-issued snapshot as a real issue commit does.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        app(InvoiceService::class)->void($c['admin'], $c['order'], $invoice, 'Uncollected correction');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(InvoiceStatus::Voided, $invoice->fresh()->status);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_phase_9c3a_new_correction_rejects_non_current_or_uncollected_invoice_sources(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice,
            InvoiceCollectionMethod::ShamCash, '30', $c['currency']->id, now());
        $service = app(InvoiceFinancialCorrectionService::class);
        $source = $service->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::Reprice,
            ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '10.00']],
                'delivery' => [['order_captain_id' => $c['order']->captainAssignments()->sole()->id, 'customer_amount' => '5.00']]],
            'Source matrix', 'Only authoritative collected issued sources', (string) Str::uuid());
        $base = (array) DB::table('invoice_financial_corrections')->where('id', $source->id)->sole();
        unset($base['id']);
        $service->abandon($c['admin'], $source);
        $this->actingAs($c['admin'])->get(route('orders.invoices.show', [$c['order'], $invoice]))
            ->assertOk()->assertSee('تم إيقاف طلب التصحيح');
        foreach (['DRAFT', 'READY', 'VOIDED', 'REPLACED', 'ISSUED'] as $index => $status) {
            $facts = (array) DB::table('invoices')->where('id', $invoice->id)->sole();
            unset($facts['id']);
            $facts['invoice_number'] = 'INVALID-'.Str::uuid();
            $facts['version'] = $index + 2;
            $facts['status'] = $status;
            $facts['superseded_at'] = now();
            $invalidId = DB::table('invoices')->insertGetId($facts);
            try {
                DB::transaction(fn () => DB::table('invoice_financial_corrections')->insert(array_replace($base, [
                    'request_key' => (string) Str::uuid(), 'original_invoice_id' => $invalidId,
                ])));
                $this->fail('New correction accepted '.$status.' / uncollected Invoice.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('authoritative collected ISSUED Invoice', $exception->getMessage());
            }
        }
        $other = $this->context('EUR');
        $uncollected = $this->issued($other);
        try {
            DB::transaction(fn () => DB::table('invoice_financial_corrections')->insert(array_replace($base, [
                'request_key' => (string) Str::uuid(), 'order_id' => $other['order']->id,
                'original_invoice_id' => $uncollected->id, 'branch_id' => $other['branch']->id,
                'currency_id' => $other['currency']->id,
            ])));
            $this->fail('New correction accepted a current uncollected ISSUED Invoice with an unrelated Collection.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('authoritative collected ISSUED Invoice', $exception->getMessage());
        }
        $prepared = $service->prepare($c['admin'], $service->request($c['employee'], $invoice,
            InvoiceFinancialCorrectionKind::Reprice, $source->proposal_payload,
            'Valid source', 'Zero delta replacement', (string) Str::uuid()));
        $service->finalize($c['admin'], $prepared);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        try {
            DB::transaction(fn () => DB::table('invoice_financial_corrections')->insert(array_replace($base, [
                'request_key' => (string) Str::uuid(),
            ])));
            $this->fail('New correction reused an already REPLACED historical original.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('authoritative collected ISSUED Invoice', $exception->getMessage());
        }
    }

    public function test_phase_9c3a_request_prepare_and_historical_cash_refund_source(): void
    {
        Storage::fake(CustomerRefundEvidenceService::DISK);
        Storage::fake('order_receipts');
        $c = $this->context();
        $invoice = $this->issued($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now(), 'Original', 'CASH-REF');
        $assignment = $c['order']->captainAssignments()->sole();
        $proposal = ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
            'delivery' => [['order_captain_id' => $assignment->id, 'customer_amount' => '5.00']]];
        $corrections = app(InvoiceFinancialCorrectionService::class);
        $correctionKey = (string) Str::uuid();
        $source = $corrections->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::Reprice, $proposal, 'Wrong product price', 'Corrected proof', $correctionKey);
        $this->assertSame(InvoiceFinancialCorrectionState::Requested, $source->state);
        try {
            $corrections->prepare($c['employee'], $source);
            $this->fail('ORDER_EMPLOYEE prepared a financial correction.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $prepared = $corrections->prepare($c['admin'], $source);
        $this->assertSame(InvoiceFinancialCorrectionState::Prepared, $prepared->state);
        $this->assertSame('25.0000', $prepared->corrected_amount);
        $this->assertSame('-5.0000', $prepared->customer_delta);
        $refundKey = (string) Str::uuid();
        $refundAt = now();
        $refund = app(CustomerRefundService::class)->record($c['admin'], $prepared, $refundKey, $refundAt, 'CASH-REFUND-1',
            UploadedFile::fake()->createWithContent('refund.png', base64_decode(self::PNG)));
        $this->assertSame($collection->captain_id, $refund->refunding_captain_id);
        $this->assertSame($collection->order_captain_id, $refund->refunding_order_captain_id);
        $this->assertSame(InvoiceFinancialCorrectionState::ReadyToFinalize, $source->fresh()->state);
        $this->assertDatabaseCount('financial_transactions', 0);
        try {
            DB::transaction(function () use ($source, $c): void {
                DB::table('invoice_financial_corrections')->where('id', $source->id)->update([
                    'state' => 'COMPLETED', 'finalized_by' => $c['admin']->id, 'finalized_at' => now(),
                ]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Direct SQL completed a correction without the replacement and snapshot.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('lacks exact immutable financial disposition', $exception->getMessage());
        }
        foreach ([
            fn () => DB::table('invoice_collections')->where('id', $collection->id)->update(['amount' => 29]),
            fn () => DB::table('invoices')->where('id', $invoice->id)->update(['grand_total' => 29]),
            fn () => DB::table('customer_refunds')->where('id', $refund->id)->update(['amount' => 4]),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('Historical financial fact was mutable through SQL.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $completed = $corrections->finalize($c['admin'], $prepared);
        $this->assertSame(InvoiceFinancialCorrectionState::Completed, $completed->state);
        $this->assertSame($source->id, $corrections->request($c['employee'], $invoice,
            InvoiceFinancialCorrectionKind::Reprice, $proposal, 'Wrong product price', 'Corrected proof', $correctionKey)->id);
        $this->assertSame($refund->id, app(CustomerRefundService::class)->record($c['admin'], $prepared,
            $refundKey, $refundAt, 'CASH-REFUND-1',
            UploadedFile::fake()->createWithContent('refund.png', base64_decode(self::PNG)))->id);
        $this->assertSame(InvoiceStatus::Replaced, $invoice->fresh()->status);
        $replacement = Invoice::findOrFail($completed->replacement_invoice_id);
        $this->assertSame('25.0000', $replacement->grand_total);
        $this->assertSame(InvoiceStatus::Issued, $replacement->status);
        $this->assertDatabaseCount('invoice_collections', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
            OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
            OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), $status, null);
        }
        app(OrderShopProgressService::class)->record($c['employee'], $c['order']->fresh(), $c['shop'], 'picked_up_at');
        app(OrderShopReceiptService::class)->upload($c['employee'], $c['order']->fresh(), $c['shop'],
            UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG)), null);
        app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $this->assertDatabaseHas('financial_transactions', ['source_type' => 'INVOICE_FINANCIAL_CORRECTION',
            'source_id' => (string) $completed->id, 'event_type' => 'INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_phase_9c3a_collected_cancellation_requires_real_refund_and_shop_return(): void
    {
        Storage::fake(CustomerRefundEvidenceService::DISK);
        Storage::fake('order_returns');
        $c = $this->context();
        $assignment = $c['order']->captainAssignments()->sole();
        $purchase = app(OrderShopPurchaseConfirmationService::class)->record($c['employee'], $c['order'], $c['shop'], [
            'order_captain_id' => $assignment->id, 'currency_id' => $c['currency']->id,
            'amount' => '25.00', 'purchased_at' => now()->subMinutes(5)->toIso8601String(),
            'request_key' => (string) Str::uuid(),
        ], UploadedFile::fake()->createWithContent('purchase.png', base64_decode(self::PNG)));
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash,
            '30', $c['currency']->id, now(), null, 'CASH-CANCEL');
        $corrections = app(InvoiceFinancialCorrectionService::class);
        $source = $corrections->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::CancelAndRefund,
            [], 'Customer cancelled', 'Full refund after Shop return', (string) Str::uuid());
        $intent = app(OrderCancellationIntentService::class)->create($c['employee'], $c['order'],
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $this->assertNotNull($intent->frozen_at);
        $prepared = $corrections->prepare($c['admin'], $source);
        $this->assertSame('0.0000', $prepared->corrected_amount);
        $this->expectValidation(fn () => $corrections->finalize($c['admin'], $prepared));
        app(OrderShopReturnService::class)->record($c['employee'], $c['order'], $c['shop'], [
            'returning_order_captain_id' => $assignment->id,
            'refund_amount' => '25.00',
            'returned_at' => now()->subMinutes(2)->toIso8601String(),
            'refunded_at' => now()->subMinute()->toIso8601String(),
            'refund_method' => 'CASH',
            'request_key' => (string) Str::uuid(),
        ], ['RETURN_AND_REFUND_PROOF' => UploadedFile::fake()->createWithContent('return.png', base64_decode(self::PNG))]);
        app(CustomerRefundService::class)->record($c['admin'], $prepared, (string) Str::uuid(), now(),
            'CASH-FULL-REFUND', UploadedFile::fake()->createWithContent('refund.png', base64_decode(self::PNG)));
        $completed = $corrections->finalize($c['admin'], $prepared);
        $this->assertSame(InvoiceFinancialCorrectionState::Completed, $completed->state);
        $this->assertNull($completed->replacement_invoice_id);
        $this->assertSame(OrderOperationalStatus::Cancelled, $c['order']->fresh()->operational_status);
        $this->assertDatabaseCount('financial_transactions', 0);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_phase_9c3a_delivered_cash_correction_reverses_original_and_posts_replacement(): void
    {
        Storage::fake(CustomerRefundEvidenceService::DISK);
        Storage::fake('order_receipts');
        $c = $this->context();
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash,
            '30', $c['currency']->id, now(), null, 'CASH-DELIVERED');
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
            OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
            OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), $status, null);
        }
        app(OrderShopProgressService::class)->record($c['employee'], $c['order']->fresh(), $c['shop'], 'picked_up_at');
        app(OrderShopReceiptService::class)->upload($c['employee'], $c['order']->fresh(), $c['shop'],
            UploadedFile::fake()->createWithContent('delivered.png', base64_decode(self::PNG)), null);
        app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $this->assertDatabaseHas('financial_transactions', ['event_type' => 'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT']);
        $assignment = $c['order']->captainAssignments()->sole();
        $source = app(InvoiceFinancialCorrectionService::class)->request($c['employee'], $invoice,
            InvoiceFinancialCorrectionKind::Reprice,
            ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                'delivery' => [['order_captain_id' => $assignment->id, 'customer_amount' => '5.00']]],
            'Wrong price', 'Corrected invoice proof', (string) Str::uuid());
        $prepared = app(InvoiceFinancialCorrectionService::class)->prepare($c['admin'], $source);
        app(CustomerRefundService::class)->record($c['admin'], $prepared, (string) Str::uuid(), now(),
            'CASH-REFUND-DELIVERED', UploadedFile::fake()->createWithContent('refund.png', base64_decode(self::PNG)));
        $completed = app(InvoiceFinancialCorrectionService::class)->finalize($c['admin'], $prepared);
        $this->assertSame(InvoiceFinancialCorrectionState::Completed, $completed->state);
        $this->assertDatabaseHas('financial_transactions', ['source_type' => 'INVOICE_FINANCIAL_CORRECTION',
            'source_id' => (string) $completed->id, 'event_type' => 'INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT']);
        $this->assertDatabaseHas('financial_transactions', ['source_type' => 'INVOICE_FINANCIAL_CORRECTION_REVERSAL',
            'event_type' => 'INVOICE_CORRECTION_REVERSE_ORIGINAL']);
        $event = DB::table('financial_transactions')->where('source_type', 'INVOICE_FINANCIAL_CORRECTION')
            ->where('source_id', (string) $completed->id)->where('event_type', 'INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT')->sole();
        $accounts = app(FinancialAccountService::class);
        $extraAccount = $accounts->getOrCreate($accounts->ownerForCaptain($c['captain']), $c['currency'],
            FinancialAccountKind::CaptainCompanyReceivable);
        try {
            DB::transaction(function () use ($event, $extraAccount, $c): void {
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $event->id,
                    'financial_account_id' => $extraAccount->id,
                    'currency_id' => $c['currency']->id,
                    'direction' => 'INCREASE', 'amount' => '1.0000',
                ]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('A hidden extra correction FinancialEntry was accepted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Entries may only be added to a DRAFT financial transaction', $exception->getMessage());
        }
        try {
            DB::transaction(function () use ($completed, $extraAccount, $c): void {
                $id = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT',
                    'source_type' => 'INVOICE_FINANCIAL_CORRECTION', 'source_id' => (string) $completed->id,
                    'event_type' => 'INVOICE_CORRECTION_CASH_SHORTFALL_DUE', 'source_version' => 1,
                    'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                    'payload_hash' => str_repeat('a', 64), 'occurred_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $id, 'financial_account_id' => $extraAccount->id,
                    'currency_id' => $c['currency']->id, 'direction' => 'INCREASE', 'amount' => '1.0000',
                ]);
                DB::table('financial_transactions')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now()]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('A correction event with no expected movement was accepted.');
        } catch (QueryException $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'incomplete or extra FinancialEntry set')
                    || str_contains($exception->getMessage(), 'Completed correction'),
                $exception->getMessage(),
            );
        }
    }

    public function test_phase_9c3a_http_request_refund_evidence_and_replacement_payment_satisfaction(): void
    {
        Storage::fake(CustomerRefundEvidenceService::DISK);
        $c = $this->context();
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            '30', $c['currency']->id, now());
        $assignment = $c['order']->captainAssignments()->sole();
        $otherBranch = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        $otherEmployee = User::factory()->create();
        $otherEmployee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $otherEmployee->branches()->attach($otherBranch);
        $this->actingAs($c['employee'])->get(route('orders.invoices.show', [$c['order'], $invoice]))
            ->assertOk()->assertSee(__('financial.ui.request_correction'))
            ->assertSee($c['captain']->user->name)->assertSee('financial-ui', false);
        $request = [
            'kind' => 'REPRICE', 'request_key' => (string) Str::uuid(),
            'reason' => 'Wrong unit price', 'explanation' => 'Verified replacement price',
            'items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
            'delivery' => [['order_captain_id' => $assignment->id, 'customer_amount' => '5.00']],
        ];
        $this->actingAs($otherEmployee)->post(route('invoices.corrections.request', $invoice), $request)->assertForbidden();
        $this->actingAs($c['employee'])->post(route('invoices.corrections.request', $invoice), $request)->assertSessionHasNoErrors();
        $source = InvoiceFinancialCorrection::query()->sole();
        $this->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()
            ->assertSee('تصحيح السعر')->assertSee('بانتظار التجهيز');
        $this->actingAs($c['employee'])->post(route('invoice-corrections.prepare', $source))->assertForbidden();
        $this->actingAs($c['admin'])->post(route('invoice-corrections.prepare', $source))->assertSessionHasNoErrors();
        $this->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()
            ->assertSee('تم التجهيز')->assertSee(__('financial.ui.required_refund'))->assertSee('شام كاش');
        $this->actingAs($c['employee'])->post(route('invoice-corrections.refund', $source), [
            'request_key' => (string) Str::uuid(), 'refunded_at' => now()->format('Y-m-d H:i:s'),
            'reference' => 'EMPLOYEE-CANNOT-REFUND',
            'evidence' => UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG)),
        ])->assertForbidden();
        $this->actingAs($c['admin'])->post(route('invoice-corrections.refund', $source), [
            'request_key' => (string) Str::uuid(), 'refunded_at' => now()->format('Y-m-d H:i:s'),
            'reference' => 'SHAM-REFUND-1',
            'evidence' => UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG)),
        ])->assertSessionHasNoErrors();
        $refund = $source->refund()->sole();
        $this->assertSame(InvoiceCollectionMethod::ShamCash, $refund->method);
        $this->assertNull($refund->refunding_captain_id);
        $this->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()
            ->assertSee('جاهز للإتمام')->assertSee(__('financial.ui.customer_refund_evidence'))
            ->assertSee(__('financial.ui.company_refund'))->assertSee('5.0000');
        $this->actingAs($c['employee'])->get(route('customer-refunds.evidence', $refund))->assertOk();
        $this->actingAs($otherEmployee)->get(route('customer-refunds.evidence', $refund))->assertForbidden();
        $c['captain']->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->actingAs($c['captain']->user)->get(route('customer-refunds.evidence', $refund))->assertForbidden();
        $this->actingAs($c['admin'])->get(route('customer-refunds.evidence', $refund))->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->actingAs($c['employee'])->post(route('invoice-corrections.finalize', $source))->assertForbidden();
        $this->actingAs($c['admin'])->post(route('invoice-corrections.finalize', $source))->assertSessionHasNoErrors();
        $replacement = Invoice::findOrFail($source->fresh()->replacement_invoice_id);
        $this->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()->assertSee('مكتمل');
        $this->get(route('orders.invoices.show', [$c['order'], $replacement]))->assertOk()
            ->assertSee(__('financial.ui.settled_payment', ['id' => $source->original_collection_id]));
        $this->actingAs($c['employee'])->post(route('invoices.collection.store', $replacement), [
            'collection_method' => 'SHAM_CASH', 'collected_at' => now()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('invoice');
        $this->assertDatabaseMissing('invoice_collections', ['invoice_id' => $replacement->id]);
    }

    public function test_phase_9c3a_corrected_sham_receivable_remains_payable_and_paid_original_blocks_correction(): void
    {
        $this->useMismatchedCorrectionTimezones();
        Storage::fake(CustomerRefundEvidenceService::DISK);
        Storage::fake('order_receipts');
        Storage::fake('captain_financial_evidence');
        $c = $this->context();
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            '30', $c['currency']->id, now());
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
            OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
            OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), $status, null);
        }
        app(OrderShopProgressService::class)->record($c['employee'], $c['order']->fresh(), $c['shop'], 'picked_up_at');
        app(OrderShopReceiptService::class)->upload($c['employee'], $c['order']->fresh(), $c['shop'],
            UploadedFile::fake()->createWithContent('delivered.png', base64_decode(self::PNG)), null);
        app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $assignment = $c['order']->captainAssignments()->sole();
        $proposal = ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
            'delivery' => [['order_captain_id' => $assignment->id, 'customer_amount' => '5.00']]];
        $corrections = app(InvoiceFinancialCorrectionService::class);
        $source = $corrections->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::Reprice,
            $proposal, 'Wrong price', 'Correct SHAM price', (string) Str::uuid());
        $prepared = $corrections->prepare($c['admin'], $source);
        $this->assertCorrectionInstantsMatch($prepared);
        app(CustomerRefundService::class)->record($c['admin'], $prepared, (string) Str::uuid(), now(),
            'SHAM-REFUND', UploadedFile::fake()->createWithContent('refund.png', base64_decode(self::PNG)));
        $completed = $corrections->finalize($c['admin'], $prepared);
        $payable = app(FinancialBalanceQueryService::class)->payableReceivableEntries($c['captain'], $c['branch'], $c['currency']);
        $this->assertCount(2, $payable);
        $this->assertTrue($payable->every(fn ($row) => $row->source_type === 'INVOICE_FINANCIAL_CORRECTION'
            && $row->source_id === (string) $completed->id));
        $this->actingAs($c['admin'])->get(route('captains.financials.show', [
            'captain' => $c['captain'], 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
        ]))->assertOk()->assertSee(__('financial.events.INVOICE_CORRECTION_SHAM_PRODUCT_DUE'))
            ->assertSee(__('financial.events.INVOICE_CORRECTION_SHAM_DELIVERY_DUE'))
            ->assertSee(__('financial.ui.this_payout'))->assertSee('USD');
        $first = $payable->first();
        $payout = app(CaptainReceivablePayoutService::class)->record($c['admin'], $c['captain'], $c['branch'],
            $c['currency'], ['request_key' => (string) Str::uuid(), 'amount' => (string) $first->remaining_amount,
                'occurred_at' => now()->subSecond()->toIso8601String(), 'reference' => 'CORRECTED-PAYOUT'],
            [['financial_entry_id' => $first->financial_entry_id, 'amount' => (string) $first->remaining_amount]],
            UploadedFile::fake()->createWithContent('payout.png', base64_decode(self::PNG)));
        $this->assertDatabaseHas('captain_receivable_payout_allocations', [
            'captain_receivable_payout_id' => $payout->id, 'financial_entry_id' => $first->financial_entry_id]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_phase_9c3a_paid_original_receivable_requires_explicit_captain_recovery(): void
    {
        $this->useMismatchedCorrectionTimezones();
        Storage::fake('order_receipts');
        Storage::fake('captain_financial_evidence');
        Storage::fake('customer_refunds');
        $c = $this->context();
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash,
            '30', $c['currency']->id, now());
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
            OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
            OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), $status, null);
        }
        app(OrderShopProgressService::class)->record($c['employee'], $c['order']->fresh(), $c['shop'], 'picked_up_at');
        app(OrderShopReceiptService::class)->upload($c['employee'], $c['order']->fresh(), $c['shop'],
            UploadedFile::fake()->createWithContent('delivered.png', base64_decode(self::PNG)), null);
        app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $productDue = app(FinancialBalanceQueryService::class)
            ->payableReceivableEntries($c['captain'], $c['branch'], $c['currency'])
            ->firstWhere('event_type', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE');
        $this->assertNotNull($productDue);
        $payout = app(CaptainReceivablePayoutService::class)->record($c['admin'], $c['captain'], $c['branch'],
            $c['currency'], ['request_key' => (string) Str::uuid(), 'amount' => (string) $productDue->amount,
                'occurred_at' => now()->subSecond()->toIso8601String(), 'reference' => 'ORIGINAL-PAYOUT'],
            [['financial_entry_id' => $productDue->financial_entry_id, 'amount' => (string) $productDue->amount]],
            UploadedFile::fake()->createWithContent('payout.png', base64_decode(self::PNG)));
        $assignment = $c['order']->captainAssignments()->sole();
        $source = app(InvoiceFinancialCorrectionService::class)->request($c['employee'], $invoice,
            InvoiceFinancialCorrectionKind::Reprice,
            ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                'delivery' => [['order_captain_id' => $assignment->id, 'customer_amount' => '5.00']]],
            'Product price correction', 'Captain has already received original amount', (string) Str::uuid());
        // 9C.3B.1 freezes the overpayment instead of rejecting preparation; completion still fails closed.
        $prepared = app(InvoiceFinancialCorrectionService::class)->prepare($c['admin'], $source);
        $this->assertCorrectionInstantsMatch($prepared);
        $this->assertTrue($prepared->receivableDispositions()->where('recovery_required', '>', 0)->exists());
        app(CustomerRefundService::class)->record($c['admin'], $prepared, (string) Str::uuid(), now(),
            'RECOVERY-PENDING-REFUND', UploadedFile::fake()->createWithContent('refund.png', base64_decode(self::PNG)));
        try {
            app(InvoiceFinancialCorrectionService::class)->finalize($c['admin'], $prepared->fresh());
            $this->fail('An overpaid historical Receivable was silently reversed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('CAPTAIN_RECOVERY_REQUIRED', $exception->errors()['correction'][0]);
            $this->assertTrue($prepared->receivableDispositions()->where('original_receivable_financial_entry_id', $productDue->financial_entry_id)->exists());
        }
        $this->assertDatabaseHas('captain_receivable_payout_allocations', [
            'captain_receivable_payout_id' => $payout->id, 'financial_entry_id' => $productDue->financial_entry_id]);
        $this->assertDatabaseCount('invoice_correction_reversals', 0);
        $this->assertSame(InvoiceFinancialCorrectionState::ReadyToFinalize, $source->fresh()->state);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_phase_9c3a_postgresql_rejects_invalid_refund_money_method_and_collector(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice,
            InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        $assignment = $c['order']->captainAssignments()->sole();
        $source = app(InvoiceFinancialCorrectionService::class)->request($c['employee'], $invoice,
            InvoiceFinancialCorrectionKind::Reprice,
            ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                'delivery' => [['order_captain_id' => $assignment->id, 'customer_amount' => '5.00']]],
            'Wrong price', 'Direct SQL refund protection', (string) Str::uuid());
        app(InvoiceFinancialCorrectionService::class)->prepare($c['admin'], $source);
        $base = [
            'invoice_financial_correction_id' => $source->id,
            'original_invoice_id' => $invoice->id, 'original_collection_id' => $collection->id,
            'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'amount' => '5.00', 'method' => 'CASH',
            'refunding_captain_id' => $collection->captain_id,
            'refunding_order_captain_id' => $collection->order_captain_id,
            'authorized_by' => $c['admin']->id, 'request_key' => (string) Str::uuid(),
            'refunded_at' => now(), 'reference' => 'SQL-TAMPER',
            'evidence_path' => 'proof/tamper.png', 'evidence_name' => 'tamper.png',
            'evidence_mime' => 'image/png', 'evidence_size' => 68,
            'evidence_sha256' => str_repeat('a', 64), 'payload_hash' => str_repeat('b', 64),
        ];
        $otherCaptain = $this->captain($c['branch']);
        foreach ([
            ['method' => 'SHAM_CASH'],
            ['refunding_captain_id' => $otherCaptain->id],
            ['amount' => '4.00'],
            ['amount' => 'NaN'],
            ['amount' => 'Infinity'],
            ['amount' => '-Infinity'],
            ['amount' => '5.0001'],
            ['evidence_path' => null],
        ] as $tamper) {
            try {
                DB::transaction(fn () => DB::table('customer_refunds')->insert(array_replace($base, $tamper)));
                $this->fail('PostgreSQL accepted invalid direct CustomerRefund money or identity.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('customer_refunds', 0);
    }

    public function test_phase_9c3a_additional_collection_is_rejected_and_zero_delta_needs_no_refund(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice,
            InvoiceCollectionMethod::ShamCash, '30', $c['currency']->id, now());
        $assignment = $c['order']->captainAssignments()->sole();
        $service = app(InvoiceFinancialCorrectionService::class);
        $higher = $service->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::Reprice,
            ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '12.00']],
                'delivery' => [['order_captain_id' => $assignment->id, 'customer_amount' => '5.00']]],
            'Higher amount', 'Requires additional collection outside this phase', (string) Str::uuid());
        try {
            $service->prepare($c['admin'], $higher);
            $this->fail('A higher corrected customer obligation was prepared.');
        } catch (ValidationException $exception) {
            $this->assertSame('يلزم تحصيل إضافي (ADDITIONAL_COLLECTION_REQUIRED).', $exception->errors()['correction'][0]);
        }
        $service->abandon($c['admin'], $higher);
        $equal = $service->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::Reprice,
            ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '10.00']],
                'delivery' => [['order_captain_id' => $assignment->id, 'customer_amount' => '5.00']]],
            'Description correction', 'Customer total remains the same', (string) Str::uuid());
        $prepared = $service->prepare($c['admin'], $equal);
        $this->assertSame('0.0000', $prepared->customer_delta);
        $this->assertSame(InvoiceFinancialCorrectionState::ReadyToFinalize, $prepared->state);
        $completed = $service->finalize($c['admin'], $prepared);
        $this->assertSame(InvoiceFinancialCorrectionState::Completed, $completed->state);
        $this->assertDatabaseCount('customer_refunds', 0);
        $this->assertSame('30.0000', Invoice::findOrFail($completed->replacement_invoice_id)->grand_total);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_phase_9c3a_completed_external_bill_payment_forbids_cancellation_correction(): void
    {
        Storage::fake('order_receipts');
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->context();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDays(2));
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice,
            InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
            OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
            OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), $status, null);
        }
        app(OrderShopProgressService::class)->record($c['employee'], $c['order']->fresh(), $c['shop'], 'picked_up_at');
        app(OrderShopReceiptService::class)->upload($c['employee'], $c['order']->fresh(), $c['shop'],
            UploadedFile::fake()->createWithContent('delivered.png', base64_decode(self::PNG)), null);
        app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $assignment = $c['order']->captainAssignments()->sole();
        app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, [
            'order_captain_id' => $assignment->id, 'currency_id' => $c['currency']->id,
            'bill_number' => 'EXTERNAL-PAID', 'bill_date' => today()->toDateString(),
            'principal_amount' => '100', 'payment_reference' => 'SHAM-EXTERNAL-PAID',
        ], [UploadedFile::fake()->createWithContent('external.png', base64_decode(self::PNG))]);
        try {
            app(InvoiceFinancialCorrectionService::class)->request($c['employee'], $invoice,
                InvoiceFinancialCorrectionKind::CancelAndRefund, [], 'Customer cancellation',
                'Cannot unwind completed external bill payment', (string) Str::uuid());
            $this->fail('A completed external bill payment entered cancellation correction.');
        } catch (ValidationException $exception) {
            $this->assertSame('لا يمكن إلغاء طلب اكتمل فيه تسديد فاتورة خارجية (COMPLETED_BILL_PAYMENT_ORDER_NOT_CANCELLABLE).', $exception->errors()['correction'][0]);
        }
        $this->assertDatabaseCount('invoice_financial_corrections', 0);
        $this->assertDatabaseCount('customer_refunds', 0);
        $this->assertDatabaseCount('order_cancellations', 0);
        $this->assertDatabaseHas('order_bill_payments', ['order_id' => $c['order']->id]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure did not occur.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function uatEvidence(array $c): void
    {
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $assignment = $c['order']->captainAssignments()->whereNull('removed_at')->sole();
        app(OrderShopPurchaseConfirmationService::class)->record($c['employee'], $c['order'], $c['shop'], [
            'order_captain_id' => $assignment->id, 'currency_id' => $c['currency']->id, 'amount' => '25.00',
            'purchased_at' => now()->subMinute(), 'request_key' => (string) Str::uuid(),
        ], UploadedFile::fake()->createWithContent('purchase.png', base64_decode(self::PNG)));
        app(OrderShopProgressService::class)->record($c['employee'], $c['order'], $c['shop'], 'picked_up_at', null);
        app(OrderShopReceiptService::class)->upload($c['employee'], $c['order'], $c['shop'], UploadedFile::fake()->createWithContent('receipt.png', base64_decode(self::PNG)), null);
    }

    public function test_uat_collection_availability_and_next_action_agree_before_and_after_delivery(): void
    {
        $c = $this->context();
        $this->uatEvidence($c);
        $invoice = $this->issued($c);
        $availability = app(InvoiceCollectionService::class)->availability($c['employee'], $invoice);
        $this->assertTrue($availability['available']);
        $component = Livewire::actingAs($c['employee'])->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'status')->assertSee(__('financial.ui.record_collection'))
            ->assertSee(route('orders.invoices.show', [$c['order'], $invoice]).'#invoice-collection', false);
        $this->actingAs($c['employee'])->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()
            ->assertSee(route('invoices.collection.store', $invoice), false);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        $component->dispatch('order-updated')->assertViewHas('step', 'status')->assertDontSee(__('financial.ui.record_collection'));
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered] as $status) {
            $this->patch(route('orders.status', $c['order']), ['operational_status' => $status->value])->assertSessionHasNoErrors();
        }
        $component->dispatch('order-updated')->assertViewHas('step', 'completed')->assertDontSee('name="operational_status"', false);
        $this->assertSame(7, $c['order']->statusHistory()->count());
        $this->assertSame('DELIVERED', $c['order']->statusHistory()->reorder()->latest('id')->first()->new_status->value);
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee(__('orders.ui_operational_history'))
            ->assertSee(__('orders.ui_history_scope'))->assertSee(__('orders.ui_operational_status_label'))
            ->assertSee(__('orders.ui_actual_price_status_label'))->assertDontSee(route('orders.shops.progress', [$c['order'], $c['shop']]), false);
        $this->assertTrue(DB::table('financial_transactions')->where('source_type', 'INVOICE_COLLECTION')->exists());
    }

    public function test_uat_corrected_current_invoice_is_settled_not_collectible_and_open_order_can_finish(): void
    {
        $c = $this->context();
        $this->uatEvidence($c);
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        $original = $invoice->fresh()->getRawOriginal();
        $service = app(InvoiceFinancialCorrectionService::class);
        $correction = $service->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::Reprice, [
            'items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '10']],
            'delivery' => [['order_captain_id' => $c['order']->captainAssignments()->sole()->id, 'customer_amount' => '5']],
        ], 'Verified same price', 'Explicit correction, no additional payment', (string) Str::uuid());
        $correction = $service->finalize($c['admin'], $service->prepare($c['admin'], $correction));
        $replacement = Invoice::findOrFail($correction->replacement_invoice_id);
        $this->assertFalse(app(InvoiceCollectionService::class)->availability($c['employee'], $replacement)['available']);
        $this->assertTrue(app(InvoiceCollectionService::class)->availability($c['employee'], $replacement)['payment_satisfied']);
        Livewire::actingAs($c['employee'])->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'status')->assertDontSee(__('financial.ui.record_collection'))
            ->assertSee(__('orders.ui_payment_settled_correction'));
        Livewire::actingAs($c['employee'])->test(Workflow::class, ['order' => $c['order']])->call('openStep', 'invoice')
            ->assertViewHas('workflow', fn ($state) => ! $state['canCollect'] && $state['availability']['payment_satisfied'])
            ->assertSee(__('orders.ui_payment_settled_correction'))->assertDontSee('wire:submit="collectInvoice"', false);
        $this->actingAs($c['employee'])->get(route('orders.show', $c['order']))->assertOk()->assertSee(__('orders.ui_payment_settled_correction'));
        $this->get(route('orders.invoices.show', [$c['order'], $replacement]))->assertOk()->assertDontSee(route('invoices.collection.store', $replacement), false);
        $this->expectValidation(fn () => app(InvoiceCollectionService::class)->record($c['employee'], $replacement, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now()));
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered] as $status) {
            app(OrderStatusService::class)->transition($c['employee'], $c['order'], $status, null);
        }
        Livewire::actingAs($c['employee'])->test(Workflow::class, ['order' => $c['order']])
            ->assertViewHas('workflow', fn ($state) => $state['complete'])->assertSee(__('orders.workflow.complete'));
        $this->assertSame('DELIVERED', $c['order']->fresh()->operational_status->value);
        $this->assertSame($original['grand_total'], $invoice->fresh()->getRawOriginal('grand_total'));
        $this->assertSame('10.0000', $invoice->items()->sole()->unit_price);
        $this->assertTrue(DB::table('financial_transactions')->where('source_type', 'INVOICE_FINANCIAL_CORRECTION')->exists());
    }

    public function test_uat_mutable_authoritative_price_refresh_uses_55_without_catalog_rewriting_snapshots(): void
    {
        $c = $this->context();
        $unit = Unit::create(['name' => 'Piece', 'code' => 'UAT_UNIT']);
        $product = Product::create(['name' => 'Catalog item', 'default_unit_id' => $unit->id]);
        $c['item']->update(['product_id' => $product->id, 'item_type' => OrderItemType::Catalog, 'unit_id' => $unit->id]);
        app(OrderItemService::class)->confirmActual($c['employee'], $c['order'], $c['shop'], $c['item'], [
            'actual_unit_price' => '50', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED',
        ]);
        $invoice = $this->ready($c);
        $shop = Shop::create(['name' => 'Catalog Shop', 'area_id' => $c['from']->id]);
        app(ShopProductService::class)->save($c['admin'], $shop, new ShopProduct, [
            'product_id' => $product->id, 'unit_id' => $unit->id, 'listed_price' => '55', 'currency_id' => $c['currency']->id,
            'is_available' => true, 'is_featured' => false,
        ]);
        $this->assertSame('50.0000', $c['item']->fresh()->actual_unit_price);
        $this->assertSame('50.0000', $invoice->items()->sole()->unit_price);
        app(OrderItemService::class)->confirmActual($c['employee'], $c['order'], $c['shop'], $c['item'], [
            'actual_unit_price' => '55', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED',
        ]);
        $this->expectValidation(fn () => app(InvoiceService::class)->issue($c['employee'], $c['order'], $invoice));
        $invoice = app(InvoiceService::class)->refresh($c['employee'], $c['order'], $invoice);
        $this->assertSame('55.0000', $invoice->items()->sole()->unit_price);
        $this->assertSame('137.5000', $invoice->items_subtotal);
        $this->assertSame('142.5000', $invoice->grand_total);
        $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order'], $invoice);
        $before = $invoice->fresh()->getRawOriginal();
        $this->expectValidation(fn () => app(OrderItemService::class)->confirmActual($c['employee'], $c['order'], $c['shop'], $c['item'], [
            'actual_unit_price' => '56', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED',
        ]));
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
    }

    public function test_uat_collection_link_is_hidden_without_actual_collection_permission(): void
    {
        $c = $this->context();
        $invoice = $this->issued($c);
        $c['employee']->roles()->sole()->permissions()->detach(Permission::where('code', 'invoice_collections.create')->sole());
        $this->assertFalse(app(InvoiceCollectionService::class)->availability($c['employee'], $invoice)['available']);
        Livewire::actingAs($c['employee'])->test(NextAction::class, ['order' => $c['order']])->assertDontSee(__('financial.ui.record_collection'));
        $this->actingAs($c['employee'])->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()->assertDontSee(route('invoices.collection.store', $invoice), false);
    }

    public function test_uat_next_action_requires_both_action_and_containing_view_permissions(): void
    {
        $c = $this->context();
        $this->uatEvidence($c);
        $role = Role::create(['code' => 'UX_CUSTOM_ACTION', 'name' => 'Custom action permissions']);
        $role->permissions()->attach(Permission::whereIn('code', ['orders.view', 'orders.pricing.update'])->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach($c['branch']);
        $c['order']->forceFill(['pricing_status' => 'PARTIALLY_CONFIRMED'])->save();
        Livewire::actingAs($user)->test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'waiting');
        Livewire::test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $c['shop']])->assertForbidden();
        $role->permissions()->attach(Permission::where('code', 'orders.update')->sole());
        $this->assertTrue($user->fresh()->can('orders.update'));
        $this->assertTrue($user->fresh()->can('orders.pricing.update'));
        $this->assertSame('PARTIALLY_CONFIRMED', $c['order']->fresh()->pricing_status->value);
        Livewire::actingAs($user->fresh())->test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'actual_prices');
        $c['order']->forceFill(['pricing_status' => 'CONFIRMED'])->save();
        $invoice = $this->ready($c);
        $role->permissions()->sync(Permission::whereIn('code', ['orders.view', 'invoices.create', 'invoices.issue'])->pluck('id'));
        Livewire::actingAs($user)->test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'waiting');
        $this->actingAs($user)->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertForbidden();
        $role->permissions()->attach(Permission::where('code', 'invoices.view')->sole());
        Livewire::actingAs($user->fresh())->test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'invoice_issue');
        $this->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk();
        app(OrderItemService::class)->confirmActual($c['employee'], $c['order'], $c['shop'], $c['item'], [
            'actual_unit_price' => '11', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED',
        ]);
        Livewire::test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'invoice_refresh');
        $role->permissions()->detach(Permission::where('code', 'invoices.view')->sole());
        Livewire::test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'waiting');
    }

    private function useMismatchedCorrectionTimezones(): void
    {
        $original = date_default_timezone_get();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        $this->beforeApplicationDestroyed(fn () => date_default_timezone_set($original));
        DB::statement("SET LOCAL TIME ZONE 'Asia/Damascus'");
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Damascus', DB::scalar('SHOW TIME ZONE'));
    }

    private function assertCorrectionInstantsMatch(InvoiceFinancialCorrection $correction): void
    {
        $this->assertGreaterThan(0, $correction->receivableDispositions()->count());
        $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM invoice_correction_receivable_dispositions d JOIN invoice_financial_corrections c ON c.id=d.invoice_financial_correction_id WHERE c.id=? AND d.captured_at IS DISTINCT FROM c.prepared_at', [$correction->id]));
        DB::select('SELECT validate_receivable_disposition_set(?)', [$correction->id]);
        try {
            DB::transaction(fn () => DB::update("UPDATE invoice_correction_receivable_dispositions SET captured_at=captured_at + interval '1 second' WHERE invoice_financial_correction_id=?", [$correction->id]));
            $this->fail('A frozen disposition timestamp was changed.');
        } catch (QueryException $exception) {
            $this->assertSame('55000', $exception->errorInfo[0]);
        }
        DB::select('SELECT validate_receivable_disposition_set(?)', [$correction->id]);
    }

    public function test_uat_ready_recommendation_uses_full_item_and_pricing_staleness(): void
    {
        $c = $this->context();
        $this->uatEvidence($c);
        $invoice = $this->ready($c);
        $component = Livewire::actingAs($c['employee'])->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'invoice_issue');
        app(OrderItemService::class)->confirmActual($c['employee'], $c['order'], $c['shop'], $c['item'], [
            'actual_unit_price' => '11', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED',
        ]);
        $component->dispatch('order-updated')->assertViewHas('step', 'invoice_refresh');
        $this->expectValidation(fn () => app(InvoiceService::class)->issue($c['employee'], $c['order'], $invoice));
        $invoice = app(InvoiceService::class)->refresh($c['employee'], $c['order'], $invoice);
        $component->dispatch('order-updated')->assertViewHas('step', 'invoice_issue');
        app(OrderDeliveryPricingService::class)->price($c['employee'], $c['order'], $c['from']);
        $component->dispatch('order-updated')->assertViewHas('step', 'invoice_refresh');
        $this->expectValidation(fn () => app(InvoiceService::class)->issue($c['employee'], $c['order'], $invoice));
        $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order'], app(InvoiceService::class)->refresh($c['employee'], $c['order'], $invoice));
        $before = $invoice->getRawOriginal();
        $component->dispatch('order-updated')->assertViewHas('step', 'status');
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
    }

    public function test_uat_pending_correction_blocks_delivery_assessment_until_explicit_resolution(): void
    {
        $c = $this->context();
        $this->uatEvidence($c);
        $invoice = $this->issued($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, '30', $c['currency']->id, now());
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['employee'], $c['order'], $status, null);
        }
        $component = Livewire::actingAs($c['employee'])->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'status')->assertViewHas('canTransition', true);
        $service = app(InvoiceFinancialCorrectionService::class);
        $correction = $service->request($c['employee'], $invoice, InvoiceFinancialCorrectionKind::Reprice, [
            'items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '10']],
            'delivery' => [['order_captain_id' => $c['order']->captainAssignments()->sole()->id, 'customer_amount' => '5']],
        ], 'Check price', 'Review before delivery', (string) Str::uuid());
        $component->dispatch('order-updated')->assertViewHas('step', 'waiting')->assertViewHas('canTransition', false)
            ->assertSee(__('domain.an_unresolved_invoice_financial_correction_blocks_original_order_posting'))
            ->assertDontSee('name="operational_status"', false);
        Livewire::actingAs($c['employee'])->test(Workflow::class, ['order' => $c['order']])->call('openStep', 'delivery')
            ->assertViewHas('workflow', fn ($state) => ! $state['canTransition'])
            ->assertSee(__('domain.an_unresolved_invoice_financial_correction_blocks_original_order_posting'))->assertDontSee('wire:click="advance"', false);
        $this->expectValidation(fn () => app(OrderStatusService::class)->transition($c['employee'], $c['order'], OrderOperationalStatus::Delivered, null));
        $this->assertSame(InvoiceFinancialCorrectionState::Requested, $correction->fresh()->state);
        $service->abandon($c['admin'], $correction);
        $component->dispatch('order-updated')->assertViewHas('step', 'status')->assertViewHas('canTransition', true);
        app(OrderStatusService::class)->transition($c['employee'], $c['order'], OrderOperationalStatus::Delivered, null);
        $component->dispatch('order-updated')->assertViewHas('step', 'completed')->assertViewHas('canTransition', false);
    }

    public function test_uat_invoice_controls_follow_cancellation_eligibility_but_allow_delivered_invoicing(): void
    {
        $c = $this->context();
        $this->actingAs($c['employee'])->get(route('orders.workflow', [$c['order'], 'step' => 'invoice']))->assertOk()
            ->assertSee('wire:click="createInvoice"', false);
        $invoice = $this->ready($c);
        $page = route('orders.invoices.show', [$c['order'], $invoice]);
        $this->get($page)->assertOk()->assertSee(route('orders.invoices.issue', [$c['order'], $invoice]), false)
            ->assertSee(route('orders.invoices.refresh', [$c['order'], $invoice]), false);
        app(OrderCancellationService::class)->cancel($c['employee'], $c['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);
        $this->get($page)->assertOk()->assertDontSee(route('orders.invoices.issue', [$c['order'], $invoice]), false)
            ->assertDontSee(route('orders.invoices.refresh', [$c['order'], $invoice]), false);
        $this->expectValidation(fn () => app(InvoiceService::class)->refresh($c['employee'], $c['order'], $invoice));

        $intent = $this->context('INT');
        $invoice = $this->ready($intent);
        $this->uatEvidence($intent);
        app(OrderCancellationIntentService::class)->create($intent['employee'], $intent['order'], OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $this->actingAs($intent['employee'])->get(route('orders.invoices.show', [$intent['order'], $invoice]))->assertOk()
            ->assertDontSee(route('orders.invoices.issue', [$intent['order'], $invoice]), false)
            ->assertDontSee(route('orders.invoices.refresh', [$intent['order'], $invoice]), false);
        $this->expectValidation(fn () => app(InvoiceService::class)->issue($intent['employee'], $intent['order'], $invoice));

        $delivered = $this->context('DEL');
        $this->uatEvidence($delivered);
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered] as $status) {
            app(OrderStatusService::class)->transition($delivered['employee'], $delivered['order'], $status, null);
        }
        $this->actingAs($delivered['employee'])->get(route('orders.workflow', [$delivered['order'], 'step' => 'invoice']))->assertOk()
            ->assertSee('wire:click="createInvoice"', false);
        $invoice = $this->issued($delivered);
        $this->get(route('orders.invoices.show', [$delivered['order'], $invoice]))->assertOk()
            ->assertSee(route('invoices.collection.store', $invoice), false);
        app(InvoiceCollectionService::class)->record($delivered['employee'], $invoice, InvoiceCollectionMethod::ShamCash, '30', $delivered['currency']->id, now());
        $this->assertTrue(app(InvoiceCollectionService::class)->availability($delivered['employee'], $invoice)['payment_satisfied']);
    }
}
