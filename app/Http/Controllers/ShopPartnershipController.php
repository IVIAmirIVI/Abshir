<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShopPartnershipRequest;
use App\Models\Shop;
use App\Models\ShopPartnership;
use App\Services\ShopPartnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopPartnershipController extends Controller
{
    public function store(ShopPartnershipRequest $request, Shop $shop, ShopPartnershipService $service): RedirectResponse
    {
        $service->start($request->user(), $shop, $request->validated());

        return back()->with('status', __('shops.flash_shop_partnership_added'));
    }

    public function end(Request $request, Shop $shop, ShopPartnership $partnership, ShopPartnershipService $service): RedirectResponse
    {
        $service->end($request->user(), $shop, $partnership);

        return back()->with('status', __('shops.flash_shop_partnership_ended'));
    }
}
