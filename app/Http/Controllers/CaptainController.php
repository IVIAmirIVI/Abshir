<?php

namespace App\Http\Controllers;

use App\Enums\CaptainOperationalStatus;
use App\Http\Requests\CaptainRequest;
use App\Models\Captain;
use App\Models\CaptainRating;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainService;
use App\Services\FinancialBalanceQueryService;
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
        $captainsQuery = Captain::query()->accessibleTo($request->user())->with(['user', 'homeBranch']);
        if ($request->user()->can('captain_ratings.view_summary')) {
            $captainsQuery->withCount(['ratings as ratings_count' => fn (Builder $ratings) => $ratings->accessibleTo($request->user())])
                ->withAvg(['ratings as ratings_average' => fn (Builder $ratings) => $ratings->accessibleTo($request->user())], 'rating');
        }
        $captains = $captainsQuery
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

        return redirect()->route('captains.show', $captain)->with('status', __('captains.flash_captain_profile_created'));
    }

    public function show(Request $request, Captain $captain, FinancialBalanceQueryService $balances): View
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
        $ratingSummary = null;
        $recentRatings = collect();
        $financialRows = collect();
        if ($request->user()->can('captain_financials.view')) {
            $accessibleBranches = $request->user()->availableBranches()->get()->keyBy('id');
            foreach (Currency::query()->orderBy('code')->get() as $currency) {
                foreach ($balances->captainBalancesByBranch($captain, $currency) as $branchId => $values) {
                    if ($branch = $accessibleBranches->get((int) $branchId)) {
                        $financialRows->push(compact('branch', 'currency', 'values'));
                    }
                }
            }
        }
        if ($request->user()->can('captain_ratings.view_summary')) {
            $ratings = CaptainRating::query()->accessibleTo($request->user())->where('captain_id', $captain->id);
            $ratingSummary = [
                'count' => (clone $ratings)->count(),
                'average' => (clone $ratings)->avg('rating'),
                'distribution' => (clone $ratings)->selectRaw('rating, COUNT(*) AS aggregate')->groupBy('rating')->pluck('aggregate', 'rating'),
            ];
            $recentRatings = (clone $ratings)->with('order:id,order_number,ordered_at')->latest('rated_at')->limit(20)->get();
        }

        return view('captains.show', [
            'captain' => $captain,
            'activeBranches' => $request->user()->can('captain_branches.view') ? $captain->activeOperationalBranches() : collect(),
            'ratingSummary' => $ratingSummary,
            'recentRatings' => $recentRatings,
            'financialRows' => $financialRows,
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

        return redirect()->route('captains.show', $captain)->with('status', __('captains.flash_captain_profile_updated'));
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
