<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\FinancialAccountKind;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderItemChangeType;
use App\Enums\OrderOperationalStatus;
use App\Livewire\Orders\CreateOrder;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderDeliveryPricing;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\CaptainService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\CustomerCreditService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialBalanceQueryService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\MixedCustomerCreditService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\ProductService;
use App\Services\SharedOrderShopPurchaseService;
use App\Services\SpecialProductDeliveryService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SpecialProductDeliveryTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    #[DataProvider('allocations')]
    public function test_special_base_precedence_and_standard_fee_increase(string $mode, string $fuel, string $extra, string $captain, string $company): void
    {
        $c = $this->context(mode: $mode, fuel: $fuel);
        $pricing = $this->price($c, $extra);
        $row = $pricing->allocations()->sole();
        $this->assertSame($captain, $row->captain_entitlement);
        $this->assertSame($company, $row->company_collectible);
        $this->assertSame((string) BigDecimal::of('250')->plus($extra)->toScale(4), $pricing->customer_delivery_total);
        $this->assertSame('250.0000', $row->special_delivery_components['special_base_fee']);
        $this->assertSame((string) BigDecimal::of($extra)->toScale(4), $row->special_delivery_components['extra_fee']);
        $this->assertSame('80.0000', $c['specialItem']->fresh()->actual_unit_price);
    }

    public static function allocations(): array
    {
        return [
            ['FIXED', '10', '0', '150.0000', '100.0000'],
            ['PERCENTAGE', '10', '0', '150.0000', '100.0000'],
            ['FIXED', '0', '25', '162.5000', '112.5000'],
            ['FIXED', '10', '25', '165.0000', '110.0000'],
            ['PERCENTAGE', '10', '25', '165.0000', '110.0000'],
            ['DEFAULT', '10', '0', '150.0000', '100.0000'],
        ];
    }

    #[DataProvider('contracts')]
    public function test_posting_preserves_collector_payers_account_types_and_idempotency(int $count, InvoiceCollectionMethod $method, bool $mixed = false): void
    {
        $c = $this->context($count);
        $pricing = $this->price($c, '25');
        $productTotal = $count === 1 ? '160' : '240';
        if ($count === 1) {
            app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'],
                $this->sharedData($c, $productTotal) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
        } else {
            app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, $productTotal), $this->sharedImage());
        }
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($c['actor'], $c['order'], $invoices->refresh($c['actor'], $c['order'], $invoices->createDraft($c['actor'], $c['order'])));
        $this->assertSame((string) BigDecimal::of($productTotal)->plus(275 * $count)->toScale(4), $invoice->grand_total);
        if ($mixed) {
            $grant = app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
                'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                'reason' => 'OVERCHARGE', 'amount' => '30', 'reference' => (string) str()->uuid(),
                'notes' => 'Special delivery credit regression', 'occurred_at' => now()->toIso8601String(),
            ]);
            $actual = (string) BigDecimal::of($invoice->grand_total)->minus('30')->toScale(4);
            $data = ['request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '30'],
                'actual_amount' => $actual, 'collected_at' => now()->toIso8601String(), 'notes' => 'Special delivery mixed settlement'];
            $service = app(MixedCustomerCreditService::class);
            $collection = $service->record($c['actor'], $invoice, $method, $data);
            $this->assertSame($actual, $collection->amount);
            $this->assertSame('30.0000', $service->creditAmount($collection));
            $this->assertSame($collection->id, $service->record($c['actor'], $invoice, $method, $data)->id);
            $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        } else {
            app(InvoiceCollectionService::class)->record($c['actor'], $invoice, $method, $invoice->grand_total, $c['currency']->id, now(), null, 'Special product test collection');
        }
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
        $this->assertSame($count === 1 ? 3 : 2, $c['order']->fresh()->accounting_contract_version);
        $balances = app(FinancialBalanceQueryService::class);
        foreach ($c['captains'] as $index => $captain) {
            $receivable = $method === InvoiceCollectionMethod::ShamCash ? ($count === 1 ? '325' : '285') : ($count === 1 ? '0' : '165');
            $deposit = $method === InvoiceCollectionMethod::Cash && $count === 1 ? ($mixed ? '-80' : '-110') : '0';
            $custody = $method === InvoiceCollectionMethod::Cash && $count === 2 && $index === 0 ? ($mixed ? '520' : '550') : '0';
            foreach ([FinancialAccountKind::CaptainCompanyReceivable->value => $receivable, FinancialAccountKind::CaptainDeposit->value => $deposit, FinancialAccountKind::CaptainCashCustody->value => $custody] as $kind => $expected) {
                $this->assertSame((string) BigDecimal::of($expected)->toScale(4), $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::from($kind)));
            }
        }
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertSame($count === 1 ? '275.0000' : '550.0000', $pricing->customer_delivery_total);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->sqlRejects(fn () => DB::table('order_delivery_allocations')->where('id', $pricing->allocations()->first()->id)->update(['special_delivery_components' => null]), '55000');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function contracts(): array
    {
        return [[1, InvoiceCollectionMethod::Cash], [1, InvoiceCollectionMethod::ShamCash], [2, InvoiceCollectionMethod::Cash], [2, InvoiceCollectionMethod::ShamCash],
            [1, InvoiceCollectionMethod::Cash, true], [1, InvoiceCollectionMethod::ShamCash, true], [2, InvoiceCollectionMethod::Cash, true], [2, InvoiceCollectionMethod::ShamCash, true]];
    }

    public function test_distinct_captain_percentages_are_applied_only_to_their_own_extra_fee(): void
    {
        $c = $this->context(2);
        app(CaptainService::class)->save($c['admin'], $c['captains'][0], ['fuel_allowance_percentage' => '0']);
        $pricing = $this->price($c, '25');
        $rows = $pricing->allocations()->orderBy('order_captain_id')->get();
        $this->assertSame(['162.5000', '165.0000'], $rows->pluck('captain_entitlement')->all());
        $this->assertSame(['112.5000', '110.0000'], $rows->pluck('company_collectible')->all());
        $this->assertSame('550.0000', $pricing->customer_delivery_total);
    }

    public function test_admin_management_versions_policy_without_changing_old_allocations_or_product_prices(): void
    {
        $c = $this->context();
        $pricing = $this->price($c, '0');
        $before = $pricing->allocations()->sole()->getAttributes();
        $oldPolicy = $c['product']->fresh()->deliveryPolicy;
        $this->actingAs($c['admin'])->get(route('products.edit', $c['product']))->assertOk()->assertSee(__('special.policy'));
        $this->actingAs($c['admin'])->put(route('products.update', $c['product']), $this->productData($c, ['captain_fixed' => '100', 'company_fixed' => '150']))
            ->assertRedirect(route('products.show', $c['product']));
        $this->assertSame(2, $c['product']->fresh()->deliveryPolicy->version);
        $this->assertSame($oldPolicy->id, $c['specialItem']->fresh()->special_delivery_policy_id);
        $this->assertSame($before, $pricing->allocations()->sole()->getAttributes());
        $this->assertSame('150.0000', $oldPolicy->fresh()->captain_fixed);
        app(CaptainService::class)->save($c['admin'], $c['captains'][0], ['fuel_allowance_percentage' => '15']);
        $this->assertSame($before, $pricing->allocations()->sole()->getAttributes());
        $this->sqlRejects(fn () => DB::table('product_delivery_policies')->where('id', $oldPolicy->id)->update(['captain_fixed' => '0']), '55000');
    }

    public function test_ordinary_products_keep_route_pricing_and_do_not_gain_special_configuration_by_default(): void
    {
        $c = $this->sharedContext(2, 1, true);
        $this->assertSame('10.0000', $c['order']->currentDeliveryPricing->customer_delivery_total);
        $this->assertNull($c['order']->currentDeliveryPricing->allocations()->sole()->special_delivery_components);
        $unit = Unit::create(['name' => 'Piece', 'code' => 'SP-'.str()->random(6)]);
        $product = app(ProductService::class)->save($c['admin'], new Product, ['name' => 'Ordinary hose', 'default_unit_id' => $unit->id]);
        $this->assertFalse($product->fresh()->is_special);
        $this->assertSame(0, $product->deliveryPolicies()->count());
    }

    public function test_invalid_policy_or_non_admin_master_edits_are_rejected_without_writes(): void
    {
        $c = $this->context();
        $data = $this->productData($c, ['captain_fixed' => '151']);
        $this->assertThrows(fn () => app(ProductService::class)->save($c['admin'], $c['product'], $data), ValidationException::class);
        $editor = Role::create(['code' => 'SPECIAL_TEST_EDITOR', 'name' => 'Catalog editor']);
        $editor->permissions()->attach(Permission::where('code', 'products.update')->sole());
        $c['actor']->roles()->attach($editor);
        $c['actor']->unsetRelation('roles');
        $this->actingAs($c['actor'])->put(route('products.update', $c['product']), $this->productData($c))
            ->assertSessionHasErrors(['is_special', 'special_policy']);
        $this->expectException(HttpException::class);
        try {
            app(ProductService::class)->save($c['actor'], $c['product'], $this->productData($c));
        } finally {
            $this->assertSame(1, $c['product']->deliveryPolicies()->count());
            $this->assertSame('150.0000', $c['product']->fresh()->deliveryPolicy->captain_fixed);
        }
    }

    public function test_unsupported_decrease_ambiguous_portions_and_cross_branch_access_fail_closed(): void
    {
        $c = $this->context(2);
        $this->assertThrows(fn () => $this->price($c, '-1'), ValidationException::class);
        $this->assertThrows(fn () => app(SpecialProductDeliveryService::class)->assign($c['actor'], $c['order'], [
            $c['specialItem']->id => [$c['captains'][0]->id => '2', $c['captains'][1]->id => '0'],
        ]), ValidationException::class);
        $outsider = User::factory()->create();
        $outsider->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $outsider->branches()->attach(Branch::factory()->create());
        $this->actingAs($outsider)->post(route('orders.special-delivery-portions', $c['order']), ['portions' => [$c['specialItem']->id => [$c['captains'][0]->id => '2']]])->assertForbidden();
        $this->assertSame('500.0000', $c['order']->fresh()->currentDeliveryPricing->customer_delivery_total);
    }

    public function test_wizard_uses_configured_defaults_review_and_explicit_shared_portions(): void
    {
        $c = $this->context(2);
        $this->actingAs($c['actor']);
        $wizard = Livewire::test(CreateOrder::class)->set('branchId', (string) $c['branch']->id)
            ->set('customerId', (string) $c['order']->customer_id)->set('customerPhoneId', (string) $c['order']->customer_phone_id)
            ->set('customerAddressId', (string) $c['order']->customer_address_id)->set('source', 'MANUAL')
            ->call('nextStep')->assertHasNoErrors()->assertSet('step', 2)
            ->set('shopType', 'ONE_TIME')->set('shopName', 'Special delivery shop')
            ->set('originAreaId', (string) $c['shop']->origin_area_id)->set('businessClassification', 'SHOP')
            ->call('addDraftShop')->assertHasNoErrors()
            ->call('selectProduct', $c['product']->id)->set('quantity', '2')->call('addDraftItem')->assertHasNoErrors()
            ->set('wizardCaptainIds', array_map('strval', array_column($c['captains'], 'id')))
            ->set('wizardCollectorId', (string) $c['captains'][0]->id)->call('nextStep')->assertHasNoErrors()->assertSet('step', 3);
        $groupKey = array_key_first($wizard->get('groups'));
        $wizard->assertSee(__('special.portions'))->call('nextStep')->assertHasErrors('special_delivery')->assertSet('step', 3)
            ->set('specialDeliveryAssignments', [$groupKey.'_0' => [$c['captains'][0]->id => '1', $c['captains'][1]->id => '1']])
            ->call('nextStep')->assertHasNoErrors()->assertSet('step', 4)
            ->call('goToStep', 1)->set('branchId', (string) $c['branch']->id)
            ->call('goToStep', 3)->assertHasNoErrors()
            ->assertSet('specialDeliveryAssignments.'.$groupKey.'_0.'.$c['captains'][0]->id, '1')
            ->call('nextStep')->assertHasNoErrors()->assertSet('step', 4)->call('submit')->assertHasNoErrors();
        $order = Order::findOrFail($wizard->get('createdOrderId'));
        $this->assertSame(2, $order->accounting_contract_version);
        $this->assertSame('500.0000', $order->currentDeliveryPricing->customer_delivery_total);
        $this->assertSame(['150.0000', '150.0000'], $order->currentDeliveryPricing->allocations()->orderBy('order_captain_id')->pluck('captain_entitlement')->all());
        $wizard->call('submit')->assertHasNoErrors()->assertSet('createdOrderId', $order->id);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_multiple_special_products_aggregate_source_fees_without_adding_an_ordinary_route_fee(): void
    {
        $c = $this->context();
        $other = app(ProductService::class)->save($c['admin'], new Product, $this->productData($c, ['delivery_fee' => '100', 'captain_fixed' => '40', 'company_fixed' => '60']));
        app(OrderItemService::class)->add($c['actor'], $c['order'], $c['shop'], ['item_type' => 'CATALOG_ITEM', 'product_id' => $other->id, 'unit_id' => $c['unit']->id, 'quantity' => '1']);
        $pricing = app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $this->assertSame('350.0000', $pricing->customer_delivery_total);
        $this->assertSame('190.0000', $pricing->allocations()->sole()->captain_entitlement);
        $this->assertCount(2, $pricing->allocations()->sole()->special_delivery_components['parts']);
        app(OrderItemService::class)->add($c['actor'], $c['order'], $c['shop'], ['item_type' => 'ONE_TIME_ITEM',
            'item_name' => 'Ordinary hose', 'unit_id' => $c['unit']->id, 'quantity' => '1']);
        $repriced = app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $this->assertSame('350.0000', $repriced->customer_delivery_total);
        $this->assertSame('190.0000', $repriced->allocations()->sole()->captain_entitlement);
    }

    public function test_translation_parity(): void
    {
        $this->assertSame(array_keys(Arr::dot(require lang_path('ar/special.php'))), array_keys(Arr::dot(require lang_path('en/special.php'))));
    }

    public function test_currency_rounding_and_forged_allocation_sources_are_enforced_by_postgresql(): void
    {
        $c = $this->context(fuel: '0', places: 0);
        $row = $this->price($c, '25')->allocations()->sole();
        $this->assertSame('163.0000', $row->captain_entitlement);
        $this->assertSame('112.0000', $row->company_collectible);
        $data = $row->getAttributes();
        $details = $row->special_delivery_components;
        $details['parts'][0]['units'] = '2';
        $data['special_delivery_components'] = $details;
        $this->sqlRejects(fn () => DB::select('SELECT assert_special_delivery_allocation(?::jsonb)', [json_encode($data)]), '23514');
        $details['parts'][0]['units'] = '1';
        $details['parts'][] = $details['parts'][0];
        $data['special_delivery_components'] = $details;
        $this->sqlRejects(fn () => DB::select('SELECT assert_special_delivery_allocation(?::jsonb)', [json_encode($data)]), '23514');
    }

    public function test_unapproved_multi_shop_combination_is_blocked_without_changing_ordinary_max_pricing(): void
    {
        $c = $this->context();
        $service = app(SpecialProductDeliveryService::class);
        $this->assertSame([], $service->quote([], $c['currency'], [$c['captains'][0]->id], shopCount: 2));
        $this->assertThrows(fn () => $service->quote($service->orderSources($c['order']), $c['currency'], [$c['captains'][0]->id], shopCount: 2), ValidationException::class);
        $this->assertSame('250.0000', $c['order']->fresh()->currentDeliveryPricing->customer_delivery_total);
    }

    public function test_quantity_change_requires_explicit_repricing_before_an_invoice_can_use_the_source(): void
    {
        $c = $this->context();
        app(OrderItemService::class)->change($c['actor'], $c['order'], $c['shop'], $c['specialItem'], [
            'change_type' => OrderItemChangeType::Quantity->value, 'quantity' => '2', 'reason' => 'Customer requests a second unit',
        ]);
        $this->assertThrows(fn () => app(SpecialProductDeliveryService::class)->assertCurrentPricing($c['order'], $c['order']->fresh()->currentDeliveryPricing), ValidationException::class);
        $this->assertSame('250.0000', $c['order']->fresh()->currentDeliveryPricing->customer_delivery_total);
        $pricing = app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $this->assertSame('500.0000', $pricing->customer_delivery_total);
        $this->assertSame('300.0000', $pricing->allocations()->sole()->captain_entitlement);
        app(SpecialProductDeliveryService::class)->assertCurrentPricing($c['order'], $pricing);
    }

    public function test_existing_discount_path_preserves_special_base_and_route_source_guards(): void
    {
        $c = $this->context();
        $this->price($c, '25');
        $pricing = app(OrderDeliveryPricingService::class)->applyDiscount($c['admin'], $c['order'], '10', 'Existing authorized company-funded discount');
        $this->assertSame('265.0000', $pricing->customer_delivery_total);
        $this->assertSame('165.0000', $pricing->allocations()->sole()->captain_entitlement);
        $this->assertSame('100.0000', $pricing->allocations()->sole()->company_collectible);
        $this->assertThrows(fn () => app(OrderDeliveryPricingService::class)->applyDiscount($c['admin'], $c['order'], '26', 'Below special base'), ValidationException::class);
        $this->assertSame('265.0000', $c['order']->fresh()->currentDeliveryPricing->customer_delivery_total);
    }

    private function context(int $count = 1, string $mode = 'FIXED', string $fuel = '10', int $places = 2): array
    {
        $this->travelTo(CarbonImmutable::now()->subMinutes(2)->startOfSecond());
        config(['order_accounting.enable_v3_single_captain' => true]);
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext($places, $count, true, '10');
        $c['unit'] = Unit::create(['name' => 'Container', 'code' => 'SP-'.str()->random(6)]);
        $c['product'] = app(ProductService::class)->save($c['admin'], new Product, $this->productData($c, ['allocation_mode' => $mode]));
        foreach ($c['captains'] as $captain) {
            app(CaptainService::class)->save($c['admin'], $captain, ['fuel_allowance_percentage' => $fuel]);
        }
        $c['specialItem'] = app(OrderItemService::class)->add($c['actor'], $c['order'], $c['shop'], [
            'item_type' => 'CATALOG_ITEM', 'product_id' => $c['product']->id, 'unit_id' => $c['unit']->id, 'quantity' => (string) $count,
        ]);
        app(OrderItemService::class)->confirmActual($c['actor'], $c['order'], $c['shop'], $c['specialItem'], [
            'actual_unit_price' => '80', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => ActualPriceSource::ManualVerified->value,
        ]);
        if ($count === 2) {
            app(SpecialProductDeliveryService::class)->assign($c['actor'], $c['order'], [$c['specialItem']->id => [$c['captains'][0]->id => '1', $c['captains'][1]->id => '1']]);
        }
        app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);

        return $c;
    }

    private function productData(array $c, array $overrides = []): array
    {
        return ['name' => 'UAT special container', 'default_unit_id' => $c['unit']->id, 'is_special' => true, 'special_policy' => [
            'currency_id' => $c['currency']->id, 'delivery_fee' => '250', 'allocation_mode' => 'FIXED',
            'captain_percentage' => '60', 'captain_fixed' => '150', 'company_fixed' => '100', ...$overrides,
        ]];
    }

    private function price(array $c, string $extra): OrderDeliveryPricing
    {
        app(DeliveryPricingSettings::class)->update($c['admin'], '50', '20');
        $result = app(OrderDeliveryPricingService::class)->requestOverride($c['actor'], $c['order'], $c['shop']->originArea,
            (string) BigDecimal::of('250')->plus($extra), 'Explicit documented extra delivery effort');
        $this->assertInstanceOf(OrderDeliveryPricing::class, $result);

        return $result;
    }

    private function sqlRejects(callable $operation, string $state): void
    {
        try {
            DB::transaction($operation);
            $this->fail('SQL accepted a financial snapshot mutation.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
