<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Models\OrderShop;
use App\Models\Permission;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\CommissionCalculator;
use App\Services\DeliveryPricingRuleService;
use App\Services\InvoiceCollectionService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderPricingService;
use App\Services\ProductGroupMembershipService;
use App\Services\ShopCommissionAccountingService;
use App\Services\ShopCommissionActivationService;
use App\Services\ShopCommissionDraftService;
use App\Services\ShopCommissionRuleResolver;
use App\Services\ShopPartnershipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ShopCommissionFixtures;
use Tests\TestCase;

class ShopCommissionAccountingTest extends TestCase
{
    use RefreshDatabase, ShopCommissionFixtures;

    #[DataProvider('earningScenarios')]
    public function test_real_single_and_shared_flows_earn_once_only_after_both_events(int $captains, string $method, bool $collectFirst): void
    {
        $c = $this->commissionOrder($captains);
        $this->assertDatabaseCount('shop_commission_transactions', 0);
        $this->assertSame('8.00', DB::table('shop_commission_lines')->sole()->amount);
        if ($collectFirst) {
            $this->collect($c, $method);
        } else {
            $this->deliverCommissionOrder($c);
        }
        $this->assertDatabaseCount('shop_commission_transactions', 0);
        if ($collectFirst) {
            $this->deliverCommissionOrder($c);
        } else {
            $this->collect($c, $method);
        }
        $service = app(ShopCommissionAccountingService::class);
        $service->postIfReady($c['order']);
        $service->postIfReady($c['order']);
        $this->assertDatabaseCount('shop_commission_transactions', 1);
        $event = DB::table('shop_commission_transactions')->sole();
        $this->assertSame('8.00', $event->amount);
        $this->assertSame('EARNING', $event->kind);
        $this->assertSame('8.00', $service->balance($event->account_id)['outstanding']);
        $history = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $data = $this->paymentData('3');
        $first = $service->recordPayment($c['admin'], $c['master'], $event->account_id, $data);
        $this->assertSame($first->id, $service->recordPayment($c['admin'], $c['master'], $event->account_id, $data)->id);
        $this->assertSame('5.00', $service->balance($event->account_id)['outstanding']);
        $service->recordPayment($c['admin'], $c['master'], $event->account_id, $this->paymentData('5', 'TRANSFER'));
        $this->assertSame('0', $service->balance($event->account_id)['outstanding']);
        $this->assertThrows(fn () => $service->recordPayment($c['admin'], $c['master'], $event->account_id, $this->paymentData('1')), ValidationException::class);
        $this->assertSame($history, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_collections', 1);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function earningScenarios(): array
    {
        return [[1, 'CASH', true], [1, 'CASH', false], [1, 'SHAM_CASH', true], [1, 'SHAM_CASH', false], [2, 'CASH', true], [2, 'CASH', false], [2, 'SHAM_CASH', true], [2, 'SHAM_CASH', false]];
    }

    public function test_percentage_uses_product_value_and_excludes_combined_delivery_discount(): void
    {
        $c = $this->commissionOrder(2, 'PERCENTAGE', '10', 2, '4', '50', true, function ($c): void {
            app(OrderDeliveryPricingService::class)->applyDiscount($c['admin'], $c['order'], '7', 'Company-funded delivery discount');
        });
        $this->assertSame('200.0000', $c['invoice']->items_subtotal);
        $this->assertSame('213.0000', $c['invoice']->grand_total);
        $this->assertSame('20.00', DB::table('shop_commission_lines')->sole()->amount);
        $calculator = app(CommissionCalculator::class);
        $this->assertSame('18.00', $calculator->calculate($c['rule'], '4', '180', 2));
    }

    #[DataProvider('roundingCases')]
    public function test_fixed_quantity_and_half_up_round_once_per_item(string $method, string $value, int $places, string $quantity, string $price, string $expected): void
    {
        $c = $this->commissionOrder(1, $method, $value, $places, $quantity, $price);
        $this->assertSame($expected, DB::table('shop_commission_lines')->sole()->amount);
        $this->assertSame($c['invoice']->items()->sole()->line_total, DB::table('shop_commission_lines')->sole()->discounted_base);
    }

    public static function roundingCases(): array
    {
        return [['FIXED', '1.25', 2, '4', '50', '5.00'], ['FIXED', '1', 0, '2.5', '10', '3'], ['PERCENTAGE', '2.5', 0, '1', '100', '3'], ['PERCENTAGE', '3', 2, '1', '10.50', '0.32']];
    }

    public function test_no_active_rule_is_frozen_as_zero_and_later_activation_does_not_retroactively_earn(): void
    {
        $c = $this->commissionOrder(active: false);
        $this->assertSame('0.00', DB::table('shop_commission_lines')->sole()->amount);
        app(ShopCommissionActivationService::class)->change($c['admin'], $c['master'], $c['partnership'], $c['rule']->id, true, 'After Invoice');
        $this->collect($c, 'SHAM_CASH');
        $this->deliverCommissionOrder($c);
        $this->assertDatabaseCount('shop_commission_transactions', 0);
        $this->assertDatabaseCount('shop_commission_accounts', 0);
    }

    public function test_frozen_revision_survives_product_price_group_and_agreement_changes(): void
    {
        $c = $this->commissionOrder();
        $before = DB::table('shop_commission_lines')->get()->toJson();
        $invoiceBefore = $c['invoice']->fresh()->toArray();
        $c['product']->update(['name' => 'Renamed product']);
        $revision = app(ShopCommissionDraftService::class)->revise($c['admin'], $c['master'], $c['partnership'], $c['rule'], ['terms' => 'New proposed rate', 'commission_value' => '20', 'expected_version' => 1]);
        app(ShopCommissionActivationService::class)->change($c['admin'], $c['master'], $c['partnership'], $revision->id, true, 'New version');
        $this->collect($c, 'CASH');
        $this->deliverCommissionOrder($c);
        $this->assertSame('8.00', DB::table('shop_commission_transactions')->sole()->amount);
        $this->assertSame($before, DB::table('shop_commission_lines')->get()->toJson());
        $this->assertSame($invoiceBefore, $c['invoice']->fresh()->toArray());
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('shop_commission_lines')->update(['amount' => '16'])), QueryException::class);
    }

    public function test_group_priorities_shop_fallback_deactivation_and_database_ambiguity_guard(): void
    {
        $c = $this->commissionOrder(active: false);
        $service = app(ShopCommissionDraftService::class);
        $activation = app(ShopCommissionActivationService::class);
        $base = ['currency_id' => $c['currency']->id, 'method' => 'PERCENTAGE', 'commission_value' => '2', 'terms' => 'Group config', 'effective_from' => now()->subDay()->toIso8601String()];
        $shopRule = $service->create($c['admin'], $c['master'], $c['partnership'], $base);
        $activation->change($c['admin'], $c['master'], $c['partnership'], $shopRule->id, true, 'Shop fallback');
        $resolver = app(ShopCommissionRuleResolver::class);
        $this->assertSame($shopRule->id, $resolver->resolveActive($c['master']->id, $c['product']->id, $c['currency']->id, now())->id);
        $first = ProductGroup::create(['name' => 'First']);
        $second = ProductGroup::create(['name' => 'Second']);
        foreach ([$first, $second] as $group) {
            app(ProductGroupMembershipService::class)->join($c['admin'], $c['product'], $group, ['joined_at' => now()->subDay()]);
        }
        $one = $service->create($c['admin'], $c['master'], $c['partnership'], $base + ['product_group_id' => $first->id, 'group_priority' => 5]);
        $two = $service->create($c['admin'], $c['master'], $c['partnership'], $base + ['product_group_id' => $second->id, 'group_priority' => 5]);
        $activation->change($c['admin'], $c['master'], $c['partnership'], $one->id, true, 'First group');
        $this->assertThrows(fn () => $activation->change($c['admin'], $c['master'], $c['partnership'], $two->id, true, 'Ambiguous group'), ValidationException::class);
        $two = $service->revise($c['admin'], $c['master'], $c['partnership'], $two, ['expected_version' => 1, 'terms' => 'Distinct priority', 'group_priority' => 6]);
        $activation->change($c['admin'], $c['master'], $c['partnership'], $two->id, true, 'Higher priority');
        $this->assertSame($two->id, $resolver->resolveActive($c['master']->id, $c['product']->id, $c['currency']->id, now())->id);
        $activation->change($c['admin'], $c['master'], $c['partnership'], $c['rule']->id, true, 'Product wins');
        $this->assertSame($c['rule']->id, $resolver->resolveActive($c['master']->id, $c['product']->id, $c['currency']->id, now())->id);
        $activation->change($c['admin'], $c['master'], $c['partnership'], $c['rule']->id, false, 'Stop Product');
        $this->assertSame($two->id, $resolver->resolveActive($c['master']->id, $c['product']->id, $c['currency']->id, now())->id);
    }

    public function test_account_http_permissions_branch_scope_payment_replay_and_escaping(): void
    {
        $c = $this->commissionOrder();
        $this->collect($c, 'CASH');
        $this->deliverCommissionOrder($c);
        $account = DB::table('shop_commission_accounts')->sole();
        $url = route('shops.commission-account', $c['master']);
        $post = route('shops.commission-payments.store', [$c['master'], $account->id]);
        $data = $this->paymentData('3', 'TRANSFER') + ['reference' => '<script>alert(1)</script>'];
        $this->actingAs($c['admin'])->post($post, $data)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee(__('commissions.subledger_notice'))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->post($post, array_replace($data, ['amount' => '4']))->assertSessionHasErrors('payment');
        $this->actingAs($c['actor'])->get($url)->assertForbidden();
        $this->post($post, $this->paymentData('1'))->assertForbidden();
        $role = Role::create(['code' => 'SHOP_FINANCE_EDITOR', 'name' => 'Editor']);
        $role->permissions()->attach(Permission::whereIn('code', ['shops.view', 'company_financials.view', 'shop_partnerships.update'])->pluck('id'));
        $foreign = User::factory()->create();
        $foreign->roles()->attach($role);
        $this->actingAs($foreign)->get($url)->assertForbidden();
        $this->post($post, $this->paymentData('1'))->assertForbidden();
        $this->assertThrows(fn () => app(ShopCommissionAccountingService::class)->recordPayment($foreign, $c['master'], $account->id, $this->paymentData('1')), HttpException::class);
        $this->assertSame(1, DB::table('shop_commission_transactions')->where('kind', 'PAYMENT')->count());
    }

    public function test_postgresql_rejects_early_accrual_wrong_amount_and_immutable_history(): void
    {
        $c = $this->commissionOrder();
        $account = DB::table('shop_commission_accounts')->sole();
        $line = DB::table('shop_commission_lines')->sole();
        $entry = ['account_id' => $account->id, 'line_id' => $line->id, 'kind' => 'EARNING', 'amount' => '8', 'occurred_at' => now()->toIso8601String(), 'recorded_by' => $c['admin']->id];
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('shop_commission_transactions')->insert($entry)), QueryException::class);
        $this->collect($c, 'CASH');
        $this->deliverCommissionOrder($c);
        foreach ([['amount' => '9'], ['amount' => 'NaN'], ['amount' => '0']] as $change) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('shop_commission_transactions')->insert(array_replace($entry, $change))), QueryException::class);
        }
        foreach (['UPDATE shop_commission_transactions SET amount=9', 'DELETE FROM shop_commission_transactions', 'TRUNCATE shop_commission_transactions'] as $sql) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::statement($sql)), QueryException::class);
        }
        $this->assertSame('8.00', DB::table('shop_commission_transactions')->sole()->amount);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_multi_shop_invoice_counts_products_once_and_keeps_shop_commissions_separate(): void
    {
        $c = $this->commissionOrder(beforeIssue: function ($c): void {
            $master = Shop::create(['name' => 'Second partner', 'area_id' => $c['shop']->origin_area_id, 'business_classification' => 'SHOP']);
            $partnership = app(ShopPartnershipService::class)->start($c['admin'], $master, ['starts_at' => now()->subDays(2), 'status' => 'ACTIVE', 'mobile_enabled' => false, 'online_orders_enabled' => false]);
            $rule = app(ShopCommissionDraftService::class)->create($c['admin'], $master, $partnership, ['currency_id' => $c['currency']->id, 'terms' => 'Second Shop', 'method' => 'PERCENTAGE', 'commission_value' => '10', 'effective_from' => now()->subDay()->toIso8601String()]);
            app(ShopCommissionActivationService::class)->change($c['admin'], $master, $partnership, $rule->id, true, 'Second Shop activation');
            $shop = OrderShop::create(['order_id' => $c['order']->id, 'shop_type' => 'REGISTERED', 'shop_id' => $master->id,
                'origin_area_id' => $c['shop']->origin_area_id, 'business_classification_snapshot' => 'SHOP', 'shop_name_snapshot' => $master->name, 'sort_order' => 2]);
            $item = $c['shop']->items()->sole()->replicate();
            $item->fill(['order_shop_id' => $shop->id, 'requested_quantity' => '2', 'current_quantity' => '2', 'actual_unit_price' => '30'])->save();
            $c['order']->update(['pricing_class' => 'MULTI_SHOP']);
            app(OrderPricingService::class)->recalculate($c['order']);
            app(DeliveryPricingRuleService::class)->create($c['admin'], ['from_area_id' => $shop->origin_area_id, 'to_area_id' => $c['order']->destination_area_id,
                'currency_id' => $c['currency']->id, 'order_pricing_class' => 'MULTI_SHOP', 'delivery_unit_fee' => '10', 'effective_from' => now()->subDay()]);
            app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $shop->originArea);
        });
        $this->assertSame('140.0000', $c['invoice']->items_subtotal);
        $this->assertSame('150.0000', $c['invoice']->grand_total);
        $this->collect($c, 'CASH');
        $this->deliverCommissionOrder($c);
        $this->assertSame(['8.00', '6.00'], DB::table('shop_commission_transactions')->orderBy('id')->pluck('amount')->all());
        $this->assertDatabaseCount('shop_commission_accounts', 2);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_http_activation_preserves_audit_and_keeps_the_previous_active_revision_visible(): void
    {
        $c = $this->commissionOrder(active: false);
        $activationUrl = route('shops.partnerships.commissions.activation', [$c['master'], $c['partnership'], $c['rule']]);
        $this->actingAs($c['actor'])->post($activationUrl, ['is_active' => 1, 'reason' => 'Forbidden'])->assertForbidden();
        $this->actingAs($c['admin'])->post($activationUrl, ['is_active' => 1, 'reason' => 'Owner activated'])->assertSessionHasNoErrors();
        $event = DB::table('shop_commission_activation_events')->sole();
        $this->assertSame($c['admin']->id, $event->recorded_by);
        app(ShopCommissionDraftService::class)->revise($c['admin'], $c['master'], $c['partnership'], $c['rule'], ['expected_version' => 1, 'terms' => 'New inactive revision', 'commission_value' => '20']);
        $this->get(route('shops.partnerships.commissions', [$c['master'], $c['partnership']]))->assertOk()
            ->assertViewHas('drafts', fn ($drafts) => $drafts->total() === 2)->assertSee(__('commissions.active'))->assertSee('Owner activated');
        $this->post($activationUrl, ['is_active' => 0, 'reason' => 'Owner stopped'])->assertSessionHasNoErrors();
        $this->assertSame(['ACTIVE', 'INACTIVE'], DB::table('shop_commission_activation_events')->orderBy('id')->pluck('state')->all());
        $this->assertSame('0.00', DB::table('shop_commission_lines')->sole()->amount);
    }

    private function collect(array $c, string $method): void
    {
        app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::from($method), $c['invoice']->grand_total, $c['currency']->id, now(), null, $method === 'SHAM_CASH' ? 'Direct payment' : null);
    }

    private function paymentData(string $amount, string $method = 'CASH'): array
    {
        return ['amount' => $amount, 'payment_method' => $method, 'occurred_at' => now()->toIso8601String(), 'request_key' => (string) str()->uuid()];
    }
}
