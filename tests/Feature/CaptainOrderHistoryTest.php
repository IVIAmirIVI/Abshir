<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCaptainRole;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Services\CaptainOrderHistoryQuery;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainSelectionService;
use App\Services\SharedOrderShopPurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class CaptainOrderHistoryTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_shared_support_and_collector_orders_are_complete_and_not_duplicated_after_role_changes(): void
    {
        $c = $this->sharedContext();
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'),
            $c['captains'][1]->id, (string) str()->uuid(), 'Choose another collector');

        $response = $this->actingAs($c['actor'])->get(route('captains.information', $c['captains'][0]));
        $response->assertOk()->assertViewHas('orderHistory', fn ($history) => $history['orders']->total() === 1)
            ->assertSee(route('orders.show', $c['order']), false)->assertSee(__('captain_dashboard.orders_collector'))
            ->assertSee(__('captain_dashboard.orders_support'))->assertSee($c['order']->customer->name);
        $this->assertSame(1, substr_count($response->getContent(), 'data-captain-order="'.$c['order']->id.'"'));
        $history = app(CaptainOrderHistoryQuery::class)->listing($c['actor'], $c['captains'][1]);
        $this->assertSame([$c['order']->id], $history['orders']->getCollection()->modelKeys());
        $this->assertSame('5.0000', $history['fees']->get($c['order']->id)->amount);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_branch_scope_blocks_foreign_order_rows_and_direct_branch_tampering(): void
    {
        $c = $this->sharedContext();
        $other = Branch::factory()->create();
        $hidden = $this->historicalOrder($c['captains'][0], $other, '2026-10-01T10:00:00Z');

        $this->actingAs($c['actor'])->get(route('captains.information', $c['captains'][0]))
            ->assertOk()->assertDontSee($hidden->order_number)->assertViewHas('orderHistory', fn ($history) => $history['orders']->total() === 1);
        $this->get(route('captains.information', ['captain' => $c['captains'][0], 'branch_id' => $other->id]))->assertForbidden();
        $this->actingAs($c['admin'])->get(route('captains.information', $c['captains'][0]))
            ->assertOk()->assertSee($hidden->order_number)->assertViewHas('orderHistory', fn ($history) => $history['orders']->total() === 2);
    }

    public function test_self_history_keeps_own_historical_branches_without_customer_or_ungranted_order_links(): void
    {
        $c = $this->sharedContext();
        $own = $c['captains'][1];
        $own->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $otherBranch = Branch::factory()->create();
        $old = $this->historicalOrder($own, $otherBranch, '2026-09-01T10:00:00Z');
        $someoneElse = $this->historicalOrder($c['captains'][0], $c['branch'], '2026-09-02T10:00:00Z');

        $response = $this->actingAs($own->user)->get(route('captain.self-information', ['captain_id' => $c['captains'][0]->id]));
        $response->assertOk()->assertSee($old->order_number)->assertSee($c['order']->order_number)->assertDontSee($someoneElse->order_number)
            ->assertDontSee($c['order']->customer->name)->assertDontSee(route('orders.show', $c['order']), false)
            ->assertViewHas('orderHistory', fn ($history) => $history['orders']->total() === 2);
        $this->assertThrows(fn () => app(CaptainOrderHistoryQuery::class)->listing($own->user, $c['captains'][0], [], true), HttpException::class);
        $this->get(route('captains.information', $c['captains'][0]))->assertForbidden();
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_history_disclosure_respects_customer_and_financial_permissions(): void
    {
        $c = $this->sharedContext();
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->detach(Permission::whereIn('code', ['captain_financials.view', 'customers.view'])->pluck('id'));
        $response = $this->actingAs($c['actor'])->get(route('captains.information', $c['captains'][0]));
        $response->assertOk()->assertSee($c['order']->order_number)->assertDontSee($c['order']->customer->name)
            ->assertDontSee(__('captain_dashboard.orders_fee'))
            ->assertViewHas('orderHistory', fn ($history) => ! $history['financial'] && $history['fees']->isEmpty());
        $order = $response->viewData('orderHistory')['orders']->sole();
        $this->assertFalse($order->relationLoaded('customer'));
        $this->assertSame(null, $order->history_customer_name);
    }

    public function test_date_range_uses_damascus_exclusive_end_and_history_is_independent_of_dashboard_period(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $c = $this->sharedContext();
        $included = $this->historicalOrder($c['captains'][0], $c['branch'], '2026-10-10T21:00:00Z');
        $excludedBefore = $this->historicalOrder($c['captains'][0], $c['branch'], '2026-10-10T20:59:59Z');
        $excludedAfter = $this->historicalOrder($c['captains'][0], $c['branch'], '2026-10-11T21:00:00Z');
        $this->assertSame('2026-10-10T21:00:00+00:00', $included->fresh()->ordered_at->utc()->toIso8601String());
        DB::statement("SET LOCAL TIME ZONE 'Asia/Damascus'");

        $this->actingAs($c['actor'])->get(route('captains.information', ['captain' => $c['captains'][0],
            'period' => 'today', 'orders_from' => '2026-10-11', 'orders_to' => '2026-10-11']))
            ->assertOk()->assertSee($included->order_number)->assertDontSee($excludedBefore->order_number)->assertDontSee($excludedAfter->order_number)
            ->assertViewHas('orderHistory', fn ($history) => $history['orders']->total() === 1);
        $this->get(route('captains.information', ['captain' => $c['captains'][0], 'orders_to' => '2026-10-11']))->assertOk();
    }

    public function test_shared_role_status_and_former_filters_use_actual_participation(): void
    {
        $c = $this->sharedContext();
        $solo = $this->historicalOrder($c['captains'][1], $c['branch'], '2026-09-01T10:00:00Z');
        $support = app(CaptainOrderHistoryQuery::class)->listing($c['actor'], $c['captains'][1], ['orders_kind' => 'shared', 'orders_role' => 'support', 'orders_status' => $c['order']->operational_status->value]);
        $this->assertSame([$c['order']->id], $support['orders']->getCollection()->modelKeys());
        $single = app(CaptainOrderHistoryQuery::class)->listing($c['actor'], $c['captains'][1], ['orders_kind' => 'single', 'orders_role' => 'collector']);
        $this->assertSame([$solo->id], $single['orders']->getCollection()->modelKeys());
        $solo->captainAssignments()->sole()->update(['removed_at' => '2026-09-01T11:00:00Z', 'removed_by' => $c['actor']->id, 'removal_reason' => 'Old assignment ended']);
        $solo->captainAssignments()->create(['captain_id' => $c['captains'][0]->id, 'assignment_role' => 'PRIMARY_COLLECTION', 'assigned_at' => '2026-09-01T11:00:00Z', 'assigned_by' => $c['actor']->id]);
        $former = app(CaptainOrderHistoryQuery::class)->listing($c['actor'], $c['captains'][1], ['orders_role' => 'former', 'orders_kind' => 'single']);
        $this->assertSame([$solo->id], $former['orders']->getCollection()->modelKeys());
    }

    public function test_history_pagination_is_bounded_deterministic_and_preserves_filters(): void
    {
        $c = $this->sharedContext();
        for ($i = 0; $i < 21; $i++) {
            $this->historicalOrder($c['captains'][0], $c['branch'], '2026-09-01T10:00:00Z');
        }
        $response = $this->actingAs($c['actor'])->get(route('captains.information', ['captain' => $c['captains'][0], 'orders_kind' => 'single', 'orders_role' => 'collector', 'orders_page' => 2]));
        $page = $response->viewData('orderHistory')['orders'];
        $response->assertOk();
        $this->assertSame(21, $page->total());
        $this->assertSame(1, $page->count());
        $this->assertStringContainsString('orders_kind=single', $page->url(1));
        $this->assertStringContainsString('orders_role=collector', $page->url(1));
    }

    public function test_history_filters_reject_invalid_values_without_database_writes(): void
    {
        $c = $this->sharedContext();
        $this->actingAs($c['actor'])->get(route('captains.information', ['captain' => $c['captains'][0], 'orders_from' => '2026-02-30', 'orders_status' => 'BOGUS', 'orders_role' => 'sql', 'orders_kind' => 'other']))
            ->assertSessionHasErrors(['orders_from', 'orders_status', 'orders_role', 'orders_kind']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_actual_shared_collection_method_is_shown_without_creating_financial_writes(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($c['actor'], $c['order'], $invoices->refresh($c['actor'], $c['order'], $invoices->createDraft($c['actor'], $c['order'])));
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($c['actor'])->get(route('captains.information', $c['captains'][1]))
            ->assertOk()->assertSee(__('financial.collection_methods.CASH'))
            ->assertViewHas('orderHistory', fn ($history) => $history['methods']->get($c['order']->id) === 'CASH');
        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
    }

    /** Historical read fixture follows the existing Captain dashboard tests; no financial sources are invented. */
    private function historicalOrder(Captain $captain, Branch $branch, string $at): Order
    {
        $customer = Customer::factory()->create();
        $order = Order::create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'order_number' => 'HISTORY-'.str()->uuid(),
            'ordered_at' => $at, 'source' => 'MANUAL', 'entry_mode' => 'LIVE', 'customer_name_snapshot' => $customer->name,
            'customer_phone_snapshot' => '0912345678', 'area_name_snapshot' => 'Area', 'area_path_snapshot' => 'Area', 'address_line_1_snapshot' => 'Street']);
        $order->captainAssignments()->create(['captain_id' => $captain->id, 'assignment_role' => OrderCaptainRole::PrimaryCollection,
            'assigned_at' => $at, 'assigned_by' => $captain->user_id]);

        return $order;
    }
}
