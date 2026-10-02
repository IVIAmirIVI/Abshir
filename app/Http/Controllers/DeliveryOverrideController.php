<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryOverrideStatus;
use App\Http\Requests\DeliveryOverrideResolutionRequest;
use App\Models\DeliveryFeeOverrideRequest;
use App\Services\OrderDeliveryPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeliveryOverrideController extends Controller
{
    public function index(): View
    {
        Gate::authorize('delivery_pricing_overrides.approve');

        return view('delivery-overrides.index', ['requests' => DeliveryFeeOverrideRequest::with(['order', 'fromArea', 'requester', 'resolver'])->latest('requested_at')->paginate(25), 'pendingCount' => DeliveryFeeOverrideRequest::where('status', DeliveryOverrideStatus::Pending)->count()]);
    }

    public function resolve(DeliveryOverrideResolutionRequest $request, DeliveryFeeOverrideRequest $override, OrderDeliveryPricingService $service): RedirectResponse
    {
        $service->resolveOverride($request->user(), $override, $request->input('decision') === 'approve', $request->input('resolution_reason'));

        return back()->with('status', __('admin.ui.override_request_resolved'));
    }
}
