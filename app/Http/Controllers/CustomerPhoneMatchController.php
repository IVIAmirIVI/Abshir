<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerPhoneDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CustomerPhoneMatchController extends Controller
{
    public function __invoke(Request $request, Customer $customer, CustomerPhoneDuplicateService $service): JsonResponse
    {
        Gate::authorize('customers.update');
        abort_unless($request->user()->canAccessCustomer($customer), 403);
        $data = $request->validate(['phone' => ['required', 'string', 'max:50']]);
        $matches = $service->visibleMatches($request->user(), $data['phone'], $customer->id)->map(fn (Customer $match) => ['id' => $match->id, 'name' => $match->name, 'url' => route('customers.show', $match)])->values();

        return response()->json(['matches' => $matches]);
    }
}
