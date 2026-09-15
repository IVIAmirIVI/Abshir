<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Unit;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('products.view');
        $search = trim($request->string('search')->toString());
        $products = Product::query()->with(['category', 'defaultUnit'])
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->when($request->filled('category_id'), fn (Builder $query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('active'), fn (Builder $query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(),
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('products.create');

        return $this->form(new Product);
    }

    public function store(ProductRequest $request, ProductService $service): RedirectResponse
    {
        $product = $service->save($request->user(), new Product, $request->validated());

        return redirect()->route('products.show', $product)->with('status', 'Product created.');
    }

    public function show(Product $product): View
    {
        Gate::authorize('products.view');
        $product->load([
            'category', 'defaultUnit', 'images',
            'groupMemberships' => fn ($query) => $query->with(['group', 'createdBy', 'endedBy'])->latest('joined_at'),
        ]);

        return view('products.show', [
            'product' => $product,
            'groups' => ProductGroup::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('products.update');

        return $this->form($product);
    }

    public function update(ProductRequest $request, Product $product, ProductService $service): RedirectResponse
    {
        $service->save($request->user(), $product, $request->validated());

        return redirect()->route('products.show', $product)->with('status', 'Product updated.');
    }

    public function status(Request $request, Product $product, ProductService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $product, (bool) $data['is_active']);

        return back()->with('status', 'Product status updated.');
    }

    private function form(Product $product): View
    {
        return view('products.form', [
            'product' => $product,
            'categories' => Category::query()->where('is_active', true)->orWhereKey($product->category_id)->orderBy('name')->get(),
            'units' => Unit::query()->where('is_active', true)->orWhereKey($product->default_unit_id)->orderBy('name')->get(),
        ]);
    }
}
