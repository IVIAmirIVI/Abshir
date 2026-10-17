<?php

namespace App\Http\Controllers;

use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Http\Requests\OrderActualPriceRequest;
use App\Http\Requests\OrderCaptainRequest;
use App\Http\Requests\OrderCaptainSwitchRequest;
use App\Http\Requests\OrderItemChangeRequest;
use App\Http\Requests\OrderItemRequest;
use App\Http\Requests\OrderShopClassificationRequest;
use App\Http\Requests\OrderShopRequest;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Captain;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Services\OrderCaptainService;
use App\Services\OrderItemService;
use App\Services\OrderShopService;
use App\Services\OrderStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderOperationController extends Controller
{
    public function addShop(OrderShopRequest $r, Order $order, OrderShopService $s): RedirectResponse
    {
        $s->add($r->user(), $order, $r->validated());

        return redirect()->route('orders.workflow', [$order, 'step' => 'shops'])->with('status', __('orders.flash.shop_added'));
    }

    public function classifyShop(OrderShopClassificationRequest $r, Order $order, OrderShop $orderShop, OrderShopService $s): RedirectResponse
    {
        $s->classifyLegacy($r->user(), $order, $orderShop, $r->validated('business_classification'));

        return redirect()->route('orders.workflow', [$order, 'step' => 'shops'])->with('status', __('orders.flash.classification'));
    }

    public function addItem(OrderItemRequest $r, Order $order, OrderShop $orderShop, OrderItemService $s): RedirectResponse
    {
        $s->add($r->user(), $order, $orderShop, $r->validated());

        return redirect()->route('orders.workflow', [$order, 'step' => 'shops'])->with('status', __('orders.flash.item_added'));
    }

    public function actual(OrderActualPriceRequest $r, Order $order, OrderShop $orderShop, OrderItem $item, OrderItemService $s): RedirectResponse
    {
        $s->confirmActual($r->user(), $order, $orderShop, $item, $r->validated());

        return redirect()->route('orders.workflow', [$order, 'step' => 'shops'])->with('status', __('orders.flash.actual_confirmed'));
    }

    public function change(OrderItemChangeRequest $r, Order $order, OrderShop $orderShop, OrderItem $item, OrderItemService $s): RedirectResponse
    {
        $s->change($r->user(), $order, $orderShop, $item, $r->validated());

        return redirect()->route('orders.workflow', [$order, 'step' => 'shops'])->with('status', __('orders.flash.item_changed'));
    }

    public function status(OrderStatusRequest $r, Order $order, OrderStatusService $s): RedirectResponse
    {
        $s->transition($r->user(), $order, OrderOperationalStatus::from($r->validated('operational_status')), $r->validated('reason'));

        return redirect()->route('orders.workflow', $order)->with('status', __('orders.flash.status_updated'));
    }

    public function assignCaptain(OrderCaptainRequest $r, Order $order, OrderCaptainService $s): RedirectResponse
    {
        $s->assign($r->user(), $order, Captain::findOrFail($r->integer('captain_id')), OrderCaptainRole::from($r->validated('assignment_role')));

        return redirect()->route('orders.workflow', [$order, 'step' => 'captain'])->with('status', __('orders.flash.captain_assigned'));
    }

    public function switchCaptain(OrderCaptainSwitchRequest $r, Order $order, OrderCaptainService $s): RedirectResponse
    {
        $s->switchPrimary($r->user(), $order, Captain::findOrFail($r->integer('captain_id')), $r->validated('reason'));

        return redirect()->route('orders.workflow', [$order, 'step' => 'captain'])->with('status', __('orders.flash.captain_switched'));
    }

    public function removeCaptain(Request $r, Order $order, OrderCaptain $assignment, OrderCaptainService $s): RedirectResponse
    {
        $data = $r->validate(['reason' => ['required', 'string', 'max:1000']]);
        $s->remove($r->user(), $order, $assignment, $data['reason']);

        return redirect()->route('orders.workflow', [$order, 'step' => 'captain'])->with('status', __('orders.flash.captain_removed'));
    }
}
