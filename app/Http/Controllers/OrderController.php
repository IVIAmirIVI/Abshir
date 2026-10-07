<?php

namespace App\Http\Controllers;

use App\Enums\OrderOperationalStatus;
use App\Http\Requests\OrderRequest;
use App\Models\Area;
use App\Models\Currency;
use App\Models\Order;
use App\Services\OrderCancellationSafetyService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $r): View
    {
        Gate::authorize('orders.view');

        return view('orders.index');
    }

    public function create(Request $r): View
    {
        Gate::authorize('orders.create');

        return view('orders.create');
    }

    public function store(OrderRequest $r, OrderService $s): RedirectResponse
    {
        $o = $s->create($r->user(), $r->validated());

        return redirect()->route('orders.show', $o)->with('status', __('orders.flash.created'));
    }

    public function show(Request $r, Order $order, OrderService $s, OrderDeliveryPricingService $deliveryPricing): View
    {
        $s->assertAccess($r->user(), $order);
        $order->load(['branch.defaultCurrency', 'destinationArea.parent', 'shops.shop.area', 'shops.originArea.parent', 'shops.receipts.uploadedBy', 'shops.items.estimatedSourceShop', 'shops.items.estimatedCurrency', 'shops.items.actualCurrency', 'shops.items.changes.changedBy', 'captainAssignments.captain.user', 'captainRatings.captain.user', 'cancellation.cancelledBy', 'statusHistory.changedBy', 'invoices.currency', 'invoices.collection', 'currentInvoice', 'billPaymentRequests.currency', 'billPaymentRequests.payment.evidence', 'billPaymentRequests.payment.paidBy', 'billPaymentRequests.payment.orderCaptain.captain.user']);
        $order->load(['shops.purchaseConfirmation.currency', 'shops.shopReturn.evidence', 'cancellationIntent.requiredShops', 'purchaseConfirmations', 'shopReturns']);
        $pricingQuery = $order->currentDeliveryPricing()->with(['rule', 'fromArea.parent', 'toArea.parent', 'currency', 'routes.fromArea.parent', 'routes.toArea.parent']);
        if ($r->user()->can('delivery_financial_details.view')) {
            $pricingQuery->with('allocations.captain.user');
        }
        $currentDeliveryPricing = $pricingQuery->first();
        $destinationAreas = Area::query()->accessibleTo($r->user())->where('is_active', true)->where('branch_id', $order->branch_id)->orderBy('name')->get();
        $pricingPreview = null;
        $pricingProblem = null;
        if ($order->shops->isNotEmpty() && $r->user()->can('delivery_pricing.view')) {
            try {
                $pricingPreview = $deliveryPricing->preview($r->user(), $order);
            } catch (ValidationException $exception) {
                $pricingProblem = collect($exception->errors())->flatten()->first();
            }
        }

        $recoveredCancellationAvailable = $order->operational_status === OrderOperationalStatus::Delivered
            && app(OrderCancellationSafetyService::class)->allowsRecoveredDeliveredCancellation($order, true);

        return view('orders.show', ['order' => $order, 'currencies' => Currency::where('is_active', true)->orderBy('code')->get(), 'currentDeliveryPricing' => $currentDeliveryPricing, 'destinationAreas' => $destinationAreas, 'pricingPreview' => $pricingPreview, 'pricingProblem' => $pricingProblem, 'recoveredCancellationAvailable' => $recoveredCancellationAvailable]);
    }
}
