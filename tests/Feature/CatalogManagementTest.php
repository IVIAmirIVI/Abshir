<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', Role::SUPER_ADMIN)->sole());

        return $user;
    }

    private function unit(array $attributes = []): Unit
    {
        return Unit::create(['name' => 'Piece', 'code' => 'PIECE', 'allows_decimal' => false, ...$attributes]);
    }

    public function test_category_hierarchy_validation_status_and_no_delete_route(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('categories.store'), ['name' => 'Food', 'code' => 'FOOD', 'sort_order' => 1])->assertSessionHasNoErrors();
        $root = Category::where('code', 'FOOD')->sole();
        $this->post(route('categories.store'), ['name' => 'Dairy', 'code' => 'DAIRY', 'parent_id' => $root->id, 'sort_order' => 2])->assertSessionHasNoErrors();
        $child = Category::where('code', 'DAIRY')->sole();
        $this->assertSame('Food › Dairy', $child->path());

        $this->put(route('categories.update', $root), ['name' => 'Food', 'parent_id' => $root->id])->assertSessionHasErrors('parent_id');
        $this->put(route('categories.update', $root), ['name' => 'Food', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');
        $this->patch(route('categories.status', $child), ['is_active' => false])->assertSessionHasNoErrors();
        $this->get(route('categories.index'))->assertOk()->assertSee('Dairy');
        $this->delete('/categories/'.$child->id)->assertStatus(405);
        $this->assertModelExists($child);
    }

    public function test_unit_code_decimal_capability_and_status_are_managed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('units.store'), ['name' => 'Kilogram', 'code' => 'kg', 'symbol' => 'kg', 'allows_decimal' => true])->assertSessionHasNoErrors();
        $unit = Unit::where('code', 'KG')->sole();
        $this->assertTrue($unit->allows_decimal);
        $this->post(route('units.store'), ['name' => 'Duplicate', 'code' => 'KG', 'allows_decimal' => false])->assertSessionHasErrors('code');
        $this->patch(route('units.status', $unit), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($unit->fresh()->is_active);
        $this->delete('/units/'.$unit->id)->assertStatus(405);
    }

    public function test_currency_is_managed_and_can_be_a_branch_default(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('currencies.store'), ['code' => 'usd', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2])->assertSessionHasNoErrors();
        $currency = Currency::where('code', 'USD')->sole();
        $this->post(route('admin.branches.store'), ['name' => 'Main', 'code' => 'MAIN', 'default_currency_id' => $currency->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('branches', ['code' => 'MAIN', 'default_currency_id' => $currency->id]);
    }

    public function test_product_validation_unique_identifiers_status_and_no_delete_route(): void
    {
        $admin = $this->admin();
        $unit = $this->unit();
        $category = Category::create(['name' => 'Food', 'code' => 'FOOD']);
        $payload = ['name' => 'Fresh Milk', 'sku' => 'MILK-1', 'barcode' => '123456', 'category_id' => $category->id, 'default_unit_id' => $unit->id];
        $this->actingAs($admin)->post(route('products.store'), $payload)->assertSessionHasNoErrors();
        $product = Product::where('sku', 'MILK-1')->sole();
        $this->assertTrue($product->category->is($category));
        $this->assertTrue($product->defaultUnit->is($unit));
        $this->post(route('products.store'), [...$payload, 'name' => 'Other'])->assertSessionHasErrors(['sku', 'barcode']);
        $this->post(route('products.store'), ['name' => 'Invalid', 'default_unit_id' => 999999])->assertSessionHasErrors('default_unit_id');
        $this->patch(route('products.status', $product), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($product->fresh()->is_active);
        $this->delete('/products/'.$product->id)->assertStatus(405);
        $this->assertModelExists($product);
    }

    public function test_product_searches_name_sku_and_barcode_and_escapes_text(): void
    {
        $admin = $this->admin();
        $unit = $this->unit();
        Product::create(['name' => '<script>Fresh Milk</script>', 'sku' => 'MILK-SEARCH', 'barcode' => '998877', 'default_unit_id' => $unit->id]);
        Product::create(['name' => 'Bread', 'sku' => 'BREAD-1', 'barcode' => '112233', 'default_unit_id' => $unit->id]);

        $this->actingAs($admin)->get(route('products.index', ['search' => 'Fresh']))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('Bread');
        $this->get(route('products.index', ['search' => 'MILK-SEARCH']))->assertSee('Fresh Milk')->assertDontSee('Bread');
        $this->get(route('products.index', ['search' => '998877']))->assertSee('Fresh Milk')->assertDontSee('Bread');
    }

    public function test_inactive_category_and_unit_remain_valid_for_existing_product_but_not_new_assignment(): void
    {
        $admin = $this->admin();
        $unit = $this->unit();
        $category = Category::create(['name' => 'Historical']);
        $product = Product::create(['name' => 'Existing', 'category_id' => $category->id, 'default_unit_id' => $unit->id]);
        $category->forceFill(['is_active' => false])->save();
        $unit->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->put(route('products.update', $product), ['name' => 'Existing updated', 'category_id' => $category->id, 'default_unit_id' => $unit->id])->assertSessionHasNoErrors();
        $this->post(route('products.store'), ['name' => 'New', 'category_id' => $category->id, 'default_unit_id' => $unit->id])->assertSessionHasErrors('default_unit_id');
        $activeUnit = Unit::create(['name' => 'Box', 'code' => 'BOX', 'allows_decimal' => false]);
        $this->post(route('products.store'), ['name' => 'New', 'category_id' => $category->id, 'default_unit_id' => $activeUnit->id])->assertSessionHasErrors('category_id');
    }
}
