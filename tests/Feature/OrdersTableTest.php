<?php

namespace Tests\Feature;

use App\Livewire\Orders\OrdersTable;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class OrdersTableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Branch $branch;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->seed(AuthorizationSeeder::class);
        $this->branch = Branch::factory()->create(['name' => 'الفرع الأول']);
        $this->otherBranch = Branch::factory()->create(['name' => 'الفرع الآخر']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $this->employee = User::factory()->create();
        $this->employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->employee->branches()->attach($this->branch);
    }

    private function order(string $name = 'أمير الحموي', string $phone = '0943973812', ?Branch $branch = null, ?string $date = null): Order
    {
        $branch ??= $this->branch;
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::factory()->create(['name' => $name]);
        $number = $customer->phones()->create(['phone' => $phone, 'normalized_phone' => $phone, 'is_primary' => true]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'عنوان', 'is_primary' => true]);

        return app(OrderService::class)->create($this->admin, [
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $number->id, 'customer_address_id' => $address->id,
            'source' => 'MANUAL', 'entry_mode' => $date ? 'HISTORICAL_MANUAL' : 'LIVE', 'ordered_at' => $date,
        ]);
    }

    public function test_index_renders_component_translations_and_order_links(): void
    {
        $order = $this->order();
        $this->actingAs($this->employee)->get(route('orders.index'))->assertOk()
            ->assertSeeLivewire(OrdersTable::class)->assertSee('أمير الحموي')->assertSee('جديد')
            ->assertSee('تقديري')->assertSee(route('orders.show', $order), false)
            ->assertSee('data-orders-table', false)->assertSee('wire:model.live.debounce.350ms', false)
            ->assertSee('orders-index-filters', false)->assertSee('تصفية الطلبات')->assertDontSee('pgRowTemplates');
    }

    public function test_sql_search_matches_reference_arabic_partial_name_and_phone(): void
    {
        $match = $this->order();
        $other = $this->order('محمد طاهر', '0955486435');
        $table = Livewire::actingAs($this->employee)->test(OrdersTable::class);
        foreach ([$match->order_number, 'مير', '9738'] as $term) {
            $table->set('search', $term)->assertSee($match->order_number)->assertDontSee($other->order_number);
        }
        // Customer edits cannot silently replace the authoritative Order snapshot used by search.
        $match->customer->update(['name' => 'اسم جديد']);
        $table->set('search', 'الحموي')->assertSee($match->order_number)
            ->set('search', 'اسم جديد')->assertDontSee($match->order_number);
    }

    public function test_selection_uses_powergrid_visible_page_only_without_business_mutations(): void
    {
        $first = $this->order();
        $second = $this->order('محمد طاهر');
        $hidden = $this->order('زبون سري', branch: $this->otherBranch);
        $before = Order::query()->orderBy('id')->get()->toArray();
        $selectAll = [['method' => 'selectCheckboxAll', 'params' => [], 'path' => '']];
        $table = Livewire::actingAs($this->employee)->test(OrdersTable::class)
            ->assertSee(__('orders.table.actions', ['reference' => $first->order_number]))
            ->assertSee(__('orders.table.view_order'))
            // The package's deferred model and click are sent in the same browser request.
            ->update(calls: $selectAll, updates: ['checkboxAll' => true]);
        $this->assertEqualsCanonicalizing([(string) $first->id, (string) $second->id], $table->get('checkboxValues'));
        $table->assertSet('checkboxAll', true)->set('checkboxValues', [(string) $first->id, (string) $hidden->id])
            ->assertSet('checkboxValues', [(string) $first->id])->assertSet('checkboxAll', false)
            ->set('search', 'طاهر')->assertSet('checkboxValues', [])
            ->update(calls: $selectAll, updates: ['checkboxAll' => true])->assertSet('checkboxValues', [(string) $second->id])
            ->update(calls: $selectAll, updates: ['checkboxAll' => false])->assertSet('checkboxValues', []);
        $this->assertSame($before, Order::query()->orderBy('id')->get()->toArray());
    }

    public function test_sort_popover_retains_sort_state_and_iso_date_picker_bindings(): void
    {
        $this->order();
        $date = now()->format('Y-m-d');
        $table = Livewire::actingAs($this->employee)->test(OrdersTable::class)
            ->set('from', $date)->set('to', $date)
            ->set('sortField', 'order_number')->set('sortDirection', 'asc')
            ->assertSet('from', $date)->assertSet('to', $date)
            ->assertSee(__('orders.table.sort_orders'));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$table->html());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//button[@aria-controls="orders-index-sort"]')->length);
        $this->assertSame(2, $xpath->query('//*[@id="orders-index-sort"]//select')->length);
        $models = [];
        $this->assertSame(0, $xpath->query('//*[@id="orders-index-filters"]//input[@type="date"]')->length);
        foreach ($xpath->query('//*[@id="orders-index-filters"]//input[@data-order-date-filter]') as $input) {
            $this->assertSame('ltr', $input->getAttribute('dir'));
            $this->assertSame('text', $input->getAttribute('type'));
            $this->assertSame('YYYY-MM-DD', $input->getAttribute('placeholder'));
            $models[] = $input->getAttribute('wire:model.live');
        }
        $this->assertSame(['from', 'to'], $models);
    }

    public function test_date_range_url_state_and_individual_clearing_preserve_other_filters(): void
    {
        $past = $this->order('الحموي قديم', date: now()->subDays(3)->format('Y-m-d H:i:s'));
        $today = $this->order();
        $date = now()->format('Y-m-d');
        Livewire::actingAs($this->employee)->withQueryParams([
            'from' => $date, 'to' => $date, 'search' => 'الحموي', 'branch_id' => (string) $this->branch->id,
            'sort' => 'order_number', 'direction' => 'asc',
        ])->test(OrdersTable::class)->assertSet('from', $date)->assertSet('to', $date)
            ->assertSee($today->order_number)->assertDontSee($past->order_number)
            ->set('from', '')->assertSet('from', '')->assertSet('to', $date)->assertSee($past->order_number)
            ->set('to', '')->assertSet('to', '')->assertSee($today->order_number)->assertSee($past->order_number)
            ->assertSet('search', 'الحموي')->assertSet('branchId', (string) $this->branch->id)
            ->assertSet('sortField', 'order_number')->assertSet('sortDirection', 'asc');
    }

    public function test_status_pricing_and_combined_filters_use_real_order_fields(): void
    {
        $match = $this->order();
        $other = $this->order('محمد طاهر');
        $match->forceFill(['operational_status' => 'CONFIRMED', 'pricing_status' => 'CONFIRMED'])->save();
        Livewire::actingAs($this->employee)->test(OrdersTable::class)
            ->set('status', 'CONFIRMED')->assertSee($match->order_number)->assertDontSee($other->order_number)
            ->set('pricingStatus', 'CONFIRMED')->set('search', 'الحموي')->assertSee($match->order_number)
            ->set('pricingStatus', 'ESTIMATED')->assertDontSee($match->order_number)
            ->call('resetFilters')->set('search', 'الحموي')->set('status', 'NEW')->assertDontSee($match->order_number);
    }

    public function test_search_preserves_quotes_ampersands_and_cannot_bypass_branch_scope(): void
    {
        $order = $this->order("O'Brian & Sons");
        $hidden = $this->order("O'Brian & Sons", branch: $this->otherBranch);
        Livewire::actingAs($this->employee)->test(OrdersTable::class)
            ->set('search', "o'bRIAN &")->assertSee($order->order_number)->assertDontSee($hidden->order_number)
            ->set('search', "' OR 1=1 --")->assertDontSee($order->order_number)->assertDontSee($hidden->order_number);
    }

    public function test_super_admin_branch_filter_and_employee_id_tampering_are_scoped_in_sql(): void
    {
        $local = $this->order();
        $hidden = $this->order('زبون سري', '999111222', $this->otherBranch);
        Livewire::actingAs($this->admin)->test(OrdersTable::class)
            ->assertSee($local->order_number)->assertSee($hidden->order_number)
            ->set('branchId', (string) $this->otherBranch->id)->assertSee($hidden->order_number)->assertDontSee($local->order_number);
        Livewire::actingAs($this->employee)->test(OrdersTable::class)
            ->assertSee($local->order_number)->assertDontSee($hidden->order_number)->assertDontSee('زبون سري')->assertDontSee('999111222')
            ->set('search', '999111')->assertDontSee($hidden->order_number)
            ->set('branchId', (string) $this->otherBranch->id)->assertDontSee($hidden->order_number)
            ->call('resetFilters')->assertSee($local->order_number)->assertDontSee($hidden->order_number);
        $this->actingAs($this->employee)->get(route('orders.index', ['branch_id' => $this->otherBranch->id, 'search' => 'سري']))
            ->assertOk()->assertDontSee($hidden->order_number)->assertDontSee('زبون سري');
        $this->get(route('orders.show', $hidden))->assertForbidden();
    }

    public function test_permission_is_checked_on_initial_and_subsequent_component_requests(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->get(route('orders.index'))->assertForbidden();
        Livewire::actingAs($viewer)->test(OrdersTable::class)->assertForbidden();
        $table = Livewire::actingAs($this->employee)->test(OrdersTable::class);
        $this->employee->roles()->detach();
        $table->set('search', 'أمير')->assertForbidden();
    }

    public function test_date_filters_use_inclusive_ordered_at_boundaries_and_validate_bad_input(): void
    {
        $older = $this->order('قديم', date: now()->subDays(3)->format('Y-m-d H:i:s'));
        $match = $this->order('متوسط', date: now()->subDay()->endOfDay()->format('Y-m-d H:i:s'));
        $new = $this->order('جديد');
        $date = now()->subDay()->format('Y-m-d');
        Livewire::actingAs($this->employee)->test(OrdersTable::class)->set('from', $date)->set('to', $date)
            ->assertSee($match->order_number)->assertDontSee($older->order_number)->assertDontSee($new->order_number)
            ->set('to', now()->subDays(2)->format('Y-m-d'))->assertHasErrors('to')->assertDontSee($match->order_number)
            ->call('resetFilters')->set('from', 'not-a-date')->assertHasErrors('from')
            ->call('resetFilters')->set('branchId', 'not-an-id')->assertHasErrors('branchId')
            ->call('resetFilters')->set('status', 'FORGED')->assertHasErrors('status')
            ->call('resetFilters')->set('pricingStatus', 'FORGED')->assertHasErrors('pricingStatus');
    }

    public function test_sort_allowlist_stable_default_and_hydrated_query_configuration(): void
    {
        $older = $this->order('AAA', date: now()->subDay()->format('Y-m-d H:i:s'));
        $first = $this->order('BBB');
        $newest = $this->order('CCC');
        $table = Livewire::actingAs($this->employee)->test(OrdersTable::class)
            ->assertSeeInOrder([$newest->order_number, $first->order_number, $older->order_number]);
        $table->call('sortBy', 'order_number')->assertSet('sortDirection', 'asc')->assertSeeInOrder([$older->order_number, $first->order_number, $newest->order_number])
            ->call('sortBy', 'order_number')->assertSet('sortDirection', 'desc');
        $table->call('sortBy', 'customer_name_snapshot')->assertSeeInOrder(['AAA', 'BBB', 'CCC'])
            ->call('sortBy', 'operational_status')->assertHasNoErrors();
        $table->call('sortBy', 'created_by')->assertHasErrors('sortField');
        $table->set('sortField', 'id desc; SELECT 1')->assertSet('sortField', 'ordered_at')
            ->set('sortDirection', 'INVALID')->assertSet('sortDirection', 'desc')
            ->set('multiSort', true)->set('sortArray', ['created_by' => 'asc'])->assertSet('multiSort', false)->assertSet('sortArray', [])
            ->set('columns', [['field' => 'created_by', 'searchable' => true]])->set('search', 'AAA')->assertSee($older->order_number)->assertDontSee($newest->order_number);
    }

    public function test_pagination_preserves_filters_and_all_filter_changes_reset_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->order('مجموعة البحث');
        }
        $other = $this->order('زبون آخر');
        $table = Livewire::actingAs($this->employee)->test(OrdersTable::class)->set('perPage', 10)
            ->set('search', 'مجموعة')->set('branchId', (string) $this->branch->id)->set('status', 'NEW')
            ->call('gotoPage', 2)->assertSet('paginators.page', 2)->assertSet('search', 'مجموعة')
            ->assertSet('branchId', (string) $this->branch->id)->assertSet('status', 'NEW')->assertDontSee($other->order_number)
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 2 && $orders->total() === 12);
        foreach (['search' => 'البحث', 'status' => '', 'branchId' => '', 'pricingStatus' => 'ESTIMATED', 'from' => now()->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'sortField' => 'order_number', 'sortDirection' => 'asc'] as $property => $value) {
            $table->call('gotoPage', 2)->set($property, $value)->assertSet('paginators.page', 1);
        }
        $table->call('gotoPage', 2)->call('sortBy', 'ordered_at')->assertSet('paginators.page', 1)
            ->call('gotoPage', 2)->set('perPage', 25)->assertSet('paginators.page', 1)
            ->assertViewHas('orders', fn ($orders) => $orders->perPage() === 25)
            ->set('perPage', 50)->assertViewHas('orders', fn ($orders) => $orders->perPage() === 50)
            ->set('perPage', 0)->assertSet('perPage', 25)
            ->set('perPage', 1000000)->assertSet('perPage', 25)
            ->call('gotoPage', -10)->assertSet('paginators.page', 1)
            ->call('gotoPage', 1000000)->assertSet('paginators.page', 1);
    }

    public function test_reset_filters_and_distinct_empty_states(): void
    {
        $table = Livewire::actingAs($this->employee)->test(OrdersTable::class)->assertSee(__('orders.table.no_orders'));
        $order = $this->order();
        $table->set('search', 'لا يوجد')->assertSee(__('orders.table.no_matches'))->assertDontSee($order->order_number)
            ->set('branchId', (string) $this->otherBranch->id)->set('status', 'CONFIRMED')->set('pricingStatus', 'CONFIRMED')
            ->set('from', now()->subDays(3)->format('Y-m-d'))->set('to', now()->subDay()->format('Y-m-d'))
            ->set('sortField', 'order_number')->set('sortDirection', 'asc')->call('resetFilters')
            ->assertSee($order->order_number)->assertSet('search', '')->assertSet('branchId', '')->assertSet('status', '')
            ->assertSet('pricingStatus', '')->assertSet('from', '')->assertSet('to', '')->assertSet('sortField', 'ordered_at')->assertSet('sortDirection', 'desc');
    }

    public function test_url_initializes_useful_filters_sort_page_size_and_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->order();
        }
        Livewire::actingAs($this->employee)->withQueryParams([
            'search' => 'الحموي', 'branch_id' => (string) $this->branch->id, 'operational_status' => 'NEW',
            'pricing_status' => 'ESTIMATED', 'sort' => 'order_number', 'direction' => 'asc', 'per_page' => '10', 'page' => '2',
        ])->test(OrdersTable::class)->assertSet('search', 'الحموي')->assertSet('branchId', (string) $this->branch->id)
            ->assertSet('status', 'NEW')->assertSet('pricingStatus', 'ESTIMATED')->assertSet('sortField', 'order_number')
            ->assertSet('sortDirection', 'asc')->assertSet('perPage', 10)->assertSet('paginators.page', 2)
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 12 && $orders->count() === 2);
    }

    public function test_branch_relationship_loading_does_not_grow_with_row_count(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->order(branch: $i % 2 ? $this->branch : $this->otherBranch);
        }
        $table = Livewire::actingAs($this->admin)->test(OrdersTable::class);
        $counts = [];
        DB::enableQueryLog();
        try {
            foreach ([10, 25, 50] as $size) {
                DB::flushQueryLog();
                $table->set('perPage', $size)->assertViewHas('orders', function ($orders) {
                    foreach ($orders as $order) {
                        $this->assertTrue($order->relationLoaded('branch'));
                    }

                    return true;
                });
                $counts[] = count(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'from "branches" where "branches"."id" in')));
            }
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame([1, 1, 1], $counts);
        $this->assertSame('abshir_testing', DB::selectOne('SELECT current_database() AS name')->name);
    }
}
