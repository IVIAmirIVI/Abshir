<?php

namespace App\Services;

use App\Data\PriceEstimateResult;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProductPriceService
{
    public function __construct(private readonly PriceEstimationSettings $settings) {}

    public function record(User $actor, array $data): ProductPriceHistory
    {
        Gate::forUser($actor)->authorize('product_prices.create');

        $product = Product::query()->findOrFail($data['product_id']);
        $shop = Shop::query()->findOrFail($data['shop_id']);
        $unit = Unit::query()->findOrFail($data['unit_id']);
        $currency = Currency::query()->findOrFail($data['currency_id']);

        abort_unless($actor->canAccessShop($shop), 403);

        foreach (['product_id' => $product, 'shop_id' => $shop, 'unit_id' => $unit, 'currency_id' => $currency] as $field => $record) {
            if (! $record->is_active) {
                throw ValidationException::withMessages([$field => __('domain.new_observations_require_an_active_record')]);
            }
        }

        return ProductPriceHistory::query()->create([
            ...$data,
            'recorded_by' => $actor->id,
        ]);
    }

    public function getLatestShopPrice(Product $product, Shop $shop, Unit $unit, Currency $currency): ?ProductPriceHistory
    {
        return ProductPriceHistory::query()
            ->with(['product', 'shop', 'unit', 'currency'])
            ->whereBelongsTo($product)
            ->whereBelongsTo($shop)
            ->whereBelongsTo($unit)
            ->whereBelongsTo($currency)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
    }

    public function estimateUnknownShopPrice(
        Product $product,
        Unit $unit,
        Currency $currency,
        ?CarbonInterface $referenceTime = null,
        ?User $actor = null,
    ): PriceEstimateResult {
        $reference = Carbon::instance(($referenceTime ?? now())->toDateTime());
        $windowDays = $this->settings->windowDays();
        $ranked = $this->rankedObservations(
            $product,
            $unit->id,
            $currency->id,
            $reference->copy()->subDays($windowDays),
            $reference,
            $actor,
            false,
        );

        $selected = DB::query()->fromSub($ranked, 'latest_prices')
            ->where('shop_rank', 1)
            ->orderBy('price')
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        if (! $selected) {
            return PriceEstimateResult::unavailable($unit->id, $currency->id, $windowDays);
        }

        $observation = ProductPriceHistory::query()->with('shop')->findOrFail($selected->id);

        return new PriceEstimateResult(
            $observation->price,
            $observation->shop_id,
            $observation->shop,
            $observation->id,
            $observation->observed_at,
            $unit->id,
            $currency->id,
            $windowDays,
        );
    }

    public function comparisonQuery(
        Product $product,
        User $actor,
        ?int $unitId = null,
        ?int $currencyId = null,
        ?int $recentDays = null,
        ?CarbonInterface $referenceTime = null,
    ): Builder {
        $reference = Carbon::instance(($referenceTime ?? now())->toDateTime());
        $ranked = $this->rankedObservations(
            $product,
            $unitId,
            $currencyId,
            $recentDays ? $reference->copy()->subDays($recentDays) : null,
            $reference,
            $actor,
            true,
        );

        return DB::query()->fromSub($ranked, 'latest_prices')
            ->join('shops', 'shops.id', '=', 'latest_prices.shop_id')
            ->join('units', 'units.id', '=', 'latest_prices.unit_id')
            ->join('currencies', 'currencies.id', '=', 'latest_prices.currency_id')
            ->where('shop_rank', 1)
            ->select([
                'latest_prices.id', 'latest_prices.shop_id', 'latest_prices.unit_id', 'latest_prices.currency_id',
                'latest_prices.price', 'latest_prices.observed_at', 'latest_prices.source',
                'shops.name as shop_name', 'units.name as unit_name', 'units.code as unit_code',
                'currencies.code as currency_code',
            ])
            ->orderBy('units.name')
            ->orderBy('currencies.code')
            ->orderBy('latest_prices.price')
            ->orderBy('shops.name');
    }

    private function rankedObservations(
        Product $product,
        ?int $unitId,
        ?int $currencyId,
        ?CarbonInterface $from,
        CarbonInterface $to,
        ?User $actor,
        bool $partitionByContext,
    ): Builder {
        $partition = $partitionByContext ? 'pph.shop_id, pph.unit_id, pph.currency_id' : 'pph.shop_id';
        $query = DB::table('product_price_history as pph')
            ->select('pph.*')
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$partition} ORDER BY pph.observed_at DESC, pph.id DESC) AS shop_rank")
            ->where('pph.product_id', $product->id)
            ->where('pph.observed_at', '<=', $to->format('Y-m-d H:i:sP'))
            ->when($unitId, fn (Builder $builder) => $builder->where('pph.unit_id', $unitId))
            ->when($currencyId, fn (Builder $builder) => $builder->where('pph.currency_id', $currencyId))
            ->when($from, fn (Builder $builder) => $builder->where('pph.observed_at', '>=', $from->format('Y-m-d H:i:sP')));

        if ($actor && ! $actor->isSuperAdmin()) {
            $query->whereIn('pph.shop_id', Shop::query()->accessibleTo($actor)->select('shops.id'));
        }

        return $query;
    }
}
