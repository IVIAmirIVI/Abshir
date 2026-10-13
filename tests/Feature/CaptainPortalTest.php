<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBranchService;
use App\Services\InvoiceCollectionService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderCaptainService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShopCommissionFixtures;
use Tests\TestCase;

class CaptainPortalTest extends TestCase
{
    use RefreshDatabase, ShopCommissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00Z'));
    }

    private function captainUser(array $c, int $index = 0): User
    {
        $user = $c['captains'][$index]->user;
        $user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());

        return $user;
    }

    public function test_portal_is_self_scoped_and_tampered_captain_or_order_ids_cannot_change_identity(): void
    {
        $c = $this->sharedContext();
        $foreign = $this->sharedContext(2, 1);
        $user = $this->captainUser($c);
        $user->setRelation('captain', $foreign['captains'][0]);
        $this->actingAs($user)->get(route('captain.portal', ['captain_id' => $foreign['captains'][0]->id, 'user_id' => $foreign['captains'][0]->user_id]))
            ->assertOk()->assertSee($c['order']->order_number)->assertDontSee($foreign['order']->order_number)
            ->assertViewHas('captain', fn ($record) => $record->id === $c['captains'][0]->id)
            ->assertViewHas('page', fn ($page) => $page->total() === 1)->assertDontSee($c['order']->customer_name_snapshot);
        $this->get(route('captain.portal.orders.show', $foreign['order']->id))->assertNotFound();
        $this->get(route('captain.portal.orders.show', 999999))->assertNotFound();
        $this->get(route('captain.portal', ['branch_id' => $foreign['branch']->id]))->assertForbidden();
        $this->get(route('orders.show', $c['order']))->assertForbidden();
        $this->get(route('reports.index'))->assertForbidden();
        $this->get(route('captains.financials.show', $foreign['captains'][0]))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertSee(route('captain.portal'));
        $this->assertSame(0, Role::where('code', 'CAPTAIN')->sole()->permissions()->count());
    }

    public function test_read_only_work_details_and_collector_follow_real_role_changes_without_private_controls(): void
    {
        $c = $this->sharedContext();
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][1]->id, (string) str()->uuid(), 'Choose actual collector');
        $user = $this->captainUser($c, 1);
        $c['shop']->update(['shop_name_snapshot' => '<script>alert("shop")</script>', 'shop_phone_snapshot' => '0911111111']);
        $this->actingAs($user)->get(route('captain.portal.orders.show', $c['order']->id))
            ->assertOk()->assertViewHas('work', true)->assertViewHas('collector', true)
            ->assertSee($c['order']->customer_phone_snapshot)->assertSee('0911111111')
            ->assertSee('<script>alert("shop")</script>')->assertDontSee('<script>alert("shop")</script>', false)
            ->assertSee(__('captain_portal.invoice_not_issued'))->assertDontSee(route('orders.workflow', $c['order']), false)
            ->assertDontSee('evidence_path')->assertDontSee('financial_entry_id');
        $support = $this->captainUser($c, 0);
        $this->actingAs($support)->get(route('captain.portal.orders.show', $c['order']->id))->assertOk()
            ->assertViewHas('collector', false)->assertDontSee(__('captain_portal.collection'));
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->post(route('captain.portal.orders.show', $c['order']->id), ['operational_status' => 'DELIVERED'])->assertStatus(405);
    }

    public function test_removed_transfer_and_inactive_branch_hide_work_contacts_but_keep_minimal_history(): void
    {
        $c = $this->sharedContext();
        $user = $this->captainUser($c, 1);
        app(OrderCaptainService::class)->remove($c['actor'], $c['order'], $c['assignments'][1], 'End support participation');
        $this->actingAs($user)->get(route('captain.portal.orders.show', $c['order']->id))->assertOk()
            ->assertViewHas('work', false)->assertDontSee($c['order']->customer_phone_snapshot)->assertDontSee($c['shop']->shop_name_snapshot);
        $this->get(route('captain.portal', ['view' => 'history']))->assertOk()->assertSee($c['order']->order_number)->assertSee(__('captain_portal.former'));
        $this->get(route('captain.portal'))->assertOk()->assertViewHas('page', fn ($page) => $page->total() === 0);
        $collector = $this->captainUser($c);
        $branch = Branch::factory()->create();
        app(CaptainBranchService::class)->transfer($c['admin'], $c['captains'][0], ['branch_id' => $branch->id, 'starts_at' => now()->toIso8601String(), 'reason' => 'Real transfer']);
        $this->actingAs($collector)->get(route('captain.portal.orders.show', $c['order']->id))->assertOk()->assertViewHas('work', false)->assertDontSee($c['order']->customer_phone_snapshot);
        $this->get(route('captain.portal', ['view' => 'history']))->assertOk()->assertSee($c['order']->order_number);
        $c['branch']->forceFill(['is_active' => false])->save();
        $this->get(route('captain.portal.orders.show', $c['order']->id))->assertOk()->assertViewHas('contact', null);
    }

    public function test_real_collection_is_not_a_second_payment_and_final_orders_redact_work_information(): void
    {
        $c = $this->commissionOrder();
        $user = $this->captainUser($c);
        $this->actingAs($user)->get(route('captain.portal.orders.show', $c['order']->id))->assertOk()
            ->assertSee(__('captain_portal.invoice_uncollected'))->assertViewHas('invoice', fn ($invoice) => $invoice->grand_total === '90.0000');
        app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $c['invoice']->grand_total, $c['currency']->id, now());
        $this->get(route('captain.portal.orders.show', $c['order']->id))->assertOk()->assertSee(__('captain_portal.collection_recorded'));
        $this->deliverCommissionOrder($c);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $this->get(route('captain.portal.orders.show', $c['order']->id))->assertOk()->assertViewHas('work', false)
            ->assertViewHas('invoice', null)->assertDontSee($c['order']->customer_phone_snapshot);
        $this->get(route('captain.self-information'))->assertOk()->assertSee(__('financial.accounts.CAPTAIN_DEPOSIT'))
            ->assertSee(__('financial.accounts.CAPTAIN_COMPANY_RECEIVABLE'))->assertSee(__('financial.accounts.CAPTAIN_CASH_CUSTODY'));
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('invoice_collections', 1);
        $this->assertSame(array_keys(Arr::dot(require lang_path('ar/captain_portal.php'))), array_keys(Arr::dot(require lang_path('en/captain_portal.php'))));
    }

    public function test_identity_revocation_validation_and_mixed_super_roles_never_expand_self_order_scope(): void
    {
        $c = $this->sharedContext();
        $foreign = $this->sharedContext(2, 1);
        $this->actingAs($c['actor'])->get(route('captain.portal'))->assertForbidden();
        $user = $this->captainUser($c);
        $user->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $this->actingAs($user)->get(route('captain.portal.orders.show', $foreign['order']->id))->assertNotFound();
        $this->getJson(route('captain.portal', ['orders_from' => '2026-02-30', 'orders_kind' => 'injected', 'view' => 'other']))
            ->assertUnprocessable()->assertJsonValidationErrors(['orders_from', 'orders_kind', 'view']);
        $user->roles()->detach();
        $this->get(route('captain.portal'))->assertForbidden();
        $user->forceFill(['is_active' => false])->save();
        $this->get(route('captain.portal'))->assertRedirect(route('login'));
    }

    public function test_detail_read_queries_are_bounded_per_page_and_do_not_grow_per_shop_or_write_records(): void
    {
        $c = $this->sharedContext(2, 1);
        $user = $this->captainUser($c);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->actingAs($user)->get(route('captain.portal.orders.show', $c['order']->id))->assertOk();
        $singleCount = count($queries);
        for ($index = 0; $index < 7; $index++) {
            $shop = $c['shop']->replicate();
            $shop->shop_name_snapshot = 'Additional '.$index;
            $shop->save();
            $item = $c['shop']->items()->sole()->replicate();
            $item->order_shop_id = $shop->id;
            $item->save();
        }
        $queries = [];
        $this->get(route('captain.portal.orders.show', $c['order']->id))->assertOk()->assertViewHas('shops', fn ($shops) => $shops->count() === 8);
        $this->assertLessThanOrEqual($singleCount + 1, count($queries));
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|alter|truncate|create|drop)\b/i', $sql))));
        $this->get(route('captain.portal'))->assertOk()->assertViewHas('page', fn ($page) => $page->perPage() === 20);
        app()->setLocale('en');
        $this->get(route('captain.portal'))->assertOk()->assertSee('Captain portal');
    }
}
