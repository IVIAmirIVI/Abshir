<?php

namespace Tests\Feature;

use App\Enums\PriceObservationSource;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Services\PriceEstimationSettings;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductPricingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role, array $branches = []): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $role)->sole());
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    private function records(Branch $branch): array
    {
        $unit = Unit::create(['name' => 'Piece', 'code' => fake()->unique()->lexify('U???'), 'allows_decimal' => false]);
        $currency = Currency::create(['name' => 'Dollar', 'code' => fake()->unique()->lexify('C??'), 'decimal_places' => 2]);
        $product = Product::create(['name' => 'Soap', 'default_unit_id' => $unit->id]);
        $shop = Shop::create(['name' => 'Scoped Shop', 'area_id' => Area::factory()->for($branch)->create()->id]);

        return compact('unit', 'currency', 'product', 'shop');
    }

    public function test_order_employee_receives_all_and_only_phase_five_operational_permissions(): void
    {
        $employee = $this->account('ORDER_EMPLOYEE');
        foreach (['product_prices.view', 'product_prices.create', 'product_prices.reports', 'product_prices.estimate'] as $permission) {
            $this->assertTrue($employee->hasPermission($permission));
        }
        foreach (['system_settings.view', 'system_settings.update', 'shop_products.create', 'shop_partnerships.create'] as $permission) {
            $this->assertFalse($employee->hasPermission($permission));
        }
        $this->assertCount(0, Role::where('code', 'CAPTAIN')->sole()->permissions);
    }

    public function test_branch_scope_denies_record_list_report_estimate_and_direct_shop_history_tampering(): void
    {
        [$assigned, $foreign] = Branch::factory()->count(2)->create();
        $context = $this->records($foreign);
        $employee = $this->account('ORDER_EMPLOYEE', [$assigned]);
        $observation = ProductPriceHistory::create([
            'product_id' => $context['product']->id,
            'shop_id' => $context['shop']->id,
            'unit_id' => $context['unit']->id,
            'currency_id' => $context['currency']->id,
            'price' => '19.0000',
            'observed_at' => now()->subHour(),
            'source' => PriceObservationSource::Manual,
        ]);
        $payload = [
            'product_id' => $context['product']->id,
            'shop_id' => $context['shop']->id,
            'unit_id' => $context['unit']->id,
            'currency_id' => $context['currency']->id,
            'price' => '20.0000',
            'observed_at' => now()->format('Y-m-d H:i:s'),
            'source' => PriceObservationSource::Manual->value,
        ];

        $this->actingAs($employee)->post(route('product-prices.store'), $payload)->assertForbidden();
        $this->get(route('product-prices.index'))->assertOk()->assertDontSee('19.0000');
        $this->get(route('shops.price-history', $context['shop']))->assertForbidden();
        $this->get(route('products.price-comparison', $context['product']))->assertOk()->assertDontSee('Scoped Shop');
        $this->get(route('product-prices.estimate', [
            'preview' => 1,
            'product_id' => $context['product']->id,
            'unit_id' => $context['unit']->id,
            'currency_id' => $context['currency']->id,
        ]))->assertOk()->assertSee('No estimate available');
        $this->assertDatabaseCount('product_price_history', 1);
        $this->assertModelExists($observation);
    }

    public function test_area_less_shop_is_available_to_super_admin_only(): void
    {
        $branch = Branch::factory()->create();
        $context = $this->records($branch);
        $context['shop']->forceFill(['area_id' => null])->save();
        $employee = $this->account('ORDER_EMPLOYEE', [$branch]);
        $admin = $this->account('SUPER_ADMIN');

        $this->actingAs($employee)->get(route('product-prices.create'))->assertOk()->assertDontSee('Scoped Shop');
        $this->actingAs($admin)->get(route('product-prices.create'))->assertOk()->assertSee('Scoped Shop');
    }

    public function test_super_admin_configures_window_and_invalid_values_are_rejected(): void
    {
        $admin = $this->account('SUPER_ADMIN');
        $this->actingAs($admin)->get(route('admin.pricing-settings.edit'))->assertOk()->assertSee('3');
        $this->put(route('admin.pricing-settings.update'), ['estimation_window_days' => 7])->assertSessionHasNoErrors();
        $this->assertSame(7, app(PriceEstimationSettings::class)->windowDays());
        $this->put(route('admin.pricing-settings.update'), ['estimation_window_days' => 0])->assertSessionHasErrors('estimation_window_days');
        $this->put(route('admin.pricing-settings.update'), ['estimation_window_days' => 366])->assertSessionHasErrors('estimation_window_days');
        $this->assertSame(7, app(PriceEstimationSettings::class)->windowDays());
    }

    public function test_captain_and_guest_cannot_access_pricing_and_employee_cannot_change_settings(): void
    {
        $captain = $this->account('CAPTAIN');
        foreach (['/price-observations', '/price-observations/create', '/price-estimate'] as $url) {
            $this->actingAs($captain)->get($url)->assertForbidden();
        }
        $employee = $this->account('ORDER_EMPLOYEE');
        $this->actingAs($employee)->put(route('admin.pricing-settings.update'), ['estimation_window_days' => 10])->assertForbidden();
        auth()->logout();
        $this->get('/price-observations')->assertRedirect('/login');
    }

    public function test_seeder_is_idempotent_and_phase_six_does_not_add_financial_schema(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $this->seed(AuthorizationSeeder::class);
        $this->assertDatabaseCount('permissions', 147);
        $this->assertCount(4, Permission::where('code', 'like', 'product_prices.%')->get());
        foreach (['payments', 'ledgers', 'commissions', 'incentives'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
