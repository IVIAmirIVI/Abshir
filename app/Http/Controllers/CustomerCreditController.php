<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Services\CustomerCreditCashPayoutService;
use App\Services\CustomerCreditService;
use App\Services\CustomerRefundEvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class CustomerCreditController extends Controller
{
    public function payout(Request $request, Customer $customer, CustomerCreditCashPayoutService $service): RedirectResponse
    {
        $request->validate(['evidence' => ['required', 'file']]);
        $service->record($request->user(), $customer, $request->except('_token', 'evidence'), $request->file('evidence'));

        return redirect()->route('customers.show', $customer)->with('status', __('credit.cash_paid'));
    }

    public function evidence(Request $request, Customer $customer, int $payout, CustomerCreditService $credits, CustomerRefundEvidenceService $evidence): Response
    {
        $credits->authorize($request->user(), $customer);
        abort_unless(Schema::hasTable('customer_credit_cash_payouts'), 404);
        $source = DB::table('customer_credit_cash_payouts')->where('customer_id', $customer->id)->find($payout);
        abort_unless($source && $request->user()->canAccessBranch(Branch::findOrFail($source->branch_id)), 404);

        return response($evidence->bytes($source->evidence_path, $source->evidence_sha256, $source->evidence_mime, $source->evidence_size), 200,
            ['Content-Type' => $source->evidence_mime, 'Content-Disposition' => 'attachment; filename="credit-payout-'.$source->id.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function store(Request $request, Customer $customer, CustomerCreditService $service): RedirectResponse
    {
        $service->grant($request->user(), $customer, $request->except('_token'));

        return redirect()->route('customers.show', $customer)->with('status', __('credit.granted'));
    }
}
