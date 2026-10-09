<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceCollectionMethod;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\SharedOrderFinancialPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SharedOrderFinancialReviewController extends Controller
{
    public function __invoke(Request $request, Order $order, OrderService $orders, SharedOrderFinancialPlanService $plans): View
    {
        Gate::authorize('captain_financials.view');
        $orders->assertAccess($request->user(), $order);
        $data = validator(['method' => $request->query('method', 'CASH')], ['method' => ['required', Rule::enum(InvoiceCollectionMethod::class)]])->validate();
        $plan = null;
        $problem = null;
        try {
            $plan = $plans->review($request->user(), $order, InvoiceCollectionMethod::from($data['method']));
        } catch (ValidationException $exception) {
            $problem = collect($exception->errors())->flatten()->first();
        }

        return view('captain-financials.shared-order-review', ['order' => $order, 'plan' => $plan, 'problem' => $problem, 'method' => $data['method']]);
    }
}
