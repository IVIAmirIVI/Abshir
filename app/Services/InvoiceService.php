<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private readonly InvoiceSnapshotService $snapshots) {}

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
                throw ValidationException::withMessages(['invoice' => 'Only the current DRAFT or READY Invoice may be refreshed.']);
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
                throw ValidationException::withMessages(['invoice' => 'Only the current READY Invoice may be issued.']);
            }
            $snapshot = $this->snapshots->build($order);
            if ($this->snapshots->isStale($invoice, $snapshot)) {
                throw ValidationException::withMessages(['invoice' => 'The READY Invoice is stale. Refresh it before issue.']);
            }
            if ($order->currentInvoice()->whereKeyNot($invoice->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Another current Invoice exists.']);
            }
            $invoice->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $actor->id]);

            return $invoice->fresh();
        });
    }

    public function void(User $actor, Order $order, Invoice $invoice, string $reason): void
    {
        Gate::forUser($actor)->authorize('invoices.void');
        $this->ownership($actor, $order, $invoice);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Void reason is required.']);
        }
        DB::transaction(function () use ($actor, $order, $invoice, $reason): void {
            Order::query()->lockForUpdate()->findOrFail($order->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => 'Only the current ISSUED Invoice may be voided.']);
            }
            if ($invoice->collection()->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['invoice' => 'A collected Invoice cannot be voided until a financial reversal workflow exists.']);
            }
            $invoice->update(['status' => InvoiceStatus::Voided, 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason, 'superseded_at' => now()]);
        });
    }

    public function replace(User $actor, Order $order, Invoice $invoice, string $reason): Invoice
    {
        Gate::forUser($actor)->authorize('invoices.replace');
        $this->ownership($actor, $order, $invoice);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Replacement reason is required.']);
        }

        return DB::transaction(function () use ($actor, $order, $invoice, $reason): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Issued || $invoice->superseded_at) {
                throw ValidationException::withMessages(['invoice' => 'Only the current ISSUED Invoice may be replaced.']);
            }
            if ($invoice->collection()->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['invoice' => 'A collected Invoice cannot be replaced until a financial reversal workflow exists.']);
            }
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
            throw ValidationException::withMessages(['invoice' => 'This Order already has a current Invoice.']);
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

    private function assertNotCancelled(Order $order): void
    {
        if ($order->operational_status === OrderOperationalStatus::Cancelled) {
            throw ValidationException::withMessages(['invoice' => 'Cancelled Orders cannot create, refresh, or issue Invoices.']);
        }
    }
}
