<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderDeliveryPricingService;
use App\Services\SpecialProductDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpecialDeliveryPortionController extends Controller
{
    public function store(Request $request, Order $order, SpecialProductDeliveryService $special, OrderDeliveryPricingService $pricing): RedirectResponse
    {
        $data = $request->validate(['portions' => ['required', 'array'], 'portions.*' => ['array'], 'portions.*.*' => ['required', 'regex:/^[0-9]{1,6}$/D']]);
        DB::transaction(function () use ($request, $order, $data, $special, $pricing): void {
            $special->assign($request->user(), $order, $data['portions']);
            $proposal = $pricing->preview($request->user(), $order);
            $pricing->price($request->user(), $order, $proposal['origin']);
        });

        return redirect()->route('orders.workflow', $order);
    }
}
