<?php

namespace App\Http\Controllers;

use App\Enums\PriceObservationSource;
use App\Http\Requests\PriceEstimateRequest;
use App\Http\Requests\ProductPriceObservationRequest;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\Shop;
use App\Models\Unit;
use App\Services\PriceEstimationSettings;
use App\Services\ProductPriceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductPriceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('product_prices.view');

        return $this->historyView($request, __('pricing.recent_price_observations'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('product_prices.create');

        return view('product-prices.create', [
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(),
            'shops' => Shop::query()->accessibleTo($request->user())->where('is_active', true)->orderBy('name')->get(),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'sources' => PriceObservationSource::cases(),
        ]);
    }

    public function store(ProductPriceObservationRequest $request, ProductPriceService $service): RedirectResponse
    {
        $observation = $service->record($request->user(), $request->validated());

        return redirect()->route('product-prices.index', ['product_id' => $observation->product_id])
            ->with('status', __('pricing.flash_price_observation_recorded_historical_observations_cannot_be_edited_or_deleted'));
    }

    public function productHistory(Request $request, Product $product): View
    {
        Gate::authorize('product_prices.view');
        $request->merge(['product_id' => $product->id]);

        return $this->historyView($request, __('pricing.history_title', ['name' => $product->name]), $product);
    }

    public function shopHistory(Request $request, Shop $shop): View
    {
        Gate::authorize('product_prices.view');
        abort_unless($request->user()->canAccessShop($shop), 403);
        $request->merge(['shop_id' => $shop->id]);

        return $this->historyView($request, __('pricing.history_title', ['name' => $shop->name]), null, $shop);
    }

    public function comparison(Request $request, Product $product, ProductPriceService $service): View
    {
        Gate::authorize('product_prices.reports');
        $filters = $request->validate([
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'recent_days' => ['nullable', 'integer', 'between:1,365'],
        ]);
        $prices = $service->comparisonQuery(
            $product,
            $request->user(),
            isset($filters['unit_id']) ? (int) $filters['unit_id'] : null,
            isset($filters['currency_id']) ? (int) $filters['currency_id'] : null,
            isset($filters['recent_days']) ? (int) $filters['recent_days'] : null,
        )->paginate(25)->withQueryString();

        return view('product-prices.comparison', [
            'product' => $product,
            'prices' => $prices,
            'units' => Unit::query()->orderBy('name')->get(),
            'currencies' => Currency::query()->orderBy('code')->get(),
        ]);
    }

    public function estimate(PriceEstimateRequest $request, ProductPriceService $service, PriceEstimationSettings $settings): View
    {
        $data = $request->validated();
        $result = null;
        if ($request->boolean('preview')) {
            $result = $service->estimateUnknownShopPrice(
                Product::query()->findOrFail($data['product_id']),
                Unit::query()->findOrFail($data['unit_id']),
                Currency::query()->findOrFail($data['currency_id']),
                isset($data['reference_at']) ? Carbon::parse($data['reference_at']) : now(),
                $request->user(),
            );
        }

        return view('product-prices.estimate', [
            'result' => $result,
            'windowDays' => $settings->windowDays(),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    private function historyView(Request $request, string $title, ?Product $lockedProduct = null, ?Shop $lockedShop = null): View
    {
        $query = ProductPriceHistory::query()->accessibleTo($request->user())
            ->with(['product', 'shop.area.branch', 'unit', 'currency', 'recordedBy'])
            ->when($request->filled('product_id'), fn (Builder $builder) => $builder->where('product_id', $request->integer('product_id')))
            ->when($request->filled('shop_id'), fn (Builder $builder) => $builder->where('shop_id', $request->integer('shop_id')))
            ->when($request->filled('unit_id'), fn (Builder $builder) => $builder->where('unit_id', $request->integer('unit_id')))
            ->when($request->filled('currency_id'), fn (Builder $builder) => $builder->where('currency_id', $request->integer('currency_id')))
            ->orderByDesc('observed_at')->orderByDesc('id');

        /** @var LengthAwarePaginator $observations */
        $observations = $query->paginate(25)->withQueryString();

        return view('product-prices.index', [
            'title' => $title,
            'observations' => $observations,
            'lockedProduct' => $lockedProduct,
            'lockedShop' => $lockedShop,
            'products' => Product::query()->orderBy('name')->get(),
            'shops' => Shop::query()->accessibleTo($request->user())->orderBy('name')->get(),
            'units' => Unit::query()->orderBy('name')->get(),
            'currencies' => Currency::query()->orderBy('code')->get(),
        ]);
    }
}
