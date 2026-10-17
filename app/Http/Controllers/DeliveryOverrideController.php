<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryOverrideStatus;
use App\Http\Requests\DeliveryOverrideResolutionRequest;
use App\Models\DeliveryFeeOverrideRequest;
use App\Services\OrderDeliveryPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeliveryOverrideController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('delivery_pricing_overrides.approve');

        $requests = DeliveryFeeOverrideRequest::query()
            ->whereHas('order', fn ($orders) => $orders->accessibleTo($request->user()));

        return view('delivery-overrides.index', [
            'requests' => (clone $requests)->with(['order', 'fromArea', 'requester', 'resolver'])->latest('requested_at')->paginate(25),
            'pendingCount' => (clone $requests)->where('status', DeliveryOverrideStatus::Pending)->count(),
        ]);
    }

    public function resolve(DeliveryOverrideResolutionRequest $request, DeliveryFeeOverrideRequest $override, OrderDeliveryPricingService $service): RedirectResponse
    {
        $service->resolveOverride($request->user(), $override, $request->input('decision') === 'approve', $request->input('resolution_reason'));

        return back()->with('status', __('admin.ui.override_request_resolved'));
    }
}
