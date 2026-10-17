<?php

namespace App\Services;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCaptainRole;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderCaptainSelection;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Read-only reconciliation of physical MC1 sources. This is never a ledger event or payout. */
class SharedOrderFinancialPlanService
{
    public function review(User $actor, Order $order, InvoiceCollectionMethod $method): array
    {
        Gate::forUser($actor)->authorize('captain_financials.view');
        Gate::forUser($actor)->authorize('orders.view');
        abort_if(! $actor->isSuperAdmin() && $actor->roles()->where('code', 'CAPTAIN')->exists(), 403);
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        if (! Schema::hasTable('order_captain_selections') || ! Schema::hasTable('order_shop_purchase_allocations')) {
            throw ValidationException::withMessages(['order' => __('shared_orders.activation_required')]);
        }

        return DB::transaction(function () use ($actor, $order, $method): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
            $selection = OrderCaptainSelection::where('order_id', $order->id)->latest('id')->first();
            if ($selection?->contract !== 'SHARED_COMPANY_V1') {
                throw ValidationException::withMessages(['order' => __('shared_orders.explicit_selection_required')]);
            }
            DB::select('SELECT assert_order_captain_selection(?)', [$order->id]);
            $assignments = $order->captainAssignments()->whereIn('id', $selection->assignment_ids)->with('captain.user')->orderBy('id')->lockForUpdate()->get();
            // Freeze precision before snapshot arithmetic; no financial event may exist yet.
            $pricingCurrency = $order->currentDeliveryPricing()->value('currency_id');
            if (! $pricingCurrency) {
                throw ValidationException::withMessages(['delivery' => __('shared_orders.source_mismatch')]);
            }
            $currency = Currency::query()->lockForUpdate()->findOrFail($pricingCurrency);
            $snapshot = app(InvoiceSnapshotService::class)->build($order);
            $scale = $currency->decimal_places;
            $pricing = $snapshot['pricing'];
            $deliveryAllocations = $pricing->allocations()->orderBy('order_captain_id')->get()->keyBy('order_captain_id');
            if ($pricing->captain_count_snapshot !== 2 || $deliveryAllocations->keys()->sort()->values()->all() !== $selection->assignment_ids
                || $snapshot['primary']->id !== $selection->collecting_order_captain_id) {
                throw ValidationException::withMessages(['delivery' => __('shared_orders.source_mismatch')]);
            }
            $shops = $order->shops()->with(['purchaseConfirmation.allocations', 'items'])->orderBy('id')->lockForUpdate()->get();
            $funding = array_fill_keys($selection->assignment_ids, '0');
            $physicalPrincipal = BigDecimal::zero();
            $sources = [];
            foreach ($shops as $shop) {
                $purchase = $shop->purchaseConfirmation;
                if (! $purchase || $purchase->purchase_mode !== SharedPurchaseBoundary::MODE
                    || $purchase->currency_id !== $currency->id || $purchase->collecting_order_captain_id !== $selection->collecting_order_captain_id) {
                    throw ValidationException::withMessages(['purchase' => __('shared_orders.complete_purchases_required')]);
                }
                DB::select('SELECT assert_shared_shop_purchase_complete(?)', [$purchase->id]);
                $lineTotal = $shop->items->reduce(fn (BigDecimal $sum, $item) => $sum->plus(
                    BigDecimal::of($item->current_quantity)->multipliedBy($item->actual_unit_price)->toScale($scale, RoundingMode::HalfUp)), BigDecimal::zero());
                if (! $lineTotal->isEqualTo($purchase->amount) || $purchase->allocations->pluck('order_captain_id')->sort()->values()->all() !== $selection->assignment_ids) {
                    throw ValidationException::withMessages(['purchase' => __('shared_orders.source_mismatch')]);
                }
                foreach ($purchase->allocations as $allocation) {
                    $funding[$allocation->order_captain_id] = (string) BigDecimal::of($funding[$allocation->order_captain_id])->plus($allocation->amount);
                }
                $physicalPrincipal = $physicalPrincipal->plus($purchase->amount);
                $sources[] = ['purchase_id' => $purchase->id, 'shop_id' => $shop->id, 'amount' => $purchase->amount,
                    'allocation_ids' => $purchase->allocations->sortBy('id')->pluck('id')->all()];
            }
            if (! $physicalPrincipal->isEqualTo($snapshot['items_subtotal'])) {
                throw ValidationException::withMessages(['purchase' => __('shared_orders.source_mismatch')]);
            }
            $participants = $assignments->map(function ($assignment) use ($funding, $deliveryAllocations, $scale): array {
                $delivery = $deliveryAllocations->get($assignment->id);

                return ['order_captain_id' => $assignment->id, 'captain_id' => $assignment->captain_id,
                    'name' => $assignment->captain->user->name,
                    'is_collector' => $assignment->assignment_role === OrderCaptainRole::PrimaryCollection,
                    'purchase_reimbursement' => $this->exactAmount($funding[$assignment->id], $scale),
                    'delivery_entitlement' => $this->exactAmount($delivery->captain_entitlement, $scale),
                    'company_obligation' => $this->exactAmount((string) BigDecimal::of($funding[$assignment->id])->plus($delivery->captain_entitlement), $scale)];
            })->all();
            $obligations = array_reduce($participants, fn (BigDecimal $sum, array $row) => $sum->plus($row['company_obligation']), BigDecimal::zero());

            return ['state' => 'review_only', 'posting_enabled' => false, 'contract' => 'SHARED_COMPANY_V1',
                'order_id' => $order->id, 'branch_id' => $order->branch_id, 'selection_id' => $selection->id,
                'currency_id' => $currency->id, 'currency_code' => $currency->code, 'decimal_places' => $scale,
                'delivery_pricing_id' => $pricing->id, 'collector_assignment_id' => $selection->collecting_order_captain_id,
                'items_subtotal' => $snapshot['items_subtotal'], 'delivery_gross' => $pricing->gross_delivery_total,
                'discount_once' => $pricing->discount_amount, 'delivery_total' => $snapshot['delivery_total'],
                'customer_invoice_total' => $snapshot['grand_total'], 'collection_method' => $method->value,
                'anticipated_cash_custody' => $method === InvoiceCollectionMethod::Cash ? $snapshot['grand_total'] : '0.0000',
                'deposit_effect' => '0.0000', 'company_obligations' => (string) $obligations->toScale(4),
                'company_delivery_net' => (string) BigDecimal::of($snapshot['grand_total'])->minus($obligations)->toScale(4),
                'participants' => $participants, 'purchase_sources' => $sources, 'items_fingerprint' => $snapshot['fingerprint'],
                'handover_recorded' => false, 'payout_recorded' => false];
        });
    }

    private function exactAmount(string $amount, int $scale): string
    {
        try {
            return (string) BigDecimal::of($amount)->toScale($scale, RoundingMode::Unnecessary);
        } catch (MathException) {
            throw ValidationException::withMessages(['currency' => __('shared_orders.source_precision')]);
        }
    }
}
