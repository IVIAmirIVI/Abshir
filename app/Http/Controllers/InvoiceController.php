<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Http\Requests\InvoiceReasonRequest;
use App\Models\Invoice;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use App\Services\InvoiceService;
use App\Services\OrderFinancialSourceSnapshotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('invoices.view');
        $term = trim($request->string('search')->toString());
        $invoices = Invoice::query()->accessibleTo($request->user())->with(['order.branch', 'currency', 'collection'])->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $x) => $x->where('invoice_number', 'like', "%{$term}%")->orWhere('customer_name_snapshot', 'like', "%{$term}%")->orWhereHas('order', fn (Builder $orders) => $orders->where('order_number', 'like', "%{$term}%"))))->when($request->filled('branch_id'), fn (Builder $q) => $q->whereHas('order', fn (Builder $orders) => $orders->where('branch_id', $request->integer('branch_id'))))->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))->when($request->input('collection') === 'collected', fn (Builder $q) => $q->whereHas('collection'))->when($request->input('collection') === 'uncollected', fn (Builder $q) => $q->whereDoesntHave('collection'))->when($request->filled('issued_from'), fn (Builder $q) => $q->whereDate('issued_at', '>=', $request->date('issued_from')))->when($request->filled('issued_to'), fn (Builder $q) => $q->whereDate('issued_at', '<=', $request->date('issued_to')))->latest('id')->paginate(25)->withQueryString();

        return view('invoices.index', ['invoices' => $invoices, 'branches' => $request->user()->availableBranches()->orderBy('name')->get(), 'statuses' => InvoiceStatus::cases()]);
    }

    public function show(Request $request, Order $order, Invoice $invoice, OrderFinancialSourceSnapshotService $snapshots): View
    {
        Gate::authorize('invoices.view');
        $this->ownership($request, $order, $invoice);

        $invoice->load(['order.branch', 'order.captainAssignments.captain.user', 'currency', 'items', 'collection.captain.user', 'issuer', 'voider', 'replacer', 'replacementInvoice']);
        $primaryCollector = $invoice->order->captainAssignments->first(fn ($assignment) => $assignment->removed_at === null && $assignment->assignment_role === OrderCaptainRole::PrimaryCollection);
        $corrections = InvoiceFinancialCorrection::query()->where('original_invoice_id', $invoice->id)
            ->with(['refund', 'receivableDispositions'])->latest('id')->get();
        $settledCorrection = InvoiceFinancialCorrection::query()->where('replacement_invoice_id', $invoice->id)
            ->where('state', 'COMPLETED')->first();
        $correctionSource = $invoice->collection && $invoice->status === InvoiceStatus::Issued
            && $request->user()->can('invoice_corrections.request') ? $snapshots->read($invoice) : null;

        return view('invoices.show', compact('invoice', 'primaryCollector', 'corrections', 'settledCorrection', 'correctionSource'));
    }

    public function print(Request $request, Order $order, Invoice $invoice): View
    {
        Gate::authorize('invoices.view');
        $this->ownership($request, $order, $invoice);

        return view('invoices.print', ['invoice' => $invoice->load(['currency', 'items'])]);
    }

    public function store(Request $request, Order $order, InvoiceService $service): RedirectResponse
    {
        $invoice = $service->createDraft($request->user(), $order);

        return redirect()->route('orders.invoices.show', [$order, $invoice])->with('status', 'Invoice draft created.');
    }

    public function refresh(Request $request, Order $order, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->refresh($request->user(), $order, $invoice);

        return back()->with('status', 'Invoice snapshot refreshed and is READY.');
    }

    public function issue(Request $request, Order $order, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->issue($request->user(), $order, $invoice);

        return back()->with('status', 'Invoice issued.');
    }

    public function void(InvoiceReasonRequest $request, Order $order, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->void($request->user(), $order, $invoice, $request->input('reason'));

        return back()->with('status', 'Invoice voided.');
    }

    public function replace(InvoiceReasonRequest $request, Order $order, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $replacement = $service->replace($request->user(), $order, $invoice, $request->input('reason'));

        return redirect()->route('orders.invoices.show', [$order, $replacement])->with('status', 'Replacement Invoice created and is READY.');
    }

    private function ownership(Request $request, Order $order, Invoice $invoice): void
    {
        abort_unless(Order::query()->accessibleTo($request->user())->whereKey($order)->exists(), 403);
        abort_if($invoice->order_id !== $order->id, 404);
    }
}
