<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
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
use App\Services\SharedOrderShopPurchaseService;
use App\Services\ShopCommissionDraftService;
use App\Services\ShopPartnershipService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class ShopCommissionDraftTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_product_and_group_drafts_reuse_partnership_with_explicit_non_posting_visibility(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->post($this->url($c), $this->data($c))->assertRedirect(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertSessionHasNoErrors();
        $product = ShopCommissionDraft::sole();
        $this->assertSame([$c['partnership']->id, $c['product']->id, null, $c['currency']->id, 1, $c['admin']->id],
            [$product->shop_partnership_id, $product->product_id, $product->product_group_id, $product->currency_id, $product->version, $product->created_by_user_id]);
        app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], ['product_group_id' => $c['group']->id, 'terms' => 'Group terms awaiting owner approval']);
        $this->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertOk()
            ->assertSee(__('commissions.non_posting'))->assertSee($c['product']->name)->assertSee($c['group']->name)->assertSee(__('commissions.draft_only'));
        $this->get(route('shops.show', $c['shop']))->assertSee(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]), false);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    public function test_revisions_preserve_original_snapshots_actor_and_reject_stale_or_duplicate_drafts(): void
    {
        $c = $this->context();
        $service = app(ShopCommissionDraftService::class);
        $first = $service->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $before = $first->fresh()->toArray();
        $c['product']->update(['name' => 'Renamed later']);
        $second = $service->revise($c['admin'], $c['shop'], $c['partnership'], $first, ['expected_version' => 1, 'terms' => 'Revised proposed terms']);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->replaces_id);
        $this->assertSame($first->configuration_key, $second->configuration_key);
        $this->assertSame($before, $first->fresh()->toArray());
        $this->assertSame($before['target_name_snapshot'], $second->target_name_snapshot);
        $this->assertThrows(fn () => $service->revise($c['admin'], $c['shop'], $c['partnership'], $first, ['expected_version' => 1, 'terms' => 'Stale retry']), ValidationException::class);
        $this->assertThrows(fn () => $service->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c)), ValidationException::class);
        $this->assertDatabaseCount('shop_commission_drafts', 2);
    }

    #[DataProvider('invalidDrafts')]
    public function test_invalid_targets_and_unapproved_financial_fields_are_rejected(string $defect): void
    {
        $c = $this->context();
        $data = $this->data($c);
        $data = match ($defect) {
            'both' => $data + ['product_group_id' => $c['group']->id],
            'neither' => ['terms' => 'Missing target'],
            'missing' => array_replace($data, ['product_id' => 999999]),
            'blank' => array_replace($data, ['terms' => '   ']),
            'percentage' => $data + ['commission_percentage' => '3'],
            'activation' => $data + ['status' => 'ACTIVE'],
        };
        $this->actingAs($c['admin'])->post($this->url($c), $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('shop_commission_drafts', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function invalidDrafts(): array
    {
        return array_combine(['both', 'neither', 'missing', 'blank', 'percentage', 'activation'], array_map(fn ($v) => [$v], ['both', 'neither', 'missing', 'blank', 'percentage', 'activation']));
    }

    public function test_branch_editor_cannot_read_write_or_tamper_with_foreign_nested_records(): void
    {
        $c = $this->context();
        $foreign = $this->context();
        $role = Role::create(['code' => 'COMMISSION_EDITOR', 'name' => 'Scoped editor']);
        $role->permissions()->attach(Permission::whereIn('code', ['shops.view', 'shop_partnerships.view', 'shop_partnerships.create', 'shop_partnerships.update', 'products.view', 'product_groups.view', 'currencies.view'])->pluck('id'));
        $editor = User::factory()->create();
        $editor->roles()->attach($role);
        $editor->branches()->attach($c['branch']);
        $this->actingAs($editor)->post($this->url($c), $this->data($c))->assertSessionHasNoErrors();
        $this->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertOk();
        $this->get(route('shops.partnerships.commissions', [$foreign['shop'], $foreign['partnership']]))->assertForbidden();
        $this->post($this->url($foreign), $this->data($foreign))->assertForbidden();
        $this->post(route('shops.partnerships.commissions.store', [$c['shop'], $foreign['partnership']]), $this->data($c))->assertNotFound();
        $foreignDraft = app(ShopCommissionDraftService::class)->create($foreign['admin'], $foreign['shop'], $foreign['partnership'], $this->data($foreign));
        $this->post(route('shops.partnerships.commissions.revise', [$c['shop'], $c['partnership'], $foreignDraft]), ['terms' => 'Tampered', 'expected_version' => 1])->assertNotFound();
        $this->assertDatabaseCount('shop_commission_drafts', 2);
        $this->assertThrows(fn () => app(ShopCommissionDraftService::class)->create($editor, $foreign['shop'], $foreign['partnership'], $this->data($foreign)), HttpException::class);
    }

    public function test_captain_and_default_employee_receive_no_new_commission_administration_permission(): void
    {
        $c = $this->context();
        foreach (['CAPTAIN', 'ORDER_EMPLOYEE'] as $code) {
            $actor = User::factory()->create();
            $actor->roles()->attach(Role::where('code', $code)->sole());
            $actor->branches()->attach($c['branch']);
            $this->actingAs($actor)->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertForbidden();
            $this->post($this->url($c), $this->data($c))->assertForbidden();
        }
        $this->assertDatabaseCount('shop_commission_drafts', 0);
    }

    public function test_area_less_shop_is_super_admin_only_even_with_commission_permissions(): void
    {
        $c = $this->context();
        $c['shop']->update(['area_id' => null]);
        $this->actingAs($c['admin'])->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertOk();
        $c['admin']->roles()->detach();
        $role = Role::create(['code' => 'SCOPED_READ', 'name' => 'Scoped read']);
        $role->permissions()->attach(Permission::whereIn('code', ['shops.view', 'shop_partnerships.view'])->pluck('id'));
        $c['admin']->roles()->attach($role);
        $c['admin']->branches()->attach($c['branch']);
        $this->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertForbidden();
    }

    public function test_read_only_partner_permission_cannot_create_or_revise_a_known_draft(): void
    {
        $c = $this->context();
        $draft = app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $role = Role::create(['code' => 'PARTNER_READ', 'name' => 'Partner read']);
        $role->permissions()->attach(Permission::whereIn('code', ['shops.view', 'shop_partnerships.view'])->pluck('id'));
        $reader = User::factory()->create();
        $reader->roles()->attach($role);
        $reader->branches()->attach($c['branch']);

        $this->actingAs($reader)->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))
            ->assertOk()->assertDontSee(__('commissions.save_draft'))->assertDontSee(__('commissions.save_revision'));
        $this->post($this->url($c), $this->data($c))->assertForbidden();
        $this->post(route('shops.partnerships.commissions.revise', [$c['shop'], $c['partnership'], $draft]), ['terms' => 'Unauthorized', 'expected_version' => 1])->assertForbidden();
        $this->assertDatabaseCount('shop_commission_drafts', 1);
    }

    public function test_inactive_reference_blocks_new_scope_but_preserves_existing_revision_identity(): void
    {
        $c = $this->context();
        $service = app(ShopCommissionDraftService::class);
        $draft = $service->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $c['currency']->is_active = false;
        $c['currency']->save();
        $this->actingAs($c['admin'])->post($this->url($c), ['product_group_id' => $c['group']->id, 'currency_id' => $c['currency']->id, 'terms' => 'Inactive reference'])
            ->assertSessionHasErrors('commission');
        $revision = $service->revise($c['admin'], $c['shop'], $c['partnership'], $draft, ['terms' => 'Preserved draft history', 'expected_version' => 1]);
        $this->assertSame($c['currency']->id, $revision->currency_id);
        $this->assertSame('USD', $revision->currency_code_snapshot);
        $this->assertSame(2, $c['currency']->fresh()->decimal_places);
    }

    public function test_postgresql_preserves_history_and_rejects_invalid_revision_identity(): void
    {
        $c = $this->context();
        $service = app(ShopCommissionDraftService::class);
        $first = $service->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        foreach (['UPDATE shop_commission_drafts SET terms=\'tampered\'', 'DELETE FROM shop_commission_drafts', 'TRUNCATE shop_commission_drafts'] as $sql) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::statement($sql)), QueryException::class);
        }
        $row = $first->getAttributes();
        unset($row['id']);
        foreach ([['product_id' => $c['product']->id, 'product_group_id' => $c['group']->id], ['product_id' => null, 'product_group_id' => null]] as $target) {
            $invalid = array_replace($row, $target, ['configuration_key' => (string) str()->uuid(), 'currency_id' => null, 'currency_code_snapshot' => null]);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('shop_commission_drafts')->insert($invalid)), QueryException::class);
        }
        $row['version'] = 2;
        $row['replaces_id'] = $first->id;
        foreach ([['version' => 99], ['configuration_key' => (string) str()->uuid()], ['product_id' => null, 'product_group_id' => $c['group']->id], ['currency_id' => null, 'currency_code_snapshot' => null]] as $change) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('shop_commission_drafts')->insert(array_replace($row, $change))), QueryException::class);
        }
        $second = $service->revise($c['admin'], $c['shop'], $c['partnership'], $first, ['expected_version' => 1, 'terms' => 'Actual revision']);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('shop_commission_drafts')->insert($row)), QueryException::class);
        $this->assertSame('Actual revision', $second->fresh()->terms);
        $this->assertDatabaseCount('shop_commission_drafts', 2);
    }

    public function test_current_history_search_and_escaping_are_paginated_and_do_not_claim_activation(): void
    {
        $c = $this->context();
        $service = app(ShopCommissionDraftService::class);
        $first = $service->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        $service->revise($c['admin'], $c['shop'], $c['partnership'], $first, ['expected_version' => 1, 'terms' => '<script>alert(1)</script>']);
        $url = route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]);
        $this->actingAs($c['admin'])->get($url)->assertOk()->assertViewHas('drafts', fn ($rows) => $rows->total() === 1)
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get($url.'?history=1&target=product&search='.urlencode($c['product']->name))->assertViewHas('drafts', fn ($rows) => $rows->total() === 2);
        $this->delete($url.'/'.$first->id)->assertNotFound();
    }

    public function test_commission_draft_does_not_mutate_real_issued_collected_v2_invoice_or_ledger(): void
    {
        Storage::fake('order_returns');
        $source = $this->sharedContext();
        app(OrderCaptainSelectionService::class)->select($source['actor'], $source['order'], array_column($source['captains'], 'id'), $source['captains'][0]->id, (string) str()->uuid());
        app(SharedOrderShopPurchaseService::class)->record($source['actor'], $source['order'], $source['shop'], $this->sharedData($source), $this->sharedImage());
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($source['actor'], $source['order'], $invoices->refresh($source['actor'], $source['order'], $invoices->createDraft($source['actor'], $source['order'])));
        app(InvoiceCollectionService::class)->record($source['actor'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $source['currency']->id, now());
        $tables = ['invoices', 'invoice_items', 'invoice_collections', 'financial_transactions', 'financial_entries', 'order_financial_source_snapshots', 'order_financial_source_snapshot_lines', 'order_delivery_pricings', 'order_delivery_allocations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $c = $this->context();
        $draft = app(ShopCommissionDraftService::class)->create($c['admin'], $c['shop'], $c['partnership'], $this->data($c));
        app(ShopCommissionDraftService::class)->revise($c['admin'], $c['shop'], $c['partnership'], $draft, ['expected_version' => 1, 'terms' => 'No financial authority']);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_uninstalled_schema_has_clear_unavailable_state_and_controlled_write_rejection(): void
    {
        $c = $this->context();
        Schema::rename('shop_commission_drafts', 'uninstalled_shop_commission_drafts');
        $this->actingAs($c['admin'])->get(route('shops.partnerships.commissions', [$c['shop'], $c['partnership']]))->assertOk()->assertSee(__('commissions.activation_required'));
        $this->post($this->url($c), $this->data($c))->assertSessionHasErrors('commission');
        $this->post(route('shops.partnerships.commissions.revise', [$c['shop'], $c['partnership'], 999]), ['terms' => 'No schema', 'expected_version' => 1])->assertSessionHasErrors('commission');
    }

    private function context(): array
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->seed(AuthorizationSeeder::class);
        $branch = Branch::factory()->create();
        $shop = Shop::create(['name' => 'Partner Shop', 'area_id' => Area::factory()->for($branch)->create()->id, 'business_classification' => 'SHOP']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $partnership = app(ShopPartnershipService::class)->start($admin, $shop, ['starts_at' => now()->subDay(), 'status' => 'ACTIVE', 'mobile_enabled' => false, 'online_orders_enabled' => false]);
        $unit = Unit::create(['name' => 'Piece', 'code' => str()->uuid(), 'allows_decimal' => false]);
        $product = Product::create(['name' => 'Target '.str()->uuid(), 'default_unit_id' => $unit->id]);
        $group = ProductGroup::create(['name' => 'Group '.str()->uuid()]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'decimal_places' => 2]);

        return compact('branch', 'shop', 'admin', 'partnership', 'product', 'group', 'currency');
    }

    private function data(array $c): array
    {
        return ['product_id' => $c['product']->id, 'currency_id' => $c['currency']->id, 'terms' => 'Proposed contract terms awaiting approval'];
    }

    private function url(array $c): string
    {
        return route('shops.partnerships.commissions.store', [$c['shop'], $c['partnership']]);
    }
}
