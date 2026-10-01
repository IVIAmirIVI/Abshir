<?php

namespace App\Http\Controllers;

use App\Http\Requests\AreaRequest;
use App\Models\Area;
use App\Models\Branch;
use App\Services\AreaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AreaController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('areas.view');
        $branches = $request->user()->availableBranches()->orderBy('name')->get();
        $branch = $request->filled('branch_id') ? Branch::findOrFail($request->integer('branch_id')) : $branches->first();
        if ($branch) {
            abort_unless($request->user()->canAccessBranch($branch), 403);
        }

        return view('areas.index', [
            'branches' => $branches,
            'branch' => $branch,
            'areas' => $branch ? Area::with('parent')->where('branch_id', $branch->id)->orderBy('name')->paginate(30)->withQueryString() : null,
        ]);
    }

    public function create(Request $request, AreaService $service): View
    {
        Gate::authorize('areas.create');

        return $this->form($request, new Area, $service);
    }

    public function edit(Request $request, Area $area, AreaService $service): View
    {
        Gate::authorize('areas.update');
        abort_unless($request->user()->canAccessArea($area), 403);

        return $this->form($request, $area, $service);
    }

    private function form(Request $request, Area $area, AreaService $service): View
    {
        $branches = $request->user()->availableBranches()->orderBy('name')->get();
        $branchId = $request->integer('branch_id') ?: ($area->branch_id ?: $branches->first()?->id);
        $branch = $branchId ? Branch::findOrFail($branchId) : null;
        if ($branch) {
            abort_unless($request->user()->canAccessBranch($branch), 403);
        }

        return view('areas.form', [
            'area' => $area,
            'branches' => $branches,
            'selectedBranch' => $branch,
            'parents' => $branch ? $service->validParents($request->user(), $branch, $area->exists ? $area : null) : collect(),
        ]);
    }

    public function store(AreaRequest $request, AreaService $service): RedirectResponse
    {
        $area = $service->save($request->user(), new Area, $request->validated());

        return redirect()->route('areas.index', ['branch_id' => $area->branch_id])->with('status', __('areas.flash.created'));
    }

    public function update(AreaRequest $request, Area $area, AreaService $service): RedirectResponse
    {
        $area = $service->save($request->user(), $area, $request->validated());

        return redirect()->route('areas.index', ['branch_id' => $area->branch_id])->with('status', __('areas.flash.updated'));
    }

    public function status(Request $request, Area $area, AreaService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $area, (bool) $data['is_active']);

        return back()->with('status', __('areas.flash.status_updated'));
    }
}
