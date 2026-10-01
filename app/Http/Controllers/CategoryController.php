<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('categories.view');

        return view('categories.index', ['categories' => Category::with('parent')->withCount('products')->orderBy('sort_order')->orderBy('name')->paginate(30)]);
    }

    public function create(CategoryService $service): View
    {
        Gate::authorize('categories.create');

        return view('categories.form', ['category' => new Category, 'parents' => $service->validParents()]);
    }

    public function store(CategoryRequest $request, CategoryService $service): RedirectResponse
    {
        $service->save($request->user(), new Category, $request->validated());

        return redirect()->route('categories.index')->with('status', __('catalog.flash_category_created'));
    }

    public function edit(Category $category, CategoryService $service): View
    {
        Gate::authorize('categories.update');

        return view('categories.form', ['category' => $category, 'parents' => $service->validParents($category)]);
    }

    public function update(CategoryRequest $request, Category $category, CategoryService $service): RedirectResponse
    {
        $service->save($request->user(), $category, $request->validated());

        return redirect()->route('categories.index')->with('status', __('catalog.flash_category_updated'));
    }

    public function status(Request $request, Category $category, CategoryService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $category, (bool) $data['is_active']);

        return back()->with('status', __('catalog.flash_category_status_updated'));
    }
}
