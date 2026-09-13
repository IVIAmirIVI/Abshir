<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerService
{
    public function save(User $actor, Customer $customer, array $data): Customer
    {
        Gate::forUser($actor)->authorize($customer->exists ? 'customers.update' : 'customers.create');

        return DB::transaction(function () use ($actor, $customer, $data): Customer {
            if ($customer->exists) {
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                abort_unless($actor->canAccessCustomer($customer), 403);
            } else {
                $customer->created_by_user_id = $actor->id;
            }
            $customer->fill($data);
            $customer->save();

            return $customer;
        });
    }

    public function setActive(User $actor, Customer $customer, bool $active): void
    {
        Gate::forUser($actor)->authorize($active ? 'customers.activate' : 'customers.deactivate');
        abort_unless($actor->canAccessCustomer($customer), 403);
        $customer->is_active = $active;
        $customer->save();
    }
}
