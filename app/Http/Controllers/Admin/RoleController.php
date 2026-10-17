<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        Gate::authorize('roles.view');
        Gate::authorize('manage-authorization');

        return view('admin.roles.index', ['roles' => Role::with('permissions')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('roles.create');
        Gate::authorize('manage-authorization');

        return $this->form(new Role);
    }

    public function edit(Role $role): View
    {
        Gate::authorize('roles.update');
        Gate::authorize('manage-authorization');

        return $this->form($role);
    }

    private function form(Role $role): View
    {
        return view('admin.roles.form', ['role' => $role, 'permissions' => Permission::orderBy('code')->get()->groupBy(fn ($permission) => explode('.', $permission->code)[0])]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $role = Role::create($request->safe()->only(['name', 'code']));
            $role->permissions()->sync($request->validated('permission_ids', []));
            app(SecurityAuditService::class)->record($request->user(), 'ROLE', $role->id, 'ROLE_CREATED', [],
                ['code' => $role->code, 'permission_ids' => $role->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all()]);
        });

        return redirect()->route('admin.roles.index')->with('status', __('admin.ui.role_created'));
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => __('domain.core_system_roles_are_protected_create_a_custom_role_to_change_access')]);
        }
        DB::transaction(function () use ($request, $role) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            if ($role->is_system) {
                throw ValidationException::withMessages(['role' => __('domain.core_system_roles_are_protected_create_a_custom_role_to_change_access')]);
            }
            $before = ['code' => $role->code, 'permission_ids' => $role->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all()];
            $role->update($request->safe()->only(['name', 'code']));
            $role->permissions()->sync($request->validated('permission_ids', []));
            app(SecurityAuditService::class)->record($request->user(), 'ROLE', $role->id, 'ROLE_ACCESS_CHANGED', $before,
                ['code' => $role->code, 'permission_ids' => $role->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all()]);
        });

        return redirect()->route('admin.roles.index')->with('status', __('admin.ui.role_updated'));
    }

    public function permissions(): View
    {
        Gate::authorize('permissions.view');

        return view('admin.roles.permissions', ['permissions' => Permission::orderBy('code')->get()]);
    }
}
