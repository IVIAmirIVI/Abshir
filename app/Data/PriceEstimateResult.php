<?php

namespace App\Data;

use App\Models\Shop;
use Illuminate\Support\Carbon;

final readonly class PriceEstimateResult
{
    public const METHOD = 'LATEST_SHOP_LOWEST_PRICE';

    public function __construct(
        public ?string $price,
        public ?int $sourceShopId,
        public ?Shop $sourceShop,
        public ?int $sourceObservationId,
        public ?Carbon $sourceObservedAt,
        public int $unitId,
        public int $currencyId,
        public int $estimationWindowDays,
        public string $estimationMethod = self::METHOD,
    ) {}

    public function available(): bool
    {
        return $this->sourceObservationId !== null;
    }

    public static function unavailable(int $unitId, int $currencyId, int $windowDays): self
    {
        return new self(null, null, null, null, null, $unitId, $currencyId, $windowDays);
    }
}
