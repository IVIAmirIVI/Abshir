<?php

namespace Tests\Feature;

use App\Livewire\Orders\AddShop;
use App\Livewire\Orders\CreateOrder;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\DeliveryFeeOverrideRequest;
use App\Models\DeliveryPricingRule;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopProduct;
use App\Models\ShopType;
use App\Models\SystemSetting;
use App\Models\Unit;
use App\Models\User;
use App\Services\CustomerPhoneService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class CreateOrderWizardTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'WIZ', 'name' => 'عملة', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create(['name' => 'سقبا']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $customer = Customer::create(['name' => 'أمير']);
        $phone = $customer->phones()->create(['phone' => '0944111222', 'normalized_phone' => '0944111222', 'is_primary' => true]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'عنوان أمير', 'is_primary' => true]);
        $type = ShopType::create(['name' => 'بقالة', 'business_classification' => 'SHOP']);
        $shop = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل الاختبار', 'area_id' => $area->id, 'shop_type_id' => $type->id]);
        $unit = Unit::create(['name' => 'قطعة', 'code' => 'WIZ', 'allows_decimal' => false]);
        $product = Product::create(['name' => 'حليب', 'sku' => 'MILK', 'default_unit_id' => $unit->id]);
        $this->rule($actor, $area, $area, $currency, 'SINGLE_SHOP', '7');

        return compact('currency', 'branch', 'area', 'actor', 'customer', 'phone', 'address', 'type', 'shop', 'unit', 'product');
    }

    private function rule($actor, $origin, $destination, $currency, string $class, string $fee): void
    {
        app(DeliveryPricingRuleService::class)->create($actor, [
            'from_area_id' => $origin->id, 'to_area_id' => $destination->id, 'currency_id' => $currency->id,
            'order_pricing_class' => $class, 'delivery_unit_fee' => $fee, 'effective_from' => now()->subHour(),
        ]);
    }

    private function start(array $c)
    {
        return Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->set('branchId', (string) $c['branch']->id)->set('customerId', (string) $c['customer']->id)->set('source', 'PHONE');
    }

    private function group($component, array $c, ?Shop $shop = null)
    {
        return $component->call('selectShop', ($shop ?? $c['shop'])->id)->call('addDraftShop')->assertHasNoErrors()
            ->call('selectProduct', $c['product']->id)->call('addDraftItem')->assertHasNoErrors();
    }

    private function ready(array $c)
    {
        return $this->group($this->start($c)->call('nextStep'), $c)->call('nextStep')->call('nextStep')->assertSet('step', 4);
    }

    private function overrideCaptain(array $c): Captain
    {
        app(DeliveryPricingSettings::class)->update($c['actor'], '65', '20');
        $captain = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => 'AVAILABLE']);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $captain;
    }

    public function test_wizard_exception_is_effective_audited_atomic_and_retry_does_not_duplicate_it(): void
    {
        $c = $this->context();
        $captain = $this->overrideCaptain($c);
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('nextStep')->call('beginDeliveryOverride')
            ->set('deliveryOverrideAmount', '6.50')->set('deliveryOverrideReason', 'سبب موثق')
            ->set('deliveryOverrideCaptainId', (string) $captain->id)->call('nextStep')->assertHasNoErrors()
            ->assertSee('data-wizard-override-review', false)->assertSee(__('wizard_delivery.immediate'));
        $groups = $component->get('groups');
        foreach ([2, 1, 3, 4] as $step) {
            $component->call('goToStep', $step)->assertHasNoErrors()->assertSet('groups', $groups)
                ->assertSet('deliveryOverrideAmount', '6.50')->assertSet('deliveryOverrideReason', 'سبب موثق');
        }
        $retry = clone $component;
        $component->call('submit')->assertHasNoErrors();
        $order = Order::sole();
        $this->assertSame('NEW', $order->operational_status->value);
        $this->assertSame('6.5000', $order->currentDeliveryPricing->applied_unit_fee);
        $this->assertSame('7.0000', $order->currentDeliveryPricing->suggested_unit_fee);
        $audit = DeliveryFeeOverrideRequest::sole();
        $this->assertSame('APPROVED', $audit->status->value);
        $this->assertSame($c['actor']->id, $audit->requested_by);
        $this->assertSame($c['actor']->id, $audit->resolved_by);
        $this->assertSame('سبب موثق', $audit->reason);
        $this->assertSame($order->currentDeliveryPricing->id, $audit->order_delivery_pricing_id);
        $this->assertSame('PRIMARY_COLLECTION', $order->captainAssignments()->sole()->assignment_role->value);
        $retry->call('submit')->assertHasNoErrors()->assertRedirect(route('orders.workflow', $order));
        $component->call('submit')->assertRedirect(route('orders.workflow', $order));
        foreach (['orders', 'order_delivery_pricings', 'delivery_fee_override_requests', 'order_captains'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_employee_exception_preview_and_persistence_keep_threshold_approval_semantics(): void
    {
        $c = $this->context();
        $captain = $this->overrideCaptain($c);
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($c['branch']);
        $c['actor'] = $employee;
        $component = $this->ready($c)->call('goToStep', 3)->call('beginDeliveryOverride')
            ->set('deliveryOverrideAmount', '12')->set('deliveryOverrideReason', 'طلب موافقة')
            ->set('deliveryOverrideCaptainId', (string) $captain->id)->assertSee(__('wizard_delivery.pending'))
            ->call('nextStep')->assertSet('step', 4);
        $this->assertSame('7.0000', $component->get('reviewedDelivery')['final_fee']);
        $component->call('submit')->assertHasNoErrors();
        $order = Order::sole();
        $this->assertSame('7.0000', $order->currentDeliveryPricing->applied_unit_fee);
        $this->assertSame('PENDING', DeliveryFeeOverrideRequest::sole()->status->value);
        $this->assertSame('12.0000', DeliveryFeeOverrideRequest::sole()->requested_unit_fee);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_employee_exception_at_exact_threshold_is_immediately_approved(): void
    {
        $c = $this->context();
        $captain = $this->overrideCaptain($c);
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($c['branch']);
        $c['actor'] = $employee;
        $this->ready($c)->call('goToStep', 3)->call('beginDeliveryOverride')->set('deliveryOverrideAmount', '8.40')
            ->set('deliveryOverrideReason', 'ضمن الحد')->set('deliveryOverrideCaptainId', (string) $captain->id)
            ->assertSee(__('wizard_delivery.immediate'))->call('nextStep')->call('submit')->assertHasNoErrors();
        $this->assertSame('8.4000', Order::sole()->currentDeliveryPricing->applied_unit_fee);
        $this->assertSame('APPROVED', DeliveryFeeOverrideRequest::sole()->status->value);
        $this->assertSame('20.0000', DeliveryFeeOverrideRequest::sole()->difference_percent);
    }

    public function test_late_exception_failure_rolls_back_order_assignment_pricing_and_audit_together(): void
    {
        $c = $this->context();
        $captain = $this->overrideCaptain($c);
        $component = $this->ready($c)->call('goToStep', 3)->call('beginDeliveryOverride')->set('deliveryOverrideAmount', '8')
            ->set('deliveryOverrideReason', 'سبب')->set('deliveryOverrideCaptainId', (string) $captain->id)->call('nextStep');
        $groups = $component->get('groups');
        $real = app(OrderDeliveryPricingService::class);
        $mock = Mockery::mock(OrderDeliveryPricingService::class);
        foreach (['preview', 'previewRoutes', 'overrideDecision', 'repriceAfterCaptainChangeLocked'] as $method) {
            $mock->shouldReceive($method)->andReturnUsing($real->{$method}(...));
        }
        $mock->shouldReceive('requestOverride')->once()->andReturnUsing(function (...$args) use ($real) {
            $real->requestOverride(...$args);
            throw ValidationException::withMessages(['delivery' => 'Late failure']);
        });
        $this->app->instance(OrderDeliveryPricingService::class, $mock);
        $component->call('submit')->assertHasErrors('delivery')->assertSet('step', 3)->assertSet('groups', $groups)
            ->assertSet('deliveryOverrideReason', 'سبب');
        foreach (['orders', 'order_shops', 'order_items', 'order_captains', 'order_delivery_pricings', 'delivery_fee_override_requests', 'financial_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_wizard_multi_shop_exception_preserves_max_routes_and_all_snapshots(): void
    {
        $c = $this->context();
        $captain = $this->overrideCaptain($c);
        $origin = Area::factory()->for($c['branch'])->create();
        $shop = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل آخر', 'area_id' => $origin->id]);
        $this->rule($c['actor'], $c['area'], $c['area'], $c['currency'], 'MULTI_SHOP', '8');
        $this->rule($c['actor'], $origin, $c['area'], $c['currency'], 'MULTI_SHOP', '12');
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $this->group($component, $c, $shop)->call('nextStep')->assertSeeText('12 WIZ')->call('beginDeliveryOverride')
            ->set('deliveryOverrideAmount', '10')->set('deliveryOverrideReason', 'سبب متعدد المحلات')
            ->set('deliveryOverrideCaptainId', (string) $captain->id)->call('nextStep')->call('submit')->assertHasNoErrors();
        $pricing = Order::sole()->currentDeliveryPricing;
        $this->assertSame('MULTI_SHOP', $pricing->order_pricing_class_snapshot->value);
        $this->assertSame('12.0000', $pricing->suggested_unit_fee);
        $this->assertSame('10.0000', $pricing->customer_delivery_total);
        $this->assertSame(2, $pricing->routes()->count());
        $this->assertSame(['8.0000', '12.0000'], $pricing->routes()->orderBy('id')->pluck('proposed_unit_fee')->all());
        $this->assertSame(1, $pricing->captain_count_snapshot);
    }

    public function test_inline_phone_and_address_creation_preserve_pending_product_and_wizard_navigation(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('selectProduct', $c['product']->id)->set('quantity', '3');
        $groups = $component->get('groups');
        $component->call('goToStep', 1)->call('openPhoneCreate')->set('newPhoneNumber', '0955555555')->call('createPhone')->assertHasNoErrors()
            ->call('openAddressCreate')->set('newAddressAreaId', (string) $c['area']->id)->set('newAddressLine', 'عنوان جديد')->call('createAddress')->assertHasNoErrors()
            ->assertSet('groups', $groups)->assertSet('quantity', '3')->assertSet('productId', (string) $c['product']->id)
            ->call('$refresh')->call('nextStep')->assertSet('step', 2)->call('addDraftItem')->call('nextStep')->call('nextStep')->call('submit')->assertHasNoErrors();
        $order = Order::sole();
        $this->assertSame('0955555555', $order->customer_phone_snapshot);
        $this->assertSame('عنوان جديد', $order->address_line_1_snapshot);
        $this->assertSame(2, $order->shops()->sole()->items()->count());
    }

    public function test_wizard_exception_validates_amount_reason_captain_and_restore_is_truly_automatic(): void
    {
        $c = $this->context();
        $captain = $this->overrideCaptain($c);
        $component = $this->ready($c)->call('goToStep', 3)->call('beginDeliveryOverride')
            ->set('deliveryOverrideCaptainId', (string) $captain->id)->set('deliveryOverrideReason', 'سبب');
        foreach (['', '0', '-1', '1.001', 'NaN', 'Infinity', '1e2', '١٢'] as $invalid) {
            $component->set('deliveryOverrideAmount', $invalid)->call('nextStep')->assertHasErrors('deliveryOverrideAmount')->assertSet('step', 3);
        }
        $component->set('deliveryOverrideAmount', '8')->set('deliveryOverrideReason', ' ')->call('nextStep')->assertHasErrors('deliveryOverrideReason');
        $component->set('deliveryOverrideReason', 'سبب')->set('deliveryOverrideCaptainId', '')->call('nextStep')->assertHasErrors('deliveryOverrideCaptainId');
        $component->call('restoreAutomaticDelivery')->assertSet('deliveryOverrideEnabled', false)
            ->call('nextStep')->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('delivery_fee_override_requests', 0);
        $this->assertDatabaseCount('order_delivery_pricings', 0);
        $this->assertDatabaseCount('order_captains', 0);
    }

    public function test_wizard_exception_missing_share_and_unauthorized_direct_action_fail_closed(): void
    {
        $c = $this->context();
        SystemSetting::where('key', DeliveryPricingSettings::CAPTAIN_SHARE_KEY)->delete();
        $this->ready($c)->call('goToStep', 3)->call('beginDeliveryOverride')->assertHasErrors('deliveryOverrideAmount')
            ->assertSet('deliveryOverrideEnabled', false);
        $role = Role::create(['code' => 'NO_EXCEPTION', 'name' => 'No exception']);
        $role->permissions()->attach(Permission::whereIn('code', ['orders.create', 'orders.update', 'orders.view', 'delivery_pricing.view'])->pluck('id'));
        $actor = User::factory()->create();
        $actor->roles()->attach($role);
        $actor->branches()->attach($c['branch']);
        $c['actor'] = $actor;
        $this->ready($c)->call('goToStep', 3)->assertDontSee('wire:click="beginDeliveryOverride"', false)
            ->call('beginDeliveryOverride')->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_wizard_exception_rejects_foreign_or_now_ineligible_captain_without_losing_draft(): void
    {
        $c = $this->context();
        $captain = $this->overrideCaptain($c);
        $foreign = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => Branch::factory()->create()->id, 'operational_status' => 'AVAILABLE']);
        $foreign->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $component = $this->ready($c)->call('goToStep', 3)->call('beginDeliveryOverride')
            ->set('deliveryOverrideAmount', '8')->set('deliveryOverrideReason', 'سبب')->set('deliveryOverrideCaptainId', (string) $foreign->id);
        $groups = $component->get('groups');
        $component->call('nextStep')->assertHasErrors('deliveryOverrideCaptainId')->assertSet('groups', $groups);
        $component->set('deliveryOverrideCaptainId', (string) $captain->id)->call('nextStep')->assertHasNoErrors();
        $captain->user->forceFill(['is_active' => false])->save();
        $component->call('submit')->assertHasErrors('deliveryOverrideCaptainId')->assertSet('step', 3)->assertSet('groups', $groups);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_wizard_exception_changed_destination_requires_explicit_reconfirmation(): void
    {
        $c = $this->context();
        $captain = $this->overrideCaptain($c);
        $area = Area::factory()->for($c['branch'])->create();
        $address = $c['customer']->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'عنوان آخر']);
        $this->rule($c['actor'], $c['area'], $area, $c['currency'], 'SINGLE_SHOP', '9');
        $component = $this->start($c)->set('customerAddressId', (string) $c['address']->id);
        $this->group($component->call('nextStep'), $c)->call('nextStep')->call('beginDeliveryOverride')
            ->set('deliveryOverrideAmount', '8')->set('deliveryOverrideReason', 'سبب')->set('deliveryOverrideCaptainId', (string) $captain->id)
            ->call('goToStep', 1)->set('customerAddressId', (string) $address->id)->call('goToStep', 3)
            ->assertSee(__('wizard_delivery.context_changed'))->call('nextStep')->assertHasErrors('deliveryOverrideAmount')
            ->assertSet('deliveryOverrideAmount', '8')->call('reconfirmDeliveryOverride')->call('nextStep')->call('submit')->assertHasNoErrors();
        $this->assertSame('9.0000', Order::sole()->currentDeliveryPricing->suggested_unit_fee);
        $this->assertSame('8.0000', Order::sole()->currentDeliveryPricing->applied_unit_fee);
    }

    public function test_changed_rule_after_review_rolls_back_creation_and_routes_only_to_delivery(): void
    {
        $c = $this->context();
        $component = $this->ready($c);
        $groups = $component->get('groups');
        app(DeliveryPricingRuleService::class)->version($c['actor'], DeliveryPricingRule::sole(), [
            'from_area_id' => $c['area']->id, 'to_area_id' => $c['area']->id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '9', 'effective_from' => now(),
        ]);
        $component->call('submit')->assertHasErrors('delivery')->assertSet('step', 3)->assertSet('groups', $groups)
            ->assertSet('customerId', (string) $c['customer']->id);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $component->call('nextStep')->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_unchanged_branch_bundled_back_refresh_and_forward_keep_contacts_and_pending_item_inputs(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('selectProduct', $c['product']->id)->set('quantity', '4');
        $groups = $component->get('groups');
        $key = $component->get('activeGroup');
        foreach ([1, 2, 1, 2] as $step) {
            $component->update(updates: ['branchId' => (string) $c['branch']->id], calls: [['method' => 'goToStep', 'params' => [$step], 'path' => '']])
                ->assertSet('step', $step)->assertSet('groups', $groups)->assertSet('activeGroup', $key)
                ->assertSet('productId', (string) $c['product']->id)->assertSet('quantity', '4')
                ->assertSet('customerPhoneId', (string) $c['phone']->id)->assertSet('customerAddressId', (string) $c['address']->id)
                ->call('$refresh')->assertSet('step', $step)->assertSet('groups', $groups);
        }
        $component->call('addDraftItem')->call('nextStep')->call('nextStep')->call('submit')->assertHasNoErrors();
        $this->assertSame(['1.0000', '4.0000'], Order::sole()->shops()->sole()->items()->orderBy('id')->pluck('current_quantity')->all());
    }

    public function test_step_validation_blocks_direct_skipping_and_submit_before_review(): void
    {
        $c = $this->context();
        Livewire::actingAs($c['actor'])->test(CreateOrder::class)->call('goToStep', 4)
            ->assertSet('step', 1)->assertHasErrors(['customerId', 'customerPhoneId', 'customerAddressId', 'source']);
        $component = $this->start($c)->call('goToStep', 4)->assertSet('step', 2)->assertHasErrors('groups');
        $this->group($component, $c)->call('submit')->assertHasErrors('wizard');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_inline_mobile_phone_preserves_the_authoritative_wizard_draft(): void
    {
        $c = $this->context();
        $component = $this->ready($c)->set('notes', 'محفوظ')->call('goToStep', 1);
        $groups = $component->get('groups');
        $component->call('openPhoneCreate')->assertSet('showPhoneCreate', true)
            ->set('newPhoneNumber', '0951234567')->call('createPhone')->assertHasNoErrors()
            ->assertSet('showPhoneCreate', false)->assertSet('groups', $groups)
            ->assertSet('branchId', (string) $c['branch']->id)
            ->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerAddressId', (string) $c['address']->id)
            ->assertSet('source', 'PHONE')->assertSet('notes', 'محفوظ');
        $phone = $c['customer']->phones()->where('phone', '0951234567')->sole();
        $component->assertSet('customerPhoneId', (string) $phone->id)->assertSee('0951234567')
            ->call('goToStep', 4)->assertSet('step', 4)->assertSet('groups', $groups)
            ->call('submit')->assertHasNoErrors();
        $this->assertSame($phone->id, Order::sole()->customer_phone_id);
    }

    public function test_inline_mobile_validation_rejects_invalid_raw_values_and_duplicate_customer_phone(): void
    {
        $c = $this->context();
        $component = $this->start($c)->call('openPhoneCreate');
        foreach (['091234567', '09123456789', '0812345678', '09 12345678', '09a12345678', '٠٩١٢٣٤٥٦٧٨', '+963912345678'] as $invalid) {
            $component->set('newPhoneNumber', $invalid)->call('createPhone')->assertHasErrors('newPhoneNumber');
            $this->assertSame(1, $c['customer']->phones()->count());
        }
        $component->set('newPhoneNumber', $c['phone']->phone)->call('createPhone')->assertHasErrors('newPhoneNumber');
        $this->assertSame(1, $c['customer']->phones()->count());
    }

    public function test_inline_mobile_phone_requires_permission_and_customer_branch_access(): void
    {
        $c = $this->context();
        $role = Role::create(['code' => 'PHONE_WIZARD', 'name' => 'Phone wizard']);
        $role->permissions()->attach(Permission::whereIn('code', ['orders.create', 'orders.view'])->pluck('id'));
        $actor = User::factory()->create();
        $actor->roles()->attach($role);
        $actor->branches()->attach($c['branch']);
        Livewire::actingAs($actor)->test(CreateOrder::class)->set('customerId', (string) $c['customer']->id)
            ->call('openPhoneCreate')->assertForbidden();
        $role->permissions()->attach(Permission::where('code', 'customers.update')->sole());
        $actor = $actor->fresh();
        $hidden = Customer::create(['name' => 'خارج الفرع']);
        $foreignArea = Area::factory()->for(Branch::factory()->create())->create();
        $hidden->addresses()->create(['area_id' => $foreignArea->id, 'address_line_1' => 'خارج الفرع']);
        Livewire::actingAs($actor)->test(CreateOrder::class)->set('customerId', (string) $hidden->id)
            ->call('openPhoneCreate')->assertNotFound();
        $this->assertSame(0, $hidden->phones()->count());
    }

    public function test_phone_service_preserves_landlines_and_legacy_data_without_global_uniqueness(): void
    {
        $c = $this->context();
        // is_primary is deliberately guarded; create a real existing primary
        // so adding a secondary landline does not trigger primary reassignment.
        $c['phone']->forceFill(['is_primary' => true])->save();
        $service = app(CustomerPhoneService::class);
        $legacy = $c['customer']->phones()->create(['phone' => '+963 955 000 111', 'normalized_phone' => '963955000111']);
        $before = $legacy->refresh()->getRawOriginal();
        $landline = $service->save($c['actor'], $c['customer'], new CustomerPhone, ['phone' => '011 123 4567']);
        $this->assertSame('0111234567', $landline->normalized_phone);
        $this->assertTrue($c['phone']->fresh()->is_primary);
        $other = Customer::create(['name' => 'زبون ثان']);
        $other->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'عنوان']);
        $duplicate = $service->save($c['actor'], $other, new CustomerPhone, ['phone' => $c['phone']->phone, 'duplicate_acknowledged' => true]);
        $this->assertSame($c['phone']->normalized_phone, $duplicate->normalized_phone);
        foreach (['09a12345678', '09 12345678', '+963912345678', '٠٩١٢٣٤٥٦٧٨'] as $invalid) {
            try {
                $service->save($c['actor'], $c['customer'], new CustomerPhone, ['phone' => $invalid]);
                $this->fail('Invalid mobile unexpectedly accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('phone', $exception->errors());
            }
        }
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
    }

    public function test_back_preserves_contacts_source_notes_groups_items_and_review(): void
    {
        $c = $this->context();
        $component = $this->ready($c)->set('notes', 'مسودة محفوظة');
        $groups = $component->get('groups');
        $component->call('goToStep', 1)->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerPhoneId', (string) $c['phone']->id)->assertSet('customerAddressId', (string) $c['address']->id)
            ->assertSet('source', 'PHONE')->assertSet('notes', 'مسودة محفوظة')->assertSet('groups', $groups)
            ->call('goToStep', 4)->assertSee('حليب')->assertSee('مسودة محفوظة')->assertSee('عنوان أمير');
    }

    public function test_sole_contacts_are_automatic_but_multiple_require_explicit_choice(): void
    {
        $c = $this->context();
        $this->start($c)->assertSet('customerPhoneId', (string) $c['phone']->id)->assertSet('customerAddressId', (string) $c['address']->id);
        $c['customer']->phones()->create(['phone' => '0999999999', 'normalized_phone' => '0999999999']);
        $c['customer']->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'عنوان ثان']);
        $this->start($c)->assertSet('customerPhoneId', '')->assertSet('customerAddressId', '')
            ->call('nextStep')->assertHasErrors(['customerPhoneId', 'customerAddressId']);
    }

    public function test_customer_search_safe_zero_one_many_and_phone_context(): void
    {
        $c = $this->context();
        $other = Customer::create(['name' => 'أمير آخر']);
        $other->phones()->create(['phone' => '0955555555', 'normalized_phone' => '0955555555']);
        $other->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'العنوان الثاني']);
        $this->start($c)->call('selectSoleCustomer', 'لا يوجد')->assertSet('customerId', (string) $c['customer']->id)
            ->call('selectSoleCustomer', 'أمير')->assertSet('customerId', (string) $c['customer']->id)
            ->call('selectSoleCustomer', '555555')->assertSet('customerId', (string) $other->id)
            ->assertSee('العنوان الثاني')->assertSee('0955555555');
    }

    public function test_shop_and_product_ambiguous_enter_never_select_first(): void
    {
        $c = $this->context();
        Shop::create(['business_classification' => 'SHOP', 'name' => 'محل آخر', 'area_id' => $c['area']->id, 'shop_type_id' => $c['type']->id]);
        Product::create(['name' => 'حليب آخر', 'default_unit_id' => $c['unit']->id]);
        $component = $this->start($c)->call('nextStep')->call('selectSoleShop', 'محل')->assertSet('shopId', '')
            ->call('selectSoleShop', 'غير موجود')->assertSet('shopId', '')->call('selectSoleShop', 'الاختبار')->assertSet('shopId', (string) $c['shop']->id)
            ->call('addDraftShop')->call('selectSoleProduct', 'حليب')->assertSet('productId', '')
            ->call('selectSoleProduct', 'غير موجود')->assertSet('productId', '')->call('selectSoleProduct', 'MILK')->assertSet('productId', (string) $c['product']->id);
        $component->call('addDraftItem')->assertHasNoErrors();
    }

    public function test_grouping_multiple_products_and_draft_removal_are_explicit(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('selectShop', $c['shop']->id)->call('addDraftShop')
            ->call('selectProduct', $c['product']->id)->set('quantity', '2')->call('addDraftItem');
        $groups = $component->get('groups');
        $this->assertCount(1, $groups);
        $key = array_key_first($groups);
        $this->assertCount(2, $groups[$key]['items']);
        $component->call('removeDraftItem', $key, 0)->assertHasNoErrors()->call('removeDraftItem', $key, 0)
            ->call('nextStep')->assertHasErrors('groups.'.$key.'.items')->call('removeDraftShop', $key)->assertSet('groups', []);
        $this->assertDatabaseCount('order_shops', 0);
    }

    public function test_one_time_shop_preserves_origin_and_creates_one_time_identity_only(): void
    {
        $c = $this->context();
        $component = $this->start($c)->call('nextStep')->set('shopType', 'ONE_TIME')->assertSet('originAreaId', (string) $c['area']->id)
            ->set('shopName', 'محل عابر')->set('businessClassification', 'SHOP')->call('addDraftShop')
            ->call('selectProduct', $c['product']->id)->call('addDraftItem')->call('goToStep', 4)->call('submit')->assertHasNoErrors()->assertRedirect();
        $shop = Order::sole()->shops()->sole();
        $this->assertNull($shop->shop_id);
        $this->assertSame($c['area']->id, $shop->origin_area_id);
        $this->assertSame('محل عابر', $shop->shop_name_snapshot);
    }

    public function test_multi_shop_uses_max_current_routes_and_never_sum(): void
    {
        $c = $this->context();
        $origin = Area::factory()->for($c['branch'])->create(['name' => 'كفر بطنا']);
        $second = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل ثان', 'area_id' => $origin->id, 'shop_type_id' => $c['type']->id]);
        $this->rule($c['actor'], $c['area'], $c['area'], $c['currency'], 'MULTI_SHOP', '8');
        $this->rule($c['actor'], $origin, $c['area'], $c['currency'], 'MULTI_SHOP', '12');
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $this->group($component, $c, $second)->call('nextStep')->assertSeeText('12 WIZ')->assertSee('كفر بطنا')
            ->assertDontSee('MULTI_SHOP')->assertDontSee('from_area_id')->call('nextStep')->call('submit')->assertHasNoErrors();
        $this->assertSame('MULTI_SHOP', Order::sole()->pricing_class->value);
        $this->assertDatabaseCount('order_shops', 2);
        $this->assertDatabaseCount('order_delivery_pricings', 0);
    }

    public function test_missing_route_blocks_review_and_new_current_rule_is_found_without_new_order(): void
    {
        $c = $this->context();
        $origin = Area::factory()->for($c['branch'])->create();
        $c['shop']->update(['area_id' => $origin->id]);
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('nextStep')->call('nextStep')
            ->assertSet('step', 3)->assertHasErrors('delivery')->assertSee(__('orders.wizard.missing_route'));
        $this->rule($c['actor'], $origin, $c['area'], $c['currency'], 'SINGLE_SHOP', '11');
        $component->call('nextStep')->assertSet('step', 4)->assertSeeText('11 WIZ');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_historical_entry_keeps_ordered_at_but_mutable_proposal_uses_current_rule(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->set('entryMode', 'HISTORICAL_MANUAL')->set('orderedAt', now()->subDays(10)->format('Y-m-d\TH:i'))->call('nextStep'), $c);
        $component->call('goToStep', 4)->assertSeeText('7 WIZ')->call('submit')->assertHasNoErrors();
        $this->assertTrue(Order::sole()->ordered_at->isBefore(now()->subDays(9)));
    }

    public function test_atomic_final_creation_redirect_and_duplicate_submit(): void
    {
        $c = $this->context();
        $component = $this->ready($c)->set('notes', 'ملاحظات')->call('submit')->assertHasNoErrors();
        $order = Order::sole();
        $component->assertRedirect(route('orders.workflow', $order))->call('submit')->assertRedirect(route('orders.workflow', $order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame('ملاحظات', $order->notes);
        $this->assertSame('NEW', $order->operational_status->value);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_domain_failure_rolls_back_order_shop_items_history_and_preserves_draft(): void
    {
        $c = $this->context();
        $component = $this->ready($c);
        $groups = $component->get('groups');
        $mock = Mockery::mock(OrderItemService::class);
        $mock->shouldReceive('add')->once()->andThrow(ValidationException::withMessages(['quantity' => 'رفض الخدمة']));
        $this->app->instance(OrderItemService::class, $mock);
        $component->call('submit')->assertHasErrors('quantity')->assertSet('step', 2)->assertSet('groups', $groups);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_shops', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_status_history', 0);
    }

    public function test_product_modal_creates_links_selects_and_preserves_state_without_price(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->set('notes', 'لا تضيع')->call('nextStep'), $c)
            ->call('openProductCreate')->assertSee('aria-modal="true"', false)->set('newProductName', 'منتج جديد')
            ->set('newProductSku', 'NEW')->set('newProductUnitId', (string) $c['unit']->id)->call('createProduct')->assertHasNoErrors()
            ->assertSet('showProductCreate', false)->assertSet('productSearch', 'منتج جديد')->assertSet('notes', 'لا تضيع')->assertSet('step', 2);
        $product = Product::where('name', 'منتج جديد')->sole();
        $component->assertSet('productId', (string) $product->id)->assertSet('unitId', (string) $c['unit']->id);
        $link = ShopProduct::where('product_id', $product->id)->sole();
        $this->assertSame($c['shop']->id, $link->shop_id);
        $this->assertNull($link->listed_price);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_product_modal_duplicate_validation_and_close_preserve_drafts(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $groups = $component->get('groups');
        $component->call('openProductCreate')->set('newProductName', ' حليب ')->set('newProductUnitId', (string) $c['unit']->id)
            ->call('createProduct')->assertHasErrors('newProductName')->assertSet('showProductCreate', true)->assertSet('groups', $groups)
            ->call('closeWizardModal', 'showProductCreate')->assertSet('groups', $groups);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_shop_modal_requires_area_but_type_is_optional_and_stays_in_wizard(): void
    {
        $c = $this->context();
        $component = $this->start($c)->call('nextStep')->call('openShopCreate')->assertSet('newShopAreaId', (string) $c['area']->id)
            ->set('newShopClassification', 'SHOP')->set('newShopName', 'محل جديد')->call('createPermanentShop')->assertHasNoErrors()->assertSet('showShopCreate', false)
            ->assertSet('shopSearch', 'محل جديد')->assertSet('step', 2)->assertSet('customerId', (string) $c['customer']->id);
        $shop = Shop::where('name', 'محل جديد')->sole();
        $component->assertSet('shopId', (string) $shop->id);
        $this->assertNull($shop->shop_type_id);
        $this->assertSame($c['area']->id, $shop->area_id);
    }

    public function test_multi_and_zero_areas_do_not_implicitly_select_or_allow_permanent_shop(): void
    {
        $c = $this->context();
        Area::factory()->for($c['branch'])->create();
        $this->start($c)->call('openShopCreate')->assertSet('newShopAreaId', '')->set('newShopClassification', 'SHOP')->set('newShopName', 'لا يحفظ')
            ->call('createPermanentShop')->assertHasErrors('newShopAreaId');
        Area::where('branch_id', $c['branch']->id)->update(['is_active' => false]);
        $this->start($c)->call('openShopCreate')->assertSee(__('orders.wizard.no_areas'))->set('newShopClassification', 'SHOP')->set('newShopName', 'لا يحفظ')
            ->call('createPermanentShop')->assertHasErrors('newShopAreaId');
        $this->assertDatabaseMissing('shops', ['name' => 'لا يحفظ']);
    }

    public function test_addressless_customer_can_add_address_without_losing_wizard(): void
    {
        $c = $this->context();
        $customer = Customer::create(['name' => 'بلا عنوان', 'created_by' => $c['actor']->id]);
        $customer->phones()->create(['phone' => '0900000000', 'normalized_phone' => '0900000000']);
        $component = $this->start($c)->set('customerId', (string) $customer->id)->assertSet('customerAddressId', '')
            ->call('openAddressCreate')->assertSet('newAddressAreaId', (string) $c['area']->id)->set('newAddressLine', 'عنوان من النافذة')
            ->call('createAddress')->assertHasNoErrors()->assertSet('showAddressCreate', false)->assertSet('source', 'PHONE');
        $component->assertSet('customerAddressId', (string) $customer->addresses()->sole()->id);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_modal_preserves_shop_draft_and_existing_duplicate_acknowledgement(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('goToStep', 1)->call('toggleCustomerCreate');
        $groups = $component->get('groups');
        $component->set('newCustomerName', 'زبون جديد')->set('newCustomerPhone', $c['phone']->phone)
            ->set('newCustomerAddress', 'عنوان جديد')->set('newCustomerAreaId', (string) $c['area']->id)
            ->call('createCustomer')->assertHasErrors('newCustomerPhone')->assertSet('groups', $groups)
            ->set('newCustomerDuplicateAcknowledged', true)->call('createCustomer')->assertHasNoErrors()
            ->assertSet('showCustomerCreate', false)->assertSet('groups', $groups)->assertSet('source', 'PHONE');
        $new = Customer::where('name', 'زبون جديد')->sole();
        $component->assertSet('customerId', (string) $new->id)->assertSet('customerAddressId', (string) $new->addresses()->sole()->id);
    }

    public function test_cross_branch_search_and_direct_shop_customer_address_tampering_are_denied(): void
    {
        $c = $this->context();
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($c['branch']);
        $foreignArea = Area::factory()->for(Branch::factory()->create())->create();
        $hidden = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل مخفي', 'area_id' => $foreignArea->id, 'shop_type_id' => $c['type']->id]);
        $c['actor'] = $employee;
        $this->start($c)->call('nextStep')->set('shopSearch', 'مخفي')->assertDontSee('محل مخفي')->call('selectShop', $hidden->id)->assertNotFound();
        $this->start($c)->call('openAddressCreate')->set('newAddressLine', 'دس عنوان')->set('newAddressAreaId', (string) $foreignArea->id)
            ->call('createAddress')->assertNotFound();
        $foreignCustomer = Customer::create(['name' => 'زبون مخفي']);
        $foreignCustomer->addresses()->create(['area_id' => $foreignArea->id, 'address_line_1' => 'مخفي']);
        $this->start($c)->call('selectCustomer', $foreignCustomer->id)->assertNotFound();
    }

    public function test_modal_permissions_are_enforced_server_side_and_override_remains_secondary(): void
    {
        $c = $this->context();
        $role = Role::create(['code' => 'WIZ_RESTRICTED', 'name' => 'Restricted']);
        $role->permissions()->attach(Permission::whereIn('code', ['orders.create', 'orders.view', 'orders.update', 'delivery_pricing.view'])->pluck('id'));
        $actor = User::factory()->create();
        $actor->roles()->attach($role);
        $actor->branches()->attach($c['branch']);
        $c['actor'] = $actor;
        $this->start($c)->call('openShopCreate')->assertForbidden();
        $this->start($c)->call('openAddressCreate')->assertForbidden();
        $this->group($this->start($c)->call('nextStep'), $c)->call('openProductCreate')->assertForbidden();
        $this->ready($c)->assertDontSee(__('orders.wizard.override'));
    }

    public function test_invalid_quantities_and_inactive_product_unit_are_rejected(): void
    {
        $c = $this->context();
        $component = $this->start($c)->call('nextStep')->call('selectShop', $c['shop']->id)->call('addDraftShop')->call('selectProduct', $c['product']->id);
        $component->set('quantity', '0')->call('addDraftItem')->assertHasErrors('quantity')
            ->set('quantity', '-1')->call('addDraftItem')->assertHasErrors('quantity')
            ->set('quantity', '1.5')->call('addDraftItem')->assertHasErrors('quantity');
        $c['product']->forceFill(['is_active' => false])->save();
        $component->set('quantity', '1')->call('addDraftItem')->assertNotFound();
    }

    public function test_revalidation_prevents_stale_shop_and_address_after_review(): void
    {
        $c = $this->context();
        $component = $this->ready($c);
        $c['address']->forceFill(['is_active' => false])->save();
        $component->call('submit')->assertHasErrors('customerAddressId')->assertSet('step', 1);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_review_is_read_only_and_no_barcode_captain_or_financial_form_is_exposed(): void
    {
        $c = $this->context();
        $this->ready($c)->assertSee('إنشاء الطلب')->assertDontSee('barcode')->assertDontSee('captain_id')
            ->assertDontSee('wire:model="quantity"', false)->assertDontSee('actual_unit_price')->assertDontSee('invoice_id')
            ->assertSee('dir="ltr"', false)->assertSee('sm:grid-cols-2', false)->assertSee('data-order-create-wizard', false);
    }

    public function test_switching_groups_preserves_each_unfinished_item_editor(): void
    {
        $c = $this->context();
        $second = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل ثان', 'area_id' => $c['area']->id, 'shop_type_id' => $c['type']->id]);
        $component = $this->group($this->start($c)->call('nextStep'), $c)->set('productSearch', 'مسودة أولى')->set('quantity', '3');
        $firstKey = $component->get('activeGroup');
        $component->call('selectShop', $second->id)->call('addDraftShop')->assertSet('productId', '')->assertSet('quantity', '1');
        $secondKey = $component->get('activeGroup');
        $component->set('productSearch', 'مسودة ثانية')->set('quantity', '4')->call('editGroup', $firstKey)
            ->assertSet('productSearch', 'مسودة أولى')->assertSet('quantity', '3')->call('editGroup', $secondKey)
            ->assertSet('productSearch', 'مسودة ثانية')->assertSet('quantity', '4');
    }

    public function test_restaurant_classification_is_inferred_from_shop_type(): void
    {
        $c = $this->context();
        $c['type']->update(['business_classification' => 'RESTAURANT']);
        $c['shop']->update(['business_classification' => 'RESTAURANT']);
        $this->rule($c['actor'], $c['area'], $c['area'], $c['currency'], 'SINGLE_RESTAURANT', '9');
        $this->ready($c)->assertSeeText('9 WIZ')->assertDontSee('SINGLE_RESTAURANT')->call('submit')->assertHasNoErrors();
        $this->assertSame('SINGLE_RESTAURANT', Order::sole()->pricing_class->value);
    }

    public function test_removing_second_shop_reverts_preview_to_single_shop_rule(): void
    {
        $c = $this->context();
        $second = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل ثان', 'area_id' => $c['area']->id, 'shop_type_id' => $c['type']->id]);
        $this->rule($c['actor'], $c['area'], $c['area'], $c['currency'], 'MULTI_SHOP', '15');
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $this->group($component, $c, $second)->call('nextStep')->assertSeeText('15 WIZ')->call('goToStep', 2)
            ->call('removeDraftShop', $component->get('activeGroup'))->call('nextStep')->assertSeeText('7 WIZ');
    }

    public function test_current_fee_recalculates_after_rule_replacement_without_creating_an_order(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('nextStep')->assertSeeText('7 WIZ');
        $rule = DeliveryPricingRule::where('from_area_id', $c['area']->id)->sole();
        app(DeliveryPricingRuleService::class)->version($c['actor'], $rule, [
            'from_area_id' => $c['area']->id, 'to_area_id' => $c['area']->id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '13', 'effective_from' => now(),
        ]);
        $component->call('$refresh')->assertSeeText('13 WIZ');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_pending_lock_prevents_a_second_submit_and_allows_retry_after_release(): void
    {
        $c = $this->context();
        $component = $this->ready($c);
        $lock = Cache::lock('order-wizard:'.$c['actor']->id.':'.$component->get('submissionKey').':lock', 60);
        $this->assertTrue($lock->get());
        $component->call('submit')->assertHasErrors('wizard');
        $this->assertDatabaseCount('orders', 0);
        $lock->release();
        $component->call('submit')->assertHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_category_and_unit_failures_keep_product_dialog_and_all_drafts(): void
    {
        $c = $this->context();
        $category = Category::create(['name' => 'تصنيف مغلق']);
        $category->forceFill(['is_active' => false])->save();
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('openProductCreate')
            ->set('newProductName', 'غير صالح')->set('newProductUnitId', (string) $c['unit']->id)->set('newProductCategoryId', (string) $category->id)
            ->call('createProduct')->assertHasErrors('newProductCategoryId')->assertSet('showProductCreate', true);
        $c['unit']->forceFill(['is_active' => false])->save();
        $component->set('newProductCategoryId', '')->call('createProduct')->assertHasErrors('newProductUnitId');
        $this->assertDatabaseMissing('products', ['name' => 'غير صالح']);
    }

    public function test_no_shop_types_is_truthful_and_optional_without_inventing_a_type(): void
    {
        $c = $this->context();
        $c['type']->forceFill(['is_active' => false])->save();
        $this->start($c)->call('openShopCreate')->assertSee(__('orders.wizard.no_types'))->set('newShopClassification', 'SHOP')->set('newShopName', 'بدون نوع')
            ->call('createPermanentShop')->assertHasNoErrors();
        $this->assertNull(Shop::where('name', 'بدون نوع')->sole()->shop_type_id);
        $this->assertDatabaseCount('shop_types', 1);
    }

    public function test_one_time_item_does_not_persist_a_catalog_product_or_guessed_price(): void
    {
        $c = $this->context();
        $component = $this->start($c)->call('nextStep')->call('selectShop', $c['shop']->id)->call('addDraftShop')
            ->set('itemType', 'ONE_TIME_ITEM')->set('itemName', 'غرض لمرة واحدة')->set('unitId', (string) $c['unit']->id)
            ->call('addDraftItem')->call('goToStep', 4)->call('submit')->assertHasNoErrors();
        $item = Order::sole()->shops()->sole()->items()->sole();
        $this->assertNull($item->product_id);
        $this->assertNull($item->actual_unit_price);
        $this->assertNull($item->estimated_unit_price);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_mobile_markup_uses_wrapping_steps_and_single_field_instances(): void
    {
        $c = $this->context();
        $html = $this->start($c)->html();
        $this->assertStringContainsString('grid-cols-2', $html);
        $this->assertStringContainsString('sm:grid-cols-4', $html);
        $this->assertSame(1, substr_count($html, 'name="customer_id"'));
        $this->assertStringNotContainsString('data-order-create-select2', $html);
        $this->assertStringNotContainsString('min-w-[', $html);
        $this->assertStringNotContainsString('barcode', $html);
    }

    public function test_branch_switch_preserves_drafts_but_invalid_group_can_be_removed_and_replaced(): void
    {
        $c = $this->context();
        $secondBranch = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        $secondArea = Area::factory()->for($secondBranch)->create();
        $secondAddress = $c['customer']->addresses()->create(['area_id' => $secondArea->id, 'address_line_1' => 'عنوان الفرع الثاني']);
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $oldKey = $component->get('activeGroup');
        $component->set('branchId', (string) $secondBranch->id)->assertSet('step', 1)
            ->assertSet('customerAddressId', (string) $secondAddress->id)->call('nextStep')->assertSet('step', 2)
            ->assertSee(__('domain.the_shop_is_outside_the_order_branch'))->call('nextStep')->assertHasErrors('groups.'.$oldKey)
            ->call('removeDraftShop', $oldKey)->assertSet('groups', [])->assertSet('source', 'PHONE');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_create_route_renders_four_arabic_wizard_steps_without_execution_controls(): void
    {
        $c = $this->context();
        $this->actingAs($c['actor'])->get(route('orders.create'))->assertOk()
            ->assertSee('data-order-create-wizard', false)->assertSee('الزبون والعنوان')->assertSee('المحلات والمنتجات')
            ->assertSee('التوصيل')->assertSee('المراجعة والإنشاء')->assertDontSee('barcode')->assertDontSee('captain_id');
    }

    public function test_forged_contacts_source_and_future_historical_time_cannot_advance(): void
    {
        $c = $this->context();
        $other = Customer::create(['name' => 'زبون ثان']);
        $phone = $other->phones()->create(['phone' => '0999111222', 'normalized_phone' => '0999111222']);
        $this->start($c)->set('customerPhoneId', (string) $phone->id)->call('nextStep')->assertHasErrors('customerPhoneId')->assertSet('step', 1);
        $this->start($c)->set('source', 'MOBILE_APP')->call('nextStep')->assertHasErrors('source')->assertSet('step', 1);
        $this->start($c)->set('entryMode', 'HISTORICAL_MANUAL')->set('orderedAt', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('nextStep')->assertHasErrors('orderedAt')->assertSet('step', 1);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_bundled_contact_updates_then_immediate_next_preserve_the_authoritative_draft(): void
    {
        $c = $this->context();
        $c['customer']->phones()->create(['phone' => '0990000000', 'normalized_phone' => '0990000000']);
        $c['customer']->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'عنوان بديل']);
        $component = Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->update(calls: [['method' => 'nextStep', 'params' => [], 'path' => '']], updates: ['branchId' => (string) $c['branch']->id, 'customerId' => (string) $c['customer']->id,
                'customerPhoneId' => (string) $c['phone']->id, 'customerAddressId' => (string) $c['address']->id,
                'source' => 'PHONE', 'notes' => 'مسودة فورية'])
            ->assertHasNoErrors()->assertSet('step', 2);
        $this->group($component, $c)->call('nextStep')->assertHasNoErrors()->assertSet('step', 3);
        $groups = $component->get('groups');
        $component->call('nextStep')->assertHasNoErrors()->assertSet('step', 4)
            ->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerPhoneId', (string) $c['phone']->id)
            ->assertSet('customerAddressId', (string) $c['address']->id)->assertSet('groups', $groups)
            ->assertSee('حليب')->assertSee('مسودة فورية');
        foreach ([1, 2, 3, 2, 3, 4] as $step) {
            $component->call('goToStep', $step)->assertHasNoErrors()->assertSet('step', $step)->assertSet('groups', $groups)
                ->assertSet('customerAddressId', (string) $c['address']->id)->assertSet('source', 'PHONE');
        }
        $component->call('submit')->assertHasNoErrors()->assertRedirect();
        $order = Order::sole();
        $this->assertSame($c['customer']->id, $order->customer_id);
        $this->assertSame($c['address']->id, $order->customer_address_id);
        $this->assertSame('PHONE', $order->source->value);
        $this->assertSame($c['product']->id, $order->shops()->sole()->items()->sole()->product_id);
    }

    public function test_arabic_search_never_pins_an_unrelated_selected_customer(): void
    {
        $c = $this->context();
        $c['customer']->update(['name' => 'أمير الحموي']);
        $match = Customer::create(['name' => 'انس']);
        $match->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'عنوان انس']);
        $component = $this->start($c)->set('customerSearch', 'انس');
        $this->assertSame([$match->id], $component->instance()->customers->pluck('id')->all());
        $component->assertSee('data-search-query="انس"', false)->assertSee('parentElement.dataset.searchQuery', false);
        $component->set('customerSearch', 'لا يوجد');
        $this->assertCount(0, $component->instance()->customers);
        $component->set('customerSearch', '4111');
        $this->assertSame([$c['customer']->id], $component->instance()->customers->pluck('id')->all());
    }

    public function test_modal_cancellation_preserves_all_contacts_and_groups(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $groups = $component->get('groups');
        $component->call('toggleCustomerCreate')->call('closeCustomerCreate');
        foreach (['openShopCreate' => 'showShopCreate', 'openProductCreate' => 'showProductCreate', 'openAddressCreate' => 'showAddressCreate'] as $open => $modal) {
            $component->call($open)->call('closeWizardModal', $modal)->assertSet('groups', $groups)
                ->assertSet('customerId', (string) $c['customer']->id)
                ->assertSet('customerPhoneId', (string) $c['phone']->id)
                ->assertSet('customerAddressId', (string) $c['address']->id)->assertSet('source', 'PHONE');
        }
    }

    public function test_known_restaurant_is_readonly_and_untyped_new_shop_keeps_order_classification(): void
    {
        $c = $this->context();
        $c['type']->update(['business_classification' => 'RESTAURANT']);
        $c['shop']->update(['business_classification' => 'RESTAURANT']);
        $component = $this->start($c)->call('nextStep')->call('selectShop', $c['shop']->id)
            ->assertSet('businessClassification', 'RESTAURANT')->assertSee('مطعم')
            ->assertDontSee('wire:model="businessClassification"', false);
        $c['type']->forceFill(['is_active' => false])->save();
        $component->call('openShopCreate')->assertSee(__('orders.wizard.no_types'))
            ->assertDontSee('wire:model="newShopTypeId"', false)->assertSee('wire:model="newShopClassification"', false)
            ->set('newShopClassification', 'SHOP')->set('newShopName', 'مطعم دائم')->set('newShopClassification', 'RESTAURANT')
            ->call('createPermanentShop')->assertHasNoErrors()->assertSet('businessClassification', 'RESTAURANT')
            ->call('addDraftShop')->assertHasNoErrors();
        $shop = Shop::where('name', 'مطعم دائم')->sole();
        $this->assertSame('RESTAURANT', $shop->business_classification->value);
        $this->assertNull($shop->shop_type_id);
        $this->assertSame('RESTAURANT', array_values($component->get('groups'))[0]['business_classification']);
        $this->assertDatabaseCount('shop_types', 1);
    }

    public function test_required_source_is_visible_and_validation_routes_only_view_without_reset(): void
    {
        $c = $this->context();
        $component = $this->ready($c);
        $groups = $component->get('groups');
        $component->set('source', '')->call('submit')->assertHasErrors('source')->assertSet('step', 1)
            ->assertSet('groups', $groups)->assertSet('customerId', (string) $c['customer']->id)
            ->assertSee('name="source"', false)->assertSee('value="WHATSAPP"', false)
            ->assertSee('value="PHONE"', false)->assertSee('value="MANUAL"', false);
        $component->set('source', 'PHONE')->call('goToStep', 4);
        $c['product']->forceFill(['is_active' => false])->save();
        $component->call('submit')->assertSet('step', 2)->assertSet('groups', $groups)
            ->assertSet('customerAddressId', (string) $c['address']->id)->assertSet('source', 'PHONE');
    }

    public function test_search_selection_and_immediate_actions_in_one_request_are_repeatable(): void
    {
        $c = $this->context();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $component = Livewire::actingAs($c['actor'])->test(CreateOrder::class)->set('branchId', (string) $c['branch']->id);
            $component->update(updates: ['customerSearch' => 'أمير', 'source' => 'PHONE'], calls: [
                ['method' => 'selectCustomer', 'params' => [$c['customer']->id], 'path' => ''],
                ['method' => 'nextStep', 'params' => [], 'path' => ''],
            ])->assertHasNoErrors()->assertSet('step', 2);
            $component->update(updates: ['quantity' => '2'], calls: [
                ['method' => 'selectShop', 'params' => [$c['shop']->id], 'path' => ''],
                ['method' => 'addDraftShop', 'params' => [], 'path' => ''],
                ['method' => 'selectProduct', 'params' => [$c['product']->id], 'path' => ''],
                ['method' => 'addDraftItem', 'params' => [], 'path' => ''],
                ['method' => 'nextStep', 'params' => [], 'path' => ''],
            ])->assertHasNoErrors()->assertSet('step', 3)->assertSeeText('7 WIZ');
            $groups = $component->get('groups');
            $component->call('nextStep')->assertHasNoErrors()->assertSet('step', 4)->assertSet('groups', $groups)
                ->assertSet('customerId', (string) $c['customer']->id)->assertSee('حليب');
            // A delayed search-only update cannot change the selected contact or draft.
            $component->update(updates: ['customerSearch' => 'قديم'])->assertSet('customerId', (string) $c['customer']->id)
                ->assertSet('customerAddressId', (string) $c['address']->id)->assertSet('groups', $groups)->assertSee('أمير');
        }
    }

    public function test_step_two_next_with_repeated_branch_update_does_not_rewind(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $groups = $component->get('groups');
        $component->update(updates: ['branchId' => (string) $c['branch']->id], calls: [
            ['method' => 'nextStep', 'params' => [], 'path' => ''],
        ])->assertHasNoErrors()->assertSet('step', 3)->assertSet('groups', $groups)
            ->assertSet('customerAddressId', (string) $c['address']->id)->assertSet('source', 'PHONE');
    }

    public function test_legacy_master_blocker_and_correction_preserve_draft_and_prevent_operator_override(): void
    {
        $c = $this->context();
        $c['shop']->forceFill(['business_classification' => null])->save();
        $component = $this->start($c)->call('nextStep')->call('selectShop', $c['shop']->id)
            ->assertSee(__('orders.wizard.missing_master_classification'))
            ->assertDontSee('wire:model="businessClassification"', false)
            ->set('businessClassification', 'RESTAURANT')->call('addDraftShop')->assertHasErrors('shopId')
            ->assertSet('step', 2)->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerAddressId', (string) $c['address']->id)->assertSet('source', 'PHONE');
        $this->actingAs($c['actor'])->put(route('shops.update', $c['shop']), [
            'name' => $c['shop']->name, 'area_id' => $c['area']->id, 'business_classification' => 'SHOP',
            'is_mobile_visible' => false, 'accepts_online_orders' => false,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $component->call('selectShop', $c['shop']->id)->assertSet('businessClassification', 'SHOP')
            ->assertDontSee('wire:model="businessClassification"', false)
            ->set('businessClassification', 'RESTAURANT')->call('addDraftShop')->assertHasErrors('shopId');
        $component->call('selectShop', $c['shop']->id)->call('addDraftShop')->call('selectProduct', $c['product']->id)
            ->call('addDraftItem')->call('nextStep')->assertHasNoErrors()->assertSet('step', 3)
            ->call('nextStep')->assertSet('step', 4)->call('submit')->assertHasNoErrors();
        $this->assertSame('SHOP', Order::sole()->shops()->sole()->business_classification_snapshot->value);
    }

    public function test_normal_shop_create_persists_classification_independent_of_type(): void
    {
        $c = $this->context();
        $this->actingAs($c['actor'])->post(route('shops.store'), [
            'name' => 'مطعم رئيسي', 'area_id' => $c['area']->id, 'shop_type_id' => $c['type']->id,
            'business_classification' => 'RESTAURANT', 'is_mobile_visible' => false, 'accepts_online_orders' => false,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $shop = Shop::where('name', 'مطعم رئيسي')->sole();
        $this->assertSame('RESTAURANT', $shop->business_classification->value);
        $this->assertSame('SHOP', $shop->type->business_classification->value);
    }

    public function test_shop_master_database_constraint_rejects_unknown_code_without_guessing_legacy_values(): void
    {
        $c = $this->context();
        $c['shop']->forceFill(['business_classification' => null])->save();
        $this->assertNull($c['shop']->fresh()->business_classification);
        DB::beginTransaction();
        try {
            DB::update('UPDATE shops SET business_classification = ? WHERE id = ?', ['UNKNOWN', $c['shop']->id]);
            $this->fail('Unsupported master classification was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_empty_active_shop_types_hide_optional_controls_without_hiding_master_classification(): void
    {
        $c = $this->context();
        $c['type']->forceFill(['is_active' => false])->save();
        foreach ([route('shops.create'), route('shops.edit', $c['shop'])] as $url) {
            $this->actingAs($c['actor'])->get($url)->assertOk()
                ->assertDontSee('name="shop_type_id"', false)->assertSee('name="business_classification"', false);
        }
        $this->start($c)->call('openShopCreate')->assertDontSee('wire:model="newShopTypeId"', false)
            ->assertSee('wire:model="newShopClassification"', false);
        $this->ready($c)->call('submit')->assertHasNoErrors();
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => Order::sole()])
            ->set('shopType', 'CREATE_PERMANENT')->assertDontSee('wire:model="newShopTypeId"', false)
            ->assertSee('wire:model="newShopClassification"', false);
        $this->assertSame($c['type']->id, $c['shop']->fresh()->shop_type_id);
    }

    public function test_u3_shared_visuals_preserve_draft_and_explicit_navigation_actions(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $groups = $component->get('groups');
        $component->assertSee('mary-surface order-ux', false)->assertSee('data-wizard-selection-summary', false)
            ->assertSee('data-draft-total-unavailable', false)->assertSee(__('order_design.draft_prices'))
            ->call('nextStep')->call('nextStep')->assertSet('step', 4)->assertSet('groups', $groups)
            ->call('goToStep', 1)->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('groups', $groups)->call('goToStep', 4)->assertSet('groups', $groups);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//nav[@data-order-stepper]//button[@aria-current="step"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-wizard-navigation]//button[@type="button" and @*[name()="wire:click"]="submit"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-order-create-wizard]')->length);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_u3_translation_catalog_and_existing_keyboard_modal_are_preserved(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Arr::dot(require lang_path('ar/order_design.php'))), array_keys(Arr::dot(require lang_path('en/order_design.php'))));
        $c = $this->context();
        $component = $this->start($c)->call('toggleCustomerCreate')->assertSee('aria-modal="true"', false)
            ->assertSee('x-trap.inert.noscroll="true"', false)->assertSee('x-on:keydown.escape.stop', false)
            ->call('closeCustomerCreate')->assertSet('customerId', (string) $c['customer']->id);
        $component->call('nextStep')->assertSet('step', 2);
    }

    public function test_branch_change_with_an_unadded_shop_does_not_break_the_next_step_render(): void
    {
        $c = $this->context();
        $otherBranch = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        $otherArea = Area::factory()->for($otherBranch)->create();
        $otherAddress = $c['customer']->addresses()->create(['area_id' => $otherArea->id, 'address_line_1' => 'Other branch']);
        $component = $this->group($this->start($c)->call('nextStep'), $c)
            ->call('selectProduct', $c['product']->id)->set('quantity', '4')
            ->call('selectShop', $c['shop']->id);
        $groups = $component->get('groups');
        $component->set('branchId', (string) $otherBranch->id)->assertSet('step', 1)
            ->assertSet('customerAddressId', (string) $otherAddress->id)
            ->call('nextStep')->assertStatus(200)->assertSet('step', 2)
            ->assertSet('groups', $groups)->assertSet('quantity', '4')
            ->assertSet('shopId', '')->assertSet('shopSearch', '')
            ->assertSet('customerId', (string) $c['customer']->id)
            ->assertSee(__('domain.the_shop_is_outside_the_order_branch'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_bundled_contact_hook_validation_returns_to_its_step_without_erasing_review_draft(): void
    {
        $c = $this->context();
        $component = $this->ready($c);
        $groups = $component->get('groups');
        $review = $component->get('reviewedDelivery');
        $component->update(updates: ['source' => 'MOBILE_APP', 'notes' => 'Retained on validation'])
            ->assertHasErrors('source')->assertSet('step', 1)
            ->assertSet('groups', $groups)->assertSet('reviewedDelivery', $review)
            ->assertSet('notes', 'Retained on validation')
            ->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerPhoneId', (string) $c['phone']->id)
            ->assertSet('customerAddressId', (string) $c['address']->id);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $component->set('source', 'PHONE')->call('goToStep', 4)->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_unchanged_branch_retains_unadded_shop_and_inline_area_drafts(): void
    {
        $c = $this->context();
        $component = $this->group($this->start($c)->call('nextStep'), $c)
            ->call('selectShop', $c['shop']->id)->call('selectProduct', $c['product']->id)
            ->set('quantity', '3')->set('newShopAreaId', (string) $c['area']->id)
            ->set('newAddressAreaId', (string) $c['area']->id);
        $groups = $component->get('groups');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->update(updates: ['branchId' => (string) $c['branch']->id], calls: [
                ['method' => 'goToStep', 'params' => [1], 'path' => ''],
                ['method' => 'nextStep', 'params' => [], 'path' => ''],
            ])->assertSet('step', 2)->assertHasNoErrors()
                ->assertSet('shopId', (string) $c['shop']->id)->assertSet('businessClassification', 'SHOP')
                ->assertSet('groups', $groups)->assertSet('quantity', '3')
                ->assertSet('productId', (string) $c['product']->id)
                ->assertSet('newShopAreaId', (string) $c['area']->id)
                ->assertSet('newAddressAreaId', (string) $c['area']->id);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_real_branch_change_preserves_one_time_shop_text_and_clears_only_old_area_references(): void
    {
        $c = $this->context();
        $other = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        Area::factory()->for($other)->create();
        $component = $this->start($c)->call('nextStep')->set('shopType', 'ONE_TIME')
            ->set('shopName', 'Pending shop')->set('shopPhone', '0951234567')
            ->set('shopAddress', 'Pending address')->set('businessClassification', 'RESTAURANT')
            ->set('originAreaId', (string) $c['area']->id)->set('newShopAreaId', (string) $c['area']->id)
            ->set('newAddressAreaId', (string) $c['area']->id);
        $component->set('branchId', (string) $other->id)->assertSet('step', 1)
            ->assertSet('shopName', 'Pending shop')->assertSet('shopPhone', '0951234567')
            ->assertSet('shopAddress', 'Pending address')->assertSet('businessClassification', 'RESTAURANT')
            ->assertSet('originAreaId', '')->assertSet('newShopAreaId', '')->assertSet('newAddressAreaId', '')
            ->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerPhoneId', (string) $c['phone']->id)->assertSet('customerAddressId', '');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_immediate_add_and_next_keep_multi_shop_quantities_through_review_and_atomic_creation(): void
    {
        $c = $this->context();
        $second = Shop::create(['name' => 'Second', 'business_classification' => 'SHOP', 'area_id' => $c['area']->id]);
        $this->rule($c['actor'], $c['area'], $c['area'], $c['currency'], 'MULTI_SHOP', '11');
        $component = $this->group($this->start($c)->call('nextStep'), $c);
        $component->call('selectShop', $second->id)->call('addDraftShop')->call('selectProduct', $c['product']->id)
            ->update(updates: ['quantity' => '4'], calls: [
                ['method' => 'addDraftItem', 'params' => [], 'path' => ''],
                ['method' => 'nextStep', 'params' => [], 'path' => ''],
            ])->assertHasNoErrors()->assertSet('step', 3);
        $groups = $component->get('groups');
        $this->assertSame(['1', '4'], array_column(array_merge(...array_column($groups, 'items')), 'quantity'));
        foreach ([4, 2, 1, 3, 4] as $step) {
            $component->call('goToStep', $step)->assertHasNoErrors()->assertSet('groups', $groups)
                ->call('$refresh')->assertSet('step', $step)->assertSet('groups', $groups);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $component->call('submit')->assertHasNoErrors();
        $order = Order::sole();
        $this->assertSame(2, $order->shops()->count());
        $this->assertSame(['1.0000', '4.0000'], $order->shops()->orderBy('id')->get()
            ->flatMap(fn ($shop) => $shop->items()->pluck('current_quantity'))->all());
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_open_inline_modal_keeps_its_text_and_reselects_the_new_branch_sole_area(): void
    {
        $c = $this->context();
        $other = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        $area = Area::factory()->for($other)->create();
        $component = $this->group($this->start($c)->call('nextStep'), $c)->call('openShopCreate')
            ->set('newShopName', 'Inline pending shop')->set('newShopClassification', 'SHOP');
        $groups = $component->get('groups');
        $component->set('branchId', (string) $other->id)->assertSet('showShopCreate', true)
            ->assertSet('newShopName', 'Inline pending shop')->assertSet('newShopAreaId', (string) $area->id)
            ->assertSet('groups', $groups)->call('createPermanentShop')->assertHasNoErrors();
        $shop = Shop::where('name', 'Inline pending shop')->sole();
        $this->assertSame($area->id, $shop->area_id);
        $component->call('openAddressCreate')->set('newAddressLine', 'Inline pending address')
            ->set('branchId', (string) $c['branch']->id)->assertSet('showAddressCreate', true)
            ->assertSet('newAddressLine', 'Inline pending address')
            ->assertSet('newAddressAreaId', (string) $c['area']->id)
            ->assertSet('groups', $groups)->call('createAddress')->assertHasNoErrors();
        $this->assertSame($c['area']->id, $c['customer']->addresses()->where('address_line_1', 'Inline pending address')->sole()->area_id);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }
}
