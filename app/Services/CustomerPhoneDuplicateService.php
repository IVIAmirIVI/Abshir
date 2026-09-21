<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Collection;

class CustomerPhoneDuplicateService
{
    public function __construct(private readonly PhoneNormalizer $normalizer) {}

    public function visibleMatches(User $actor, string $phone, ?int $excludeCustomerId = null): Collection
    {
        $normalized = $this->normalizer->normalize($phone);
        if ($normalized === '') {
            return collect();
        }

        return Customer::query()->accessibleTo($actor)
            ->when($excludeCustomerId, fn ($query) => $query->whereKeyNot($excludeCustomerId))
            ->whereHas('phones', fn ($phones) => $phones->where('normalized_phone', $normalized))
            ->orderBy('name')->get(['id', 'name']);
    }
}
