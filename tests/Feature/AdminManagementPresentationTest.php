<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementPresentationTest extends TestCase
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

    private function dom(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function checkedValues(string $html, string $name): array
    {
        $values = [];
        foreach ($this->dom($html)->query('//input[@name="'.$name.'"][@checked]') as $input) {
            $values[] = (int) $input->getAttribute('value');
        }

        return $values;
    }

    public function test_management_lists_and_forms_keep_scoped_shell_and_submit_controls(): void
    {
        foreach (['captains', 'captain-groups', 'admin.branches', 'admin.users', 'admin.roles'] as $resource) {
            $this->get(route($resource.'.index'))->assertOk()->assertSee('management-ux', false);
            $this->get(route($resource.'.create'))->assertOk()->assertSee('management-ux', false)
                ->assertSee('name="_token"', false)->assertSee('type="submit"', false);
        }
        $this->get(route('admin.permissions.index'))->assertOk()->assertSee('management-ux', false);
    }

    public function test_user_role_and_branch_checkboxes_preserve_exact_assigned_ids(): void
    {
        $user = User::factory()->create();
        $roles = Role::whereIn('code', ['ORDER_EMPLOYEE', 'CAPTAIN'])->get();
        $branches = Branch::factory()->count(2)->create();
        $user->roles()->attach($roles->modelKeys());
        $user->branches()->attach($branches->modelKeys());
        $response = $this->get(route('admin.users.edit', $user))->assertOk();

        $this->assertEqualsCanonicalizing($roles->modelKeys(), $this->checkedValues($response->getContent(), 'role_ids[]'));
        $this->assertEqualsCanonicalizing($branches->modelKeys(), $this->checkedValues($response->getContent(), 'branch_ids[]'));
        $this->assertEqualsCanonicalizing($roles->modelKeys(), $user->fresh()->roles->modelKeys());
    }

    public function test_permission_validation_redisplay_uses_old_ids_without_granting_them(): void
    {
        $permissions = Permission::orderBy('id')->limit(2)->get();
        $role = Role::create(['name' => 'Presentation role', 'code' => 'PRESENTATION', 'is_system' => false]);
        $role->permissions()->attach($permissions[0]);
        $response = $this->withSession(['_old_input' => ['permission_ids' => [$permissions[1]->id]]])
            ->get(route('admin.roles.edit', $role))->assertOk();

        $this->assertSame([$permissions[1]->id], $this->checkedValues($response->getContent(), 'permission_ids[]'));
        $this->assertSame([$permissions[0]->id], $role->fresh()->permissions->modelKeys());
    }

    public function test_system_role_form_stays_disabled_without_save_action(): void
    {
        $role = Role::where('code', 'CAPTAIN')->sole();
        $response = $this->get(route('admin.roles.edit', $role))->assertOk()
            ->assertSee(__('admin.ui.this_core_role_is_protected'))->assertDontSee(__('admin.ui.save_role'));
        $this->assertSame(1, $this->dom($response->getContent())->query('//form//fieldset[@disabled]')->length);
    }

    public function test_captain_profile_and_branch_history_forms_keep_authoritative_identity(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $branch = Branch::factory()->create();
        Branch::factory()->create();
        CaptainGroup::factory()->create();
        $captain = Captain::factory()->for($user)->create(['home_branch_id' => $branch->id,
            'operational_status' => CaptainOperationalStatus::Unavailable]);
        $this->get(route('captains.show', $captain))->assertOk()->assertSee($branch->name)
            ->assertSee(__('captains.ui_operational_status'))->assertSee(__('captains.ui_account'))
            ->assertSee(__('captains.ui_employment_history'))->assertSee(route('captains.status', $captain), false);
        $this->get(route('captains.edit', $captain))->assertOk()->assertSee($branch->name)
            ->assertDontSee('name="home_branch_id"', false);
        foreach (['captains.assignments.create', 'captains.transfer.create', 'captains.groups.create'] as $route) {
            $this->get(route($route, $captain))->assertOk()->assertSee('management-ux', false)
                ->assertSee('type="submit"', false);
        }
        $this->assertSame($branch->id, $captain->fresh()->home_branch_id);
        $this->assertSame(CaptainOperationalStatus::Unavailable, $captain->fresh()->operational_status);
    }

    public function test_form_errors_are_associated_with_controls_and_parent_navigation_preserves_old_input(): void
    {
        $response = $this->withSession([
            '_old_input' => ['name' => 'Preserved draft', 'email' => 'bad-email'],
            'errors' => ['default' => ['format' => ':message', 'messages' => [
                'email' => ['يجب أن يكون البريد الإلكتروني عنوان بريد إلكتروني صالحًا.'],
            ]]],
        ])
            ->get(route('admin.users.create'))->assertOk()->assertSee('Preserved draft')->assertSee('bad-email');
        $dom = $this->dom($response->getContent());
        $this->assertSame(1, $dom->query('//input[@id="email"][@aria-invalid="true"][@aria-describedby="email-error"]')->length);
        $this->assertSame(1, $dom->query('//ul[@id="email-error"]/li')->length);
        $this->assertSame(1, $dom->query('//nav[@aria-label="'.__('common.breadcrumbs').'"]//a')->length);
        $this->assertStringContainsString(__('common.required_field'), $response->getContent());
    }

    public function test_management_pagination_keeps_real_page_links_and_authorized_records(): void
    {
        User::factory()->count(23)->create();
        $first = $this->get(route('admin.users.index'))->assertOk();
        $second = $this->get(route('admin.users.index', ['page' => 2]))->assertOk();
        $this->assertSame(1, $this->dom($first->getContent())->query('//nav[@role="navigation"]')->length);
        $first->assertSee('page=2', false);
        $second->assertSee('page=1', false);
        $this->assertDatabaseCount('users', 24);
    }
}
