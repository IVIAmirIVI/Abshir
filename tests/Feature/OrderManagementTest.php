<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainAssignmentType;
use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemChangeType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingStatus;
use App\Enums\PriceObservationSource;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderCaptainService;
use App\Services\OrderItemService;
use App\Services\OrderService;
use App\Services\OrderShopService;
use App\Services\OrderStatusService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $role = 'ORDER_EMPLOYEE'): array
    {
        $this->seed(AuthorizationSeeder::class);
        $suffix = (string) (Unit::query()->count() + 1);
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create(['name' => 'Original Area']);
        $customer = Customer::create(['name' => 'Original Customer']);
        $phone = $customer->phones()->create(['phone' => '+963 111', 'normalized_phone' => '963111']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Original street']);
        $unit = Unit::create(['name' => 'Piece '.$suffix, 'code' => 'U'.$suffix, 'allows_decimal' => false]);
        $currency = Currency::create(['name' => 'Dollar '.$suffix, 'code' => 'C'.$suffix]);
        $product = Product::create(['name' => 'Original Product', 'default_unit_id' => $unit->id]);
        $shop = Shop::create(['name' => 'Registered Shop', 'area_id' => $area->id]);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', $role)->sole());
        $actor->branches()->attach($branch);

        return compact('branch', 'area', 'customer', 'phone', 'address', 'unit', 'currency', 'product', 'shop', 'actor');
    }

    private function order(array $c): Order
    {
        return app(OrderService::class)->create($c['actor'], ['branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id, 'customer_phone_id' => $c['phone']->id, 'customer_address_id' => $c['address']->id, 'ordered_at' => now()->subDay()]);
    }

    public function test_creation_preserves_customer_snapshots_and_initial_history(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $c['customer']->update(['name' => 'Changed']);
        $c['phone']->update(['phone' => '999']);
        $c['address']->update(['address_line_1' => 'Changed']);
        $c['area']->update(['name' => 'Changed']);
        $this->assertStringStartsWith('ORD-', $order->order_number);
        $this->assertSame('Original Customer', $order->customer_name_snapshot);
        $this->assertSame('+963 111', $order->customer_phone_snapshot);
        $this->assertSame('Original street', $order->address_line_1_snapshot);
        $this->assertSame('Original Area', $order->area_name_snapshot);
        $this->assertSame(OrderOperationalStatus::New, $order->operational_status);
        $this->assertCount(1, $order->statusHistory);
        $this->assertFalse($order->ordered_at->equalTo($order->created_at));
    }

    public function test_registered_and_one_time_shops_items_and_price_snapshots_work(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $ss = app(OrderShopService::class);
        $registered = $ss->add($c['actor'], $order, ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $shopCount = Shop::count();
        $one = $ss->add($c['actor'], $order, ['shop_type' => 'ONE_TIME', 'shop_name' => 'Exceptional']);
        $productCount = Product::count();
        ProductPriceHistory::create(['product_id' => $c['product']->id, 'shop_id' => $c['shop']->id, 'unit_id' => $c['unit']->id, 'currency_id' => $c['currency']->id, 'price' => '12.5000', 'observed_at' => now(), 'source' => PriceObservationSource::ShopConfirmed]);
        $is = app(OrderItemService::class);
        $item = $is->add($c['actor'], $order, $registered, ['item_type' => 'CATALOG_ITEM', 'product_id' => $c['product']->id, 'unit_id' => $c['unit']->id, 'quantity' => '2', 'estimated_currency_id' => $c['currency']->id]);
        $manual = $is->add($c['actor'], $order, $one, ['item_type' => 'ONE_TIME_ITEM', 'item_name' => 'Rare part', 'unit_id' => $c['unit']->id, 'quantity' => '1', 'estimated_currency_id' => $c['currency']->id, 'manual_estimated_unit_price' => '3.0000']);
        $this->assertSame($shopCount, Shop::count());
        $this->assertSame($productCount, Product::count());
        $this->assertSame('12.5000', $item->estimated_unit_price);
        $this->assertNotNull($item->estimated_source_observed_at);
        $this->assertSame('MANUAL_ESTIMATE', $manual->estimated_price_method->value);
        $source = $item->estimated_source_price_observation_id;
        $is->confirmActual($c['actor'], $order, $registered, $item, ['actual_unit_price' => '13.0000', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => ActualPriceSource::ShopConfirmed->value]);
        $this->assertSame($source, $item->fresh()->estimated_source_price_observation_id);
        $this->assertSame(OrderPricingStatus::PartiallyConfirmed, $order->fresh()->pricing_status);
        $is->confirmActual($c['actor'], $order, $one, $manual, ['actual_unit_price' => '4.0000', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => ActualPriceSource::ManualVerified->value]);
        $this->assertSame(OrderPricingStatus::Confirmed, $order->fresh()->pricing_status);
    }

    public function test_quantity_change_and_status_transition_preserve_history(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $os = app(OrderShopService::class)->add($c['actor'], $order, ['shop_type' => 'ONE_TIME', 'shop_name' => 'Shop']);
        $is = app(OrderItemService::class);
        $item = $is->add($c['actor'], $order, $os, ['item_type' => 'ONE_TIME_ITEM', 'item_name' => 'Item', 'unit_id' => $c['unit']->id, 'quantity' => '2']);
        $is->change($c['actor'], $order, $os, $item, ['change_type' => OrderItemChangeType::Quantity->value, 'quantity' => '3', 'reason' => 'Approved']);
        $this->assertSame('2.0000', $item->fresh()->requested_quantity);
        $this->assertSame('3.0000', $item->fresh()->current_quantity);
        $this->assertCount(1, $item->changes);
        $statuses = app(OrderStatusService::class);
        $statuses->transition($c['actor'], $order, OrderOperationalStatus::Confirmed, 'Confirmed');
        $this->assertCount(2, $order->statusHistory);
        $this->expectException(ValidationException::class);
        $statuses->transition($c['actor'], $order, OrderOperationalStatus::Delivered, null);
    }

    public function test_primary_and_support_captains_share_order_and_switch_preserves_history(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $make = function () use ($c) {
            $u = User::factory()->create();
            $cap = Captain::create(['user_id' => $u->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => CaptainOperationalStatus::Available]);
            $cap->employmentPeriods()->create(['started_at' => now()->subMonth()]);

            return $cap;
        };
        $a = $make();
        $b = $make();
        $service = app(OrderCaptainService::class);
        $primary = $service->assign($c['actor'], $order, $a, OrderCaptainRole::PrimaryCollection);
        $support = $service->assign($c['actor'], $order, $b, OrderCaptainRole::Support);
        $new = $service->switchPrimary($c['actor'], $order, $b, 'Handoff');
        $this->assertSame(OrderCaptainRole::PrimaryCollection, $new->assignment_role);
        $this->assertNotNull($primary->fresh()->removed_at);
        $this->assertNotNull($support->fresh()->removed_at);
        $this->assertStringContainsString('Handoff', $primary->fresh()->removal_reason);
        $this->assertStringContainsString('Handoff', $support->fresh()->removal_reason);
        $this->assertCount(1, $order->captainAssignments()->whereNull('removed_at')->get());
        $this->assertSame(1, $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->count());
    }

    public function test_support_cannot_be_assigned_first_and_primary_with_support_cannot_be_removed(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $makeCaptain = function () use ($c): Captain {
            $user = User::factory()->create();
            $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => CaptainOperationalStatus::Available]);
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

            return $captain;
        };
        $primaryCaptain = $makeCaptain();
        $supportCaptain = $makeCaptain();
        $service = app(OrderCaptainService::class);

        try {
            $service->assign($c['actor'], $order, $supportCaptain, OrderCaptainRole::Support);
            $this->fail('Support Captain was assigned without a primary.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $primary = $service->assign($c['actor'], $order, $primaryCaptain, OrderCaptainRole::PrimaryCollection);
        $support = $service->assign($c['actor'], $order, $supportCaptain, OrderCaptainRole::Support);

        try {
            $service->remove($c['actor'], $order, $primary, 'Direct removal');
            $this->fail('Primary Captain was removed while Support remained active.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($primary->fresh()->removed_at);
        $this->assertNull($support->fresh()->removed_at);
    }

    public function test_only_active_primary_can_be_removed_while_history_is_preserved(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $service = app(OrderCaptainService::class);
        $assignment = $service->assign($c['actor'], $order, $captain, OrderCaptainRole::PrimaryCollection);

        $service->remove($c['actor'], $order, $assignment, 'No longer needed');

        $this->assertNotNull($assignment->fresh()->removed_at);
        $this->assertSame($c['actor']->id, $assignment->fresh()->removed_by);
        $this->assertSame('No longer needed', $assignment->fresh()->removal_reason);
        $this->assertSame(0, $order->captainAssignments()->whereNull('removed_at')->count());
    }

    public function test_permissions_no_delete_and_no_financial_schema(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        foreach (['orders.view', 'orders.create', 'orders.update', 'orders.status.update', 'orders.pricing.update', 'orders.captains.assign', 'orders.captains.remove', 'orders.items.change'] as $p) {
            $this->assertTrue($c['actor']->hasPermission($p));
        }$captain = $this->context('CAPTAIN')['actor'];
        $this->assertCount(0, $captain->roles->first()->permissions()->where('code', 'like', 'orders.%')->get());
        $this->actingAs($c['actor'])->delete('/orders/'.$order->id)->assertStatus(405);
        foreach (['invoices', 'payments', 'ledgers', 'commissions', 'incentives'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_order_rejects_a_phone_owned_by_another_customer(): void
    {
        $c = $this->context();
        $other = Customer::create(['name' => 'Other Customer']);
        $otherPhone = $other->phones()->create(['phone' => '222', 'normalized_phone' => '222']);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->create($c['actor'], [
            'branch_id' => $c['branch']->id,
            'customer_id' => $c['customer']->id,
            'customer_phone_id' => $otherPhone->id,
            'customer_address_id' => $c['address']->id,
            'ordered_at' => now(),
        ]);
    }

    public function test_order_rejects_an_address_owned_by_another_customer(): void
    {
        $c = $this->context();
        $other = Customer::create(['name' => 'Other Customer']);
        $otherAddress = $other->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'Other street']);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->create($c['actor'], [
            'branch_id' => $c['branch']->id,
            'customer_id' => $c['customer']->id,
            'customer_phone_id' => $c['phone']->id,
            'customer_address_id' => $otherAddress->id,
            'ordered_at' => now(),
        ]);
    }

    public function test_order_rejects_an_inaccessible_branch(): void
    {
        $c = $this->context();
        $foreignBranch = Branch::factory()->create();

        $this->expectException(HttpException::class);
        app(OrderService::class)->create($c['actor'], [
            'branch_id' => $foreignBranch->id,
            'customer_id' => $c['customer']->id,
            'customer_phone_id' => $c['phone']->id,
            'customer_address_id' => $c['address']->id,
            'ordered_at' => now(),
        ]);
    }

    public function test_item_snapshots_and_unit_decimal_rules_are_enforced(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $orderShop = app(OrderShopService::class)->add($c['actor'], $order, ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $service = app(OrderItemService::class);
        $item = $service->add($c['actor'], $order, $orderShop, ['item_type' => 'CATALOG_ITEM', 'product_id' => $c['product']->id, 'unit_id' => $c['unit']->id, 'quantity' => '2']);
        $c['product']->update(['name' => 'Renamed Product']);
        $c['unit']->update(['name' => 'Renamed Unit']);

        $this->assertSame('Original Product', $item->product_name_snapshot);
        $this->assertStringStartsWith('Piece ', $item->unit_name_snapshot);

        $this->expectException(ValidationException::class);
        $service->add($c['actor'], $order, $orderShop, ['item_type' => 'ONE_TIME_ITEM', 'item_name' => 'Fractional', 'unit_id' => $c['unit']->id, 'quantity' => '2.5']);
    }

    public function test_unknown_shop_estimate_is_snapshotted_and_later_observations_do_not_change_it(): void
    {
        $c = $this->context();
        ProductPriceHistory::create(['product_id' => $c['product']->id, 'shop_id' => $c['shop']->id, 'unit_id' => $c['unit']->id, 'currency_id' => $c['currency']->id, 'price' => '9.0000', 'observed_at' => now()->subHour(), 'source' => PriceObservationSource::ShopConfirmed]);
        $order = $this->order($c);
        $orderShop = app(OrderShopService::class)->add($c['actor'], $order, ['shop_type' => 'ONE_TIME', 'shop_name' => 'Unknown']);
        $item = app(OrderItemService::class)->add($c['actor'], $order, $orderShop, ['item_type' => 'CATALOG_ITEM', 'product_id' => $c['product']->id, 'unit_id' => $c['unit']->id, 'quantity' => '1', 'estimated_currency_id' => $c['currency']->id]);
        ProductPriceHistory::create(['product_id' => $c['product']->id, 'shop_id' => $c['shop']->id, 'unit_id' => $c['unit']->id, 'currency_id' => $c['currency']->id, 'price' => '4.0000', 'observed_at' => now(), 'source' => PriceObservationSource::ShopConfirmed]);

        $this->assertSame('9.0000', $item->fresh()->estimated_unit_price);
        $this->assertSame($c['shop']->id, $item->estimated_source_shop_id);
        $this->assertNotNull($item->estimated_source_price_observation_id);
        $this->assertNotNull($item->estimated_source_observed_at);
    }

    public function test_nested_shop_item_tampering_is_rejected(): void
    {
        $c = $this->context();
        $first = $this->order($c);
        $second = $this->order($c);
        $firstShop = app(OrderShopService::class)->add($c['actor'], $first, ['shop_type' => 'ONE_TIME', 'shop_name' => 'First']);

        $this->expectException(HttpException::class);
        app(OrderItemService::class)->add($c['actor'], $second, $firstShop, ['item_type' => 'ONE_TIME_ITEM', 'item_name' => 'Forged', 'unit_id' => $c['unit']->id, 'quantity' => '1']);
    }

    public function test_captain_must_be_available_employed_and_relevant_to_order_branch(): void
    {
        $c = $this->context();
        $order = $this->order($c);
        $foreignBranch = Branch::factory()->create();
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $foreignBranch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $service = app(OrderCaptainService::class);

        try {
            $service->assign($c['actor'], $order, $captain, OrderCaptainRole::PrimaryCollection);
            $this->fail('Captain outside the Order Branch was assigned.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $captain->branchAssignments()->create([
            'assignment_type' => CaptainAssignmentType::Temporary,
            'branch_id' => $c['branch']->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'reason' => 'Temporary support',
        ]);
        $assignment = $service->assign($c['actor'], $order, $captain->fresh(), OrderCaptainRole::PrimaryCollection);
        $this->assertSame($captain->id, $assignment->captain_id);
    }
}
