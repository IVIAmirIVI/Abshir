<?php

namespace App\Http\Controllers;

use App\Models\Captain;
use App\Models\CaptainReceivableRecovery;
use App\Models\Invoice;
use App\Models\InvoiceFinancialCorrection;
use App\Services\CaptainReceivableRecoveryEvidenceService;
use App\Services\CaptainReceivableRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CaptainReceivableRecoveryController extends Controller
{
    public function store(Request $request, InvoiceFinancialCorrection $correction, Captain $captain, CaptainReceivableRecoveryService $service): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'received_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:191'], 'evidence' => ['required', 'file', 'max:5120'],
            'amount' => ['prohibited'], 'branch_id' => ['prohibited'], 'currency_id' => ['prohibited'], 'received_by_user_id' => ['prohibited'], 'method' => ['prohibited']]);
        $service->record($request->user(), $correction, $captain, $data, $request->file('evidence'));

        return back()->with('status', __('financial.flash.recovery_recorded'));
    }

    public function evidence(Request $request, InvoiceFinancialCorrection $correction, CaptainReceivableRecovery $recovery, CaptainReceivableRecoveryEvidenceService $evidence): Response
    {
        Gate::authorize('captain_receivable_recoveries.view_evidence');
        abort_unless($recovery->invoice_financial_correction_id === $correction->id && $recovery->branch_id === $correction->branch_id
            && $recovery->currency_id === $correction->currency_id, 404);
        abort_unless(Invoice::query()->accessibleTo($request->user())->whereKey($correction->original_invoice_id)
            ->where('order_id', $correction->order_id)->whereHas('order', fn ($q) => $q->where('branch_id', $recovery->branch_id))->exists(), 403);
        $bytes = $evidence->bytes($recovery->evidence_path, $recovery->evidence_sha256, $recovery->evidence_mime, $recovery->evidence_size);

        return response($bytes, 200, ['Content-Type' => $recovery->evidence_mime, 'Content-Disposition' => 'attachment; filename="captain-recovery-'.$recovery->id.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
