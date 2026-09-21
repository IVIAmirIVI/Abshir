<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerPhoneService
{
    public function __construct(public PhoneNormalizer $normalizer, private readonly CustomerPhoneDuplicateService $duplicates) {}

    public function save(User $actor, Customer $customer, CustomerPhone $phone, array $data): CustomerPhone
    {
        Gate::forUser($actor)->authorize('customers.update');
        abort_unless($actor->canAccessCustomer($customer), 403);
        abort_if($phone->exists && $phone->customer_id !== $customer->id, 404);
        if ($this->duplicates->visibleMatches($actor, $data['phone'], $customer->id)->isNotEmpty() && ! ($data['duplicate_acknowledged'] ?? false)) {
            throw ValidationException::withMessages(['phone' => 'This phone number already exists for another customer. Confirm that you want to continue.']);
        }

        return DB::transaction(function () use ($customer, $phone, $data): CustomerPhone {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($phone->exists) {
                $phone = CustomerPhone::query()->lockForUpdate()->findOrFail($phone->id);
            }
            $phone->fill([
                'phone' => $data['phone'],
                'normalized_phone' => $this->normalizer->normalize($data['phone']),
                'label' => $data['label'] ?? null,
            ]);
            $phone->customer_id = $customer->id;
            $makePrimary = (bool) ($data['is_primary'] ?? $phone->is_primary)
                || ! $customer->phones()->where('id', '!=', $phone->id)->where('is_primary', true)->exists();
            if ($makePrimary) {
                $customer->phones()->where('id', '!=', $phone->id)->update(['is_primary' => false]);
            }
            $phone->is_primary = $makePrimary;
            $phone->save();

            return $phone;
        });
    }

    public function makePrimary(User $actor, Customer $customer, CustomerPhone $phone): void
    {
        $this->assertAccess($actor, $customer, $phone);
        DB::transaction(function () use ($customer, $phone): void {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $customer->phones()->update(['is_primary' => false]);
            $phone->is_primary = true;
            $phone->save();
        });
    }

    public function delete(User $actor, Customer $customer, CustomerPhone $phone): void
    {
        $this->assertAccess($actor, $customer, $phone);
        DB::transaction(function () use ($customer, $phone): void {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $wasPrimary = $phone->is_primary;
            $phone->delete();
            if ($wasPrimary) {
                $next = $customer->phones()->oldest()->first();
                if ($next) {
                    $next->is_primary = true;
                    $next->save();
                }
            }
        });
    }

    private function assertAccess(User $actor, Customer $customer, CustomerPhone $phone): void
    {
        Gate::forUser($actor)->authorize('customers.update');
        abort_unless($actor->canAccessCustomer($customer), 403);
        abort_if($phone->customer_id !== $customer->id, 404);
    }
}
