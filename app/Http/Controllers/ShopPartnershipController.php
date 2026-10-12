<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShopPartnershipRequest;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Models\ShopPartnership;
use App\Services\ShopCommissionActivationService;
use App\Services\ShopCommissionDraftService;
use App\Services\ShopCommissionRuleResolver;
use App\Services\ShopPartnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShopPartnershipController extends Controller
{
    public function commissions(Request $request, Shop $shop, ShopPartnership $partnership, ShopCommissionDraftService $service): View
    {
        $service->assertAccess($request->user(), $shop, $partnership);
        $filters = $request->validate([
            'history' => ['nullable', 'boolean'], 'target' => ['nullable', Rule::in(['shop', 'product', 'group'])], 'search' => ['nullable', 'string', 'max:255'],
            'preview_product_id' => ['nullable', 'required_with:preview_currency_id,preview_at', 'integer', 'exists:products,id'],
            'preview_currency_id' => ['nullable', 'required_with:preview_product_id,preview_at', 'integer', 'exists:currencies,id'],
            'preview_at' => ['nullable', 'required_with:preview_product_id,preview_currency_id', 'date'],
        ]);
        $installed = Schema::hasTable('shop_commission_drafts');
        $agreementsInstalled = $installed && Schema::hasColumn('shop_commission_drafts', 'method');
        $accountingInstalled = $installed && Schema::hasTable('shop_commission_activation_events');
        $activations = $accountingInstalled ? DB::table('shop_commission_activation_events as e')->join('shop_commission_drafts as d', 'd.id', '=', 'e.draft_id')->join('users as activation_actor', 'activation_actor.id', '=', 'e.recorded_by')
            ->where('d.shop_partnership_id', $partnership->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('shop_commission_activation_events as newer')
            ->whereColumn('newer.configuration_key', 'e.configuration_key')->whereColumn('newer.id', '>', 'e.id'))->select('e.*', 'activation_actor.name as actor_name')->get()->keyBy('draft_id') : collect();
        $preview = filled($filters['preview_product_id'] ?? null) ? app(ShopCommissionRuleResolver::class)->preview(
            $request->user(), $shop, $partnership, Product::findOrFail($filters['preview_product_id']),
            Currency::findOrFail($filters['preview_currency_id']), Carbon::parse($filters['preview_at'], 'Asia/Damascus')) : null;
        $drafts = $installed ? $partnership->commissionDrafts()->with(['createdBy', 'nextVersion'])
            ->when(! ($filters['history'] ?? false), fn ($q) => $q->where(fn ($current) => $current->whereDoesntHave('nextVersion')->orWhereIn('id', $activations->where('state', 'ACTIVE')->keys())))
            ->when(($filters['target'] ?? '') === 'product', fn ($q) => $q->whereNotNull('product_id'))
            ->when(($filters['target'] ?? '') === 'group', fn ($q) => $q->whereNotNull('product_group_id'))
            ->when(($filters['target'] ?? '') === 'shop', fn ($q) => $q->whereNull('product_id')->whereNull('product_group_id'))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('target_name_snapshot', 'ilike', '%'.$filters['search'].'%'))
            ->orderByDesc('recorded_at')->orderByDesc('id')->paginate(20)->withQueryString() : null;

        return view('shops.commission-drafts', [
            'shop' => $shop, 'partnership' => $partnership, 'installed' => $installed, 'agreementsInstalled' => $agreementsInstalled, 'accountingInstalled' => $accountingInstalled, 'activations' => $activations, 'drafts' => $drafts, 'filters' => $filters, 'preview' => $preview,
            'products' => $installed && $request->user()->can('products.view') ? Product::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'groups' => $installed && $request->user()->can('product_groups.view') ? ProductGroup::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            'currencies' => $installed && $request->user()->can('currencies.view') ? Currency::where('is_active', true)->orderBy('code')->get(['id', 'code']) : collect(),
        ]);
    }

    public function commissionActivation(Request $request, Shop $shop, ShopPartnership $partnership, int $draft, ShopCommissionActivationService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']]);
        $service->change($request->user(), $shop, $partnership, $draft, (bool) $data['is_active'], $data['reason']);

        return redirect()->route('shops.partnerships.commissions', [$shop, $partnership])->with('status', __('commissions.activation_saved'));
    }

    public function commissionStore(Request $request, Shop $shop, ShopPartnership $partnership, ShopCommissionDraftService $service): RedirectResponse
    {
        $service->create($request->user(), $shop, $partnership, $request->except('_token', '_method'));

        return redirect()->route('shops.partnerships.commissions', [$shop, $partnership])->with('status', __('commissions.saved'));
    }

    public function commissionRevise(Request $request, Shop $shop, ShopPartnership $partnership, int $draft, ShopCommissionDraftService $service): RedirectResponse
    {
        $service->revise($request->user(), $shop, $partnership, $draft, $request->except('_token', '_method'));

        return redirect()->route('shops.partnerships.commissions', [$shop, $partnership])->with('status', __('commissions.saved'));
    }

    public function store(ShopPartnershipRequest $request, Shop $shop, ShopPartnershipService $service): RedirectResponse
    {
        $service->start($request->user(), $shop, $request->validated());

        return back()->with('status', __('shops.flash_shop_partnership_added'));
    }

    public function end(Request $request, Shop $shop, ShopPartnership $partnership, ShopPartnershipService $service): RedirectResponse
    {
        $service->end($request->user(), $shop, $partnership);

        return back()->with('status', __('shops.flash_shop_partnership_ended'));
    }
}
