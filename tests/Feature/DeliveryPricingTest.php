<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\DeliveryOverrideStatus;
use App\Enums\OrderCaptainRole;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryFeeOverrideRequest;
use App\Models\DeliveryPricingUserLimit;
use App\Models\OrderCaptain;
use App\Models\Role;
use App\Models\User;
use App\Services\DeliveryAllocationService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderService;
use Brick\Math\BigDecimal;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeliveryPricingTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['name' => 'Dollar', 'code' => 'USD', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create(['name' => 'Origin']);
        $to = Area::factory()->for($branch)->create(['name' => 'Destination']);
        $customer = Customer::create(['name' => 'Customer']);
        $phone = $customer->phones()->create(['phone' => '111', 'normalized_phone' => '111']);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Street']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'ordered_at' => now()->subDay()]);

        return compact('currency', 'branch', 'from', 'to', 'customer', 'admin', 'employee', 'order');
    }

    private function rule(array $c, string $fee = '200.0000', ?Area $from = null, ?Area $to = null): mixed
    {
        return app(DeliveryPricingRuleService::class)->create($c['admin'], ['from_area_id' => ($from ?? $c['from'])->id, 'to_area_id' => ($to ?? $c['to'])->id, 'currency_id' => $c['currency']->id, 'delivery_unit_fee' => $fee, 'effective_from' => now()->subDays(2), 'effective_until' => null]);
    }

    private function captain(array $c): Captain
    {
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $captain;
    }

    private function configure(array $c, string $share = '65', string $threshold = '10'): void
    {
        app(DeliveryPricingSettings::class)->update($c['admin'], $share, $threshold);
    }

    public function test_directional_same_area_exact_pair_and_effective_time_rules(): void
    {
        $c = $this->context();
        $service = app(DeliveryPricingRuleService::class);
        $forward = $this->rule($c, '150');
        $reverse = $this->rule($c, '175', $c['to'], $c['from']);
        $same = $this->rule($c, '100', $c['from'], $c['from']);
        $this->assertSame($forward->id, $service->applicable($c['from']->id, $c['to']->id, $c['currency']->id, now())->id);
        $this->assertSame('175.0000', $reverse->delivery_unit_fee);
        $this->assertSame('100.0000', $same->delivery_unit_fee);
        $this->assertNull($service->applicable($c['to']->id, $c['to']->id, $c['currency']->id, now()));
        $this->assertNull($service->applicable($c['from']->id, $c['to']->id, $c['currency']->id, now()->subDays(3)));
    }

    public function test_rule_overlap_is_rejected_and_version_history_is_preserved(): void
    {
        $c = $this->context();
        $service = app(DeliveryPricingRuleService::class);
        $old = $this->rule($c, '150');
        try {
            $this->rule($c, '160');
            $this->fail('Overlapping rule was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $new = $service->version($c['admin'], $old, ['from_area_id' => $c['from']->id, 'to_area_id' => $c['to']->id, 'currency_id' => $c['currency']->id, 'delivery_unit_fee' => '175', 'effective_from' => now()->addDay(), 'effective_until' => null]);
        $this->assertNotNull($old->fresh()->effective_until);
        $this->assertSame($new->id, $old->fresh()->superseded_by);
        $this->assertSame($old->id, $service->applicable($c['from']->id, $c['to']->id, $c['currency']->id, $c['order']->ordered_at)->id);
        $this->assertSame($new->id, $service->applicable($c['from']->id, $c['to']->id, $c['currency']->id, now()->addDays(2))->id);
    }

    public function test_rule_admin_routes_enforce_permissions_and_currency_dimension(): void
    {
        $c = $this->context();
        $payload = ['from_area_id' => $c['from']->id, 'to_area_id' => $c['to']->id, 'currency_id' => $c['currency']->id, 'delivery_unit_fee' => '150.00', 'effective_from' => now()->subHour()->format('Y-m-d H:i:s')];
        $this->actingAs($c['employee'])->post(route('delivery-pricing-rules.store'), $payload)->assertForbidden();
        $this->actingAs($c['admin'])->post(route('delivery-pricing-rules.store'), $payload)->assertRedirect(route('delivery-pricing-rules.index'));
        $this->assertDatabaseCount('delivery_pricing_rules', 1);
        $other = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'decimal_places' => 2]);
        $this->assertNull(app(DeliveryPricingRuleService::class)->applicable($c['from']->id, $c['to']->id, $other->id, now()));
        try {
            app(DeliveryPricingRuleService::class)->create($c['admin'], [...$payload, 'currency_id' => $other->id, 'delivery_unit_fee' => '1.001']);
            $this->fail('Currency minor-unit precision was ignored.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_preview_needs_no_captain_but_final_pricing_does_and_requires_share_setting(): void
    {
        $c = $this->context();
        $rule = $this->rule($c);
        $service = app(OrderDeliveryPricingService::class);
        $this->assertSame($rule->id, $service->preview($c['employee'], $c['order'], $c['from'])['rule']->id);
        try {
            $service->price($c['employee'], $c['order'], $c['from']);
            $this->fail('Finalized without share.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->configure($c);
        try {
            $service->price($c['employee'], $c['order'], $c['from']);
            $this->fail('Finalized without Captain.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_multiple_captains_receive_full_units_and_snapshot_share(): void
    {
        $c = $this->context();
        $this->rule($c);
        $this->configure($c);
        $captains = app(OrderCaptainService::class);
        $captains->assign($c['employee'], $c['order'], $this->captain($c), OrderCaptainRole::PrimaryCollection);
        $captains->assign($c['employee'], $c['order'], $this->captain($c), OrderCaptainRole::Support);
        $pricing = app(OrderDeliveryPricingService::class)->price($c['employee'], $c['order'], $c['from']);
        $this->assertSame(2, $pricing->captain_count_snapshot);
        $this->assertSame('400.0000', $pricing->gross_delivery_total);
        $this->assertSame('65.0000', $pricing->captain_share_percent_snapshot);
        $this->assertCount(2, $pricing->allocations);
        foreach ($pricing->allocations as $allocation) {
            $this->assertSame('200.0000', $allocation->applied_unit_fee);
            $this->assertSame('130.0000', $allocation->captain_entitlement);
        }
        $captains->assign($c['employee'], $c['order'], $this->captain($c), OrderCaptainRole::Support);
        $threeCaptainPricing = $c['order']->currentDeliveryPricing()->with('allocations')->first();
        $this->assertSame(3, $threeCaptainPricing->captain_count_snapshot);
        $this->assertSame('600.0000', $threeCaptainPricing->gross_delivery_total);
        $this->assertCount(3, $threeCaptainPricing->allocations);
        app(DeliveryPricingSettings::class)->update($c['admin'], '50', '10');
        $this->assertSame('65.0000', $threeCaptainPricing->fresh()->captain_share_percent_snapshot);
    }

    public function test_existing_snapshot_is_unchanged_after_rule_version_changes(): void
    {
        $c = $this->context();
        $rule = $this->rule($c);
        $this->configure($c);
        app(OrderCaptainService::class)->assign($c['employee'], $c['order'], $this->captain($c), OrderCaptainRole::PrimaryCollection);
        $pricing = app(OrderDeliveryPricingService::class)->price($c['employee'], $c['order'], $c['from']);
        app(DeliveryPricingRuleService::class)->version($c['admin'], $rule, ['from_area_id' => $c['from']->id, 'to_area_id' => $c['to']->id, 'currency_id' => $c['currency']->id, 'delivery_unit_fee' => '250', 'effective_from' => now()->addDay(), 'effective_until' => null]);
        $this->assertSame($rule->id, $pricing->fresh()->delivery_pricing_rule_id);
        $this->assertSame('200.0000', $pricing->fresh()->suggested_unit_fee);
        $this->assertNull($pricing->fresh()->superseded_at);
    }

    public function test_discount_business_examples_and_deterministic_remainder(): void
    {
        $currency = Currency::create(['name' => 'Dollar', 'code' => 'USD', 'decimal_places' => 2]);
        $assignment = fn (int $id) => new OrderCaptain(['captain_id' => $id]);
        $rows = collect([$assignment(1)]);
        $rows[0]->id = 1;
        $service = app(DeliveryAllocationService::class);
        foreach (['0' => ['70.0000', '0.0000'], '50' => ['20.0000', '0.0000'], '70' => ['0.0000', '0.0000'], '100' => ['0.0000', '30.0000'], '200' => ['0.0000', '130.0000']] as $discount => $expected) {
            $row = $service->calculate('200', '65', $discount, $currency, $rows)[0];
            $this->assertSame($expected[0], $row['company_collectible']);
            $this->assertSame($expected[1], $row['captain_shortfall']);
            $this->assertSame('130.0000', $row['captain_entitlement']);
        }
        $many = collect([$assignment(1), $assignment(2), $assignment(3)]);
        foreach ($many as $i => $a) {
            $a->id = $i + 1;
        }
        $allocated = collect($service->calculate('10', '50', '1.00', $currency, $many));
        $this->assertSame('1.0000', (string) $allocated->reduce(fn ($sum, $row) => BigDecimal::of($sum)->plus($row['discount_allocated']), '0')->toScale(4));
        $this->assertSame(['0.3400', '0.3300', '0.3300'], $allocated->pluck('discount_allocated')->all());
    }

    public function test_employee_discount_limits_and_abshir_bears_discount(): void
    {
        $c = $this->context();
        $this->rule($c);
        $this->configure($c);
        app(OrderCaptainService::class)->assign($c['employee'], $c['order'], $this->captain($c), OrderCaptainRole::PrimaryCollection);
        $service = app(OrderDeliveryPricingService::class);
        $pricing = $service->price($c['employee'], $c['order'], $c['from']);
        try {
            $service->applyDiscount($c['employee'], $c['order'], '1', 'Denied');
            $this->fail('Zero-limit employee discounted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        DeliveryPricingUserLimit::create(['user_id' => $c['employee']->id, 'max_discount_percent' => '25', 'updated_by' => $c['admin']->id]);
        $discounted = $service->applyDiscount($c['employee'], $c['order'], '50', 'Customer care');
        $this->assertSame('150.0000', $discounted->customer_delivery_total);
        $this->assertSame('130.0000', $discounted->allocations->first()->captain_entitlement);
        $this->assertSame('20.0000', $discounted->allocations->first()->company_collectible);
        $this->assertNotNull($pricing->fresh()->superseded_at);
        try {
            $service->applyDiscount($c['employee'], $c['order'], '51', 'Too much');
            $this->fail('Excess discount accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_override_threshold_pending_approval_and_double_resolution(): void
    {
        $c = $this->context();
        $this->rule($c);
        $this->configure($c, '65', '10');
        app(OrderCaptainService::class)->assign($c['employee'], $c['order'], $this->captain($c), OrderCaptainRole::PrimaryCollection);
        $service = app(OrderDeliveryPricingService::class);
        $within = $service->requestOverride($c['employee'], $c['order'], $c['from'], '190', 'Small adjustment');
        $this->assertSame('190.0000', $within->applied_unit_fee);
        $this->assertDatabaseHas('delivery_fee_override_requests', ['order_delivery_pricing_id' => $within->id, 'status' => DeliveryOverrideStatus::Approved->value, 'reason' => 'Small adjustment']);
        $pending = $service->requestOverride($c['employee'], $c['order'], $c['from'], '150', 'Large adjustment');
        $this->assertInstanceOf(DeliveryFeeOverrideRequest::class, $pending);
        $this->assertSame(DeliveryOverrideStatus::Pending, $pending->status);
        $this->assertSame('190.0000', $c['order']->currentDeliveryPricing->applied_unit_fee);
        $service->resolveOverride($c['admin'], $pending, true, 'Approved');
        $this->assertSame(DeliveryOverrideStatus::Approved, $pending->fresh()->status);
        $this->assertSame('150.0000', $c['order']->currentDeliveryPricing()->first()->applied_unit_fee);
        try {
            $service->resolveOverride($c['admin'], $pending, true, null);
            $this->fail('Double approval succeeded.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_override_rejection_authorization_and_required_reason(): void
    {
        $c = $this->context();
        $this->rule($c);
        $this->configure($c, '65', '0');
        app(OrderCaptainService::class)->assign($c['employee'], $c['order'], $this->captain($c), OrderCaptainRole::PrimaryCollection);
        $service = app(OrderDeliveryPricingService::class);
        $pricing = $service->price($c['employee'], $c['order'], $c['from']);
        try {
            $service->requestOverride($c['employee'], $c['order'], $c['from'], '190', '');
            $this->fail('Override without reason was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $pending = $service->requestOverride($c['employee'], $c['order'], $c['from'], '190', 'Needs approval');
        $this->actingAs($c['employee'])->patch(route('delivery-overrides.resolve', $pending), ['decision' => 'reject', 'resolution_reason' => 'No'])->assertForbidden();
        $service->resolveOverride($c['admin'], $pending, false, 'Not justified');
        $this->assertSame(DeliveryOverrideStatus::Rejected, $pending->fresh()->status);
        $this->assertSame($pricing->id, $c['order']->currentDeliveryPricing()->first()->id);
    }

    public function test_historical_destination_confirmation_is_explicit_and_one_time(): void
    {
        $c = $this->context();
        $c['order']->update(['destination_area_id' => null]);
        $service = app(OrderDeliveryPricingService::class);
        $service->confirmHistoricalDestination($c['employee'], $c['order'], $c['to']);
        $order = $c['order']->fresh();
        $this->assertSame($c['to']->id, $order->destination_area_id);
        $this->assertSame($c['employee']->id, $order->destination_area_confirmed_by);
        $this->assertNotNull($order->destination_area_confirmed_at);
        try {
            $service->confirmHistoricalDestination($c['employee'], $order, $c['from']);
            $this->fail('Confirmed destination was rewritten.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_captain_count_change_versions_pricing_but_primary_switch_does_not(): void
    {
        $c = $this->context();
        $this->rule($c);
        $this->configure($c);
        $captainService = app(OrderCaptainService::class);
        $first = $this->captain($c);
        $second = $this->captain($c);
        $primary = $captainService->assign($c['employee'], $c['order'], $first, OrderCaptainRole::PrimaryCollection);
        $pricing = app(OrderDeliveryPricingService::class)->price($c['employee'], $c['order'], $c['from']);
        $support = $captainService->assign($c['employee'], $c['order'], $second, OrderCaptainRole::Support);
        $this->assertSame(2, $c['order']->currentDeliveryPricing()->first()->captain_count_snapshot);
        $this->assertNotNull($pricing->fresh()->superseded_at);
        $captainService->remove($c['employee'], $c['order'], $support, 'No longer required');
        $this->assertSame(1, $c['order']->currentDeliveryPricing()->first()->captain_count_snapshot);
        $version = $c['order']->currentDeliveryPricing()->first()->version;
        $captainService->switchPrimary($c['employee'], $c['order'], $second, 'Operational handoff');
        $this->assertSame($version, $c['order']->currentDeliveryPricing()->first()->version);
        $this->assertNotNull($primary->fresh()->removed_at);
    }

    public function test_permissions_scope_and_no_future_financial_schema(): void
    {
        $c = $this->context();
        $codes = $c['employee']->roles->first()->permissions()->where('code', 'like', 'delivery%')->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['delivery_pricing.view', 'delivery_pricing.apply', 'delivery_pricing.override', 'delivery_discounts.apply'], $codes);
        $this->assertFalse($c['employee']->hasPermission('delivery_pricing_rules.create'));
        $this->assertFalse($c['employee']->hasPermission('delivery_pricing_overrides.approve'));
        $this->assertFalse($c['employee']->hasPermission('delivery_financial_details.view'));
        $this->assertCount(0, Role::where('code', 'CAPTAIN')->sole()->permissions()->where('code', 'like', 'delivery%')->get());
        foreach (['payments', 'financial_accounts', 'financial_transactions', 'captain_deposits', 'captain_receivables'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_sensitive_delivery_details_and_cross_branch_area_tampering_are_denied(): void
    {
        $c = $this->context();
        $this->rule($c);
        $this->configure($c);
        app(OrderCaptainService::class)->assign($c['employee'], $c['order'], $this->captain($c), OrderCaptainRole::PrimaryCollection);
        app(OrderDeliveryPricingService::class)->price($c['employee'], $c['order'], $c['from']);
        $this->actingAs($c['employee'])->get(route('orders.show', $c['order']))->assertOk()->assertDontSee('Captain share snapshot')->assertDontSee('Company collectible');
        $this->actingAs($c['admin'])->get(route('orders.show', $c['order']))->assertOk()->assertSee('Captain share snapshot')->assertSee('Company collectible');

        $otherBranch = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        $otherArea = Area::factory()->for($otherBranch)->create();
        try {
            app(OrderDeliveryPricingService::class)->preview($c['employee'], $c['order'], $otherArea);
            $this->fail('Cross-Branch origin tampering succeeded.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
