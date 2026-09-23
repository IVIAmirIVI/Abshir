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
use Illuminate\Validation\ValidationException;

class InvoiceCollectionService
{
    public function __construct(private readonly OrderFinancialPostingService $financials) {}

    public function record(User $actor, Invoice $invoice, InvoiceCollectionMethod $method, string $amount, int $currencyId, mixed $collectedAt, ?string $notes = null, ?string $paymentReference = null): InvoiceCollection
    {
        Gate::forUser($actor)->authorize('invoice_collections.create');
        abort_unless(Invoice::query()->accessibleTo($actor)->whereKey($invoice)->exists(), 403);

        return DB::transaction(function () use ($actor, $invoice, $method, $amount, $currencyId, $collectedAt, $notes, $paymentReference): InvoiceCollection {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => 'Collection requires the current ISSUED Invoice.']);
            }
            if ($invoice->collection()->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['invoice' => 'This Invoice is already collected.']);
            }
            if ($currencyId !== $invoice->currency_id || ! BigDecimal::of($amount)->isEqualTo(BigDecimal::of($invoice->grand_total))) {
                throw ValidationException::withMessages(['amount' => 'Collection must equal the full Invoice total in its Currency.']);
            }
            $collectedAt = Carbon::parse($collectedAt);
            if ($collectedAt->lt($invoice->issued_at) || $collectedAt->isFuture()) {
                throw ValidationException::withMessages(['collected_at' => 'Collection time must be between Invoice issue and now.']);
            }
            $captainId = null;
            $orderCaptainId = null;
            if ($method === InvoiceCollectionMethod::Cash) {
                $primary = $order->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->lockForUpdate()->first();
                if (! $primary) {
                    throw ValidationException::withMessages(['captain' => 'A CASH collection requires an active PRIMARY_COLLECTION Captain.']);
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
            $this->financials->postCashCollectionCustody($collection);

            return $collection;
        });
    }
}
