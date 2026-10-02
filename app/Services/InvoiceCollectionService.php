<?php

namespace App\Services;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InvoiceCollectionService
{
    public function __construct(private readonly CorrectedOrderFinancialService $financials) {}

    public function record(User $actor, Invoice $invoice, InvoiceCollectionMethod $method, string $amount, int $currencyId, mixed $collectedAt, ?string $notes = null, ?string $paymentReference = null): InvoiceCollection
    {
        Gate::forUser($actor)->authorize('invoice_collections.create');
        abort_unless(Invoice::query()->accessibleTo($actor)->whereKey($invoice)->exists(), 403);

        return DB::transaction(function () use ($actor, $invoice, $method, $amount, $currencyId, $collectedAt, $notes, $paymentReference): InvoiceCollection {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            if (Schema::hasTable('order_cancellation_intents') && $order->cancellationIntent()->exists()) {
                throw ValidationException::withMessages(['order' => __('domain.collection_is_blocked_by_an_after_purchase_cancellation_intent')]);
            }
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (Schema::hasTable('invoice_financial_corrections')
                && DB::table('invoice_financial_corrections')->where('replacement_invoice_id', $invoice->id)
                    ->where('state', 'COMPLETED')->exists()) {
                throw ValidationException::withMessages(['invoice' => __('domain.corrected_invoice_payment_is_satisfied_by_the_original_collection_and_customerrefund')]);
            }
            if ($invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => __('domain.collection_requires_the_current_issued_invoice')]);
            }
            if ($invoice->collection()->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['invoice' => __('domain.this_invoice_is_already_collected')]);
            }
            if ($currencyId !== $invoice->currency_id || ! BigDecimal::of($amount)->isEqualTo(BigDecimal::of($invoice->grand_total))) {
                throw ValidationException::withMessages(['amount' => __('domain.collection_must_equal_the_full_invoice_total_in_its_currency')]);
            }
            $collectedAt = Carbon::parse($collectedAt);
            if ($collectedAt->lt($invoice->issued_at) || $collectedAt->isFuture()) {
                throw ValidationException::withMessages(['collected_at' => __('domain.collection_time_must_be_between_invoice_issue_and_now')]);
            }
            $captainId = null;
            $orderCaptainId = null;
            if ($method === InvoiceCollectionMethod::Cash) {
                $primary = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->lockForUpdate()->first();
                if (! $primary) {
                    throw ValidationException::withMessages(['captain' => __('domain.a_cash_collection_requires_an_active_primary_collection_captain')]);
                }
                $captainId = $primary->captain_id;
                $orderCaptainId = $primary->id;
            }

            $collection = $invoice->collection()->create([
                'order_id' => $order->id,
                'captain_id' => $captainId,
                'order_captain_id' => $orderCaptainId,
                'collection_method' => $method,
                'payment_reference' => filled($paymentReference) ? trim($paymentReference) : null,
                'currency_id' => $invoice->currency_id,
                'amount' => $invoice->grand_total,
                'collected_at' => $collectedAt,
                'recorded_by' => $actor->id,
                'notes' => $notes,
            ]);
            $this->financials->postIfReady($order);

            return $collection;
        });
    }
}
