<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainDepositManualAdjustment;
use App\Models\Currency;
use App\Services\CaptainDepositManualAdjustmentEvidenceService;
use App\Services\CaptainDepositManualAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CaptainDepositManualAdjustmentController extends Controller
{
    private function authorizeSuperAdmin(Request $request): void
    {
        Gate::authorize(CaptainDepositManualAdjustmentService::PERMISSION);
        abort_unless($request->user()->is_active && $request->user()->isSuperAdmin(), 403);
    }

    public function store(Request $request, Captain $captain, CaptainDepositManualAdjustmentService $service): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'request_key' => ['required', 'uuid'], 'direction' => ['required', 'in:INCREASE,DECREASE'],
            'amount' => ['required', 'string'], 'reason' => ['required', 'string'],
            'occurred_at' => ['required', 'date'], 'evidence' => ['required', 'file', 'max:5120'],
            'account_kind' => ['prohibited'], 'adjusted_by_user_id' => ['prohibited'],
            'source_type' => ['prohibited'], 'order_id' => ['prohibited'], 'invoice_id' => ['prohibited'],
        ]);
        $service->record($request->user(), $captain, Branch::findOrFail($data['branch_id']), Currency::findOrFail($data['currency_id']),
            $data, $request->file('evidence'));

        return back()->with('status', __('financial.flash.adjustment_recorded'));
    }

    public function evidence(Request $request, Captain $captain, CaptainDepositManualAdjustment $adjustment, CaptainDepositManualAdjustmentEvidenceService $evidence): Response
    {
        $this->authorizeSuperAdmin($request);
        abort_unless($adjustment->captain_id === $captain->id, 404);
        abort_unless($adjustment->branch && $adjustment->currency && $request->user()->canAccessBranch($adjustment->branch), 403);
        try {
            $bytes = $evidence->bytes($adjustment->evidence_path, $adjustment->evidence_sha256, $adjustment->evidence_mime, $adjustment->evidence_size);
        } catch (\Throwable) {
            abort(409, 'Adjustment evidence integrity check failed.');
        }

        return response($bytes, 200, ['Content-Type' => $adjustment->evidence_mime,
            'Content-Disposition' => 'attachment; filename="manual-deposit-adjustment-'.$adjustment->id.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
