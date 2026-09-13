<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerPhoneRequest;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Services\CustomerPhoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerPhoneController extends Controller
{
    public function create(Request $request, Customer $customer): View
    {
        $this->authorizeCustomer($request, $customer);

        return view('customers.phones.form', ['customer' => $customer, 'phone' => new CustomerPhone]);
    }

    public function edit(Request $request, Customer $customer, CustomerPhone $phone): View
    {
        $this->authorizeCustomer($request, $customer);
        abort_unless($phone->customer_id === $customer->id, 404);

        return view('customers.phones.form', compact('customer', 'phone'));
    }

    public function store(CustomerPhoneRequest $request, Customer $customer, CustomerPhoneService $service): RedirectResponse
    {
        $service->save($request->user(), $customer, new CustomerPhone, $request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Phone added.');
    }

    public function update(CustomerPhoneRequest $request, Customer $customer, CustomerPhone $phone, CustomerPhoneService $service): RedirectResponse
    {
        $service->save($request->user(), $customer, $phone, $request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Phone updated.');
    }

    public function destroy(Request $request, Customer $customer, CustomerPhone $phone, CustomerPhoneService $service): RedirectResponse
    {
        $service->delete($request->user(), $customer, $phone);

        return redirect()->route('customers.show', $customer)->with('status', 'Phone removed.');
    }

    public function primary(Request $request, Customer $customer, CustomerPhone $phone, CustomerPhoneService $service): RedirectResponse
    {
        $service->makePrimary($request->user(), $customer, $phone);

        return back()->with('status', 'Primary phone updated.');
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        Gate::authorize('customers.update');
        abort_unless($request->user()->canAccessCustomer($customer), 403);
    }
}
