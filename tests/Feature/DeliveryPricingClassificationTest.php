<?php

namespace Tests\Feature;

use App\Enums\OrderPricingClass;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\DeliveryPricingRule;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\User;
use App\Services\DeliveryPricingRuleService;
use App\Services\OrderService;
use App\Services\OrderShopService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryPricingClassificationTest extends TestCase
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
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'HISTORICAL_MANUAL', 'ordered_at' => now()->subHour()]);

        return compact('currency', 'branch', 'from', 'to', 'admin', 'employee', 'order');
    }

    public function test_registered_shop_types_are_snapshotted_without_name_inference(): void
    {
        $c = $this->context();
        $restaurantType = ShopType::create(['name' => 'Food place', 'business_classification' => ShopBusinessClassification::Restaurant]);
        $normalType = ShopType::create(['name' => 'Restaurant Supplies', 'business_classification' => ShopBusinessClassification::Shop]);
        $restaurant = Shop::create(['name' => 'Alpha', 'shop_type_id' => $restaurantType->id, 'area_id' => $c['from']->id]);
        $normal = Shop::create(['name' => 'Contains Restaurant in name', 'shop_type_id' => $normalType->id, 'area_id' => $c['from']->id]);
        $service = app(OrderShopService::class);

        $snapshot = $service->add($c['employee'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $restaurant->id]);
        $this->assertSame(ShopBusinessClassification::Restaurant, $snapshot->business_classification_snapshot);
        $this->assertSame(OrderPricingClass::SingleRestaurant, $c['order']->fresh()->pricing_class);

        $otherOrder = app(OrderService::class)->create($c['employee'], ['branch_id' => $c['branch']->id, 'customer_id' => $c['order']->customer_id, 'customer_phone_id' => $c['order']->customer_phone_id, 'customer_address_id' => $c['order']->customer_address_id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE', 'ordered_at' => now()]);
        $service->add($c['employee'], $otherOrder, ['shop_type' => 'REGISTERED', 'shop_id' => $normal->id]);
        $this->assertSame(OrderPricingClass::SingleShop, $otherOrder->fresh()->pricing_class);

        $restaurantType->update(['business_classification' => ShopBusinessClassification::Shop]);
        $this->assertSame(ShopBusinessClassification::Restaurant, $snapshot->fresh()->business_classification_snapshot);
        $this->assertSame(OrderPricingClass::SingleRestaurant, $c['order']->fresh()->pricing_class);

        $service->add($c['employee'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $normal->id]);
        $this->assertSame(OrderPricingClass::MultiShop, $c['order']->fresh()->pricing_class);
    }

    public function test_one_time_classification_and_multi_shop_rules_are_deterministic(): void
    {
        $c = $this->context();
        $service = app(OrderShopService::class);
        $first = $service->add($c['employee'], $c['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'RESTAURANT', 'shop_name' => 'One-time Restaurant']);
        $this->assertSame(ShopBusinessClassification::Restaurant, $first->business_classification_snapshot);
        $this->assertSame(OrderPricingClass::SingleRestaurant, $c['order']->fresh()->pricing_class);

        $second = $service->add($c['employee'], $c['order'], ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'One-time Shop']);
        $this->assertSame(ShopBusinessClassification::Shop, $second->business_classification_snapshot);
        $this->assertSame(OrderPricingClass::MultiShop, $c['order']->fresh()->pricing_class);
    }

    public function test_unconfigured_registered_and_one_time_shops_require_explicit_classification(): void
    {
        $c = $this->context();
        $type = ShopType::create(['name' => 'Legacy type']);
        $shop = Shop::create(['name' => 'Legacy Shop', 'shop_type_id' => $type->id, 'area_id' => $c['from']->id]);
        $service = app(OrderShopService::class);

        foreach ([
            ['shop_type' => 'REGISTERED', 'shop_id' => $shop->id],
            ['shop_type' => 'ONE_TIME', 'shop_name' => 'Unknown'],
        ] as $data) {
            try {
                $service->add($c['employee'], $c['order'], $data);
                $this->fail('An unclassified Order Shop was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('business_classification', $exception->errors());
            }
        }

        $snapshot = $service->add($c['employee'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $shop->id, 'business_classification' => 'SHOP']);
        $this->assertSame(ShopBusinessClassification::Shop, $snapshot->business_classification_snapshot);
    }

    public function test_legacy_order_shop_can_be_classified_once_with_nested_ownership_enforced(): void
    {
        $c = $this->context();
        $legacy = OrderShop::create(['order_id' => $c['order']->id, 'shop_type' => 'ONE_TIME', 'shop_name_snapshot' => 'Legacy Shop']);
        $service = app(OrderShopService::class);

        $classified = $service->classifyLegacy($c['employee'], $c['order'], $legacy, 'RESTAURANT');
        $this->assertSame(ShopBusinessClassification::Restaurant, $classified->business_classification_snapshot);
        $this->assertSame(OrderPricingClass::SingleRestaurant, $c['order']->fresh()->pricing_class);

        try {
            $service->classifyLegacy($c['employee'], $c['order'], $legacy, 'SHOP');
            $this->fail('An immutable Order Shop classification was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('business_classification', $exception->errors());
        }

        $otherOrder = app(OrderService::class)->create($c['employee'], ['branch_id' => $c['branch']->id, 'customer_id' => $c['order']->customer_id, 'customer_phone_id' => $c['order']->customer_phone_id, 'customer_address_id' => $c['order']->customer_address_id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE', 'ordered_at' => now()]);
        $this->get(route('orders.shops.classification', [$otherOrder, $legacy]))->assertStatus(405);
        $this->actingAs($c['employee'])->patch(route('orders.shops.classification', [$otherOrder, $legacy]), ['business_classification' => 'SHOP'])->assertNotFound();
    }

    public function test_rule_resolution_is_exact_for_all_three_classes_without_fallback(): void
    {
        $c = $this->context();
        $service = app(DeliveryPricingRuleService::class);
        $fees = [
            OrderPricingClass::SingleRestaurant->value => '20.00',
            OrderPricingClass::SingleShop->value => '25.00',
            OrderPricingClass::MultiShop->value => '35.00',
        ];
        foreach ($fees as $class => $fee) {
            $service->create($c['admin'], ['from_area_id' => $c['from']->id, 'to_area_id' => $c['to']->id, 'currency_id' => $c['currency']->id, 'order_pricing_class' => $class, 'delivery_unit_fee' => $fee, 'effective_from' => now()->subDay()]);
        }
        foreach (OrderPricingClass::cases() as $class) {
            $this->assertSame($fees[$class->value].'00', $service->applicable($c['from']->id, $c['to']->id, $c['currency']->id, $class, now())->delivery_unit_fee);
        }

        DeliveryPricingRule::where('order_pricing_class', OrderPricingClass::MultiShop->value)->delete();
        $this->assertNull($service->applicable($c['from']->id, $c['to']->id, $c['currency']->id, OrderPricingClass::MultiShop, now()));
        $this->assertNotNull($service->applicable($c['from']->id, $c['to']->id, $c['currency']->id, OrderPricingClass::SingleShop, now()));
    }

    public function test_legacy_rule_migration_preserves_financial_references_and_builds_independent_three_version_chains(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Migration-chain verification uses an isolated PostgreSQL schema.');
        }

        $originalSearchPath = DB::selectOne('SHOW search_path')->search_path;
        $schema = 'classification_migration_'.str()->lower(str()->random(10));
        DB::statement('CREATE SCHEMA "'.$schema.'"');

        try {
            DB::statement('SET search_path TO "'.$schema.'"');
            Schema::create('shop_types', function ($table): void {
                $table->id();
                $table->text('description')->nullable();
            });
            Schema::create('orders', function ($table): void {
                $table->id();
                $table->string('pricing_status');
            });
            Schema::create('order_shops', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->string('shop_type');
            });
            Schema::create('delivery_pricing_rules', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('from_area_id');
                $table->unsignedBigInteger('to_area_id');
                $table->unsignedBigInteger('currency_id');
                $table->decimal('delivery_unit_fee', 18, 4);
                $table->timestampTz('effective_from');
                $table->timestampTz('effective_until')->nullable();
                $table->boolean('is_active');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('superseded_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
            Schema::create('order_delivery_pricings', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('delivery_pricing_rule_id')->nullable();
                $table->unsignedBigInteger('currency_id');
            });
            Schema::create('delivery_fee_override_requests', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('from_area_id');
            });

            $boundaries = [now()->subDays(3), now()->subDays(2), now()->subDay()];
            $ids = [];
            foreach (['A', 'B', 'C'] as $index => $label) {
                $ids[$label] = DB::table('delivery_pricing_rules')->insertGetId(['from_area_id' => 11, 'to_area_id' => 12, 'currency_id' => 13, 'delivery_unit_fee' => 40 + $index, 'effective_from' => $boundaries[$index], 'effective_until' => $index < 2 ? $boundaries[$index + 1] : null, 'is_active' => true, 'created_by' => null, 'superseded_by' => null, 'notes' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('delivery_pricing_rules')->where('id', $ids['A'])->update(['superseded_by' => $ids['B']]);
            DB::table('delivery_pricing_rules')->where('id', $ids['B'])->update(['superseded_by' => $ids['C']]);
            $historicalPricingId = DB::table('order_delivery_pricings')->insertGetId(['delivery_pricing_rule_id' => $ids['B'], 'currency_id' => 13]);

            $migration = require base_path('database/migrations/2026_09_21_000000_add_delivery_pricing_classification.php');
            $migration->up();

            foreach ($ids as $label => $id) {
                $legacy = DB::table('delivery_pricing_rules')->find($id);
                $this->assertSame($label, $legacy->notes);
                $this->assertNull($legacy->order_pricing_class);
            }
            $this->assertSame($ids['B'], DB::table('order_delivery_pricings')->where('id', $historicalPricingId)->value('delivery_pricing_rule_id'));

            foreach (OrderPricingClass::cases() as $class) {
                $chain = DB::table('delivery_pricing_rules')->where('order_pricing_class', $class->value)->orderBy('effective_from')->get();
                $this->assertSame(['A', 'B', 'C'], $chain->pluck('notes')->all());
                $this->assertSame($chain[1]->id, $chain[0]->superseded_by);
                $this->assertSame($chain[2]->id, $chain[1]->superseded_by);
                $this->assertNull($chain[2]->superseded_by);
                $this->assertSame([11], $chain->pluck('from_area_id')->unique()->values()->all());
                $this->assertSame([12], $chain->pluck('to_area_id')->unique()->values()->all());
                $this->assertSame([13], $chain->pluck('currency_id')->unique()->values()->all());
                $this->assertFalse($chain->contains(fn ($row) => $row->superseded_by === $row->id));
                $this->assertFalse($chain->contains(fn ($row) => $row->superseded_by && ! $chain->contains('id', $row->superseded_by)));
            }

            try {
                $migration->down();
                $this->fail('The forward-only migration allowed destructive rollback.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('forward-only', $exception->getMessage());
            }
        } finally {
            DB::statement('SET search_path TO '.$originalSearchPath);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }
}
