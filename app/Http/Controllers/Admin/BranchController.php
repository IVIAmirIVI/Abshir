<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchRequest;
use App\Models\Branch;
use App\Models\Currency;
use App\Services\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('branches.view');

        return view('admin.branches.index', ['branches' => $request->user()->availableBranches()->orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        Gate::authorize('branches.create');

        return view('admin.branches.form', ['branch' => new Branch, 'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get()]);
    }

    public function edit(Request $request, Branch $branch): View
    {
        Gate::authorize('branches.update');
        abort_unless($request->user()->canAccessBranch($branch), 403);

        return view('admin.branches.form', [
            'branch' => $branch,
            'currencies' => Currency::query()->where('is_active', true)->orWhereKey($branch->default_currency_id)->orderBy('code')->get(),
        ]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $branch = Branch::create($request->validated());
            $granted = ! $request->user()->isSuperAdmin();
            if ($granted) {
                $request->user()->branches()->syncWithoutDetaching([$branch->id]);
            }
            app(SecurityAuditService::class)->record($request->user(), 'BRANCH', $branch->id, 'BRANCH_CREATED', [],
                ['is_active' => $branch->is_active, 'default_currency_id' => $branch->default_currency_id,
                    'access_granted_user_id' => $granted ? $request->user()->id : null], $branch->id);
        });

        return redirect()->route('admin.branches.index')->with('status', __('admin.ui.branch_created'));
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        DB::transaction(function () use ($request, $branch): void {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            abort_unless($request->user()->canAccessBranch($branch), 403);
            $before = ['default_currency_id' => $branch->default_currency_id];
            $branch->update($request->validated());
            app(SecurityAuditService::class)->record($request->user(), 'BRANCH', $branch->id, 'BRANCH_ACCESS_CHANGED', $before,
                ['default_currency_id' => $branch->default_currency_id], $branch->id);
        });

        return redirect()->route('admin.branches.index')->with('status', __('admin.ui.branch_updated'));
    }

    public function status(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        Gate::authorize($data['is_active'] ? 'branches.activate' : 'branches.deactivate');
        DB::transaction(function () use ($request, $branch, $data): void {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            abort_unless($request->user()->canAccessBranch($branch), 403);
            $before = ['is_active' => $branch->is_active];
            $branch->is_active = (bool) $data['is_active'];
            $branch->save();
            app(SecurityAuditService::class)->record($request->user(), 'BRANCH', $branch->id, 'BRANCH_STATUS_CHANGED', $before,
                ['is_active' => $branch->is_active], $branch->id);
        });

        return redirect()->route('admin.branches.index')->with('status', __('admin.ui.branch_status_updated'));
    }
}
