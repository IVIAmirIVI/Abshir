<?php

namespace Tests\Feature;

use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPhone;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $this->actingAs($admin);
    }

    public function test_management_lists_and_create_forms_render_scoped_components(): void
    {
        foreach (['customers', 'shops', 'products', 'categories', 'units', 'product-groups', 'shop-types'] as $resource) {
            $this->get(route($resource.'.index'))->assertOk()->assertSee('management-ux', false);
            $this->get(route($resource.'.create'))->assertOk()->assertSee('management-ux', false)
                ->assertSee('name="_token"', false)->assertSee('type="submit"', false);
        }
    }

    public function test_customer_detail_and_nested_forms_keep_real_data_and_phone_semantics(): void
    {
        $customer = Customer::factory()->create();
        $phone = CustomerPhone::factory()->for($customer)->create(['phone' => '0912345678', 'normalized_phone' => '0912345678']);
        $address = CustomerAddress::factory()->for($customer)->for(Area::factory())->create(['label' => null]);

        $this->get(route('customers.show', $customer))->assertOk()->assertSee('0912345678')
            ->assertSee(__('common.terms.address'))->assertDontSee('common.terms.address');
        $this->get(route('customers.edit', $customer))->assertOk()->assertSee('name="_method"', false);
        $this->get(route('customers.phones.edit', [$customer, $phone]))->assertOk()
            ->assertSee('type="tel"', false)->assertSee('inputmode="numeric"', false)->assertSee('0912345678')
            ->assertSee('data-phone-match-url', false)->assertSee('management-ux', false);
        $this->get(route('customers.addresses.edit', [$customer, $address]))->assertOk()
            ->assertSee('name="area_id"', false)->assertSee($address->area->branch->name);
    }

    public function test_shop_master_classification_is_visible_and_independent_of_optional_type(): void
    {
        $type = ShopType::create(['name' => 'Optional shop type', 'code' => 'PRESENTATION']);
        $shop = Shop::create(['name' => 'Restaurant master', 'area_id' => Area::factory()->create()->id,
            'shop_type_id' => $type->id, 'business_classification' => ShopBusinessClassification::Restaurant]);
        foreach (['shops.index', 'shops.show'] as $route) {
            $response = $this->get($route === 'shops.index' ? route($route) : route($route, $shop));
            $response->assertOk()->assertSee(__('orders.shop_classifications.RESTAURANT'))->assertSee($type->name);
        }
        $this->get(route('shops.edit', $shop))->assertOk()->assertSee('name="business_classification"', false);
        $this->assertSame(ShopBusinessClassification::Restaurant, $shop->fresh()->business_classification);
    }

    public function test_product_pages_preserve_unit_and_do_not_invent_missing_prices(): void
    {
        $unit = Unit::create(['name' => 'Presentation unit', 'code' => 'PRESENTATION', 'allows_decimal' => true]);
        $product = Product::create(['name' => 'Presentation product', 'default_unit_id' => $unit->id]);
        $this->get(route('products.index', ['search' => $product->name]))->assertOk()->assertSee($unit->name);
        $this->get(route('products.show', $product))->assertOk()->assertSee($unit->name)
            ->assertSee(route('products.price-history', $product), false)->assertSee('multipart/form-data', false);
        $this->get(route('products.edit', $product))->assertOk()->assertSee('name="default_unit_id"', false);
        $this->assertSame(0, $product->priceHistory()->count());
    }
}
