<?php

namespace App\Http\Controllers;

use App\Models\BillPaymentFeeRule;
use App\Models\Currency;
use App\Services\BillPaymentFeeRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BillPaymentFeeRuleController extends Controller
{
    public function index(): View
    {
        Gate::authorize('bill_payment_fees.manage');

        return view('bill-payment-fees.index', [
            'rules' => BillPaymentFeeRule::query()->with('currency')->orderByDesc('effective_from')->paginate(25),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, BillPaymentFeeRuleService $service): RedirectResponse
    {
        $data = $this->validated($request);
        $service->create($request->user(), (int) $data['currency_id'], $data['fixed_fee'], $data['effective_from']);

        return back()->with('status', __('admin.ui.bill_payment_fee_rule_created'));
    }

    public function version(Request $request, BillPaymentFeeRule $rule, BillPaymentFeeRuleService $service): RedirectResponse
    {
        $data = $request->validate(['fixed_fee' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/'], 'effective_from' => ['required', 'date']]);
        $service->version($request->user(), $rule, $data['fixed_fee'], $data['effective_from']);

        return back()->with('status', __('admin.ui.bill_payment_fee_version_created'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'fixed_fee' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'effective_from' => ['required', 'date'],
        ]);
    }
}
