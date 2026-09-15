<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchRequest;
use App\Models\Branch;
use App\Models\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $branch = Branch::create($request->validated());
        if (! $request->user()->isSuperAdmin()) {
            $request->user()->branches()->syncWithoutDetaching([$branch->id]);
        }

        return redirect()->route('admin.branches.index')->with('status', 'Branch created.');
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        abort_unless($request->user()->canAccessBranch($branch), 403);
        $branch->update($request->validated());

        return redirect()->route('admin.branches.index')->with('status', 'Branch updated.');
    }

    public function status(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        Gate::authorize($data['is_active'] ? 'branches.activate' : 'branches.deactivate');
        abort_unless($request->user()->canAccessBranch($branch), 403);
        $branch->is_active = (bool) $data['is_active'];
        $branch->save();

        return redirect()->route('admin.branches.index')->with('status', 'Branch status updated.');
    }
}
