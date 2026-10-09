<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceSnapshotService $snapshots,
        private readonly OrderFinancialPostingService $financials,
        private readonly OrderFinancialSourceSnapshotService $financialSources,
        private readonly CorrectedOrderFinancialService $correctedFinancials,
    ) {}

    public function createDraft(User $actor, Order $order): Invoice
    {
        Gate::forUser($actor)->authorize('invoices.create');
        $this->access($actor, $order);

        return DB::transaction(function () use ($actor, $order): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertNotCancelled($order);

            return $this->createDraftLocked($actor, $order);
        });
    }

    public function refresh(User $actor, Order $order, Invoice $invoice): Invoice
    {
        Gate::forUser($actor)->authorize('invoices.create');
        $this->ownership($actor, $order, $invoice);

        return DB::transaction(function () use ($order, $invoice): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertNotCancelled($order);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Ready], true) || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => __('domain.only_the_current_draft_or_ready_invoice_may_be_refreshed')]);
            }

            return $this->applySnapshot($invoice, $this->snapshots->build($order));
        });
    }

    public function issue(User $actor, Order $order, Invoice $invoice): Invoice
    {
        Gate::forUser($actor)->authorize('invoices.issue');
        $this->ownership($actor, $order, $invoice);

        return DB::transaction(function () use ($actor, $order, $invoice): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertNotCancelled($order);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Ready || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => __('domain.only_the_current_ready_invoice_may_be_issued')]);
            }
            $snapshot = $this->snapshots->build($order);
            if ($this->snapshots->isStale($invoice, $snapshot)) {
                throw ValidationException::withMessages(['invoice' => __('domain.the_ready_invoice_is_stale_refresh_it_before_issue')]);
            }
            if ($order->currentInvoice()->whereKeyNot($invoice->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['invoice' => __('domain.another_current_invoice_exists')]);
            }
            $invoice->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $actor->id]);
            $this->financialSources->capture($invoice->fresh());
            $this->correctedFinancials->postIfReady($order);

            return $invoice->fresh();
        });
    }

    public function void(User $actor, Order $order, Invoice $invoice, string $reason): void
    {
        Gate::forUser($actor)->authorize('invoices.void');
        $this->ownership($actor, $order, $invoice);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('domain.void_reason_is_required')]);
        }
        DB::transaction(function () use ($actor, $order, $invoice, $reason): void {
            Order::query()->lockForUpdate()->findOrFail($order->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => __('domain.only_the_current_issued_invoice_may_be_voided')]);
            }
            if (Schema::hasTable('invoice_financial_corrections')
                && DB::table('invoice_financial_corrections')->where('replacement_invoice_id', $invoice->id)
                    ->where('state', 'COMPLETED')->exists()) {
                throw ValidationException::withMessages(['invoice' => __('domain.a_financially_corrected_replacement_invoice_is_immutable')]);
            }
            if ($invoice->collection()->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['invoice' => __('domain.a_collected_invoice_cannot_be_voided_until_a_financial_reversal_workflow_exists')]);
            }
            $this->assertNotFinanciallyCrystallized($invoice);
            $invoice->update(['status' => InvoiceStatus::Voided, 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason, 'superseded_at' => now()]);
        });
    }

    public function replace(User $actor, Order $order, Invoice $invoice, string $reason): Invoice
    {
        Gate::forUser($actor)->authorize('invoices.replace');
        $this->ownership($actor, $order, $invoice);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('domain.replacement_reason_is_required')]);
        }

        return DB::transaction(function () use ($actor, $order, $invoice, $reason): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => __('domain.only_the_current_issued_invoice_may_be_replaced')]);
            }
            if (Schema::hasTable('invoice_financial_corrections')
                && DB::table('invoice_financial_corrections')->where('replacement_invoice_id', $invoice->id)
                    ->where('state', 'COMPLETED')->exists()) {
                throw ValidationException::withMessages(['invoice' => __('domain.a_financially_corrected_replacement_invoice_is_immutable')]);
            }
            if ($invoice->collection()->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['invoice' => __('domain.a_collected_invoice_cannot_be_replaced_until_a_financial_reversal_workflow_exists')]);
            }
            $this->assertNotFinanciallyCrystallized($invoice);
            $snapshot = $this->snapshots->build($order);
            $invoice->update(['status' => InvoiceStatus::Replaced, 'replaced_at' => now(), 'replaced_by' => $actor->id, 'replacement_reason' => $reason, 'superseded_at' => now()]);
            $replacement = $this->createDraftLocked($actor, $order);
            $replacement = $this->applySnapshot($replacement, $snapshot);
            $invoice->update(['replaced_by_invoice_id' => $replacement->id]);

            return $replacement;
        });
    }

    private function createDraftLocked(User $actor, Order $order): Invoice
    {
        if ($order->currentInvoice()->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['invoice' => __('domain.this_order_already_has_a_current_invoice')]);
        }
        $invoice = $order->invoices()->create([
            'invoice_number' => 'PENDING-'.Str::uuid(),
            'version' => ((int) $order->invoices()->max('version')) + 1,
            'status' => InvoiceStatus::Draft,
            'customer_name_snapshot' => $order->customer_name_snapshot,
            'customer_phone_snapshot' => $order->customer_phone_snapshot,
            'address_snapshot' => collect([$order->address_line_1_snapshot, $order->address_line_2_snapshot, $order->landmark_snapshot])->filter()->implode(', '),
            'area_snapshot' => $order->area_path_snapshot ?: $order->area_name_snapshot,
            'order_number_snapshot' => $order->order_number,
            'ordered_at_snapshot' => $order->ordered_at,
            'created_by' => $actor->id,
        ]);
        $invoice->update(['invoice_number' => 'INV-'.now()->format('Ymd').'-'.str_pad((string) $invoice->id, 8, '0', STR_PAD_LEFT)]);

        return $invoice->fresh();
    }

    private function applySnapshot(Invoice $invoice, array $snapshot): Invoice
    {
        $invoice->items()->delete();
        foreach ($snapshot['lines'] as $line) {
            $invoice->items()->create($line);
        }
        $invoice->update(['status' => InvoiceStatus::Ready, 'currency_id' => $snapshot['currency']->id, 'source_order_delivery_pricing_id' => $snapshot['pricing']->id, 'source_items_fingerprint' => $snapshot['fingerprint'], 'items_subtotal' => $snapshot['items_subtotal'], 'delivery_total' => $snapshot['delivery_total'], 'grand_total' => $snapshot['grand_total'], 'ready_at' => now()]);

        return $invoice->fresh(['items', 'currency']);
    }

    private function ownership(User $actor, Order $order, Invoice $invoice): void
    {
        $this->access($actor, $order);
        abort_if($invoice->order_id !== $order->id, 404);
    }

    private function access(User $actor, Order $order): void
    {
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
    }

    public function mutationProblem(Order $order): ?string
    {
        if ($problem = app(SharedPurchaseBoundary::class)->financialProblem($order)) {
            return $problem;
        }
        if ($order->operational_status === OrderOperationalStatus::Cancelled
            || (Schema::hasTable('order_cancellation_intents') && $order->cancellationIntent()->exists())) {
            return __('domain.cancelled_orders_cannot_create_refresh_or_issue_invoices');
        }

        return null;
    }

    private function assertNotCancelled(Order $order): void
    {
        if ($problem = $this->mutationProblem($order)) {
            throw ValidationException::withMessages(['invoice' => $problem]);
        }
    }

    private function assertNotFinanciallyCrystallized(Invoice $invoice): void
    {
        if ($this->financials->hasOrderDerivedEvents($invoice)) {
            throw ValidationException::withMessages([
                'invoice' => __('domain.a_financially_crystallized_invoice_cannot_be_corrected_until_the_financial_reversal_workflow_exists'),
            ]);
        }
    }
}
