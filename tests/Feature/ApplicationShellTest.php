<?php

namespace Tests\Feature;

use App\Livewire\Shell\NavigationFinder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class ApplicationShellTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $code): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $code)->sole());

        return $user;
    }

    public function test_rtl_shell_renders_accessible_drawer_and_real_mary_livewire_components(): void
    {
        $response = $this->actingAs($this->account('SUPER_ADMIN'))->get('/dashboard')->assertOk()
            ->assertSee('dir="rtl"', false)->assertSee('data-app-shell', false)
            ->assertSee('id="desktop-navigation"', false)->assertSee('id="mobile-navigation"', false)
            ->assertSee('href="#app-main"', false)->assertSee('tabindex="-1"', false)
            ->assertSeeLivewire(NavigationFinder::class)->assertSee('الوصول السريع')
            ->assertSee('wire:model.live.debounce.200ms="search"', false)
            ->assertSee('data-theme="abshir"', false)->assertSee('x-trap="open"', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//dialog')->length);
        $this->assertSame(1, $xpath->query('//*[@data-shell-finder]//input[@type="text"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//*[@data-shell-finder]//button[contains(@class,"btn")]')->length);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $this->assertNotContains($node->getAttribute('id'), $ids);
            $ids[] = $node->getAttribute('id');
        }
        $this->assertStringNotContainsString('Alpine.start(', file_get_contents(resource_path('js/app.js')));
    }

    public function test_navigation_finder_search_is_permission_filtered_and_does_not_expose_admin_links(): void
    {
        $employee = $this->account('ORDER_EMPLOYEE');
        $finder = Livewire::actingAs($employee)->test(NavigationFinder::class)
            ->set('open', true)->assertSet('open', true)->assertSee('الطلبات')
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.bill-payment-fees.index'), false)
            ->set('search', 'الزبائن')->assertSee(route('customers.index'), false)
            ->assertDontSee(route('orders.index'), false)
            ->set('search', '<script>')->assertSee(__('shell.no_pages'))
            ->call('close')->assertSet('open', false)->assertSet('search', '');
        $employee->roles()->detach();
        $finder->call('$refresh')->assertDontSee(route('customers.index'), false);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_custom_role_does_not_gain_super_admin_only_role_management(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $role = Role::create(['code' => 'CUSTOM_UI', 'name' => 'Custom UI']);
        $role->permissions()->sync(Permission::whereIn('code', ['users.view', 'roles.view'])->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role);
        Livewire::actingAs($user)->test(NavigationFinder::class)
            ->assertSee(route('admin.users.index'), false)->assertDontSee(route('admin.roles.index'), false);
        $this->actingAs($user)->get('/admin/roles')->assertForbidden();
    }

    public function test_captain_gets_only_existing_home_and_profile_and_protected_routes_still_deny_access(): void
    {
        $captain = $this->account('CAPTAIN');
        $links = Livewire::actingAs($captain)->test(NavigationFinder::class)->get('links');
        $this->assertSame(['dashboard', 'profile.edit'], array_column($links, 'route'));
        foreach (['orders.index', 'captain-financials.index', 'invoices.index', 'admin.users.index'] as $route) {
            $this->actingAs($captain)->get(route($route))->assertForbidden();
        }
        $this->assertDatabaseMissing('roles', ['code' => 'SUPERVISOR']);
    }

    public function test_active_navigation_uses_the_most_specific_existing_route(): void
    {
        $response = $this->actingAs($this->account('SUPER_ADMIN'))->get(route('product-prices.index'))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['desktop', 'mobile'] as $surface) {
            $active = $xpath->query('//*[@data-navigation="'.$surface.'"]//a[@aria-current="page"]');
            $this->assertSame(1, $active->length);
            $this->assertSame(route('product-prices.index'), $active->item(0)->getAttribute('href'));
        }
    }

    public function test_package_components_do_not_register_unneeded_upload_or_spotlight_routes(): void
    {
        foreach (Route::getRoutes() as $route) {
            $this->assertFalse(str_starts_with($route->getName() ?? '', 'mary.'));
        }
        $this->get('/mary/spotlight')->assertNotFound();
        $this->actingAs($this->account('SUPER_ADMIN'))->post('/mary/upload')->assertNotFound();
        $this->assertSame('mary-', config('mary.prefix'));
    }

    public function test_shell_translation_parity_and_english_direction(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Arr::dot(require lang_path('ar/shell.php'))), array_keys(Arr::dot(require lang_path('en/shell.php'))));
        app()->setLocale('en');
        $this->actingAs($this->account('SUPER_ADMIN'))->get('/dashboard')->assertOk()
            ->assertSee('dir="ltr"', false)->assertSee('Quick navigation')->assertSee('Main navigation');
    }
}
