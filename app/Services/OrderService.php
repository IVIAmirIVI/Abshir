<?php

namespace App\Services;

use App\Enums\OrderEntryMode;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingStatus;
use App\Enums\OrderSource;
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
    public function create(User $actor, array $data, array $captainIds = [], ?int $collectorId = null, ?string $captainSelectionKey = null): Order
    {
        Gate::forUser($actor)->authorize('orders.create');
        $source = OrderSource::tryFrom((string) ($data['source'] ?? ''));
        if (! $source || ! in_array($source, OrderSource::staffChoices(), true)) {
            throw ValidationException::withMessages(['source' => __('domain.select_whatsapp_phone_or_manual_for_a_staff_created_order')]);
        }
        $entryMode = OrderEntryMode::tryFrom((string) ($data['entry_mode'] ?? ''));
        if (! $entryMode) {
            throw ValidationException::withMessages(['entry_mode' => __('domain.select_a_valid_order_entry_mode')]);
        }
        if ($entryMode === OrderEntryMode::HistoricalManual) {
            Gate::forUser($actor)->authorize('orders.historical_entry');
            if (empty($data['ordered_at'])) {
                throw ValidationException::withMessages(['ordered_at' => __('domain.historical_order_date_and_time_are_required')]);
            }
            $orderedAt = Carbon::parse($data['ordered_at']);
            if ($orderedAt->isFuture()) {
                throw ValidationException::withMessages(['ordered_at' => __('domain.historical_order_date_and_time_cannot_be_in_the_future')]);
            }
        } else {
            $orderedAt = now();
        }
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $phone = CustomerPhone::query()->findOrFail($data['customer_phone_id']);
        $address = CustomerAddress::query()->with('area.parent')->findOrFail($data['customer_address_id']);
        abort_unless($actor->canAccessBranch($branch), 403);
        if (! $branch->is_active || ! $customer->is_active || ! $address->is_active || ! $address->area->is_active) {
            throw ValidationException::withMessages(['order' => __('domain.new_orders_require_active_branch_customer_address_and_area_records')]);
        }
        if ($phone->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['customer_phone_id' => __('domain.the_selected_phone_does_not_belong_to_this_customer')]);
        }
        if ($address->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['customer_address_id' => __('domain.the_selected_address_does_not_belong_to_this_customer')]);
        }
        if ($address->area->branch_id !== $branch->id) {
            throw ValidationException::withMessages(['customer_address_id' => __('domain.the_selected_address_is_outside_the_order_branch')]);
        }

        return DB::transaction(function () use ($actor, $branch, $customer, $phone, $address, $data, $source, $entryMode, $orderedAt, $captainIds, $collectorId, $captainSelectionKey): Order {
            $selectionKey = $captainSelectionKey ?? (string) Str::uuid();
            if ($captainIds !== [] || $collectorId !== null) {
                $captainIds = app(OrderCaptainSelectionService::class)->validateForCreation($actor, $branch, $captainIds, $collectorId, $selectionKey);
            }
            $order = new Order(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'destination_area_id' => $address->area_id, 'order_number' => 'PENDING-'.Str::uuid(), 'ordered_at' => $orderedAt, 'source' => $source, 'entry_mode' => $entryMode, 'operational_status' => OrderOperationalStatus::New, 'pricing_status' => OrderPricingStatus::Estimated, 'customer_name_snapshot' => $customer->name, 'customer_phone_snapshot' => $phone->phone, 'customer_phone_normalized_snapshot' => $phone->normalized_phone, 'address_label_snapshot' => $address->label, 'recipient_name_snapshot' => $address->recipient_name, 'area_name_snapshot' => $address->area->name, 'area_path_snapshot' => $address->area->path(), 'address_line_1_snapshot' => $address->address_line_1, 'address_line_2_snapshot' => $address->address_line_2, 'landmark_snapshot' => $address->landmark, 'latitude_snapshot' => $address->latitude, 'longitude_snapshot' => $address->longitude, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id]);
            if (count($captainIds) === 1 && config('order_accounting.enable_v3_single_captain', false)) {
                $order->forceFill(['accounting_contract_version' => 3]);
            }
            $order->save();
            $order->order_number = 'ORD-'.$order->ordered_at->format('Ymd').'-'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
            $order->save();
            $order->statusHistory()->create(['previous_status' => null, 'new_status' => OrderOperationalStatus::New, 'changed_by' => $actor->id, 'changed_at' => now(), 'reason' => 'Order created']);
            if ($captainIds !== []) {
                app(OrderCaptainSelectionService::class)->select($actor, $order, $captainIds, $collectorId, $selectionKey);
            }

            return $order;
        });
    }

    public function assertAccess(User $actor, Order $order): void
    {
        Gate::forUser($actor)->authorize('orders.view');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
    }
}
