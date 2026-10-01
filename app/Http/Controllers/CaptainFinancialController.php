<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainDepositManualAdjustment;
use App\Models\CaptainDepositReceipt;
use App\Models\CaptainReceivablePayout;
use App\Models\Currency;
use App\Services\CaptainDepositReceiptService;
use App\Services\CaptainFinancialEvidenceService;
use App\Services\CaptainReceivablePayoutService;
use App\Services\FinancialBalanceQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CaptainFinancialController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('captain_financials.view');
        $branchIds = $request->user()->availableBranches()->pluck('branches.id');
        $captainIds = DB::table('financial_entries as e')
            ->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->whereIn('t.branch_id', $branchIds)->where('o.owner_type', 'CAPTAIN')
            ->distinct()->pluck('o.captain_id');

        return view('captain-financials.index', [
            'captains' => Captain::query()->with('user')->whereIn('id', $captainIds)->orderBy('id')->paginate(25),
        ]);
    }

    public function show(Request $request, Captain $captain, FinancialBalanceQueryService $balances): View
    {
        Gate::authorize('captain_financials.view');
        $branches = $request->user()->availableBranches()->orderBy('name')->get();
        $branchIds = $branches->pluck('id');
        $balanceBranchIds = $request->user()->isSuperAdmin() ? null : $branchIds->all();
        $currencies = Currency::query()->orderBy('code')->get();
        $rows = collect();
        foreach ($currencies as $currency) {
            foreach ($balances->captainBalancesByBranch($captain, $currency, $balanceBranchIds) as $branchId => $values) {
                $branch = $branches->firstWhere('id', (int) $branchId);
                if ($branch) {
                    $rows->push(compact('branch', 'currency', 'values'));
                }
            }
        }
        if (! $request->user()->canAccessCaptain($captain) && $rows->isEmpty()) {
            abort(403);
        }
        $selectedBranch = $branches->firstWhere('id', (int) $request->query('branch_id'));
        $selectedCurrency = $currencies->firstWhere('id', (int) $request->query('currency_id'));
        $outstanding = $selectedBranch && $selectedCurrency
            ? $balances->payableReceivableEntries($captain, $selectedBranch, $selectedCurrency) : collect();
        $manualAdjustmentsEnabled = $request->user()->isSuperAdmin()
            && DB::scalar("SELECT to_regprocedure('captain_manual_deposits_active()') IS NOT NULL")
            && DB::scalar('SELECT captain_manual_deposits_active()');

        return view('captain-financials.show', [
            'captain' => $captain->load('user'), 'rows' => $rows, 'branches' => $branches,
            'currencies' => $currencies, 'selectedBranch' => $selectedBranch,
            'selectedCurrency' => $selectedCurrency, 'outstanding' => $outstanding,
            'manualAdjustmentsEnabled' => $manualAdjustmentsEnabled,
            'manualAdjustments' => $manualAdjustmentsEnabled
                ? CaptainDepositManualAdjustment::query()->where('captain_id', $captain->id)
                    ->with(['branch', 'currency', 'adjustedBy'])->latest('id')->limit(30)->get() : collect(),
            'adjustmentBranches' => $manualAdjustmentsEnabled
                ? $branches->filter(fn ($branch) => DB::scalar('SELECT manual_deposit_branch_legitimate(?,?)', [$captain->id, $branch->id])) : collect(),
            'receipts' => $request->user()->can('captain_deposits.view')
                ? CaptainDepositReceipt::query()->where('captain_id', $captain->id)->whereIn('branch_id', $branchIds)
                    ->with(['branch', 'currency', 'receivedBy'])->latest('id')->limit(30)->get() : collect(),
            'payouts' => $request->user()->can('captain_receivable_payouts.view')
                ? CaptainReceivablePayout::query()->where('captain_id', $captain->id)->whereIn('branch_id', $branchIds)
                    ->with(['branch', 'currency', 'paidBy', 'allocations'])->latest('id')->limit(30)->get() : collect(),
        ]);
    }

    public function deposit(Request $request, Captain $captain, CaptainDepositReceiptService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'request_key' => ['required', 'uuid'], 'amount' => ['required', 'string'],
            'occurred_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string'], 'evidence' => ['required', 'file', 'max:5120'],
        ]);
        $service->record($request->user(), $captain, Branch::findOrFail($data['branch_id']),
            Currency::findOrFail($data['currency_id']), $data, $request->file('evidence'));

        return back()->with('status', 'Captain Deposit receipt recorded.');
    }

    public function payout(Request $request, Captain $captain, CaptainReceivablePayoutService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'request_key' => ['required', 'uuid'], 'amount' => ['required', 'string'],
            'occurred_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string'], 'evidence' => ['required', 'file', 'max:5120'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.financial_entry_id' => ['required', 'integer'],
            'allocations.*.amount' => ['nullable', 'string'],
        ]);
        $allocations = collect($data['allocations'])->filter(fn ($allocation) => filled($allocation['amount'] ?? null)
            && ! preg_match('/^0+(?:\.0+)?$/', trim((string) $allocation['amount'])))->values()->all();
        $service->record($request->user(), $captain, Branch::findOrFail($data['branch_id']),
            Currency::findOrFail($data['currency_id']), $data, $allocations, $request->file('evidence'));

        return back()->with('status', 'Attributed Captain Receivable payout recorded.');
    }

    public function depositEvidence(Request $request, Captain $captain, CaptainDepositReceipt $receipt, CaptainFinancialEvidenceService $evidence): Response
    {
        Gate::authorize('captain_financial_evidence.download');
        Gate::authorize('captain_deposits.view');
        abort_unless($receipt->captain_id === $captain->id, 404);
        abort_unless($request->user()->canAccessBranch($receipt->branch), 403);

        return $this->evidenceResponse($evidence, $receipt);
    }

    public function payoutEvidence(Request $request, Captain $captain, CaptainReceivablePayout $payout, CaptainFinancialEvidenceService $evidence): Response
    {
        Gate::authorize('captain_financial_evidence.download');
        Gate::authorize('captain_receivable_payouts.view');
        abort_unless($payout->captain_id === $captain->id, 404);
        abort_unless($request->user()->canAccessBranch($payout->branch), 403);

        return $this->evidenceResponse($evidence, $payout);
    }

    private function evidenceResponse(CaptainFinancialEvidenceService $evidence, CaptainDepositReceipt|CaptainReceivablePayout $source): Response
    {
        try {
            $bytes = $evidence->bytes($source->evidence_path, $source->evidence_sha256);
        } catch (\Throwable) {
            abort(409, 'Evidence integrity check failed.');
        }
        abort_unless(strlen($bytes) === (int) $source->evidence_size
            && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $source->evidence_mime, 409, 'Evidence integrity check failed.');

        return response($bytes, 200, ['Content-Type' => $source->evidence_mime,
            'Content-Disposition' => 'attachment; filename="captain-evidence-'.$source->id.'"']);
    }
}
