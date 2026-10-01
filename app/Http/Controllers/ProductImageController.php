<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductImageController extends Controller
{
    public function store(ProductImageRequest $request, Product $product, ProductImageService $service): RedirectResponse
    {
        $service->add($request->user(), $product, $request->file('image'), $request->safe()->except('image'));

        return back()->with('status', __('catalog.flash_product_image_added'));
    }

    public function primary(Request $request, Product $product, ProductImage $image, ProductImageService $service): RedirectResponse
    {
        $service->makePrimary($request->user(), $product, $image);

        return back()->with('status', __('catalog.flash_primary_image_updated'));
    }

    public function destroy(Request $request, Product $product, ProductImage $image, ProductImageService $service): RedirectResponse
    {
        $service->remove($request->user(), $product, $image);

        return back()->with('status', __('catalog.flash_product_image_removed'));
    }
}
