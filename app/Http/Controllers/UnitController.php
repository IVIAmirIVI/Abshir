<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnitRequest;
use App\Models\Unit;
use App\Services\UnitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UnitController extends Controller
{
    public function index(): View
    {
        Gate::authorize('units.view');

        return view('units.index', ['units' => Unit::query()->orderBy('name')->paginate(30)]);
    }

    public function create(): View
    {
        Gate::authorize('units.create');

        return view('units.form', ['unit' => new Unit]);
    }

    public function store(UnitRequest $request, UnitService $service): RedirectResponse
    {
        $service->save($request->user(), new Unit, $request->validated());

        return redirect()->route('units.index')->with('status', 'Unit created.');
    }

    public function edit(Unit $unit): View
    {
        Gate::authorize('units.update');

        return view('units.form', compact('unit'));
    }

    public function update(UnitRequest $request, Unit $unit, UnitService $service): RedirectResponse
    {
        $service->save($request->user(), $unit, $request->validated());

        return redirect()->route('units.index')->with('status', 'Unit updated.');
    }

    public function status(Request $request, Unit $unit, UnitService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $unit, (bool) $data['is_active']);

        return back()->with('status', 'Unit status updated.');
    }
}
