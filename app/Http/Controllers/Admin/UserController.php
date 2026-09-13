<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\ManageUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('users.view');

        return view('admin.users.index', ['users' => User::with(['roles', 'branches'])->orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        Gate::authorize('users.create');

        return $this->form(new User);
    }

    public function edit(User $user): View
    {
        Gate::authorize('users.update');
        abort_if(! auth()->user()->isSuperAdmin() && ($user->is(auth()->user()) || $user->isSuperAdmin()), 403);

        return $this->form($user);
    }

    private function form(User $user): View
    {
        return view('admin.users.form', [
            'user' => $user,
            'roles' => auth()->user()->isSuperAdmin() ? Role::orderBy('name')->get() : collect(),
            'branches' => auth()->user()->isSuperAdmin() ? Branch::orderBy('name')->get() : collect(),
        ]);
    }

    public function store(UserRequest $request, ManageUser $manager): RedirectResponse
    {
        $manager->save($request->user(), new User, $request->validated());

        return redirect()->route('admin.users.index')->with('status', 'User created.');
    }

    public function update(UserRequest $request, User $user, ManageUser $manager): RedirectResponse
    {
        $manager->save($request->user(), $user, $request->validated());

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    public function status(Request $request, User $user, ManageUser $manager): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $manager->setActive($request->user(), $user, (bool) $data['is_active']);

        return redirect()->route('admin.users.index')->with('status', 'User status updated.');
    }
}
