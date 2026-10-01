<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductGroupMembershipRequest;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupMember;
use App\Services\ProductGroupMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductGroupMembershipController extends Controller
{
    public function store(ProductGroupMembershipRequest $request, Product $product, ProductGroupMembershipService $service): RedirectResponse
    {
        $service->join($request->user(), $product, ProductGroup::findOrFail($request->integer('product_group_id')), $request->validated());

        return back()->with('status', __('catalog.flash_product_group_membership_added'));
    }

    public function end(Request $request, Product $product, ProductGroupMember $membership, ProductGroupMembershipService $service): RedirectResponse
    {
        $service->end($request->user(), $product, $membership);

        return back()->with('status', __('catalog.flash_product_group_membership_ended'));
    }
}
