<?php

namespace App\Services;

use App\Enums\OrderCaptainRole;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Order;
use App\Models\OrderCaptainSelection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit participant/collector intent; historical legacy assignments are never converted automatically. */
class OrderCaptainSelectionService
{
    /** Validate and lock real participants before the Order's immutable contract is bound. */
    public function validateForCreation(User $actor, Branch $branch, array $captainIds, ?int $collectorId, string $requestKey): array
    {
        Gate::forUser($actor)->authorize('orders.captains.assign');
        abort_unless($actor->canAccessBranch($branch), 403);
        $ids = $this->validatedIds($captainIds, $collectorId, $requestKey, null);
        Captain::query()->whereIn('id', $ids)->orderBy('id')->lock('FOR NO KEY UPDATE')->get();
        $eligible = app(OrderCaptainService::class)->eligibleForBranch($actor, $branch)->pluck('id')->all();
        if (array_diff($ids, $eligible)) {
            throw ValidationException::withMessages(['captains' => __('domain.captain_is_not_currently_eligible_for_this_order_branch')]);
        }

        return $ids;
    }

    public function select(User $actor, Order $order, array $captainIds, int $collectorId, string $requestKey, ?string $reason = null): OrderCaptainSelection
    {
        Gate::forUser($actor)->authorize('orders.captains.assign');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        $ids = $this->validatedIds($captainIds, $collectorId, $requestKey, $reason);
        $requestKey = strtolower($requestKey);

        return DB::transaction(function () use ($actor, $order, $ids, $collectorId, $requestKey, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
            // The key is global. Different Orders must not race through the unique index into a raw 500.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['order-captain-selection:'.$requestKey]);
            $existing = OrderCaptainSelection::where('request_key', $requestKey)->first();
            if ($existing) {
                $rows = $order->captainAssignments()->whereIn('id', $existing->assignment_ids)->get();
                if ($existing->order_id !== $order->id || $existing->selected_by !== $actor->id
                    || $rows->pluck('captain_id')->sort()->values()->all() !== $ids
                    || $rows->firstWhere('id', $existing->collecting_order_captain_id)?->captain_id !== $collectorId
                    || $existing->reason !== (filled($reason) ? trim($reason) : null)) {
                    throw ValidationException::withMessages(['key' => __('shared_orders.retry_conflict')]);
                }

                return $existing;
            }
            if ((int) $order->accounting_contract_version === 3 && count($ids) !== 1) {
                throw ValidationException::withMessages(['captains' => __('financial.accounting_v3_single_only')]);
            }
            app(OrderMutationGuard::class)->assertMutableLocked($order);
            // Physical sources already identify their payers/collector. No reassignment without a reviewed reversal.
            if ($order->purchaseConfirmations()->exists() || $order->invoices()->exists() || $order->billPaymentRequests()->exists()) {
                throw ValidationException::withMessages(['captains' => __('shared_orders.bound_participants')]);
            }
            // Protect eligibility without blocking unrelated owner/account FK KEY SHARE reads.
            Captain::query()->whereIn('id', $ids)->orderBy('id')->lock('FOR NO KEY UPDATE')->get();
            $eligible = app(OrderCaptainService::class)->eligibleForOrder($actor, $order)->pluck('id')->all();
            if (array_diff($ids, $eligible)) {
                throw ValidationException::withMessages(['captains' => __('domain.captain_is_not_currently_eligible_for_this_order_branch')]);
            }
            $current = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();
            $same = $current->pluck('captain_id')->sort()->values()->all() === $ids
                && $current->firstWhere('assignment_role', OrderCaptainRole::PrimaryCollection)?->captain_id === $collectorId;
            $previous = OrderCaptainSelection::where('order_id', $order->id)->exists();
            if (($previous || ($current->isNotEmpty() && ! $same)) && blank($reason)) {
                throw ValidationException::withMessages(['reason' => __('shared_orders.change_reason')]);
            }
            if (! $same && $current->isNotEmpty()) {
                Gate::forUser($actor)->authorize('orders.captains.remove');
                foreach ($current as $assignment) {
                    $assignment->update(['removed_at' => now(), 'removed_by' => $actor->id, 'removal_reason' => trim($reason)]);
                }
            }
            if (! $same) {
                foreach ($ids as $id) {
                    $current->push($order->captainAssignments()->create(['captain_id' => $id,
                        'assignment_role' => $id === $collectorId ? OrderCaptainRole::PrimaryCollection : OrderCaptainRole::Support,
                        'assigned_at' => now(), 'assigned_by' => $actor->id]));
                }
                $current = $current->filter(fn ($row) => $row->removed_at === null)->values();
            }
            $selection = OrderCaptainSelection::create(['order_id' => $order->id, 'branch_id' => $order->branch_id,
                'assignment_ids' => $current->pluck('id')->sort()->values()->all(),
                'collecting_order_captain_id' => $current->firstWhere('assignment_role', OrderCaptainRole::PrimaryCollection)->id,
                'selected_by' => $actor->id, 'selected_at' => now(), 'request_key' => $requestKey,
                'contract' => count($ids) === 1 ? 'SINGLE_V1' : 'SHARED_COMPANY_V1',
                'reason' => filled($reason) ? trim($reason) : null]);
            DB::select('SELECT assert_order_captain_selection(?)', [$order->id]);
            if (! $same) {
                app(OrderDeliveryPricingService::class)->repriceAfterCaptainChangeLocked($actor, $order);
            }

            return $selection->fresh();
        });
    }

    private function validatedIds(array $captainIds, ?int $collectorId, string $requestKey, ?string $reason): array
    {
        Validator::make(['captains' => $captainIds, 'collector' => $collectorId, 'key' => $requestKey, 'reason' => $reason], [
            'captains' => ['required', 'array', 'min:1', 'max:2'], 'captains.*' => ['required', 'integer', 'distinct'],
            'collector' => ['required', 'integer'], 'key' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $ids = collect($captainIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        if (! in_array($collectorId, $ids, true)) {
            throw ValidationException::withMessages(['collector' => __('shared_orders.collector_participant')]);
        }
        if (! Schema::hasTable('order_captain_selections')) {
            throw ValidationException::withMessages(['captains' => __('shared_orders.activation_required')]);
        }

        return $ids;
    }
}
