<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\InvoiceCollectionMethod;
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
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use App\Services\OrderShopService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceAndCollectionTest extends TestCase
{
    use RefreshDatabase;

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
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'ordered_at' => now()->subDay()]);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'One-time Shop', 'sort_order' => 1]);
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

    private function issued(array $context): Invoice
    {
        return app(InvoiceService::class)->issue($context['employee'], $context['order'], $this->ready($context));
    }

    public function test_shop_composition_change_invalidates_pricing_and_makes_ready_invoice_stale(): void
    {
        $c = $this->context();
        $pricing = $c['order']->currentDeliveryPricing()->firstOrFail();
        $invoice = $this->ready($c);

        app(OrderShopService::class)->add($c['employee'], $c['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'RESTAURANT', 'shop_name' => 'Second Shop']);

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
        $this->assertSame($c['employee']->id, $collection->recorded_by);
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
        $response->assertOk()->assertSee($usdInvoice->invoice_number)->assertSee($eurInvoice->invoice_number)->assertDontSee($cashInvoice->invoice_number)->assertSee('USD-REF');
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
        $otherOrder = app(OrderService::class)->create($c['employee'], ['branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id, 'customer_phone_id' => $c['customer']->phones()->first()->id, 'customer_address_id' => $c['customer']->addresses()->first()->id, 'ordered_at' => now()]);
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

    public function test_no_phase_nine_financial_schema_exists(): void
    {
        foreach (['financial_accounts', 'financial_transactions', 'captain_deposits', 'captain_receivables', 'cash_custody', 'commissions', 'incentives', 'salaries'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
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
}
