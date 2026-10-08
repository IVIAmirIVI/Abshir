<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\OrderCaptainRole;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainInformationQuery;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialAccountService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use Carbon\Carbon;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CaptainInformationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private Branch $branch;

    private Branch $otherBranch;

    private Captain $captain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        $this->seed(AuthorizationSeeder::class);
        $this->branch = Branch::factory()->create(['name' => 'Accessible branch']);
        $this->otherBranch = Branch::factory()->create(['name' => 'Hidden branch']);
        $this->admin = $this->account('SUPER_ADMIN');
        $this->employee = $this->account('ORDER_EMPLOYEE');
        $this->employee->branches()->attach($this->branch);
        $this->captain = Captain::factory()->create(['home_branch_id' => $this->branch->id, 'operational_status' => 'AVAILABLE']);
    }

    public function test_captain_admin_access_stays_closed_while_exact_self_identity_can_read(): void
    {
        $user = $this->account('CAPTAIN');
        $url = route('captains.information', $this->captain);
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->get(route('captain.self-information'))->assertForbidden();
        $own = Captain::factory()->create(['user_id' => $user->id, 'home_branch_id' => $this->branch->id]);
        $this->assertSame($own->id, $user->captain->id);
        $this->actingAs($user)->get(route('captains.information', $own))->assertForbidden();
        $this->get($url.'?captain_id='.$own->id)->assertForbidden();
        $this->get(route('captain.self-information'))->assertOk()->assertViewHas('captain', fn ($record) => $record->id === $own->id);
        // Administrative grants cannot accidentally turn the fallback into branch-wide Captain access.
        $user->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $user->branches()->attach($this->branch);
        $this->get($url)->assertForbidden();
        $this->get(route('captains.information', $own))->assertForbidden();
        $this->get(route('captain.self-information').'?captain_id='.$this->captain->id)
            ->assertOk()->assertViewHas('captain', fn ($record) => $record->id === $own->id);
    }

    public function test_guest_inactive_and_branch_or_identifier_tampering_fail_closed(): void
    {
        $url = route('captains.information', $this->captain);
        $this->get($url)->assertRedirect(route('login'));
        $hidden = Captain::factory()->create(['home_branch_id' => $this->otherBranch->id]);
        $this->actingAs($this->employee)->get(route('captains.information', $hidden))->assertForbidden();
        $this->get($url.'?branch_id='.$this->otherBranch->id)->assertForbidden();
        $this->get($url.'?branch_id[]=1')->assertSessionHasErrors('branch_id');
        $this->get($url.'?period=all')->assertSessionHasErrors('period');
        $this->employee->forceFill(['is_active' => false])->save();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_balances_are_exact_current_separate_scoped_and_include_reversals(): void
    {
        $usd = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 4]);
        $syp = Currency::create(['code' => 'SYP', 'name' => 'Lira', 'decimal_places' => 0]);
        $this->movement($usd, $this->branch, FinancialAccountKind::CaptainDeposit, '10.1234', true);
        $this->movement($usd, $this->branch, FinancialAccountKind::CaptainDeposit, '12.0000', false);
        $this->movement($usd, $this->branch, FinancialAccountKind::CaptainCompanyReceivable, '7.2500', true);
        $this->movement($usd, $this->branch, FinancialAccountKind::CaptainCashCustody, '800.0000', true);
        $event = $this->movement($usd, $this->branch, FinancialAccountKind::CaptainDeposit, '5.0000', true);
        app(FinancialPostingService::class)->reverse($event, 'U5_REVERSAL', $event->id, 'U5_REVERSE', 1, now(), 'Fixture reversal');
        $this->movement($usd, $this->otherBranch, FinancialAccountKind::CaptainDeposit, '999.0000', true);
        $this->movement($syp, $this->branch, FinancialAccountKind::CaptainDeposit, '2000', true);
        foreach (['today', '7days', '30days'] as $period) {
            $snapshot = $this->snapshot($period);
            $this->assertCount(2, $snapshot['rows']);
            $usdRow = collect($snapshot['rows'])->firstWhere('currency.code', 'USD');
            $this->assertSame(['CAPTAIN_DEPOSIT' => '-1.8766', 'CAPTAIN_COMPANY_RECEIVABLE' => '7.2500', 'CAPTAIN_CASH_CUSTODY' => '800.0000'], $usdRow['values']);
            $this->assertSame('2000.0000', collect($snapshot['rows'])->firstWhere('currency.code', 'SYP')['values']['CAPTAIN_DEPOSIT']);
            $this->assertFalse($snapshot['movements']->contains('branch_name', 'Hidden branch'));
            $this->assertCount(0, $snapshot['earnings']);
        }
        $global = app(CaptainInformationQuery::class)->snapshot($this->admin, $this->captain);
        $this->assertCount(2, $global['balanceRows']);
        $this->assertSame('997.1234', collect($global['balanceRows'])->firstWhere('currency.code', 'USD')['values']['CAPTAIN_DEPOSIT']);
        $this->assertSame([], $global['rows']);
        $this->assertSame([], $this->snapshot()['balanceRows']);
        $this->actingAs($this->employee)->get(route('captains.information', $this->captain))
            ->assertOk()->assertSee(__('captain_dashboard.branch_activity'))->assertSee(__('captain_dashboard.global_unavailable'))
            ->assertDontSee(__('captain_dashboard.balances'))->assertDontSee('997.1234')->assertDontSee('Hidden branch');
    }

    public function test_other_captain_entries_do_not_leak_and_movement_history_is_bounded(): void
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        for ($i = 0; $i < 28; $i++) {
            $this->movement($currency, $this->branch, FinancialAccountKind::CaptainDeposit, '1', true);
        }
        $other = Captain::factory()->create(['home_branch_id' => $this->branch->id]);
        $this->movement($currency, $this->branch, FinancialAccountKind::CaptainDeposit, '900', true, $other);
        $snapshot = $this->snapshot();
        $this->assertSame('28.0000', $snapshot['rows'][0]['values']['CAPTAIN_DEPOSIT']);
        $this->assertCount(25, $snapshot['movements']);
        $this->assertFalse($snapshot['movements']->contains('amount', '900.0000'));
    }

    public function test_permission_sections_are_independent_and_no_financial_write_occurs(): void
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->actingAs($this->employee)->get(route('captains.information', $this->captain))
            ->assertOk()->assertSee('data-captain-information', false)->assertSee(__('captain_dashboard.incentives_unavailable'))
            ->assertSee(__('captain_dashboard.deductions_unavailable'))->assertSee('dir="rtl"', false);
        $this->assertSame([], $writes);
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->sync(Permission::where('code', 'captains.view')->pluck('id'));
        $response = $this->get(route('captains.information', $this->captain));
        $response->assertOk()->assertSee(__('captain_dashboard.financial_denied'))->assertSee(__('captain_dashboard.performance_denied'));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//*[@data-captain-information]//form[translate(@method,"POST","post")="post"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-captain-information]//*[@*[name()="wire:click"]]')->length);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_participation_uses_outcome_instant_and_damascus_exclusive_boundaries(): void
    {
        // Damascus midnight is 21:00 UTC on the previous date.
        $this->outcome('DELIVERED', '2026-10-07T21:00:00Z');
        $this->outcome('DELIVERED', '2026-10-07T20:59:59Z');
        $this->outcome('DELIVERED', '2026-10-08T21:00:00Z');
        $this->outcome('CANCELLED', '2026-10-08T09:00:00Z');
        $this->outcome('DELIVERED', '2026-10-08T10:00:00Z', '2026-10-08T09:59:59Z');
        $this->outcome('DELIVERED', '2026-10-08T10:00:00Z', null, $this->otherBranch);
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $result = $this->snapshot('today')['activity'];
        $this->assertSame(['assigned' => 0, 'delivered' => 1, 'cancelled' => 1], $result['metrics']);
        $this->assertSame('2026-10-08', $result['trend'][0]['date']);
        $this->assertSame(2, $this->snapshot('7days')['activity']['metrics']['delivered']);
        $this->assertCount(30, $this->snapshot('30days')['activity']['trend']);
        $this->captain->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $own = app(CaptainInformationQuery::class)->selfSnapshot($this->captain->user, 'today');
        $this->assertSame(['assigned' => 0, 'delivered' => 2, 'cancelled' => 1], $own['activity']['metrics']);
    }

    public function test_assignment_metric_deduplicates_reassignments_and_keeps_ended_history(): void
    {
        $order = $this->order($this->branch);
        $order->captainAssignments()->create(['captain_id' => $this->captain->id, 'assignment_role' => 'PRIMARY_COLLECTION',
            'assigned_at' => now()->subHour(), 'removed_at' => now()->subMinutes(30), 'assigned_by' => $this->admin->id, 'removed_by' => $this->admin->id, 'removal_reason' => 'Reassignment']);
        $order->captainAssignments()->create(['captain_id' => $this->captain->id, 'assignment_role' => 'PRIMARY_COLLECTION', 'assigned_at' => now(), 'assigned_by' => $this->admin->id]);
        $this->assertSame(1, $this->snapshot('today')['activity']['metrics']['assigned']);
    }

    public function test_real_cash_and_sham_sources_use_entitlement_not_invoice_or_reimbursement(): void
    {
        foreach ([InvoiceCollectionMethod::Cash, InvoiceCollectionMethod::ShamCash] as $method) {
            $invoice = $this->recognizedInvoice($method);
            $this->assertSame('30.0000', $invoice->grand_total);
        }
        $snapshot = $this->snapshot();
        $this->assertCount(1, $snapshot['earnings']);
        // 65% of 5 per Order; customer invoices total 60 and products total 50.
        $this->assertSame('6.5000', $snapshot['earnings'][0]->amount);
        $this->captain->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->assertSame('6.5000', app(CaptainInformationQuery::class)->selfSnapshot($this->captain->user)['earnings'][0]->amount);
    }

    public function test_issued_uncollected_source_is_excluded_and_completed_correction_is_not_double_counted(): void
    {
        $invoice = $this->recognizedInvoice(InvoiceCollectionMethod::ShamCash);
        $this->assertSame('3.2500', $this->snapshot()['earnings'][0]->amount);
        $service = app(InvoiceFinancialCorrectionService::class);
        $correction = $service->request($this->admin, $invoice, InvoiceFinancialCorrectionKind::Reprice, [
            'items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '25.00']],
            'delivery' => [['order_captain_id' => $invoice->order->captainAssignments()->sole()->id, 'customer_amount' => '5.00']],
        ], 'Verify corrected basis', 'Same financial entitlement', (string) Str::uuid());
        $correction = $service->prepare($this->admin, $correction);
        $service->finalize($this->admin, $correction);
        $this->assertSame('3.2500', $this->snapshot()['earnings'][0]->amount);
        $this->assertNotNull($invoice->fresh()->superseded_at);
        $this->captain->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->assertSame('3.2500', app(CaptainInformationQuery::class)->selfSnapshot($this->captain->user)['earnings'][0]->amount);
    }

    public function test_translation_parity_english_and_profile_navigation(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Arr::dot(require lang_path('ar/captain_dashboard.php'))), array_keys(Arr::dot(require lang_path('en/captain_dashboard.php'))));
        $this->actingAs($this->admin)->get(route('captains.show', $this->captain))->assertOk()->assertSee(route('captains.information', $this->captain), false);
        app()->setLocale('en');
        $this->get(route('captains.information', $this->captain))->assertOk()->assertSee(__('captain_dashboard.title'))->assertSee('dir="ltr"', false);
    }

    private function account(string $code): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $code)->sole());

        return $user;
    }

    private function snapshot(string $period = '7days'): array
    {
        return app(CaptainInformationQuery::class)->snapshot($this->employee, $this->captain, $period);
    }

    private function movement(Currency $currency, Branch $branch, FinancialAccountKind $kind, string $amount, bool $increase, ?Captain $captain = null)
    {
        $accounts = app(FinancialAccountService::class);
        $account = $accounts->getOrCreate($accounts->ownerForCaptain($captain ?? $this->captain), $currency, $kind);

        return app(FinancialPostingService::class)->postEvent($currency, 'U5_FIXTURE', (string) Str::uuid(), 'U5_MOVEMENT', 1, now()->subMonths(2), [
            ['account' => $account, 'direction' => $increase ? FinancialEntryDirection::Increase : FinancialEntryDirection::Decrease, 'amount' => $amount],
        ], $branch);
    }

    private function order(Branch $branch): Order
    {
        $customer = Customer::factory()->create();

        return Order::create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'order_number' => 'U5-'.Str::uuid(),
            'ordered_at' => now()->subDays(10), 'source' => 'MANUAL', 'entry_mode' => 'LIVE', 'customer_name_snapshot' => $customer->name,
            'customer_phone_snapshot' => '000', 'area_name_snapshot' => 'Area', 'area_path_snapshot' => 'Area', 'address_line_1_snapshot' => 'Street']);
    }

    private function outcome(string $status, string $at, ?string $removed = null, ?Branch $branch = null): void
    {
        $order = $this->order($branch ?? $this->branch);
        $order->captainAssignments()->create(['captain_id' => $this->captain->id, 'assignment_role' => 'PRIMARY_COLLECTION',
            'assigned_at' => '2026-10-01T09:00:00Z', 'assigned_by' => $this->admin->id,
            'removed_at' => $removed, 'removed_by' => $removed ? $this->admin->id : null, 'removal_reason' => $removed ? 'Replaced' : null]);
        // Fixture-only final history; dashboard never creates or changes it.
        $order->forceFill(['operational_status' => $status])->save();
        $order->statusHistory()->create(['previous_status' => 'HEADING_TO_CUSTOMER', 'new_status' => $status, 'changed_at' => $at, 'changed_by' => $this->admin->id]);
    }

    private function recognizedInvoice(InvoiceCollectionMethod $method)
    {
        $beforeEarnings = $this->snapshot()['earnings']->all();
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'decimal_places' => 2]);
        $this->branch->update(['default_currency_id' => $currency->id]);
        $origin = Area::factory()->for($this->branch)->create();
        $destination = Area::factory()->for($this->branch)->create();
        $customer = Customer::factory()->create();
        $phone = $customer->phones()->create(['phone' => '0912345678', 'normalized_phone' => '0912345678']);
        $address = $customer->addresses()->create(['area_id' => $destination->id, 'address_line_1' => 'Street']);
        $order = app(OrderService::class)->create($this->employee, ['branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => 'ONE_TIME', 'business_classification_snapshot' => 'SHOP',
            'shop_name_snapshot' => 'Shop', 'origin_area_id' => $origin->id, 'sort_order' => 1]);
        $order->update(['pricing_class' => 'SINGLE_SHOP']);
        OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => 'ONE_TIME_ITEM', 'product_name_snapshot' => 'Item', 'unit_name_snapshot' => 'Piece',
            'requested_quantity' => '1', 'current_quantity' => '1', 'actual_unit_price' => '25.00', 'actual_price_source' => 'MANUAL_VERIFIED',
            'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $this->employee->id]);
        app(OrderPricingService::class)->recalculate($order);
        $this->captain->employmentPeriods()->firstOrCreate(['ended_at' => null], ['started_at' => now()->subMonth()]);
        app(OrderCaptainService::class)->assign($this->employee, $order, $this->captain, OrderCaptainRole::PrimaryCollection);
        app(DeliveryPricingRuleService::class)->create($this->admin, ['from_area_id' => $origin->id, 'to_area_id' => $destination->id,
            'currency_id' => $currency->id, 'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '5.00', 'effective_from' => now()->subDay(), 'effective_until' => null]);
        app(DeliveryPricingSettings::class)->update($this->admin, '65', '0');
        app(OrderDeliveryPricingService::class)->price($this->employee, $order, $origin);
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($this->employee, $order, $invoices->refresh($this->employee, $order, $invoices->createDraft($this->employee, $order)));
        $this->assertEquals($beforeEarnings, $this->snapshot()['earnings']->all());
        $order->forceFill(['operational_status' => 'DELIVERED'])->save();
        $order->statusHistory()->create(['previous_status' => 'HEADING_TO_CUSTOMER', 'new_status' => 'DELIVERED', 'changed_at' => now(), 'changed_by' => $this->employee->id]);
        app(InvoiceCollectionService::class)->record($this->employee, $invoice, $method, $invoice->grand_total, $currency->id, now(), null,
            $method === InvoiceCollectionMethod::ShamCash ? 'U5-reference-'.Str::uuid() : null);

        return $invoice;
    }
}
