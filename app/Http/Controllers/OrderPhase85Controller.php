<?php

namespace App\Http\Controllers;

use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Http\Requests\CaptainRatingRequest;
use App\Http\Requests\OrderCancellationRequest;
use App\Http\Requests\OrderShopProgressRequest;
use App\Models\Captain;
use App\Models\Order;
use App\Models\OrderShop;
use App\Services\CaptainRatingService;
use App\Services\OrderCancellationService;
use App\Services\OrderShopProgressService;
use Illuminate\Http\RedirectResponse;

class OrderPhase85Controller extends Controller
{
    public function progress(OrderShopProgressRequest $request, Order $order, OrderShop $orderShop, OrderShopProgressService $service): RedirectResponse
    {
        $service->record($request->user(), $order, $orderShop, $request->validated('marker'));

        return redirect()->route('orders.workflow', [$order, 'step' => 'shops'])->with('status', __('orders.flash.progress_recorded'));
    }

    public function cancel(OrderCancellationRequest $request, Order $order, OrderCancellationService $service): RedirectResponse
    {
        $service->cancel($request->user(), $order, OrderCancellationClassification::from($request->validated('classification')), OrderCancellationReason::from($request->validated('reason')), $request->validated('notes'));

        return redirect()->route('orders.workflow', $order)->with('status', __('orders.flash.cancelled'));
    }

    public function rating(CaptainRatingRequest $request, Order $order, CaptainRatingService $service): RedirectResponse
    {
        $service->recordManual($request->user(), $order, Captain::findOrFail($request->integer('captain_id')), $request->integer('rating'));

        return redirect()->route('orders.workflow', $order)->with('status', __('orders.flash.rating_recorded'));
    }
}
