<?php

namespace App\Http\Controllers;

use App\Enums\CaptainOperationalStatus;
use App\Http\Requests\CaptainRequest;
use App\Models\Captain;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CaptainController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('captains.view');
        $search = trim($request->string('search')->toString());
        $captains = Captain::query()->accessibleTo($request->user())->with(['user', 'homeBranch'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(fn (Builder $matches) => $matches
                    ->where('employee_code', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $users) => $users->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])));
            })->orderBy('id')->paginate(20)->withQueryString();

        return view('captains.index', compact('captains'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('captains.create');

        return $this->form($request, new Captain);
    }

    public function store(CaptainRequest $request, CaptainService $service): RedirectResponse
    {
        $captain = $service->save($request->user(), new Captain, $request->validated());

        return redirect()->route('captains.show', $captain)->with('status', 'Captain profile created.');
    }

    public function show(Request $request, Captain $captain): View
    {
        Gate::authorize('captains.view');
        abort_unless($request->user()->canAccessCaptain($captain), 403);
        $captain->load([
            'user',
            'homeBranch',
            'employmentPeriods' => fn ($query) => $query->with(['startedBy', 'endedBy'])->latest('started_at'),
            'statusChanges' => fn ($query) => $query->with('changedBy')->latest('changed_at'),
        ]);
        if ($request->user()->can('captain_branches.view')) {
            $captain->load(['branchAssignments' => fn ($query) => $query->with(['fromBranch', 'branch', 'createdBy', 'endedBy'])->latest('starts_at')]);
        }
        if ($request->user()->can('captain_groups.view')) {
            $captain->load(['groupMemberships' => fn ($query) => $query->with(['group', 'createdBy', 'endedBy'])->latest('joined_at')]);
        }

        return view('captains.show', [
            'captain' => $captain,
            'activeBranches' => $request->user()->can('captain_branches.view') ? $captain->activeOperationalBranches() : collect(),
        ]);
    }

    public function edit(Request $request, Captain $captain): View
    {
        Gate::authorize('captains.update');
        abort_unless($request->user()->canAccessCaptain($captain), 403);

        return $this->form($request, $captain);
    }

    public function update(CaptainRequest $request, Captain $captain, CaptainService $service): RedirectResponse
    {
        $service->save($request->user(), $captain, $request->validated());

        return redirect()->route('captains.show', $captain)->with('status', 'Captain profile updated.');
    }

    private function form(Request $request, Captain $captain): View
    {
        $users = User::query()->when(! $captain->exists, fn (Builder $query) => $query
            ->whereDoesntHave('captain')
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('code', [Role::SUPER_ADMIN, 'ORDER_EMPLOYEE'])))
            ->when($captain->exists, fn (Builder $query) => $query->whereKey($captain->user_id))
            ->orderBy('name')->get();

        return view('captains.form', [
            'captain' => $captain,
            'users' => $users,
            'branches' => $request->user()->availableBranches()->where('is_active', true)->orderBy('name')->get(),
            'statuses' => CaptainOperationalStatus::cases(),
        ]);
    }
}
