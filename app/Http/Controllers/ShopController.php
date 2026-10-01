<?php

namespace App\Http\Controllers;

use App\Enums\ShopPartnershipStatus;
use App\Http\Requests\ShopRequest;
use App\Models\Area;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\Unit;
use App\Services\ShopService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ShopController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('shops.view');
        $search = trim($request->string('search')->toString());
        $shops = Shop::query()->accessibleTo($request->user())->with(['type', 'area.branch'])
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->when($request->filled('shop_type_id'), fn (Builder $query) => $query->where('shop_type_id', $request->integer('shop_type_id')))
            ->when($request->filled('area_id'), fn (Builder $query) => $query->where('area_id', $request->integer('area_id')))
            ->when($request->filled('branch_id'), fn (Builder $query) => $query->whereHas('area', fn (Builder $areas) => $areas->where('branch_id', $request->integer('branch_id'))))
            ->when($request->filled('active'), fn (Builder $query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('shops.index', [
            'shops' => $shops,
            'types' => ShopType::query()->orderBy('name')->get(),
            'areas' => Area::query()->accessibleTo($request->user())->with('branch')->orderBy('name')->get(),
            'branches' => $request->user()->availableBranches()->orderBy('name')->get(),
            'search' => $search,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('shops.create');

        return $this->form($request, new Shop);
    }

    public function store(ShopRequest $request, ShopService $service): RedirectResponse
    {
        $shop = $service->save($request->user(), new Shop, $request->validated());

        return redirect()->route('shops.show', $shop)->with('status', __('shops.flash_shop_created'));
    }

    public function show(Request $request, Shop $shop): View
    {
        Gate::authorize('shops.view');
        abort_unless($request->user()->canAccessShop($shop), 403);
        $shop->load([
            'type', 'area.branch',
            'products' => fn ($query) => $query->with(['product', 'unit', 'currency'])->orderByDesc('is_active')->latest(),
            'partnerships' => fn ($query) => $query->with(['createdBy', 'endedBy'])->latest('starts_at'),
        ]);

        return view('shops.show', [
            'shop' => $shop,
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'partnershipStatuses' => [ShopPartnershipStatus::Active, ShopPartnershipStatus::Suspended],
        ]);
    }

    public function edit(Request $request, Shop $shop): View
    {
        Gate::authorize('shops.update');
        abort_unless($request->user()->canAccessShop($shop), 403);

        return $this->form($request, $shop);
    }

    public function update(ShopRequest $request, Shop $shop, ShopService $service): RedirectResponse
    {
        $service->save($request->user(), $shop, $request->validated());

        return redirect()->route('shops.show', $shop)->with('status', __('shops.flash_shop_updated'));
    }

    public function status(Request $request, Shop $shop, ShopService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $shop, (bool) $data['is_active']);

        return back()->with('status', __('shops.flash_shop_status_updated'));
    }

    private function form(Request $request, Shop $shop): View
    {
        return view('shops.form', [
            'shop' => $shop,
            'types' => ShopType::query()->where('is_active', true)->orWhereKey($shop->shop_type_id)->orderBy('name')->get(),
            'areas' => Area::query()->accessibleTo($request->user())->where(function (Builder $query) use ($shop): void {
                $query->where('is_active', true)->when($shop->area_id, fn (Builder $areas) => $areas->orWhereKey($shop->area_id));
            })->with('branch')->orderBy('name')->get(),
        ]);
    }
}
