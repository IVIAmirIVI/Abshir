<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupMember;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageAndGroupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', Role::SUPER_ADMIN)->sole());

        return $user;
    }

    private function product(): Product
    {
        $unit = Unit::firstOrCreate(['code' => 'PIECE'], ['name' => 'Piece', 'allows_decimal' => false]);

        return Product::create(['name' => 'Catalog Product', 'default_unit_id' => $unit->id]);
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }

    public function test_product_supports_multiple_images_and_one_primary_image(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('products.images.store', $product), ['image' => $this->image('one.png')])->assertSessionHasNoErrors();
        $this->post(route('products.images.store', $product), ['image' => $this->image('two.png'), 'is_primary' => true])->assertSessionHasNoErrors();
        $this->assertSame(2, $product->images()->count());
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $primary = $product->images()->where('is_primary', true)->sole();
        Storage::disk('public')->assertExists($primary->storage_path);

        $this->delete(route('products.images.destroy', [$product, $primary]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($primary->storage_path);
        $this->assertModelExists($product);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_invalid_image_and_nested_image_tampering_are_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        [$first, $second] = [$this->product(), $this->product()];
        $this->actingAs($admin)->post(route('products.images.store', $first), ['image' => UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');
        $this->post(route('products.images.store', $second), ['image' => $this->image('valid.png')])->assertSessionHasNoErrors();
        $image = $second->images()->sole();
        $this->patch(route('products.images.primary', [$first, $image]))->assertNotFound();
    }

    public function test_product_group_memberships_are_historical_non_overlapping_and_queryable_at_time(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        [$first, $second] = [ProductGroup::create(['name' => 'Promotion']), ProductGroup::create(['name' => 'Reporting'])];
        $this->actingAs($admin)->post(route('products.groups.store', $product), ['product_group_id' => $first->id, 'joined_at' => '2026-01-01 08:00:00'])->assertSessionHasNoErrors();
        $this->post(route('products.groups.store', $product), ['product_group_id' => $second->id, 'joined_at' => '2026-01-01 08:00:00'])->assertSessionHasNoErrors();
        $this->assertSame(2, $product->groupMemberships()->count());
        $membership = $product->groupMemberships()->where('product_group_id', $first->id)->sole();
        $this->assertTrue($product->isMemberOfAt($first, now()));
        $this->post(route('products.groups.store', $product), ['product_group_id' => $first->id, 'joined_at' => '2026-02-01 08:00:00'])->assertSessionHasErrors('joined_at');
        $this->patch(route('products.groups.end', [$product, $membership]))->assertSessionHasNoErrors();
        $this->assertNotNull($membership->fresh()->left_at);
        $this->assertSame($admin->id, $membership->fresh()->ended_by_user_id);
        $this->assertFalse($product->isMemberOfAt($first, now()->addMinute()));
    }

    public function test_inactive_group_keeps_history_but_rejects_new_membership(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $group = ProductGroup::create(['name' => 'Historical']);
        ProductGroupMember::create(['product_id' => $product->id, 'product_group_id' => $group->id, 'joined_at' => now()->subDay(), 'left_at' => now()->subHour()]);
        $group->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)->get(route('product-groups.index'))->assertOk()->assertSee('Historical');
        $this->post(route('products.groups.store', $product), ['product_group_id' => $group->id, 'joined_at' => now()])->assertSessionHasErrors('product_group_id');
        $this->assertDatabaseHas('product_group_members', ['product_id' => $product->id, 'product_group_id' => $group->id]);
    }
}
