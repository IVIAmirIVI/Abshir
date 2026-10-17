<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Livewire\Orders\CreateOrder;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\CaptainService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialBalanceQueryService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopService;
use App\Services\OrderStatusService;
use App\Services\ProductService;
use App\Services\SharedOrderShopPurchaseService;
use App\Services\SpecialAddonApprovalService;
use App\Services\SpecialProductDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SpecialAddonApprovalTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_explicit_approval_keeps_special_base_instead_of_the_higher_addon_route_fee(): void
    {
        $c = $this->context();
        $this->assertThrows(fn () => app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea), ValidationException::class);
        $this->approve($c);
        $pricing = app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $this->assertSame('250.0000', $pricing->customer_delivery_total);
        $this->assertSame('150.0000', $pricing->allocations()->sole()->captain_entitlement);
        $this->assertSame('100.0000', $pricing->allocations()->sole()->company_collectible);
        $this->assertSame($c['shop']->id, $pricing->routes()->where('is_selected_max', true)->sole()->order_shop_id);
        $this->assertSame('999.0000', $pricing->routes()->where('order_shop_id', $c['addon']->id)->sole()->proposed_unit_fee);
        $this->assertDatabaseCount('order_special_addon_approvals', 1);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->actingAs($c['actor'])->get(route('orders.workflow', ['order' => $c['order'], 'step' => 'captain']))->assertOk()->assertSee(__('special.addon_title'));
    }

    #[DataProvider('payments')]
    public function test_approved_addon_posts_existing_single_and_shared_contracts_without_duplicate_cash(int $count, InvoiceCollectionMethod $method): void
    {
        $c = $this->context($count);
        $approval = $this->approve($c);
        $pricing = app(OrderDeliveryPricingService::class)->requestOverride($c['actor'], $c['order'], $c['shop']->originArea, '275', 'Approved small same-trip pickup effort');
        foreach ([[$c['shop'], $count === 1 ? '160' : '240'], [$c['addon'], '20']] as [$shop, $amount]) {
            if ($count === 1) {
                app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $shop,
                    $this->sharedData($c, $amount) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
            } else {
                app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $shop, $this->sharedData($c, $amount), $this->sharedImage());
            }
            app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $shop, $this->sharedImage(), null);
            app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $shop, 'picked_up_at');
        }
        $this->actingAs($c['actor'])->get(route('orders.invoices.preview', $c['order']))
            ->assertOk()->assertSee(__('financial.ui.pre_delivery_notice'))
            ->assertSee('>'.($count === 1 ? '455' : '810').' '.$c['currency']->code.'</bdi>', false);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $s = app(InvoiceService::class);
        $invoice = $s->issue($c['actor'], $c['order'], $s->refresh($c['actor'], $c['order'], $s->createDraft($c['actor'], $c['order'])));
        $this->assertSame($count === 1 ? '455.0000' : '810.0000', $invoice->grand_total);
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, $method, $invoice->grand_total, $c['currency']->id, now(), null, 'Approved addon acceptance');
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
        $this->assertSame($count === 1 ? 3 : 2, $c['order']->fresh()->accounting_contract_version);
        $this->assertSame($count === 1 ? '275.0000' : '550.0000', $pricing->customer_delivery_total);
        foreach ($pricing->allocations()->get() as $row) {
            $this->assertSame('165.0000', $row->captain_entitlement);
            $this->assertSame('110.0000', $row->company_collectible);
            $this->assertSame([$approval], $row->special_delivery_components['addon_approval_ids']);
        }
        foreach ($c['captains'] as $index => $captain) {
            $balance = app(FinancialBalanceQueryService::class);
            $receivable = $method === InvoiceCollectionMethod::ShamCash ? ($count === 1 ? '345.0000' : '295.0000') : ($count === 1 ? '0.0000' : '165.0000');
            $this->assertSame($receivable, $balance->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame($method === InvoiceCollectionMethod::Cash && $count === 2 && $index === 0 ? '550.0000' : '0.0000', $balance->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCashCustody));
            $this->assertSame($method === InvoiceCollectionMethod::Cash && $count === 1 ? '-110.0000' : '0.0000', $balance->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainDeposit));
        }
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_collections', 1);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function payments(): array
    {
        return [[1, InvoiceCollectionMethod::Cash], [1, InvoiceCollectionMethod::ShamCash], [2, InvoiceCollectionMethod::Cash], [2, InvoiceCollectionMethod::ShamCash]];
    }

    public function test_approval_is_idempotent_immutable_and_invalidated_by_changed_items(): void
    {
        $c = $this->context();
        $id = $this->approve($c);
        $this->assertSame($id, $this->approve($c));
        $pricing = app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $before = $pricing->allocations()->sole()->getAttributes();
        $this->sqlRejects(fn () => DB::table('order_special_addon_approvals')->where('id', $id)->update(['reason' => 'Changed']), '55000');
        app(OrderItemService::class)->change($c['actor'], $c['order'], $c['addon'], $c['addonItem'], ['change_type' => 'QUANTITY_CHANGE', 'quantity' => '2', 'reason' => 'Another hose']);
        $this->assertThrows(fn () => app(SpecialProductDeliveryService::class)->assertCurrentPricing($c['order'], $pricing), ValidationException::class);
        $this->assertThrows(fn () => app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea), ValidationException::class);
        $next = $this->approve($c);
        $this->assertNotSame($id, $next);
        $new = app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $this->assertSame('250.0000', $new->customer_delivery_total);
        $this->assertSame($before, $pricing->allocations()->sole()->getAttributes());
    }

    #[DataProvider('deniedActors')]
    public function test_unauthorized_and_foreign_branch_approval_requests_are_forbidden(string $role): void
    {
        $c = $this->context();
        $actor = User::factory()->create();
        if ($role !== 'NONE') {
            $actor->roles()->attach(Role::where('code', $role)->sole());
        }
        $actor->branches()->attach($role === 'ORDER_EMPLOYEE' ? Branch::factory()->create() : $c['branch']);
        $this->actingAs($actor)->post(route('orders.special-addons', $c['order']), $this->payload($c))->assertForbidden();
        $this->assertDatabaseCount('order_special_addon_approvals', 0);
    }

    public static function deniedActors(): array
    {
        return [['NONE'], ['CAPTAIN'], ['ORDER_EMPLOYEE']];
    }

    public function test_forged_actor_facts_and_allocation_approval_ids_are_rejected(): void
    {
        $c = $this->context();
        $this->actingAs($c['actor'])->post(route('orders.special-addons', $c['order']), $this->payload($c) + ['approved_by' => $c['admin']->id])->assertSessionHasErrors('approved_by');
        $this->actingAs($c['actor'])->post(route('orders.special-addons', $c['order']), $this->payload($c))->assertRedirect(route('orders.workflow', $c['order']));
        $row = (array) DB::table('order_special_addon_approvals')->sole();
        unset($row['id'], $row['facts_digest']);
        $row['approved_by'] = User::factory()->create()->id;
        $this->sqlRejects(fn () => DB::table('order_special_addon_approvals')->insert($row), '23514');
        $row['approved_by'] = $c['actor']->id;
        $row['facts'] = '{}';
        $this->sqlRejects(fn () => DB::table('order_special_addon_approvals')->insert($row), '23514');
        $pricing = app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $allocation = $pricing->allocations()->sole();
        $data = $allocation->getAttributes();
        $components = $allocation->special_delivery_components;
        $components['addon_approval_ids'] = [];
        $data['special_delivery_components'] = $components;
        $this->sqlRejects(fn () => DB::select('SELECT assert_special_delivery_allocation(?::jsonb)', [json_encode($data)]), '23514');
    }

    #[DataProvider('draftKinds')]
    public function test_wizard_requires_explicit_approval_and_preserves_it_through_review_and_creation(bool $ordinary): void
    {
        $c = $this->context(2);
        $w = Livewire::actingAs($c['actor'])->test(CreateOrder::class)->set('branchId', (string) $c['branch']->id)
            ->set('customerId', (string) $c['order']->customer_id)->set('source', 'MANUAL')->call('nextStep')->assertHasNoErrors()
            ->set('shopType', 'ONE_TIME')->set('shopName', 'UAT special shop')->set('originAreaId', (string) $c['shop']->origin_area_id)->set('businessClassification', 'SHOP')
            ->call('addDraftShop')->call('selectProduct', $c['product']->id)->set('quantity', '2')->call('addDraftItem')->assertHasNoErrors();
        $sourceKey = array_key_first($w->get('groups')).'_0';
        $w->set('shopType', 'ONE_TIME')->set('shopName', 'UAT nearby hose shop')->set('originAreaId', (string) $c['addon']->origin_area_id)->set('businessClassification', 'SHOP')
            ->call('addDraftShop')->set('itemType', 'ONE_TIME_ITEM')->set('itemName', 'UAT small hose')->set('unitId', (string) $c['unit']->id)->call('addDraftItem')
            ->call('nextStep')->assertHasErrors('special_delivery')->assertSet('step', 2);
        $key = array_key_last($w->get('groups'));
        $w->set('addonInputs', [$key => ['source_key' => $sourceKey, 'reason' => 'Small same-trip pickup explicitly checked']])->call('approveDraftAddon', $key)->assertHasNoErrors();
        if ($ordinary) {
            $sourceGroup = array_key_first($w->get('groups'));
            $w->call('removeDraftItem', $sourceGroup, 0)->call('editGroup', $sourceGroup)
                ->set('itemType', 'ONE_TIME_ITEM')->set('itemName', 'Ordinary replacement')->set('unitId', (string) $c['unit']->id)->call('addDraftItem')
                ->call('nextStep')->assertHasNoErrors()->set('wizardCaptainIds', [(string) $c['captains'][0]->id])
                ->call('nextStep')->assertHasNoErrors()->call('submit')->assertHasNoErrors();
            $order = Order::findOrFail($w->get('createdOrderId'));
            $this->assertSame('999.0000', $order->currentDeliveryPricing->customer_delivery_total);
            $this->assertSame(0, DB::table('order_special_addon_approvals')->where('order_id', $order->id)->count());

            return;
        }
        $w->call('nextStep')->assertHasNoErrors()->assertSet('step', 3)
            ->set('wizardCaptainIds', array_map('strval', array_column($c['captains'], 'id')))->set('wizardCollectorId', (string) $c['captains'][0]->id)
            ->set('specialDeliveryAssignments', [$sourceKey => [$c['captains'][0]->id => '1', $c['captains'][1]->id => '1']])
            ->call('beginDeliveryOverride')->set('deliveryOverrideAmount', '275')->set('deliveryOverrideReason', 'Approved additional effort')
            ->call('nextStep')->assertHasNoErrors()->assertSet('step', 4)->call('goToStep', 2)->call('goToStep', 4)->assertHasNoErrors()
            ->call('submit')->assertHasNoErrors();
        $order = Order::findOrFail($w->get('createdOrderId'));
        $this->assertSame(2, $order->accounting_contract_version);
        $this->assertSame('550.0000', $order->currentDeliveryPricing->customer_delivery_total);
        $this->assertSame($c['actor']->id, DB::table('order_special_addon_approvals')->where('order_id', $order->id)->sole()->approved_by);
        $w->call('submit')->assertHasNoErrors();
        $this->assertSame(1, DB::table('order_special_addon_approvals')->where('order_id', $order->id)->count());
    }

    public static function draftKinds(): array
    {
        return [[false], [true]];
    }

    private function context(int $count = 1): array
    {
        $this->travelTo(CarbonImmutable::now()->subMinutes(2)->startOfSecond());
        config(['order_accounting.enable_v3_single_captain' => true]);
        Storage::fake('order_receipts');
        Storage::fake('order_returns');
        $c = $this->sharedContext(2, $count, true, '10');
        $c['unit'] = Unit::create(['name' => 'Unit', 'code' => 'ADD-'.str()->random(6)]);
        $c['product'] = app(ProductService::class)->save($c['admin'], new Product, ['name' => 'UAT special cylinder', 'default_unit_id' => $c['unit']->id,
            'is_special' => true, 'special_policy' => ['currency_id' => $c['currency']->id, 'delivery_fee' => '250', 'allocation_mode' => 'FIXED', 'captain_fixed' => '150', 'company_fixed' => '100']]);
        foreach ($c['captains'] as $captain) {
            app(CaptainService::class)->save($c['admin'], $captain, ['fuel_allowance_percentage' => '10']);
        }
        $c['source'] = $this->item($c, $c['shop'], ['item_type' => 'CATALOG_ITEM', 'product_id' => $c['product']->id, 'quantity' => (string) $count], '80');
        if ($count === 2) {
            app(SpecialProductDeliveryService::class)->assign($c['actor'], $c['order'], [$c['source']->id => [$c['captains'][0]->id => '1', $c['captains'][1]->id => '1']]);
        }
        app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $other = Area::factory()->for($c['branch'])->create();
        $c['addon'] = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'ONE_TIME', 'shop_name' => 'UAT nearby addon', 'business_classification' => 'SHOP', 'origin_area_id' => $other->id]);
        $c['addonItem'] = $this->item($c, $c['addon'], ['item_type' => 'ONE_TIME_ITEM', 'item_name' => 'UAT small hose', 'quantity' => '1'], '20');
        foreach ([[$c['shop']->origin_area_id, '10'], [$other->id, '999']] as [$from, $fee]) {
            app(DeliveryPricingRuleService::class)->create($c['admin'], ['from_area_id' => $from, 'to_area_id' => $c['order']->destination_area_id,
                'currency_id' => $c['currency']->id, 'order_pricing_class' => 'MULTI_SHOP', 'delivery_unit_fee' => $fee, 'effective_from' => now()->subDay()]);
        }
        app(DeliveryPricingSettings::class)->update($c['admin'], '50', '20');

        return $c;
    }

    private function item(array $c, OrderShop $shop, array $data, string $actual): OrderItem
    {
        $item = app(OrderItemService::class)->add($c['actor'], $c['order'], $shop, $data + ['unit_id' => $c['unit']->id]);
        app(OrderItemService::class)->confirmActual($c['actor'], $c['order'], $shop, $item, ['actual_unit_price' => $actual,
            'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED']);

        return $item;
    }

    private function approve(array $c): int
    {
        return app(SpecialAddonApprovalService::class)->approve($c['actor'], $c['order'], $c['addon'], $c['source'], 'Small hose picked up on the same practical trip');
    }

    private function payload(array $c): array
    {
        return ['order_shop_id' => $c['addon']->id, 'source_order_item_id' => $c['source']->id, 'reason' => 'Supervisor checked the same trip'];
    }

    private function sqlRejects(callable $action, string $state): void
    {
        try {
            DB::transaction($action);
            $this->fail('PostgreSQL accepted forged or mutable approval data.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
