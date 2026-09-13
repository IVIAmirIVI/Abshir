<?php

namespace App\Http\Controllers;

use App\Http\Requests\CaptainGroupRequest;
use App\Models\CaptainGroup;
use App\Services\CaptainGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CaptainGroupController extends Controller
{
    public function index(): View
    {
        Gate::authorize('captain_groups.view');

        return view('captain-groups.index', ['groups' => CaptainGroup::query()->withCount('memberships')->orderBy('name')->paginate(30)]);
    }

    public function create(): View
    {
        Gate::authorize('captain_groups.create');

        return view('captain-groups.form', ['captainGroup' => new CaptainGroup]);
    }

    public function store(CaptainGroupRequest $request, CaptainGroupService $service): RedirectResponse
    {
        $service->save($request->user(), new CaptainGroup, $request->validated());

        return redirect()->route('captain-groups.index')->with('status', 'Captain group created.');
    }

    public function edit(CaptainGroup $captainGroup): View
    {
        Gate::authorize('captain_groups.update');

        return view('captain-groups.form', compact('captainGroup'));
    }

    public function update(CaptainGroupRequest $request, CaptainGroup $captainGroup, CaptainGroupService $service): RedirectResponse
    {
        $service->save($request->user(), $captainGroup, $request->validated());

        return redirect()->route('captain-groups.index')->with('status', 'Captain group updated.');
    }

    public function status(Request $request, CaptainGroup $captainGroup, CaptainGroupService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $captainGroup, (bool) $data['is_active']);

        return back()->with('status', 'Captain group status updated.');
    }
}
