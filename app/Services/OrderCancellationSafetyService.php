<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderCancellationSafetyService
{
    public function allowsRecoveredDeliveredCancellation(Order $order, bool $forIntent): bool
    {
        return DB::scalar("SELECT to_regprocedure('captain_recovery_cancellation_allowed(bigint,boolean)') IS NOT NULL")
            && DB::scalar('SELECT captain_recovery_cancellation_allowed(?,?)', [$order->id, $forIntent]);
    }

    public function assertNoFinancialCorrectionRequiredLocked(Order $order, bool $forIntent = false): void
    {
        app(SharedPurchaseBoundary::class)->assertLegacyFlowAllowedLocked($order);
        $correction = Schema::hasTable('invoice_financial_corrections') ? DB::table('invoice_financial_corrections')
            ->where('order_id', $order->id)->where('kind', 'CANCEL_AND_REFUND')
            ->whereIn('state', $forIntent ? ['REQUESTED', 'PREPARED', 'READY_TO_FINALIZE'] : ['COMPLETED'])
            ->orderByDesc('id')->first() : null;
        if ($correction && ! $order->billPayments()->exists()) {
            if ($forIntent || (BigDecimal::of((string) $correction->corrected_amount)->isZero()
                && DB::table('customer_refunds')->where('invoice_financial_correction_id', $correction->id)
                    ->where('amount', $correction->original_amount)->exists())) {
                return;
            }
        }
        $invoiceIds = $order->invoices()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $collectionIds = DB::table('invoice_collections')->where('order_id', $order->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($order->currentInvoice()->where('status', InvoiceStatus::Issued->value)->lockForUpdate()->exists()
            || $collectionIds !== []
            || $order->billPayments()->exists()
            || DB::table('financial_transactions')->where('transaction_kind', 'EVENT')
                ->where(fn ($query) => $query
                    ->where(fn ($q) => $q->where('source_type', 'CUSTOMER_INVOICE')->whereIn('source_id', $invoiceIds))
                    ->orWhere(fn ($q) => $q->where('source_type', 'INVOICE_COLLECTION')->whereIn('source_id', $collectionIds)))
                ->exists()) {
            throw ValidationException::withMessages(['order' => __('domain.financial_correction_required_settled_invoice_bill_payment_or_order_derived_financial_history_cannot_be_cancelled_in_phase_9c_2')]);
        }
    }

    public function assertNoIntentLocked(Order $order): void
    {
        if ($order->cancellationIntent()->exists()) {
            throw ValidationException::withMessages(['order' => __('domain.this_order_has_a_pending_after_purchase_cancellation_intent')]);
        }
    }

    public function assertNoLegacyPurchaseCandidateLocked(Order $order): void
    {
        if ($order->shops()->where(fn ($query) => $query->whereNotNull('picked_up_at')->orWhereHas('receipts'))
            ->whereDoesntHave('purchaseConfirmation')->exists()) {
            throw ValidationException::withMessages(['order' => __('domain.legacy_purchase_confirmation_required_pickup_or_receipt_evidence_cannot_be_assigned_an_invented_captain_amount_or_currency')]);
        }
    }
}
