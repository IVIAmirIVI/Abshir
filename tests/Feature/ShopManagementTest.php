<?php

namespace Tests\Feature;

use App\Enums\ShopPartnershipStatus;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopPartnership;
use App\Models\ShopProduct;
use App\Models\ShopType;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShopManagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'SUPER_ADMIN', array $branches = []): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $role)->sole());
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    private function shopData(Area $area, ?ShopType $type = null, array $overrides = []): array
    {
        return [
            'name' => 'Central Market',
            'code' => 'CENTRAL',
            'shop_type_id' => $type?->id,
            'area_id' => $area->id,
            'is_mobile_visible' => true,
            'accepts_online_orders' => true,
            ...$overrides,
        ];
    }

    public function test_shop_type_and_shop_creation_flags_search_status_and_no_delete(): void
    {
        $admin = $this->account();
        $area = Area::factory()->create();
        $this->actingAs($admin)->post(route('shop-types.store'), ['name' => 'Supermarket', 'code' => 'MARKET'])->assertSessionHasNoErrors();
        $type = ShopType::where('code', 'MARKET')->sole();
        $this->post(route('shops.store'), $this->shopData($area, $type))->assertSessionHasNoErrors();
        $shop = Shop::where('code', 'CENTRAL')->sole();
        $this->assertTrue($shop->type->is($type));
        $this->assertTrue($shop->is_mobile_visible);
        $this->assertTrue($shop->accepts_online_orders);
        $this->get(route('shops.index', ['search' => 'Central']))->assertOk()->assertSee('Central Market');
        $this->patch(route('shops.status', $shop), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($shop->fresh()->is_active);
        $this->delete('/shops/'.$shop->id)->assertStatus(405);
        $this->assertModelExists($shop);
    }

    public function test_shop_visibility_and_mutation_are_derived_from_area_branch(): void
    {
        [$assigned, $foreign] = Branch::factory()->count(2)->create();
        $visibleArea = Area::factory()->for($assigned)->create();
        $foreignArea = Area::factory()->for($foreign)->create();
        $visibleShop = Shop::create($this->shopData($visibleArea, overrides: ['code' => 'VISIBLE']));
        $foreignShop = Shop::create($this->shopData($foreignArea, overrides: ['name' => 'Foreign Shop', 'code' => 'FOREIGN']));
        $employee = $this->account('ORDER_EMPLOYEE', [$assigned]);

        $this->actingAs($employee)->get(route('shops.index'))->assertOk()->assertSee($visibleShop->name)->assertDontSee($foreignShop->name);
        $this->get(route('shops.show', $foreignShop))->assertForbidden();
    }

    public function test_shop_product_supports_units_exact_decimal_flags_and_duplicate_prevention(): void
    {
        $admin = $this->account();
        $shop = Shop::create($this->shopData(Area::factory()->create()));
        $productUnit = Unit::create(['name' => 'Piece', 'code' => 'PIECE', 'allows_decimal' => false]);
        $box = Unit::create(['name' => 'Box', 'code' => 'BOX', 'allows_decimal' => false]);
        $product = Product::create(['name' => 'Soap', 'default_unit_id' => $productUnit->id]);
        $currency = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2]);
        $base = ['product_id' => $product->id, 'currency_id' => $currency->id, 'listed_price' => '12.3400', 'is_available' => true, 'is_featured' => true];

        $this->actingAs($admin)->post(route('shops.products.store', $shop), [...$base, 'unit_id' => $productUnit->id])->assertSessionHasNoErrors();
        $this->post(route('shops.products.store', $shop), [...$base, 'unit_id' => $box->id, 'listed_price' => '50.1250'])->assertSessionHasNoErrors();
        $this->assertSame(2, $shop->products()->count());
        $listing = ShopProduct::where('shop_id', $shop->id)->where('unit_id', $productUnit->id)->sole();
        $this->assertSame('12.3400', $listing->listed_price);
        $this->assertTrue($listing->is_available);
        $this->assertTrue($listing->is_featured);
        $this->post(route('shops.products.store', $shop), [...$base, 'unit_id' => $productUnit->id])->assertSessionHasErrors('product_id');
        $this->patch(route('shops.products.status', [$shop, $listing]), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($listing->fresh()->is_active);
        $this->assertFalse(Schema::hasColumn('shop_products', 'quantity'));
        $this->assertFalse(Schema::hasColumn('shop_products', 'stock'));
    }

    public function test_partnership_history_controls_mobile_readiness_without_commission_logic(): void
    {
        $admin = $this->account();
        $shop = Shop::create($this->shopData(Area::factory()->create()));
        $payload = [
            'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'status' => ShopPartnershipStatus::Active->value,
            'mobile_enabled' => true,
            'online_orders_enabled' => true,
        ];
        $this->actingAs($admin)->post(route('shops.partnerships.store', $shop), $payload)->assertSessionHasNoErrors();
        $partnership = $shop->partnerships()->sole();
        $this->assertTrue($shop->hasMobileReadyPartnership());
        $this->post(route('shops.partnerships.store', $shop), $payload)->assertSessionHasErrors('starts_at');
        $this->patch(route('shops.partnerships.end', [$shop, $partnership]))->assertSessionHasNoErrors();
        $this->assertSame(ShopPartnershipStatus::Ended, $partnership->fresh()->status);
        $this->assertNotNull($partnership->fresh()->ends_at);
        $this->assertSame($admin->id, $partnership->fresh()->ended_by_user_id);
        $this->assertModelExists($partnership);
        $this->assertFalse($shop->hasMobileReadyPartnership());
        foreach (['commission_rate', 'commission_percentage', 'balance', 'inventory_quantity'] as $column) {
            $this->assertFalse(Schema::hasColumn('shop_partnerships', $column));
        }
    }

    public function test_nested_shop_product_and_partnership_tampering_is_denied(): void
    {
        $admin = $this->account();
        [$first, $second] = [Shop::create($this->shopData(Area::factory()->create(), overrides: ['code' => 'ONE'])), Shop::create($this->shopData(Area::factory()->create(), overrides: ['code' => 'TWO']))];
        $unit = Unit::create(['name' => 'Piece', 'code' => 'PIECE', 'allows_decimal' => false]);
        $product = Product::create(['name' => 'Soap', 'default_unit_id' => $unit->id]);
        $listing = ShopProduct::create(['shop_id' => $second->id, 'product_id' => $product->id, 'unit_id' => $unit->id]);
        $partnership = ShopPartnership::create(['shop_id' => $second->id, 'starts_at' => now(), 'status' => ShopPartnershipStatus::Active]);

        $this->actingAs($admin)->get(route('shops.products.edit', [$first, $listing]))->assertNotFound();
        $this->patch(route('shops.partnerships.end', [$first, $partnership]))->assertNotFound();
    }
}
