<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceCollectionMethod;
use App\Http\Requests\InvoiceCollectionRequest;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Services\InvoiceCollectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceCollectionController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('invoice_collections.view');
        $collections = InvoiceCollection::query()->accessibleTo($request->user())->with(['invoice', 'order.branch', 'captain.user', 'currency', 'recordedBy'])->when($request->filled('branch_id'), fn (Builder $q) => $q->whereHas('order', fn (Builder $orders) => $orders->where('branch_id', $request->integer('branch_id'))))->when($request->filled('from'), fn (Builder $q) => $q->whereDate('collected_at', '>=', $request->date('from')))->when($request->filled('to'), fn (Builder $q) => $q->whereDate('collected_at', '<=', $request->date('to')))->latest('collected_at')->paginate(25)->withQueryString();

        return view('invoice-collections.index', ['collections' => $collections, 'branches' => $request->user()->availableBranches()->orderBy('name')->get()]);
    }

    public function shamCash(Request $request): View
    {
        Gate::authorize('invoice_collections.sham_cash.view');

        $query = InvoiceCollection::query()
            ->accessibleTo($request->user())
            ->where('collection_method', InvoiceCollectionMethod::ShamCash)
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('collected_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('collected_at', '<=', $request->date('to')));

        $collections = (clone $query)
            ->with(['invoice', 'order.branch', 'currency', 'recordedBy'])
            ->latest('collected_at')
            ->paginate(25)
            ->withQueryString();
        $totals = (clone $query)
            ->join('currencies', 'currencies.id', '=', 'invoice_collections.currency_id')
            ->select('invoice_collections.currency_id', 'currencies.code', DB::raw('SUM(invoice_collections.amount) AS total'))
            ->groupBy('invoice_collections.currency_id', 'currencies.code')
            ->orderBy('currencies.code')
            ->get();

        return view('invoice-collections.sham-cash', compact('collections', 'totals'));
    }

    public function store(InvoiceCollectionRequest $request, Invoice $invoice, InvoiceCollectionService $service): RedirectResponse
    {
        $service->record(
            $request->user(),
            $invoice,
            InvoiceCollectionMethod::from($request->validated('collection_method')),
            $invoice->grand_total,
            $invoice->currency_id,
            $request->validated('collected_at'),
            $request->validated('notes'),
            $request->validated('payment_reference'),
        );

        return redirect()->route('orders.workflow', [$invoice->order_id, 'step' => 'invoice'])->with('status', __('financial.flash.collection_recorded'));
    }
}
