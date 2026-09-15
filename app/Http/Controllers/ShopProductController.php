<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShopProductRequest;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopProduct;
use App\Models\Unit;
use App\Services\ShopProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ShopProductController extends Controller
{
    public function create(Request $request, Shop $shop): View
    {
        Gate::authorize('shop_products.create');
        abort_unless($request->user()->canAccessShop($shop), 403);

        return $this->form($shop, new ShopProduct);
    }

    public function store(ShopProductRequest $request, Shop $shop, ShopProductService $service): RedirectResponse
    {
        $service->save($request->user(), $shop, new ShopProduct, $request->validated());

        return redirect()->route('shops.show', $shop)->with('status', 'Shop Product added.');
    }

    public function edit(Request $request, Shop $shop, ShopProduct $shopProduct): View
    {
        Gate::authorize('shop_products.update');
        abort_unless($request->user()->canAccessShop($shop), 403);
        abort_if($shopProduct->shop_id !== $shop->id, 404);

        return $this->form($shop, $shopProduct);
    }

    public function update(ShopProductRequest $request, Shop $shop, ShopProduct $shopProduct, ShopProductService $service): RedirectResponse
    {
        $service->save($request->user(), $shop, $shopProduct, $request->validated());

        return redirect()->route('shops.show', $shop)->with('status', 'Shop Product updated.');
    }

    public function status(Request $request, Shop $shop, ShopProduct $shopProduct, ShopProductService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $shop, $shopProduct, (bool) $data['is_active']);

        return back()->with('status', 'Shop Product status updated.');
    }

    private function form(Shop $shop, ShopProduct $listing): View
    {
        return view('shop-products.form', [
            'shop' => $shop,
            'listing' => $listing,
            'products' => Product::query()->where('is_active', true)->orWhereKey($listing->product_id)->orderBy('name')->get(),
            'units' => Unit::query()->where('is_active', true)->orWhereKey($listing->unit_id)->orderBy('name')->get(),
            'currencies' => Currency::query()->where('is_active', true)->orWhereKey($listing->currency_id)->orderBy('code')->get(),
        ]);
    }
}
