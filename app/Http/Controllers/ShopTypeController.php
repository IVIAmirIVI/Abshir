<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShopTypeRequest;
use App\Models\ShopType;
use App\Services\ShopTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ShopTypeController extends Controller
{
    public function index(): View
    {
        Gate::authorize('shop_types.view');

        return view('shop-types.index', ['types' => ShopType::query()->withCount('shops')->orderBy('name')->paginate(30)]);
    }

    public function create(): View
    {
        Gate::authorize('shop_types.create');

        return view('shop-types.form', ['shopType' => new ShopType]);
    }

    public function store(ShopTypeRequest $request, ShopTypeService $service): RedirectResponse
    {
        $service->save($request->user(), new ShopType, $request->validated());

        return redirect()->route('shop-types.index')->with('status', 'Shop Type created.');
    }

    public function edit(ShopType $shopType): View
    {
        Gate::authorize('shop_types.update');

        return view('shop-types.form', compact('shopType'));
    }

    public function update(ShopTypeRequest $request, ShopType $shopType, ShopTypeService $service): RedirectResponse
    {
        $service->save($request->user(), $shopType, $request->validated());

        return redirect()->route('shop-types.index')->with('status', 'Shop Type updated.');
    }

    public function status(Request $request, ShopType $shopType, ShopTypeService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $shopType, (bool) $data['is_active']);

        return back()->with('status', 'Shop Type status updated.');
    }
}
