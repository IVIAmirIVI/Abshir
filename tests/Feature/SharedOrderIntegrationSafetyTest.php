<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Models\Area;
use App\Models\FinancialTransaction;
use App\Models\Role;
use App\Services\CaptainInformationQuery;
use App\Services\DeliveryPricingRuleService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCancellationService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderPricingService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopService;
use App\Services\OrderStatusService;
use App\Services\OrderWorkflowData;
use App\Services\SharedOrderFinancialPlanService;
use App\Services\SharedOrderShopPurchaseService;
use App\Support\DemoOrderReset\OrderResetMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SharedOrderIntegrationSafetyTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_multi_shop_max_pricing_and_actual_per_shop_shares_count_principal_once(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $origin = Area::factory()->for($c['branch'])->create();
        foreach ([[$c['shop']->origin_area_id, '10'], [$origin->id, '15']] as [$from, $fee]) {
            app(DeliveryPricingRuleService::class)->create($c['admin'], ['from_area_id' => $from,
                'to_area_id' => $c['order']->destination_area_id, 'currency_id' => $c['currency']->id,
                'order_pricing_class' => 'MULTI_SHOP', 'delivery_unit_fee' => $fee, 'effective_from' => now()->subHour()]);
        }
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'ONE_TIME',
            'shop_name' => 'Second shop', 'origin_area_id' => $origin->id, 'business_classification' => 'SHOP']);
        $item = $c['shop']->items()->sole()->replicate();
        $item->forceFill(['order_shop_id' => $shop->id, 'actual_unit_price' => '40'])->save();
        app(OrderPricingService::class)->recalculate($c['order']);
        $this->select($c);
        app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        $this->purchase($c);
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $shop, $this->sharedData($c, '40'), $this->sharedImage());
        $plan = app(SharedOrderFinancialPlanService::class)->review($c['actor'], $c['order'], InvoiceCollectionMethod::Cash);
        $this->assertSame('120.0000', $plan['items_subtotal']);
        $this->assertSame('30.0000', $plan['delivery_gross']);
        $this->assertSame('150.0000', $plan['customer_invoice_total']);
        $this->assertSame('135.0000', $plan['company_obligations']);
        $this->assertSame(['60.00', '60.00'], array_column($plan['participants'], 'purchase_reimbursement'));
        $this->assertSame(['7.50', '7.50'], array_column($plan['participants'], 'delivery_entitlement'));
        $this->assertCount(2, $plan['purchase_sources']);
        $this->assertSame(0, DB::table('financial_entries')->count());
    }

    public function test_existing_override_audit_survives_shared_plan_and_entitlements_use_the_audited_fee(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $this->select($c);
        app(OrderDeliveryPricingService::class)->requestOverride($c['admin'], $c['order'], $c['shop']->originArea, '12', 'Owner approved exception');
        $before = DB::table('delivery_fee_override_requests')->orderBy('id')->get()->toJson();
        $this->purchase($c);
        $plan = app(SharedOrderFinancialPlanService::class)->review($c['admin'], $c['order'], InvoiceCollectionMethod::Cash);
        $this->assertSame('24.0000', $plan['delivery_gross']);
        $this->assertSame('92.0000', $plan['company_obligations']);
        $this->assertSame(['6.00', '6.00'], array_column($plan['participants'], 'delivery_entitlement'));
        $this->assertSame($before, DB::table('delivery_fee_override_requests')->orderBy('id')->get()->toJson());
    }

    public function test_finance_review_and_real_selection_history_render_under_branch_authorization(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $this->select($c);
        $this->purchase($c);
        $this->actingAs($c['actor'])->get(route('shared-orders.financial-review', $c['order']))
            ->assertOk()->assertSee(__('shared_orders.preview_only'))->assertSee($c['captains'][0]->user->name)
            ->assertSee($c['captains'][1]->user->name)->assertDontSee('shared_orders.');
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee(__('shared_orders.selection_history'));
        $foreign = $this->sharedContext();
        $this->actingAs($foreign['actor'])->get(route('shared-orders.financial-review', $c['order']))->assertForbidden();
        $this->get(route('orders.shops.purchases.evidence', [$c['order'], $c['shop'], $c['order']->purchaseConfirmations()->sole()]))->assertForbidden();
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_cancel_before_purchase_preserves_selection_history_and_has_no_financial_effect(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        $before = $c['order']->captainSelections()->get()->toJson();
        app(OrderCancellationService::class)->cancel($c['actor'], $c['order'], OrderCancellationClassification::BeforePurchase,
            OrderCancellationReason::cases()[0], 'Cancelled before any purchase');
        $this->assertSame('CANCELLED', $c['order']->fresh()->operational_status->value);
        $this->assertSame($before, $c['order']->captainSelections()->get()->toJson());
        $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
            array_column($c['captains'], 'id'), $c['captains'][1]->id, (string) str()->uuid(), 'Attempt after cancellation'), ValidationException::class);
        $this->assertSame(0, DB::table('financial_entries')->count());
    }

    public function test_shared_plan_and_assignment_history_do_not_become_dashboard_earnings_or_duplicate_orders(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $this->select($c);
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'),
            $c['captains'][1]->id, (string) str()->uuid(), 'Collector changed');
        $this->purchase($c);
        app(SharedOrderFinancialPlanService::class)->review($c['actor'], $c['order'], InvoiceCollectionMethod::Cash);
        foreach ($c['captains'] as $captain) {
            $captain->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
            $self = app(CaptainInformationQuery::class)->selfSnapshot($captain->user, 'today');
            $this->assertSame($captain->id, $self['captain']->id);
            $this->assertSame(1, $self['activity']['metrics']['assigned']);
            $this->assertSame(0, $self['activity']['metrics']['delivered']);
            $this->assertTrue($self['earnings']->isEmpty());
            $this->assertSame([], $self['balanceRows']);
        }
        $this->actingAs($c['captains'][0]->user)->get(route('captain.self-information').'?captain_id='.$c['captains'][1]->id)
            ->assertOk()->assertViewHas('captain', fn ($record) => $record->id === $c['captains'][0]->id);
        $this->get(route('shared-orders.financial-review', $c['order']))->assertForbidden();
    }

    public function test_demo_reset_knows_selection_dependency_and_refuses_unattended_testing_execution(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        $before = DB::table('orders')->orderBy('id')->get()->toJson();
        $tables = (new \ReflectionClass(OrderResetMaintenance::class))->getConstant('TABLES');
        $this->assertContains('order_captain_selections', $tables);
        $this->assertContains('order_shop_purchase_allocations', $tables);
        $this->assertSame(1, Artisan::call('abshir:reset-demo-orders', ['--no-interaction' => true]));
        $this->assertStringContainsString('Interactive confirmation is required', Artisan::output());
        $this->assertSame($before, DB::table('orders')->orderBy('id')->get()->toJson());
        $this->assertSame(1, DB::table('order_captain_selections')->count());
    }

    public function test_reset_catalog_covers_new_dependencies_and_their_active_delete_guards(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        $tables = (new \ReflectionClass(OrderResetMaintenance::class))->getConstant('TABLES');
        $dependencies = DB::select("WITH RECURSIVE d(oid) AS (
            SELECT 'orders'::regclass::oid UNION
            SELECT c.conrelid FROM pg_constraint c JOIN d ON d.oid=c.confrelid WHERE c.contype='f'
        ) SELECT relname FROM d JOIN pg_class ON pg_class.oid=d.oid");
        $names = array_column($dependencies, 'relname');
        foreach (['order_captain_selections', 'order_shop_purchase_allocations'] as $name) {
            $this->assertContains($name, $names);
            $this->assertContains($name, $tables);
            $guards = DB::select('SELECT t.tgenabled,pg_get_functiondef(t.tgfoid) AS definition
                FROM pg_trigger t WHERE t.tgrelid=CAST(? AS regclass) AND NOT t.tgisinternal AND (t.tgtype & 8)<>0', [$name]);
            $this->assertNotEmpty($guards);
            foreach ($guards as $guard) {
                $this->assertSame('O', $guard->tgenabled);
                $this->assertMatchesRegularExpression('/^BEGIN[ \\t]*\\r?$/m', $guard->definition);
            }
        }
        $this->assertThrows(fn () => OrderResetMaintenance::connect(config('database.connections.pgsql'), 'testing', false), \RuntimeException::class);
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('order_captain_selections')->count());
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_selected_shared_workflow_explains_blocked_bill_and_delivery_execution(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        $workflow = app(OrderWorkflowData::class)->load($c['actor'], $c['order']);
        $this->assertSame(__('shared_orders.pending_finance'), $workflow['billPaymentRequestProblem']);
        $before = DB::table('order_status_history')->count();
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
            OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
            OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], $status, null);
            $c['order']->refresh();
        }
        $this->assertSame(__('shared_orders.pending_finance'), app(OrderStatusService::class)->nextTransitionProblem($c['order']));
        $this->assertThrows(fn () => app(OrderStatusService::class)->transition($c['actor'], $c['order'],
            OrderOperationalStatus::Delivered, null), ValidationException::class);
        $this->assertSame('HEADING_TO_CUSTOMER', $c['order']->fresh()->operational_status->value);
        $this->assertSame($before + 5, DB::table('order_status_history')->count());
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_audited_single_captain_retains_real_invoice_cash_and_separate_deposit_semantics(): void
    {
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext(2, 1);
        $this->select($c);
        $data = $this->sharedData($c);
        $data['order_captain_id'] = $c['assignments'][0]->id;
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], $data, $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($c['actor'], $c['order'], $invoices->refresh($c['actor'], $c['order'], $invoices->createDraft($c['actor'], $c['order'])));
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
            OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
            OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], $status, null);
            $c['order']->refresh();
        }
        $collection = app(InvoiceCollectionService::class)->record($c['actor'], $invoice,
            InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame('90.0000', $collection->amount);
        $this->assertSame($c['captains'][0]->id, $collection->captain_id);
        $event = FinancialTransaction::sole();
        $this->assertSame('ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT', $event->event_type);
        $entry = $event->entries()->with('account')->sole();
        $this->assertSame('CAPTAIN_DEPOSIT', $entry->account->account_kind->value);
        $this->assertSame('DECREASE', $entry->direction->value);
        $this->assertSame('5.0000', $entry->amount);
        $this->assertSame(0, DB::table('financial_accounts')->where('account_kind', 'CAPTAIN_CASH_CUSTODY')->count());
        $this->assertSame(0, DB::table('captain_receivable_payouts')->count());
    }

    private function select(array $c): void
    {
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
            array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
    }

    private function purchase(array $c): void
    {
        // A new selection's assigned_at is now; record the real action after both participants exist.
        $data = $this->sharedData($c);
        $data['purchased_at'] = now()->format('Y-m-d H:i:s.uP');
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $data, $this->sharedImage());
    }
}
