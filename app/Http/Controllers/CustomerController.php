<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request, PhoneNormalizer $normalizer): View
    {
        Gate::authorize('customers.view');
        $customers = Customer::query()->accessibleTo($request->user())
            ->with(['phones' => fn ($query) => $query->orderByDesc('is_primary')])
            ->search($request->string('search')->toString(), $normalizer)
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('customers.index', compact('customers'));
    }

    public function create(): View
    {
        Gate::authorize('customers.create');

        return view('customers.form', ['customer' => new Customer]);
    }

    public function show(Request $request, Customer $customer): View
    {
        Gate::authorize('customers.view');
        abort_unless($request->user()->canAccessCustomer($customer), 403);

        $customer->load('phones')->setRelation(
            'addresses',
            $customer->addressesAccessibleTo($request->user())->with('area.branch')->get(),
        );

        return view('customers.show', compact('customer'));
    }

    public function edit(Request $request, Customer $customer): View
    {
        Gate::authorize('customers.update');
        abort_unless($request->user()->canAccessCustomer($customer), 403);

        return view('customers.form', compact('customer'));
    }

    public function store(CustomerRequest $request, CustomerService $service): RedirectResponse
    {
        $customer = $service->save($request->user(), new Customer, $request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Customer created. Add a phone or address when ready.');
    }

    public function update(CustomerRequest $request, Customer $customer, CustomerService $service): RedirectResponse
    {
        $service->save($request->user(), $customer, $request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Customer updated.');
    }

    public function status(Request $request, Customer $customer, CustomerService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $customer, (bool) $data['is_active']);

        return back()->with('status', 'Customer status updated.');
    }
}
