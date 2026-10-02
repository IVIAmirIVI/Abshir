<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceFinancialCorrectionKind;
use App\Models\CustomerRefund;
use App\Models\Invoice;
use App\Models\InvoiceFinancialCorrection;
use App\Services\CustomerRefundEvidenceService;
use App\Services\CustomerRefundService;
use App\Services\InvoiceFinancialCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InvoiceFinancialCorrectionController extends Controller
{
    public function request(Request $request, Invoice $invoice, InvoiceFinancialCorrectionService $service): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(InvoiceFinancialCorrectionKind::class)],
            'request_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:191'],
            'explanation' => ['required', 'string', 'max:4000'],
            'items' => ['nullable', 'array'],
            'items.*.invoice_item_id' => ['required', 'integer'],
            'items.*.unit_price' => ['required', 'string'],
            'delivery' => ['nullable', 'array'],
            'delivery.*.order_captain_id' => ['required', 'integer'],
            'delivery.*.customer_amount' => ['required', 'string'],
        ]);
        $kind = InvoiceFinancialCorrectionKind::from($data['kind']);
        $proposal = $kind === InvoiceFinancialCorrectionKind::CancelAndRefund ? [] : [
            'items' => $data['items'] ?? [], 'delivery' => $data['delivery'] ?? [],
        ];
        $service->request($request->user(), $invoice, $kind, $proposal, $data['reason'],
            $data['explanation'], $data['request_key']);

        return back()->with('status', __('financial.flash.correction_requested'));
    }

    public function prepare(Request $request, InvoiceFinancialCorrection $correction, InvoiceFinancialCorrectionService $service): RedirectResponse
    {
        $service->prepare($request->user(), $correction);

        return back()->with('status', __('financial.flash.correction_prepared'));
    }

    public function abandon(Request $request, InvoiceFinancialCorrection $correction, InvoiceFinancialCorrectionService $service): RedirectResponse
    {
        $service->abandon($request->user(), $correction);

        return back()->with('status', __('financial.flash.correction_abandoned'));
    }

    public function refund(Request $request, InvoiceFinancialCorrection $correction, CustomerRefundService $service): RedirectResponse
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'refunded_at' => ['required', 'date'],
            'reference' => ['required', 'string', 'max:191'],
            'evidence' => ['required', 'file', 'max:5120'],
        ]);
        $service->record($request->user(), $correction, $data['request_key'], $data['refunded_at'],
            $data['reference'], $request->file('evidence'));

        return back()->with('status', __('financial.flash.refund_recorded'));
    }

    public function finalize(Request $request, InvoiceFinancialCorrection $correction, InvoiceFinancialCorrectionService $service): RedirectResponse
    {
        $service->finalize($request->user(), $correction);

        return back()->with('status', __('financial.flash.correction_finalized'));
    }

    public function evidence(Request $request, CustomerRefund $refund, CustomerRefundEvidenceService $evidence): Response
    {
        Gate::authorize('customer_refunds.view_evidence');
        $correction = $refund->correction;
        abort_unless(InvoiceFinancialCorrection::query()->whereKey($correction->id)
            ->where('original_invoice_id', $refund->original_invoice_id)
            ->where('original_collection_id', $refund->original_collection_id)
            ->where('order_id', $correction->order_id)
            ->where('branch_id', $refund->branch_id)
            ->where('currency_id', $refund->currency_id)->exists(), 404);
        abort_unless(Invoice::query()->accessibleTo($request->user())
            ->whereKey($correction->original_invoice_id)->where('order_id', $correction->order_id)->exists(), 403);
        $bytes = $evidence->bytes($refund->evidence_path, $refund->evidence_sha256,
            $refund->evidence_mime, $refund->evidence_size);

        return response($bytes, 200, [
            'Content-Type' => $refund->evidence_mime,
            'Content-Disposition' => 'attachment; filename="refund-evidence-'.$refund->id.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
