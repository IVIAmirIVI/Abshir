<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeliveryDiscountRequest;
use App\Http\Requests\DeliveryFeeOverrideRequest;
use App\Http\Requests\OrderDeliveryPricingRequest;
use App\Http\Requests\OrderDestinationAreaRequest;
use App\Models\Area;
use App\Models\DeliveryFeeOverrideRequest as OverrideModel;
use App\Models\Order;
use App\Services\OrderDeliveryPricingService;
use Illuminate\Http\RedirectResponse;

class OrderDeliveryPricingController extends Controller
{
    public function destination(OrderDestinationAreaRequest $request, Order $order, OrderDeliveryPricingService $service): RedirectResponse
    {
        $service->confirmHistoricalDestination($request->user(), $order, Area::findOrFail($request->integer('destination_area_id')));

        return back()->with('status', __('orders.flash.destination_confirmed'));
    }

    public function apply(OrderDeliveryPricingRequest $request, Order $order, OrderDeliveryPricingService $service): RedirectResponse
    {
        $service->price($request->user(), $order, Area::findOrFail($request->integer('from_area_id')));

        return back()->with('status', __('orders.flash.delivery_priced'));
    }

    public function override(DeliveryFeeOverrideRequest $request, Order $order, OrderDeliveryPricingService $service): RedirectResponse
    {
        $result = $service->requestOverride($request->user(), $order, Area::findOrFail($request->integer('from_area_id')), $request->input('requested_unit_fee'), $request->input('reason'));

        return back()->with('status', $result instanceof OverrideModel ? __('orders.flash.override_requested') : __('orders.flash.override_applied'));
    }

    public function discount(DeliveryDiscountRequest $request, Order $order, OrderDeliveryPricingService $service): RedirectResponse
    {
        $service->applyDiscount($request->user(), $order, $request->input('discount_amount'), $request->input('reason'));

        return back()->with('status', __('orders.flash.discount_applied'));
    }
}
