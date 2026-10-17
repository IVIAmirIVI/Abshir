<?php

namespace Tests\Feature;

use App\Enums\OrderCancellationReason;
use App\Livewire\Dashboard\OperationalDashboard;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalDashboardQuery;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopService;
use Carbon\Carbon;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OperationalDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Branch $branch;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        // Pin fixture input instants; individual tests deliberately change the session timezone.
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        $this->seed(AuthorizationSeeder::class);
        $this->branch = Branch::factory()->create(['name' => 'فرع متاح']);
        $this->otherBranch = Branch::factory()->create(['name' => 'فرع محجوب']);
        $this->admin = $this->account('SUPER_ADMIN');
        $this->employee = $this->account('ORDER_EMPLOYEE');
        $this->employee->branches()->attach($this->branch);
    }

    private function account(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $role)->sole());

        return $user;
    }

    private function order(?Branch $branch = null, ?string $date = null): Order
    {
        $branch ??= $this->branch;
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::factory()->create();
        $phone = $customer->phones()->create(['phone' => '0991112223', 'normalized_phone' => '0991112223']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'عنوان']);

        return app(OrderService::class)->create($this->admin, [
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id,
            'source' => 'MANUAL', 'entry_mode' => $date ? 'HISTORICAL_MANUAL' : 'LIVE', 'ordered_at' => $date,
        ]);
    }

    private function snapshot(?User $actor = null, string $period = 'today', string $branch = ''): array
    {
        return app(OperationalDashboardQuery::class)->snapshot($actor ?? $this->employee, $period, $branch);
    }

    private function outcome(Order $order, string $status, string $time): void
    {
        // Historical outcome fixture only: dashboard must read, never recreate, these facts.
        $order->forceFill(['operational_status' => $status])->save();
        $order->statusHistory()->create(['previous_status' => 'NEW', 'new_status' => $status,
            'changed_at' => $time, 'changed_by' => $this->admin->id]);
    }

    public function test_record_creation_and_business_order_dates_have_distinct_meanings(): void
    {
        $historical = $this->order(date: '2026-09-01 10:00:00+00:00');
        $today = $this->order();
        $oldRecord = $this->order(date: '2026-10-07 20:59:59+00:00');
        DB::table('orders')->where('id', $oldRecord->id)->update(['created_at' => '2026-10-07 20:59:59']);
        $result = $this->snapshot();
        $this->assertSame(2, $result['metrics']['created_today']);
        $this->assertSame(3, $result['metrics']['open']);
        $this->assertSame([['date' => '2026-10-08', 'orders' => 1, 'delivered' => 0, 'cancelled' => 0]], $result['trend']);
        $this->assertSame(3, array_sum(array_column($result['distribution'], 'count')));
        $this->assertSame([$historical->id, $oldRecord->id, $today->id], $result['followUp']->modelKeys());
    }

    public function test_damascus_day_boundaries_are_half_open_and_session_timezone_independent(): void
    {
        foreach (['2026-10-07 20:59:59+00:00', '2026-10-07 21:00:00+00:00', '2026-10-08 20:59:59+00:00', '2026-10-08 21:00:00+00:00'] as $time) {
            $order = $this->order();
            DB::table('orders')->where('id', $order->id)->update(['ordered_at' => $time, 'created_at' => substr($time, 0, 19)]);
        }
        $utc = $this->snapshot();
        $this->assertSame(2, $utc['metrics']['created_today']);
        $this->assertSame(2, $utc['trend'][0]['orders']);
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $shifted = $this->snapshot();
        $this->assertSame($utc['metrics'], $shifted['metrics']);
        $this->assertSame($utc['trend'], $shifted['trend']);
    }

    public function test_final_outcomes_use_history_time_not_order_date_and_never_multiply_orders(): void
    {
        $delivered = $this->order(date: '2026-09-01 10:00:00+00:00');
        $this->outcome($delivered, 'DELIVERED', '2026-10-07 21:00:00+00:00');
        $delivered->statusHistory()->create(['new_status' => 'DELIVERED', 'changed_at' => '2026-10-08 10:00:00+00:00']);
        $cancelled = $this->order(date: '2026-09-01 10:00:00+00:00');
        $this->outcome($cancelled, 'CANCELLED', '2026-10-08 20:59:59+00:00');
        $this->outcome($this->order(), 'DELIVERED', '2026-10-08 21:00:00+00:00');
        $this->outcome($this->order(), 'CANCELLED', '2026-10-07 20:59:59+00:00');
        $this->outcome($this->order($this->otherBranch), 'DELIVERED', '2026-10-08 10:00:00+00:00');
        $result = $this->snapshot();
        $this->assertSame(1, $result['metrics']['delivered']);
        $this->assertSame(1, $result['metrics']['cancelled']);
        $this->assertSame(1, $result['trend'][0]['delivered']);
        $this->assertSame(1, $result['trend'][0]['cancelled']);
        $this->assertSame(0, $result['metrics']['open']);
        $this->assertSame(0, $result['metrics']['attention']);
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $this->assertSame($result['metrics'], $this->snapshot()['metrics']);
    }

    public function test_periods_are_zero_filled_and_current_counts_remain_all_time(): void
    {
        $this->order(date: '2026-10-01 21:00:00+00:00');
        $this->order(date: '2026-10-01 20:59:59+00:00');
        $this->order(date: '2026-09-09 21:00:00+00:00');
        $week = $this->snapshot(period: '7days');
        $this->assertCount(7, $week['trend']);
        $this->assertSame('2026-10-02', $week['from']);
        $this->assertSame(1, array_sum(array_column($week['trend'], 'orders')));
        $month = $this->snapshot(period: '30days');
        $this->assertCount(30, $month['trend']);
        $this->assertSame('2026-09-09', $month['from']);
        $this->assertSame(3, array_sum(array_column($month['trend'], 'orders')));
        $this->assertSame($week['metrics']['open'], $month['metrics']['open']);
        $this->assertSame($week['distribution'], $month['distribution']);
    }

    public function test_follow_up_is_bounded_stable_and_awaiting_captain_uses_active_primary(): void
    {
        $confirmed = $this->order();
        $confirmed->forceFill(['operational_status' => 'CONFIRMED'])->save();
        $captain = Captain::factory()->create(['home_branch_id' => $this->branch->id]);
        $assignment = $confirmed->captainAssignments()->create(['captain_id' => $captain->id,
            'assignment_role' => 'PRIMARY_COLLECTION', 'assigned_at' => now(), 'assigned_by' => $this->admin->id]);
        $this->assertSame(0, $this->snapshot()['metrics']['awaiting_captain']);
        $assignment->update(['removed_at' => now(), 'removed_by' => $this->admin->id, 'removal_reason' => 'Fixture removal']);
        $result = $this->snapshot();
        $this->assertSame(1, $result['metrics']['awaiting_captain']);
        $this->assertSame('assign', $result['followUp']->sole()->attention_action);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->snapshot();
        $singleOrderQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 12; $i++) {
            $this->order();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $this->snapshot();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(13, $result['metrics']['attention']);
        $this->assertCount(10, $result['followUp']);
        $this->assertSame($confirmed->id, $result['followUp']->first()->id);
        $this->assertSame($singleOrderQueryCount, count($queries));
        $this->assertSame('confirm', $result['followUp']->last()->attention_action);
        $this->assertTrue($result['followUp']->every(fn ($order) => $order->relationLoaded('branch')));
    }

    public function test_employee_scope_excludes_unassigned_and_inactive_branches_and_admin_can_filter(): void
    {
        $local = $this->order();
        $hidden = $this->order($this->otherBranch);
        $inactive = Branch::factory()->create();
        $this->employee->branches()->attach($inactive);
        $this->order($inactive);
        $inactive->forceFill(['is_active' => false])->save();
        $this->assertSame(1, $this->snapshot()['metrics']['open']);
        $this->assertSame([$local->id], $this->snapshot()['followUp']->modelKeys());
        $this->assertSame(3, $this->snapshot($this->admin)['metrics']['open']);
        $this->assertSame([$hidden->id], $this->snapshot($this->admin, branch: (string) $this->otherBranch->id)['followUp']->modelKeys());
        Livewire::actingAs($this->employee)->test(OperationalDashboard::class)
            ->assertSee($local->order_number)->assertDontSee($hidden->order_number)->assertDontSee('فرع محجوب')
            ->set('branchId', (string) $this->otherBranch->id)->assertForbidden();
        Livewire::actingAs($this->employee)->test(OperationalDashboard::class)
            ->set('branchId', (string) $inactive->id)->assertForbidden();
        Livewire::actingAs($this->admin)->test(OperationalDashboard::class)
            ->set('branchId', '9999999999999999999999999999999')->assertForbidden();
    }

    public function test_missing_receipt_attention_comes_from_purchase_facts_and_stops_after_evidence_or_cancellation_intent(): void
    {
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $order = $this->order();
        $shop = app(OrderShopService::class)->add($this->admin, $order, ['shop_type' => 'ONE_TIME',
            'shop_name' => 'محل اختبار', 'business_classification' => 'SHOP', 'origin_area_id' => $order->destination_area_id]);
        $currency = Currency::create(['code' => 'U2', 'name' => 'اختبار', 'decimal_places' => 2]);
        $captain = Captain::factory()->create(['home_branch_id' => $this->branch->id]);
        $assignment = $order->captainAssignments()->create(['captain_id' => $captain->id, 'assignment_role' => 'PRIMARY_COLLECTION',
            'assigned_at' => now(), 'assigned_by' => $this->admin->id]);
        $order->forceFill(['operational_status' => 'SHOPPING'])->save();
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->sync(Permission::whereIn('code', ['orders.view', 'order_shop_receipts.upload'])->pluck('id'));
        $this->assertSame(0, $this->snapshot()['metrics']['attention']);
        $file = fn () => UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        app(OrderShopPurchaseConfirmationService::class)->record($this->admin, $order, $shop, [
            'order_captain_id' => $assignment->id, 'currency_id' => $currency->id, 'amount' => '20',
            'purchased_at' => '2026-10-07 10:00:00+00:00', 'request_key' => (string) Str::uuid(),
        ], $file());
        $this->assertSame('receipt', $this->snapshot()['followUp']->sole()->attention_action);
        app(OrderShopReceiptService::class)->upload($this->employee, $order, $shop, $file(), null);
        $this->assertSame(0, $this->snapshot()['metrics']['attention']);
        app(OrderCancellationIntentService::class)->create($this->admin, $order,
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $this->assertSame(1, $this->snapshot()['metrics']['open']);
        $this->assertCount(0, $this->snapshot()['followUp']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_roles_and_revoked_permissions_cannot_access_administrative_analytics(): void
    {
        $captain = $this->account('CAPTAIN');
        $this->actingAs($captain)->get('/dashboard')->assertOk()->assertDontSeeLivewire(OperationalDashboard::class);
        Livewire::actingAs($captain)->test(OperationalDashboard::class)->assertForbidden();
        $this->employee->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        Livewire::actingAs($this->employee)->test(OperationalDashboard::class)->assertForbidden();
        $this->employee->roles()->detach(Role::where('code', 'CAPTAIN')->sole());
        $component = Livewire::actingAs($this->employee)->test(OperationalDashboard::class);
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->detach(Permission::where('code', 'orders.view')->sole());
        $component->call('$refresh')->assertForbidden();
        $inactive = $this->account('ORDER_EMPLOYEE');
        $inactive->update(['is_active' => false]);
        $this->assertFalse(app(OperationalDashboardQuery::class)->canView($inactive));
    }

    public function test_follow_up_respects_action_permissions_without_granting_new_permissions(): void
    {
        $this->order();
        $confirmed = $this->order();
        $confirmed->forceFill(['operational_status' => 'CONFIRMED'])->save();
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->sync(Permission::where('code', 'orders.view')->pluck('id'));
        $result = $this->snapshot();
        $this->assertSame(2, $result['metrics']['open']);
        $this->assertSame(0, $result['metrics']['attention']);
        $this->assertArrayNotHasKey('awaiting_captain', $result['metrics']);
        $this->assertCount(0, $result['followUp']);
        $this->assertDatabaseMissing('roles', ['code' => 'SUPERVISOR']);
    }

    public function test_filter_rendering_and_refresh_are_read_only_and_keep_accessible_tables_and_real_links(): void
    {
        $order = $this->order();
        $before = $order->fresh()->getRawOriginal();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $component = Livewire::actingAs($this->employee)->withQueryParams(['period' => 'today', 'branch_id' => (string) $this->branch->id])
            ->test(OperationalDashboard::class)->assertSet('period', 'today')->assertSet('branchId', (string) $this->branch->id)
            ->assertSee('data-operational-dashboard', false)->assertSee(__('dashboard.timezone'))
            ->assertSee(route('orders.workflow', $order), false)
            ->assertSee(e(route('orders.index', ['branch_id' => $this->branch->id, 'operational_status' => 'NEW'])), false)
            ->set('period', '30days')->call('$refresh')->assertSet('branchId', (string) $this->branch->id);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(3, $xpath->query('//table')->length);
        $this->assertSame(2, $xpath->query('//canvas[@role="img"]')->length);
        $this->assertSame([], $writes);
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('invoice_collections', 0);
    }

    public function test_invalid_period_fails_closed_and_empty_data_is_not_fabricated(): void
    {
        Livewire::actingAs($this->employee)->test(OperationalDashboard::class)
            ->assertSee(__('dashboard.no_chart_data'))->assertSee(__('dashboard.no_attention'))
            ->set('period', 'all')->assertHasErrors('period')->assertDontSee('dashboard-kpi-open', false)
            ->set('period', '7days')->assertHasNoErrors()->assertSee('dashboard-kpi-open', false);
        $this->assertSame(0, array_sum(array_column($this->snapshot()['distribution'], 'count')));
    }

    public function test_arabic_english_key_parity_and_dashboard_shell_render(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Arr::dot(require lang_path('ar/dashboard.php'))), array_keys(Arr::dot(require lang_path('en/dashboard.php'))));
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSeeLivewire(OperationalDashboard::class)
            ->assertSee('dir="rtl"', false)->assertSee(__('dashboard.title'));
        app()->setLocale('en');
        $this->get('/dashboard')->assertOk()->assertSee('dir="ltr"', false)->assertSee(__('dashboard.title'));
    }
}
