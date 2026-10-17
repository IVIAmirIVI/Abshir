<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Services\OrderService;
use App\Services\SpecialAddonApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpecialAddonApprovalController extends Controller
{
    public function store(Request $request, Order $order, SpecialAddonApprovalService $service): RedirectResponse
    {
        $service->authorize($request->user());
        app(OrderService::class)->assertAccess($request->user(), $order);
        $data = $request->validate(['order_shop_id' => ['required', 'integer'], 'source_order_item_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'], 'approved_by' => ['prohibited'], 'approved_at' => ['prohibited'], 'facts' => ['prohibited']]);
        $shop = OrderShop::where('order_id', $order->id)->findOrFail($data['order_shop_id']);
        $source = OrderItem::whereHas('orderShop', fn ($q) => $q->where('order_id', $order->id))->findOrFail($data['source_order_item_id']);
        $service->approve($request->user(), $order, $shop, $source, $data['reason']);

        return redirect()->route('orders.workflow', $order);
    }
}
