<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class OrderWorkflowState
{
    /** Presentation only. No stored wizard state or alternative business transitions. */
    public function describe(User $actor, Order $order): array
    {
        app(OrderService::class)->assertAccess($actor, $order);
        $order->loadMissing(['shops.items', 'shops.receipts', 'shops.purchaseConfirmation', 'captainAssignments', 'currentInvoice.collection', 'currentDeliveryPricing', 'cancellationIntent']);
        $guard = app(OrderMutationGuard::class);
        $exceptional = $order->operational_status === OrderOperationalStatus::Cancelled || $order->cancellationIntent !== null;
        $delivered = $order->operational_status === OrderOperationalStatus::Delivered;
        $mutable = ! $guard->isPricingFrozen($order);
        $evidenceMutable = $guard->isOperationalEvidenceMutable($order);
        $invoice = $order->currentInvoice;
        $availability = $invoice ? app(InvoiceCollectionService::class)->availability($actor, $invoice) : null;
        $correctionProblem = app(CorrectedOrderFinancialService::class)->correctionPostingProblem($order);
        $complete = ! $exceptional && $delivered && $invoice?->status === InvoiceStatus::Issued
            && ($availability['payment_satisfied'] ?? false) && $correctionProblem === null;
        $primary = $order->captainAssignments->first(fn ($a) => $a->removed_at === null && $a->assignment_role === OrderCaptainRole::PrimaryCollection);
        $shopComplete = $order->shops->isNotEmpty() && $order->shops->every(fn ($s) => $s->items->isNotEmpty()
            && $s->items->every(fn ($i) => $i->actual_price_confirmed_at !== null) && $s->purchaseConfirmation && $s->picked_up_at && $s->receipts->isNotEmpty());
        $step = match (true) {
            $exceptional => 'exception',
            $complete => 'complete',
            $delivered => 'invoice',
            $order->operational_status === OrderOperationalStatus::New => 'confirm',
            ! $primary || ! $order->currentDeliveryPricing => 'captain',
            ! $shopComplete => 'shops',
            default => 'delivery',
        };
        $transitionProblem = app(OrderStatusService::class)->nextTransitionProblem($order);
        $next = $order->operational_status->next();
        $invoiceProblem = app(InvoiceService::class)->mutationProblem($order);
        if (! $exceptional && (! $invoice || in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Ready], true)) && $actor->can('invoices.view')) {
            $invoiceProblem ??= app(InvoiceSnapshotService::class)->readinessProblem($order);
        }
        $stale = ! $exceptional && $invoice?->status === InvoiceStatus::Ready
            && $actor->can('invoices.view') && app(InvoiceSnapshotService::class)->currentSourcesAreStale($invoice);

        $shopActions = $order->shops->mapWithKeys(fn ($shop) => [$shop->id => match (true) {
            $shop->items->isEmpty() || $shop->items->contains(fn ($item) => $item->actual_price_confirmed_at === null) => 'items',
            ! $shop->purchaseConfirmation => 'purchase',
            $shop->receipts->isEmpty() => 'receipt',
            ! $shop->picked_up_at => 'pickup',
            default => 'shop_done',
        }])->all();
        $statusAction = ['kind' => 'status', 'panel' => $step];
        $nextAction = match (true) {
            $exceptional || $complete => null,
            $delivered => ['kind' => 'invoice', 'panel' => 'invoice'],
            $order->operational_status === OrderOperationalStatus::New => $statusAction,
            $order->shops->isEmpty() => ['kind' => 'items', 'panel' => 'shops'],
            ! $primary => ['kind' => 'captain', 'panel' => 'captain'],
            $next === OrderOperationalStatus::CaptainAssigned => $statusAction,
            ! $order->currentDeliveryPricing => ['kind' => 'pricing', 'panel' => 'captain'],
            in_array($next, [OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping], true) => $statusAction,
            ! $shopComplete => ['kind' => 'shops', 'panel' => 'shops'],
            default => $statusAction,
        };
        if ($nextAction) {
            if ($nextAction['kind'] === 'shops') {
                $shopId = array_search(true, array_map(fn ($action) => $action !== 'shop_done', $shopActions), true);
                $nextAction['shop_id'] = $shopId === false ? null : $shopId;
                $nextAction['kind'] = $shopId === false ? 'items' : $shopActions[$shopId];
            }
            $allowed = match ($nextAction['kind']) {
                'status' => $transitionProblem === null && $actor->can('orders.status.update'),
                'captain' => $mutable && $actor->can('orders.captains.assign'),
                'pricing' => $mutable && $actor->can('delivery_pricing.apply'),
                'items' => $mutable && $actor->can('orders.update') && (! isset($nextAction['shop_id'])
                    || $order->shops->firstWhere('id', $nextAction['shop_id'])->items->isEmpty() || $actor->can('orders.pricing.update')),
                'purchase' => $evidenceMutable && ! app(SharedPurchaseBoundary::class)->hasSelectedSharedContract($order)
                    && $actor->can('order_shop_purchases.confirm'),
                'receipt' => $evidenceMutable && $actor->can('order_shop_receipts.upload'),
                'pickup' => $evidenceMutable && $actor->can('orders.update'),
                'invoice' => $actor->can('invoices.view'),
                default => false,
            };
            $nextAction['allowed'] = $allowed;
        }

        return compact('step', 'complete', 'exceptional', 'delivered', 'mutable', 'evidenceMutable', 'primary', 'shopComplete', 'transitionProblem', 'next', 'invoiceProblem', 'stale', 'availability', 'correctionProblem', 'nextAction', 'shopActions') + [
            'canTransition' => ! $exceptional && $next !== null && $transitionProblem === null && Gate::forUser($actor)->allows('orders.status.update'),
            'canCreateInvoice' => ! $exceptional && ! $invoice && $invoiceProblem === null && $actor->can('invoices.create'),
            'canRefreshInvoice' => ! $exceptional && $invoice && in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Ready], true) && $invoiceProblem === null && $actor->can('invoices.create'),
            'canIssueInvoice' => ! $exceptional && $invoice?->status === InvoiceStatus::Ready && ! $stale && $invoiceProblem === null && $actor->can('invoices.issue'),
            'canCollect' => ! $exceptional && ($availability['available'] ?? false),
            'canFinalizePricing' => $mutable && $primary && app(DeliveryPricingSettings::class)->captainSharePercent() !== null && $actor->can('delivery_pricing.apply'),
            'steps' => [
                'summary' => true,
                'confirm' => $order->operational_status !== OrderOperationalStatus::New,
                'captain' => $primary && $order->currentDeliveryPricing,
                'shops' => $shopComplete,
                'delivery' => $delivered,
                'invoice' => $invoice?->status === InvoiceStatus::Issued && ($availability['payment_satisfied'] ?? false),
                'complete' => $complete,
            ],
        ];
    }
}
