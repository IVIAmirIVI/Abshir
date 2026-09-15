<?php

namespace App\Services;

use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPhone;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(User $actor, array $data): Order
    {
        Gate::forUser($actor)->authorize('orders.create');
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $phone = CustomerPhone::query()->findOrFail($data['customer_phone_id']);
        $address = CustomerAddress::query()->with('area.parent')->findOrFail($data['customer_address_id']);
        abort_unless($actor->canAccessBranch($branch), 403);
        if (! $branch->is_active || ! $customer->is_active || ! $address->is_active || ! $address->area->is_active) {
            throw ValidationException::withMessages(['order' => 'New Orders require active Branch, Customer, Address, and Area records.']);
        }
        if ($phone->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['customer_phone_id' => 'The selected phone does not belong to this Customer.']);
        }
        if ($address->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['customer_address_id' => 'The selected address does not belong to this Customer.']);
        }
        if ($address->area->branch_id !== $branch->id) {
            throw ValidationException::withMessages(['customer_address_id' => 'The selected address is outside the Order Branch.']);
        }

        return DB::transaction(function () use ($actor, $branch, $customer, $phone, $address, $data): Order {
            $order = Order::query()->create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'order_number' => 'PENDING-'.Str::uuid(), 'ordered_at' => Carbon::parse($data['ordered_at']), 'operational_status' => OrderOperationalStatus::New, 'pricing_status' => OrderPricingStatus::Estimated, 'customer_name_snapshot' => $customer->name, 'customer_phone_snapshot' => $phone->phone, 'customer_phone_normalized_snapshot' => $phone->normalized_phone, 'address_label_snapshot' => $address->label, 'recipient_name_snapshot' => $address->recipient_name, 'area_name_snapshot' => $address->area->name, 'area_path_snapshot' => $address->area->path(), 'address_line_1_snapshot' => $address->address_line_1, 'address_line_2_snapshot' => $address->address_line_2, 'landmark_snapshot' => $address->landmark, 'latitude_snapshot' => $address->latitude, 'longitude_snapshot' => $address->longitude, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id]);
            $order->order_number = 'ORD-'.$order->ordered_at->format('Ymd').'-'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
            $order->save();
            $order->statusHistory()->create(['previous_status' => null, 'new_status' => OrderOperationalStatus::New, 'changed_by' => $actor->id, 'changed_at' => now(), 'reason' => 'Order created']);

            return $order;
        });
    }

    public function assertAccess(User $actor, Order $order): void
    {
        Gate::forUser($actor)->authorize('orders.view');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
    }
}
