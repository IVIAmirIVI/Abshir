<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\OrderWorkflowData;
use App\Services\OrderWorkflowState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        return redirect()->route('orders.workflow', $o)->with('status', __('orders.flash.created'));
    }

    public function workflow(Request $r, Order $order, OrderService $s): View
    {
        $s->assertAccess($r->user(), $order);

        return view('orders.workflow', compact('order'));
    }

    public function show(Request $r, Order $order, OrderWorkflowData $data, OrderWorkflowState $state): View
    {
        $view = $data->load($r->user(), $order);

        return view('orders.show', $view + ['workflow' => $state->describe($r->user(), $order)]);
    }
}
