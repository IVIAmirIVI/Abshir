<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeliveryPricingRuleRequest;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\DeliveryPricingRule;
use App\Services\DeliveryPricingRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeliveryPricingRuleController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('delivery_pricing_rules.view');
        $rules = DeliveryPricingRule::query()->with(['fromArea.parent', 'toArea.parent', 'currency'])->when($request->filled('branch_id'), fn ($q) => $q->whereHas('fromArea', fn ($areas) => $areas->where('branch_id', $request->integer('branch_id'))))->when($request->filled('from_area_id'), fn ($q) => $q->where('from_area_id', $request->integer('from_area_id')))->when($request->filled('to_area_id'), fn ($q) => $q->where('to_area_id', $request->integer('to_area_id')))->when($request->filled('currency_id'), fn ($q) => $q->where('currency_id', $request->integer('currency_id')))->when($request->input('scope') === 'current', fn ($q) => $q->effectiveAt(now()))->latest('effective_from')->paginate(25)->withQueryString();

        return view('delivery-pricing-rules.index', ['rules' => $rules, ...$this->formData()]);
    }

    public function create(): View
    {
        Gate::authorize('delivery_pricing_rules.create');

        return view('delivery-pricing-rules.form', [...$this->formData(), 'rule' => new DeliveryPricingRule]);
    }

    public function store(DeliveryPricingRuleRequest $request, DeliveryPricingRuleService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated());

        return redirect()->route('delivery-pricing-rules.index')->with('status', 'Delivery pricing rule created.');
    }

    public function edit(DeliveryPricingRule $deliveryPricingRule): View
    {
        Gate::authorize('delivery_pricing_rules.update');

        return view('delivery-pricing-rules.form', [...$this->formData(), 'rule' => $deliveryPricingRule]);
    }

    public function update(DeliveryPricingRuleRequest $request, DeliveryPricingRule $deliveryPricingRule, DeliveryPricingRuleService $service): RedirectResponse
    {
        $service->version($request->user(), $deliveryPricingRule, $request->validated());

        return redirect()->route('delivery-pricing-rules.index')->with('status', 'New delivery pricing version created.');
    }

    public function status(Request $request, DeliveryPricingRule $deliveryPricingRule, DeliveryPricingRuleService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $deliveryPricingRule, (bool) $data['is_active']);

        return back()->with('status', 'Rule status updated.');
    }

    private function formData(): array
    {
        return ['areas' => Area::with('parent')->orderBy('name')->get(), 'branches' => Branch::orderBy('name')->get(), 'currencies' => Currency::where('is_active', true)->orderBy('code')->get()];
    }
}
