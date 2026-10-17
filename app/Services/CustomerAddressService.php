<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CustomerAddressService
{
    public function save(User $actor, Customer $customer, CustomerAddress $address, array $data): CustomerAddress
    {
        Gate::forUser($actor)->authorize('customers.update');
        abort_unless($actor->canAccessCustomer($customer), 403);
        abort_if($address->exists && $address->customer_id !== $customer->id, 404);

        return DB::transaction(function () use ($actor, $customer, $address, $data): CustomerAddress {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($address->exists) {
                $address = CustomerAddress::query()->lockForUpdate()->findOrFail($address->id);
                abort_unless($actor->canAccessArea($address->area), 403);
            }
            $area = Area::query()->findOrFail($data['area_id']);
            abort_unless($actor->canAccessArea($area), 403);
            if (! $area->is_active && (! $address->exists || $address->area_id !== $area->id)) {
                throw ValidationException::withMessages(['area_id' => __('domain.the_selected_area_is_inactive')]);
            }
            $address->fill($data);
            $address->customer_id = $customer->id;
            $makePrimary = (bool) ($data['is_primary'] ?? $address->is_primary)
                || ! $customer->addresses()->where('id', '!=', $address->id)->where('is_primary', true)->exists();
            if ($makePrimary) {
                $customer->addresses()->where('id', '!=', $address->id)->update(['is_primary' => false]);
            }
            $address->is_primary = $makePrimary;
            $address->save();

            return $address;
        });
    }

    public function makePrimary(User $actor, Customer $customer, CustomerAddress $address): void
    {
        $this->assertAccess($actor, $customer, $address);
        if (! $address->is_active) {
            throw ValidationException::withMessages(['address' => __('domain.an_inactive_address_cannot_be_primary')]);
        }
        DB::transaction(function () use ($customer, $address): void {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $customer->addresses()->update(['is_primary' => false]);
            $address->is_primary = true;
            $address->save();
        });
    }

    public function setActive(User $actor, Customer $customer, CustomerAddress $address, bool $active): void
    {
        $this->assertAccess($actor, $customer, $address);
        if ($active && ! $address->area->is_active) {
            throw ValidationException::withMessages(['address' => __('domain.an_address_in_an_inactive_area_cannot_be_activated')]);
        }
        DB::transaction(function () use ($customer, $address, $active): void {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $address->is_active = $active;
            if (! $active && $address->is_primary) {
                $address->is_primary = false;
                $next = $customer->addresses()->whereKeyNot($address->id)->where('is_active', true)->oldest()->first();
                if ($next) {
                    $next->is_primary = true;
                    $next->save();
                }
            }
            if ($active && ! $customer->addresses()->whereKeyNot($address->id)->where('is_primary', true)->exists()) {
                $address->is_primary = true;
            }
            $address->save();
        });
    }

    private function assertAccess(User $actor, Customer $customer, CustomerAddress $address): void
    {
        Gate::forUser($actor)->authorize('customers.update');
        abort_unless($actor->canAccessCustomer($customer), 403);
        abort_if($address->customer_id !== $customer->id, 404);
        abort_unless($actor->canAccessArea($address->area), 403);
    }
}
