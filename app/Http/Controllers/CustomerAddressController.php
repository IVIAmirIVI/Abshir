<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerAddressRequest;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\CustomerAddressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerAddressController extends Controller
{
    public function create(Request $request, Customer $customer): View
    {
        $this->authorizeCustomer($request, $customer);

        return $this->form($request, $customer, new CustomerAddress);
    }

    public function edit(Request $request, Customer $customer, CustomerAddress $address): View
    {
        $this->authorizeCustomer($request, $customer);
        abort_unless($address->customer_id === $customer->id, 404);
        abort_unless($request->user()->canAccessArea($address->area), 403);

        return $this->form($request, $customer, $address);
    }

    private function form(Request $request, Customer $customer, CustomerAddress $address): View
    {
        $areas = Area::query()->accessibleTo($request->user())->where(function ($query) use ($address): void {
            $query->where('is_active', true);
            if ($address->exists) {
                $query->orWhereKey($address->area_id);
            }
        })->with('branch')->orderBy('name')->get();

        return view('customers.addresses.form', compact('customer', 'address', 'areas'));
    }

    public function store(CustomerAddressRequest $request, Customer $customer, CustomerAddressService $service): RedirectResponse
    {
        $service->save($request->user(), $customer, new CustomerAddress, $request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Address added.');
    }

    public function update(CustomerAddressRequest $request, Customer $customer, CustomerAddress $address, CustomerAddressService $service): RedirectResponse
    {
        $service->save($request->user(), $customer, $address, $request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Address updated.');
    }

    public function status(Request $request, Customer $customer, CustomerAddress $address, CustomerAddressService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $customer, $address, (bool) $data['is_active']);

        return back()->with('status', 'Address status updated.');
    }

    public function primary(Request $request, Customer $customer, CustomerAddress $address, CustomerAddressService $service): RedirectResponse
    {
        $service->makePrimary($request->user(), $customer, $address);

        return back()->with('status', 'Primary address updated.');
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        Gate::authorize('customers.update');
        abort_unless($request->user()->canAccessCustomer($customer), 403);
    }
}
