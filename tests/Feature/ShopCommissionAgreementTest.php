<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopCommissionDraft;
use App\Models\Unit;
use App\Models\User;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\ProductGroupMembershipService;
use App\Services\SharedOrderShopPurchaseService;
use App\Services\ShopCommissionDraftService;
use App\Services\ShopCommissionEligibilityService;
use App\Services\ShopCommissionRuleResolver;
use App\Services\ShopPartnershipService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class ShopCommissionAgreementTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_http_configuration_and_revision_preserve_exact_values_and_history(): void
    {
        $c = $this->context();
        $url = route('shops.partnerships.commissions.store', [$c['shop'], $c['partnership']]);
        $this->actingAs($c['admin'])->post($url, $this->data($c))->assertSessionHasNoErrors();
        $first = ShopCommissionDraft::sole();
        $before = $first->getAttributes();
        $this->assertSame('PERCENTAGE', $first->method);
        $this->assertSame('3.125', $first->commission_value);
        $this->post(route('shops.partnerships.commissions.revise', [$c['shop'], $c['partnership'], $first]),
            ['expected_version' => 1, 'terms' => 'Revised fixed configuration', 'method' => 'FIXED', 'commission_value' => '12.34'])
            ->assertSessionHasNoErrors();
        $last = $first->nextVersion()->sole();
        $this->assertSame('FIXED', $last->method);
        $this->assertSame('12.34', $last->commission_value);
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertSame($first->id, $last->replaces_id);
        $this->assertSame($c['admin']->id, $last->created_by_user_id);
        $this->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertOk()
            ->assertSee(__('commissions.preview'))->assertSee(__('commissions.calculation_policy'));
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected_without_any_writes(array $change): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->post(route('shops.partnerships.commissions.store', [$c['shop'], $c['partnership']]), array_replace($this->data($c), $change))->assertSessionHasErrors();
        $this->assertDatabaseCount('shop_commission_drafts', 0);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'negative' => [['commission_value' => '-1']], 'above hundred' => [['commission_value' => '100.0001']],
            'float' => [['commission_value' => 0.1]], 'non finite' => [['commission_value' => 'NaN']],
            'fixed precision' => [['method' => 'FIXED', 'commission_value' => '1.234']],
            'no currency' => [['currency_id' => null]], 'no start' => [['effective_from' => null]],
            'end boundary' => [['effective_from' => '2026-10-12', 'effective_until' => '2026-10-12']],
            'unknown method' => [['method' => 'ACTIVE']], 'activation' => [['status' => 'ACTIVE']],
            'unexpected priority' => [['group_priority' => 2]],
        ];
    }

    public function test_product_then_group_then_shop_precedence_and_currency_isolation(): void
    {
        $c = $this->context();
        $service = app(ShopCommissionDraftService::class);
        $shop = $service->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['product_id' => null]));
        $this->assertSame($shop->id, $this->preview($c)['rule']->id);
        app(ProductGroupMembershipService::class)->join($c['admin'], $c['product'], $c['group'], ['joined_at' => now()->subDay()]);
        $group = $service->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['product_id' => null, 'product_group_id' => $c['group']->id, 'group_priority' => 1]));
        $this->assertSame($group->id, $this->preview($c)['rule']->id);
        $product = $service->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $this->assertSame($product->id, $this->preview($c)['rule']->id);
        $other = Currency::create(['code' => 'EUR', 'name' => 'Euro']);
        $this->assertSame('NONE', $this->preview(array_replace($c, ['currency' => $other]))['status']);
        $this->assertThrows(fn () => $service->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['product_id' => null])), ValidationException::class);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_explicit_group_priority_and_ties_fail_closed_for_preview_and_activation(): void
    {
        $c = $this->context();
        $secondGroup = ProductGroup::create(['name' => 'Second group']);
        $service = app(ShopCommissionDraftService::class);
        foreach ([$c['group'], $secondGroup] as $group) {
            app(ProductGroupMembershipService::class)->join($c['admin'], $c['product'], $group, ['joined_at' => now()->subDay()]);
            $service->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['product_id' => null, 'product_group_id' => $group->id, 'group_priority' => 5]));
        }
        $this->assertSame(['CONFLICT', null], [$this->preview($c)['status'], $this->preview($c)['rule']]);
        $this->assertThrows(fn () => app(ShopCommissionRuleResolver::class)->assertActivationReady($c['admin'], $c['shop'], $c['partnership'], $c['product'], $c['currency'], now()),
            fn (ValidationException $e) => $e->errors()['commission'][0] === __('commissions.ambiguous'));
        $draft = ShopCommissionDraft::where('product_group_id', $secondGroup->id)->sole();
        $winner = $service->revise($c['admin'], $c['shop'], $c['partnership'], $draft, ['expected_version' => 1, 'terms' => 'Higher priority', 'group_priority' => 6]);
        $this->assertSame($winner->id, $this->preview($c)['rule']->id);
        app(ShopCommissionRuleResolver::class)->assertActivationReady($c['admin'], $c['shop'], $c['partnership'], $c['product'], $c['currency'], now());
        $this->assertDatabaseCount('shop_commission_activation_events', 0);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    public function test_effective_dates_historical_versions_and_membership_end_are_exclusive(): void
    {
        $c = $this->context();
        $service = app(ShopCommissionDraftService::class);
        $first = $service->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $knownAt = now();
        $this->travel(1)->minutes();
        $start = now()->addDay()->startOfSecond();
        $end = $start->copy()->addDay();
        $second = $service->revise($c['admin'], $c['shop'], $c['partnership'], $first, [
            'expected_version' => 1, 'terms' => 'Future version', 'commission_value' => '9', 'effective_from' => $start->toIso8601String(), 'effective_until' => $end->toIso8601String()]);
        $this->assertSame($first->id, $this->preview($c)['rule']->id);
        $this->assertSame($second->id, $this->preview($c, $start)['rule']->id);
        $this->assertSame('NONE', $this->preview($c, $end)['status']);
        $historic = app(ShopCommissionRuleResolver::class)->preview($c['admin'], $c['shop'], $c['partnership'], $c['product'], $c['currency'], $start, $knownAt);
        $this->assertSame($first->id, $historic['rule']->id);
        $membershipEnd = $end->copy()->addHour()->setTimezone(DB::scalar('SHOW timezone'));
        $member = app(ProductGroupMembershipService::class)->join($c['admin'], $c['product'], $c['group'], ['joined_at' => now()->subDay(), 'left_at' => $membershipEnd]);
        $group = $service->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['product_id' => null, 'product_group_id' => $c['group']->id, 'group_priority' => 5]));
        $this->assertSame($group->id, $this->preview($c, $end)['rule']->id);
        $this->assertSame('NONE', $this->preview($c, $member->left_at)['status']);
    }

    public function test_read_only_preview_and_configuration_cannot_bypass_branch_or_catalog_permissions(): void
    {
        $c = $this->context();
        $other = $this->context();
        $role = Role::create(['code' => 'COMMISSION_READER', 'name' => 'Reader']);
        $role->permissions()->attach(Permission::whereIn('code', ['shops.view', 'shop_partnerships.view', 'products.view', 'currencies.view'])->pluck('id'));
        $actor = User::factory()->create();
        $actor->roles()->attach($role);
        $actor->branches()->attach($c['branch']);
        $this->assertSame('NONE', $this->preview(array_replace($c, ['admin' => $actor]))['status']);
        $this->assertThrows(fn () => $this->preview(array_replace($other, ['admin' => $actor])), HttpException::class);
        $this->actingAs($actor)->post(route('shops.partnerships.commissions.store', [$c['shop'], $c['partnership']]), $this->data($c))->assertForbidden();
        $this->assertThrows(fn () => app(ShopCommissionRuleResolver::class)->assertActivationReady($actor, $c['shop'], $c['partnership'], $c['product'], $c['currency'], now()), AuthorizationException::class);
        $this->assertThrows(fn () => $this->preview(array_replace($c, ['partnership' => $other['partnership']])), HttpException::class);
        $this->assertDatabaseCount('shop_commission_drafts', 0);
    }

    public function test_postgresql_rejects_invalid_values_precision_and_configured_downgrade(): void
    {
        $c = $this->context();
        $first = app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $row = $first->getAttributes();
        unset($row['id']);
        $row['version'] = 2;
        $row['replaces_id'] = $first->id;
        foreach ([['commission_value' => 'NaN'], ['commission_value' => 'Infinity'], ['commission_value' => '-Infinity'], ['commission_value' => '-1'], ['commission_value' => '101'], ['method' => 'FIXED', 'commission_value' => '1.234'],
            ['method' => null, 'commission_value' => null, 'effective_from' => null], ['group_priority' => 2]] as $change) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('shop_commission_drafts')->insert(array_replace($row, $change))), QueryException::class);
        }
        $this->assertThrows(fn () => DB::transaction(fn () => $first->update(['commission_value' => '5'])), QueryException::class);
        $this->assertSame('3.125', $first->fresh()->commission_value);
    }

    public function test_additive_migration_preserves_phase10a_rows_and_allows_audited_configuration_upgrade(): void
    {
        $c = $this->context();
        foreach (['shop_commission_transactions', 'shop_commission_lines', 'shop_commission_invoice_snapshots', 'shop_commission_issue_instants', 'shop_commission_activation_events', 'shop_commission_accounts', 'shop_commission_legacy_invoices', 'shop_commission_epoch'] as $table) {
            Schema::drop($table);
        }
        Schema::drop('shop_commission_drafts');
        DB::statement('DROP FUNCTION guard_shop_commission_draft()');
        DB::statement('DROP FUNCTION guard_shop_commission_agreement()');
        (require database_path('migrations/2026_10_12_132329_create_shop_commission_drafts_table.php'))->up();
        $service = app(ShopCommissionDraftService::class);
        $data = array_intersect_key($this->data($c), array_flip(['product_id', 'currency_id', 'terms']));
        $legacy = $service->create($c['admin'], $c['shop'], $c['partnership'], $data);
        $before = $legacy->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_12_140314_extend_shop_commission_drafts_with_agreement_rules.php');
        $migration->up();
        $after = array_intersect_key($legacy->fresh()->getAttributes(), $before);
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
        $this->assertSame('NONE', $this->preview($c)['status']);
        $new = $service->revise($c['admin'], $c['shop'], $c['partnership'], $legacy, [
            'expected_version' => 1, 'terms' => 'Configured revision', 'method' => 'FIXED', 'commission_value' => '5.25', 'effective_from' => now()->subDay()->toIso8601String()]);
        $this->assertSame($new->id, $this->preview($c)['rule']->id);
        $this->assertNull($legacy->fresh()->method);
        $this->assertThrows(fn () => $migration->down(), \LogicException::class);
    }

    public function test_real_item_matching_uses_its_own_shop_product_currency_and_branch(): void
    {
        $source = $this->sharedContext(2, 1);
        $item = $source['shop']->items()->sole();
        $resolver = app(ShopCommissionRuleResolver::class);
        $this->assertSame('no_partner_shop', $resolver->previewItem($source['admin'], $item, now())['reason']);
        $c = $this->context();
        $source['shop']->update(['shop_id' => $c['shop']->id, 'shop_type' => 'REGISTERED']);
        $draft = app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['product_id' => null, 'currency_id' => $source['currency']->id]));
        $this->assertSame($draft->id, $resolver->previewItem($source['admin'], $item, now())['rule']->id);
        $item->update(['product_id' => $c['product']->id]);
        $product = app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['currency_id' => $source['currency']->id]));
        $this->assertSame($product->id, $resolver->previewItem($source['admin'], $item, now())['rule']->id);
        $this->assertThrows(fn () => $resolver->previewItem($source['actor'], $item, now()), AuthorizationException::class);
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->assertThrows(fn () => $resolver->previewItem($foreign, $item, now()), HttpException::class);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    public function test_preview_http_displays_match_without_activation_or_financial_amount_and_rejects_partial_inputs(): void
    {
        $c = $this->context();
        $draft = app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $url = route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]);
        $parameters = ['preview_product_id' => $c['product']->id, 'preview_currency_id' => $c['currency']->id, 'preview_at' => now()->toIso8601String()];
        $this->actingAs($c['admin'])->get($url.'?'.http_build_query($parameters))->assertOk()
            ->assertViewHas('preview', fn ($result) => $result['rule']->id === $draft->id && $result['status'] === 'MATCHED')
            ->assertSee(__('commissions.preview_only'))->assertSee('3.125');
        $this->get($url.'?preview_product_id='.$c['product']->id)->assertSessionHasErrors(['preview_currency_id', 'preview_at']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_damascus_local_form_and_absolute_preview_instant_match_at_exact_boundaries(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12T12:00:00+03:00'));
        $c = $this->context();
        $draft = app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), [
            'effective_from' => '2026-10-12T13:00:00', 'effective_until' => '2026-10-12T11:00:00Z']));
        $this->assertSame('2026-10-12T10:00:00+00:00', $draft->fresh()->effective_from->utc()->toIso8601String());
        $this->assertSame('NONE', $this->preview($c, Carbon::parse('2026-10-12T09:59:59Z'))['status']);
        $this->assertSame($draft->id, $this->preview($c, Carbon::parse('2026-10-12T10:00:00Z'))['rule']->id);
        $this->assertSame('NONE', $this->preview($c, Carbon::parse('2026-10-12T11:00:00Z'))['status']);
        $this->assertThrows(fn () => app(ShopCommissionDraftService::class)->revise($c['admin'], $c['shop'], $c['partnership'], $draft,
            ['expected_version' => 1, 'terms' => 'Invalid instant interval', 'effective_from' => '2026-10-12T13:00:00', 'effective_until' => '2026-10-12T09:59:59Z']),
            fn (ValidationException $e) => $e->errors()['effective_until'][0] === __('commissions.invalid_interval'));
        $this->actingAs($c['admin'])->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))
            ->assertOk()->assertSee('2026-10-12T13:00:00.000000', false);
    }

    public function test_configuration_edit_requires_currency_permission_but_terms_only_revision_remains_available(): void
    {
        $c = $this->context();
        $draft = app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $role = Role::create(['code' => 'TERMS_EDITOR', 'name' => 'Terms editor']);
        $role->permissions()->attach(Permission::whereIn('code', ['shops.view', 'shop_partnerships.view', 'shop_partnerships.update'])->pluck('id'));
        $actor = User::factory()->create();
        $actor->roles()->attach($role);
        $actor->branches()->attach($c['branch']);
        $this->actingAs($actor)->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertOk()->assertDontSee('name="method"', false);
        $url = route('shops.partnerships.commissions.revise', [$c['shop'], $c['partnership'], $draft]);
        $this->post($url, ['terms' => 'Forbidden configuration', 'expected_version' => 1, 'commission_value' => '5'])->assertForbidden();
        $this->post($url, ['terms' => 'Permitted notes revision', 'expected_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame('3.125', $draft->nextVersion()->sole()->commission_value);
    }

    public function test_zero_and_hundred_percent_configurations_are_exact_and_group_priority_is_mandatory(): void
    {
        $c = $this->context();
        $service = app(ShopCommissionDraftService::class);
        $first = $service->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['commission_value' => '100']));
        $this->assertSame('100', $first->commission_value);
        $zero = $service->revise($c['admin'], $c['shop'], $c['partnership'], $first, ['terms' => 'Zero proposal', 'expected_version' => 1, 'method' => 'FIXED', 'commission_value' => '0']);
        $this->assertSame('0', $zero->commission_value);
        $this->assertThrows(fn () => $service->create($c['admin'], $c['shop'], $c['partnership'], array_replace($this->data($c), ['product_id' => null, 'product_group_id' => $c['group']->id])),
            fn (ValidationException $e) => isset($e->errors()['group_priority']));
        $this->assertDatabaseCount('financial_entries', 0);
    }

    #[DataProvider('collectionScenarios')]
    public function test_eligibility_requires_real_delivery_and_current_collection_and_never_mutates_finances(int $count, string $method): void
    {
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext(2, $count);
        $eligibility = app(ShopCommissionEligibilityService::class);
        $this->assertFalse($eligibility->inspect($c['admin'], $c['order'])['eligible']);
        if ($count === 2) {
            app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
        }
        $purchase = $count === 2 ? SharedOrderShopPurchaseService::class : OrderShopPurchaseConfirmationService::class;
        app($purchase)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($c['actor'], $c['order'], $invoices->refresh($c['actor'], $c['order'], $invoices->createDraft($c['actor'], $c['order'])));
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
        $this->assertSame('not_collected', $eligibility->inspect($c['admin'], $c['order'])['reason']);
        $collection = app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::from($method), $invoice->grand_total, $c['currency']->id, now(), null, $method === 'SHAM_CASH' ? 'Direct payment' : null);
        $tables = ['orders', 'invoices', 'invoice_items', 'invoice_collections', 'financial_transactions', 'financial_entries', 'order_financial_source_snapshots', 'order_financial_source_snapshot_lines', 'order_delivery_pricings', 'order_delivery_allocations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $result = $eligibility->inspect($c['admin'], $c['order']);
        $this->assertTrue($result['eligible']);
        $this->assertSame([$invoice->id, $collection->id], [$result['invoice_id'], $result['collection_id']]);
        $config = $this->context();
        $draft = app(ShopCommissionDraftService::class)->create($config['admin'], $config['shop'], $config['partnership'], $this->data($config));
        $this->assertSame($draft->id, $this->preview($config)['rule']->id);
        app(ShopCommissionDraftService::class)->revise($config['admin'], $config['shop'], $config['partnership'], $draft, ['expected_version' => 1, 'terms' => 'No financial effect', 'commission_value' => '4']);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->assertThrows(fn () => $eligibility->inspect($foreign, $c['order']), HttpException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function collectionScenarios(): array
    {
        return [[1, 'CASH'], [1, 'SHAM_CASH'], [2, 'CASH'], [2, 'SHAM_CASH']];
    }

    private function context(): array
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->seed(AuthorizationSeeder::class);
        $branch = Branch::factory()->create();
        $shop = Shop::create(['name' => 'Partner', 'area_id' => Area::factory()->for($branch)->create()->id, 'business_classification' => 'SHOP']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $partnership = app(ShopPartnershipService::class)->start($admin, $shop, ['starts_at' => now()->subDays(3), 'status' => 'ACTIVE', 'mobile_enabled' => false, 'online_orders_enabled' => false]);
        $unit = Unit::create(['name' => 'Piece', 'code' => str()->uuid(), 'allows_decimal' => false]);
        $product = Product::create(['name' => 'Product', 'default_unit_id' => $unit->id]);
        $group = ProductGroup::create(['name' => 'Group']);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'decimal_places' => 2]);

        return compact('branch', 'shop', 'admin', 'partnership', 'product', 'group', 'currency');
    }

    private function data(array $c): array
    {
        return ['product_id' => $c['product']->id, 'currency_id' => $c['currency']->id, 'terms' => 'Proposed agreement', 'method' => 'PERCENTAGE', 'commission_value' => '3.125', 'effective_from' => now()->subDay()->toIso8601String()];
    }

    private function preview(array $c, ?Carbon $at = null): array
    {
        return app(ShopCommissionRuleResolver::class)->preview($c['admin'], $c['shop'], $c['partnership'], $c['product'], $c['currency'], $at ?? now());
    }
}
