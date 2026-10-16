<?php

namespace App\Services;

use App\Enums\OrderOperationalStatus;
use App\Models\Area;
use App\Models\Currency;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderWorkflowData
{
    public function __construct(private readonly OrderService $orders, private readonly OrderDeliveryPricingService $pricing) {}

    public function load(User $actor, Order $order): array
    {
        $this->orders->assertAccess($actor, $order);
        $order->load(['branch.defaultCurrency', 'destinationArea.parent', 'shops.shop.area', 'shops.originArea.parent', 'shops.receipts.uploadedBy', 'shops.items.estimatedSourceShop', 'shops.items.estimatedCurrency', 'shops.items.actualCurrency', 'shops.items.changes.changedBy', 'captainAssignments.captain.user', 'captainRatings.captain.user', 'cancellation.cancelledBy', 'statusHistory.changedBy', 'invoices.currency', 'invoices.collection', 'currentInvoice', 'billPaymentRequests.currency', 'billPaymentRequests.payment.evidence', 'billPaymentRequests.payment.paidBy', 'billPaymentRequests.payment.orderCaptain.captain.user']);
        $order->load(['shops.purchaseConfirmation.currency', 'shops.shopReturn.evidence', 'cancellationIntent.requiredShops', 'purchaseConfirmations', 'shopReturns']);
        if (Schema::hasTable('order_shop_purchase_allocations')) {
            $order->load('shops.purchaseConfirmation.allocations.orderCaptain.captain.user');
        }
        $sharedFinancialSelected = app(SharedPurchaseBoundary::class)->hasSelectedSharedContract($order);
        $pricingQuery = $order->currentDeliveryPricing()->with(['rule', 'fromArea.parent', 'toArea.parent', 'currency', 'routes.fromArea.parent', 'routes.toArea.parent']);
        if ($actor->can('delivery_financial_details.view')) {
            $pricingQuery->with('allocations.captain.user');
        }
        $currentDeliveryPricing = $pricingQuery->first();
        $destinationAreas = Area::query()->accessibleTo($actor)->where('is_active', true)->where('branch_id', $order->branch_id)->orderBy('name')->get();
        $pricingPreview = null;
        $pricingProblem = null;
        if ($order->shops->isNotEmpty() && $actor->can('delivery_pricing.view')) {
            try {
                $pricingPreview = $this->pricing->preview($actor, $order);
            } catch (ValidationException $exception) {
                $pricingProblem = collect($exception->errors())->flatten()->first();
            }
        }

        $recoveredCancellationAvailable = $order->operational_status === OrderOperationalStatus::Delivered
            && app(OrderCancellationSafetyService::class)->allowsRecoveredDeliveredCancellation($order, true);

        $cancellationUi = ['visible' => false, 'allowed' => false, 'afterPurchase' => $order->purchaseConfirmations->isNotEmpty(), 'problem' => null];
        $permission = $cancellationUi['afterPurchase'] ? 'order_cancellation_intents.create' : 'orders.cancel';
        if ($actor->can($permission) && ! $order->cancellation && ! $order->cancellationIntent) {
            $cancellationUi['visible'] = true;
            try {
                // Rendering must not wait on mutation locks. POST actions still
                // acquire their Order lock and recheck the same safety rules.
                if ($order->operational_status === OrderOperationalStatus::Cancelled || ($order->operational_status === OrderOperationalStatus::Delivered
                    && ! ($cancellationUi['afterPurchase'] && $recoveredCancellationAvailable))) {
                    throw ValidationException::withMessages(['order' => __('domain.delivered_or_already_cancelled_orders_cannot_be_cancelled')]);
                }
                $safety = app(OrderCancellationSafetyService::class);
                $safety->assertNoIntentLocked($order);
                $safety->assertNoFinancialCorrectionRequiredLocked($order, $cancellationUi['afterPurchase'], lockInvoice: false);
                if ($cancellationUi['afterPurchase']) {
                    $safety->assertNoLegacyPurchaseCandidateLocked($order);
                } elseif ($order->shops()->where(fn ($q) => $q->whereNotNull('picked_up_at')->orWhereHas('receipts'))->exists()) {
                    throw ValidationException::withMessages(['order' => __('domain.before_purchase_conflicts_with_existing_pickup_or_receipt_evidence')]);
                }
                $cancellationUi['allowed'] = true;
            } catch (ValidationException $exception) {
                $cancellationUi['problem'] = collect($exception->errors())->flatten()->first();
            }
        }

        $currencies = Currency::where('is_active', true)->orderBy('code')->get();
        $billPaymentCurrencies = $actor->can('bill_payments.record') ? $currencies->filter(fn ($currency) => app(BillPaymentFeeRuleService::class)->applicable($currency->id, now()) !== null) : collect();
        $billContractProblem = app(OrderBillPaymentService::class)->contractProblem($order);
        $billPaymentRequestProblem = match (true) {
            ! $actor->can('bill_payments.record') => null,
            $billContractProblem !== null => $billContractProblem,
            $order->cancellationIntent !== null => __('domain.bill_payment_is_blocked_by_a_cancellation_intent'),
            in_array($order->operational_status, [OrderOperationalStatus::Delivered, OrderOperationalStatus::Cancelled], true) => __('domain.new_additional_service_requests_require_an_open_order'),
            $billPaymentCurrencies->isEmpty() => __('orders.ui_bill_fee_unavailable'),
            default => null,
        };

        return compact('order', 'currentDeliveryPricing', 'destinationAreas', 'pricingPreview', 'pricingProblem', 'recoveredCancellationAvailable', 'cancellationUi', 'currencies', 'billPaymentCurrencies', 'billPaymentRequestProblem', 'sharedFinancialSelected');
    }
}
