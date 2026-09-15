<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductGroupRequest;
use App\Models\ProductGroup;
use App\Services\ProductGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductGroupController extends Controller
{
    public function index(): View
    {
        Gate::authorize('product_groups.view');

        return view('product-groups.index', ['groups' => ProductGroup::query()->withCount('memberships')->orderBy('name')->paginate(30)]);
    }

    public function create(): View
    {
        Gate::authorize('product_groups.create');

        return view('product-groups.form', ['productGroup' => new ProductGroup]);
    }

    public function store(ProductGroupRequest $request, ProductGroupService $service): RedirectResponse
    {
        $service->save($request->user(), new ProductGroup, $request->validated());

        return redirect()->route('product-groups.index')->with('status', 'Product Group created.');
    }

    public function edit(ProductGroup $productGroup): View
    {
        Gate::authorize('product_groups.update');

        return view('product-groups.form', compact('productGroup'));
    }

    public function update(ProductGroupRequest $request, ProductGroup $productGroup, ProductGroupService $service): RedirectResponse
    {
        $service->save($request->user(), $productGroup, $request->validated());

        return redirect()->route('product-groups.index')->with('status', 'Product Group updated.');
    }

    public function status(Request $request, ProductGroup $productGroup, ProductGroupService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $productGroup, (bool) $data['is_active']);

        return back()->with('status', 'Product Group status updated.');
    }
}
