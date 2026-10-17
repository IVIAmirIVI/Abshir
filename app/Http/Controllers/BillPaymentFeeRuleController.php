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
            'supportsTiers' => app(BillPaymentFeeRuleService::class)->supportsTiers(),
        ]);
    }

    public function store(Request $request, BillPaymentFeeRuleService $service): RedirectResponse
    {
        Gate::authorize('bill_payment_fees.manage');
        $data = $this->validated($request);
        $service->create($request->user(), (int) $data['currency_id'], $data['fixed_fee'] ?? null, $data['effective_from'], ($data['fee_mode'] ?? 'FIXED') === 'TIERED' ? $data['fee_tiers'] : null);

        return back()->with('status', __('admin.ui.bill_payment_fee_rule_created'));
    }

    public function version(Request $request, BillPaymentFeeRule $rule, BillPaymentFeeRuleService $service): RedirectResponse
    {
        Gate::authorize('bill_payment_fees.manage');
        $data = $this->validated($request, false);
        $service->version($request->user(), $rule, $data['fixed_fee'] ?? null, $data['effective_from'], ($data['fee_mode'] ?? 'FIXED') === 'TIERED' ? $data['fee_tiers'] : null);

        return back()->with('status', __('admin.ui.bill_payment_fee_version_created'));
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'currency_id' => [$creating ? 'required' : 'nullable', 'integer', 'exists:currencies,id'],
            'fee_mode' => ['nullable', 'in:FIXED,TIERED'],
            'fixed_fee' => ['required_unless:fee_mode,TIERED', 'nullable', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'fee_tiers' => ['required_if:fee_mode,TIERED', 'array'],
            'fee_tiers.*.from' => ['required', 'string'],
            'fee_tiers.*.until' => ['nullable', 'string'],
            'fee_tiers.*.type' => ['required', 'in:FIXED,PERCENTAGE'],
            'fee_tiers.*.value' => ['required', 'string'],
            'effective_from' => ['required', 'date'],
        ]);
    }
}
