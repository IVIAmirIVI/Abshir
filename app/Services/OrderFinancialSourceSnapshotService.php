<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Exceptions\FinancialLedgerException;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\OrderDeliveryPricing;
use App\Models\OrderFinancialSourceSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class OrderFinancialSourceSnapshotService
{
    /** @return array<string, mixed> */
    public function build(Invoice $invoice): array
    {
        return DB::transaction(function () use ($invoice): array {
            $order = Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            // SHARE excludes every concurrent INSERT/UPDATE/DELETE, including direct SQL
            // writers that do not participate in the application's Order-row protocol.
            DB::statement('LOCK TABLE order_delivery_pricings, order_captains, order_delivery_allocations IN SHARE MODE');
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            return $this->buildLocked($order, $invoice);
        });
    }

    /** @return array<string, mixed> */
    private function buildLocked(Order $order, Invoice $invoice): array
    {
        if ($invoice->status !== InvoiceStatus::Issued || ! $invoice->issued_at || ! $invoice->currency_id
            || ! $invoice->source_order_delivery_pricing_id || $invoice->items_subtotal === null) {
            throw new FinancialLedgerException("Invoice {$invoice->id} lacks an issued financial source snapshot.");
        }
        $pricing = OrderDeliveryPricing::query()->lockForUpdate()->find($invoice->source_order_delivery_pricing_id);
        if (! $pricing) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has no delivery pricing source.");
        }
        $active = OrderCaptain::query()->where('order_id', $invoice->order_id)->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();
        $allocations = $pricing->allocations()->orderBy('order_captain_id')->lockForUpdate()->get();
        $currency = Currency::query()->lockForUpdate()->find($invoice->currency_id);
        if (! $pricing || ! $currency || $pricing->order_id !== $invoice->order_id || $pricing->currency_id !== $currency->id) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has an inconsistent pricing or Currency source.");
        }
        if ($order->id !== $invoice->order_id || $order->branch_id === null
            || $invoice->delivery_total === null
            || ! BigDecimal::of($invoice->delivery_total)->isEqualTo(BigDecimal::of($pricing->customer_delivery_total))) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has an inconsistent Order Branch or Delivery total.");
        }
        if ($allocations->isEmpty() || $allocations->count() !== $pricing->captain_count_snapshot
            || $allocations->pluck('order_captain_id')->duplicates()->isNotEmpty()
            || $allocations->pluck('captain_id')->duplicates()->isNotEmpty()) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has an inconsistent historical Captain set.");
        }
        $primaries = $active->where('assignment_role', OrderCaptainRole::PrimaryCollection);
        if ($primaries->count() !== 1) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has no unique active PRIMARY_COLLECTION Captain.");
        }
        $primary = $primaries->first();
        if ($active->pluck('id')->sort()->values()->all() !== $allocations->pluck('order_captain_id')->sort()->values()->all()
            || $active->pluck('captain_id')->sort()->values()->all() !== $allocations->pluck('captain_id')->sort()->values()->all()) {
            throw new FinancialLedgerException("Invoice {$invoice->id} Captain participants differ from its pricing source.");
        }
        try {
            $subtotal = BigDecimal::of($invoice->items_subtotal)->toScale($currency->decimal_places, RoundingMode::Unnecessary);
            if ($subtotal->isLessThan(0)) {
                throw new \InvalidArgumentException;
            }
            $physicalFunding = app(SharedPurchaseBoundary::class)->hasSelectedSharedContract($order)
                ? app(SharedOrderInvoiceSourceService::class)->fundingLocked($order, $currency->id, $pricing->id, (string) $subtotal) : null;
            [$base, $remainder] = $subtotal->getUnscaledValue()->quotientAndRemainder($allocations->count());
            $remainderCount = $remainder->toInt();
            $rows = [];
            $customerTotal = BigDecimal::zero();
            $productTotal = BigDecimal::zero();
            foreach ($allocations->values() as $index => $allocation) {
                $assignment = $active->firstWhere('id', $allocation->order_captain_id);
                if (! $assignment || $assignment->order_id !== $invoice->order_id || $assignment->captain_id !== $allocation->captain_id
                    || $allocation->currency_id !== $currency->id) {
                    throw new FinancialLedgerException("Invoice {$invoice->id} has a mismatched historical Captain allocation.");
                }
                $entitlement = BigDecimal::of($allocation->captain_entitlement)->toScale($currency->decimal_places, RoundingMode::Unnecessary);
                $customer = BigDecimal::of($allocation->customer_delivery_amount_allocated)->toScale($currency->decimal_places, RoundingMode::Unnecessary);
                $company = BigDecimal::of($allocation->company_collectible)->toScale($currency->decimal_places, RoundingMode::Unnecessary);
                $shortfall = BigDecimal::of($allocation->captain_shortfall)->toScale($currency->decimal_places, RoundingMode::Unnecessary);
                if ($entitlement->isLessThan(0) || $customer->isLessThan(0) || $company->isLessThan(0) || $shortfall->isLessThan(0)
                    || ! $company->isEqualTo($customer->isGreaterThan($entitlement) ? $customer->minus($entitlement) : BigDecimal::zero())
                    || ! $shortfall->isEqualTo($entitlement->isGreaterThan($customer) ? $entitlement->minus($customer) : BigDecimal::zero())) {
                    throw new FinancialLedgerException("Invoice {$invoice->id} has inconsistent delivery allocation money.");
                }
                $minor = $base->plus($index < $remainderCount ? 1 : 0);
                $share = $physicalFunding === null ? BigDecimal::ofUnscaledValue($minor, $currency->decimal_places) : BigDecimal::of($physicalFunding[$assignment->id]);
                $customerTotal = $customerTotal->plus($customer);
                $productTotal = $productTotal->plus($share);
                $rows[] = [
                    'order_captain_id' => $assignment->id,
                    'captain_id' => $assignment->captain_id,
                    'is_primary' => $assignment->captain_id === $primary->captain_id,
                    'product_funding_share' => (string) $share->toScale(4),
                    'captain_entitlement' => (string) $entitlement->toScale(4),
                    'customer_delivery_amount_allocated' => (string) $customer->toScale(4),
                    'company_collectible' => (string) $company->toScale(4),
                    'captain_shortfall' => (string) $shortfall->toScale(4),
                ];
            }
            if (! $customerTotal->isEqualTo(BigDecimal::of($invoice->delivery_total))
                || ! $productTotal->isEqualTo($subtotal)) {
                throw new FinancialLedgerException("Invoice {$invoice->id} has unreconciled delivery or product allocations.");
            }
        } catch (FinancialLedgerException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has invalid money for its Currency precision.");
        }
        if (collect($rows)->where('is_primary', true)->count() !== 1) {
            throw new FinancialLedgerException("Invoice {$invoice->id} has no unique historical PRIMARY_COLLECTION Captain.");
        }
        $issuedAt = DB::table('invoices')->where('id', $invoice->id)->value('issued_at');
        if (! $issuedAt) {
            throw new FinancialLedgerException("Invoice {$invoice->id} lacks an issued timestamp.");
        }
        $payload = [
            'fingerprint_version' => 2,
            'invoice_id' => $invoice->id,
            'invoice_version' => $invoice->version,
            'order_id' => $invoice->order_id,
            'branch_id' => $order->branch_id,
            'issued_at' => CarbonImmutable::parse($issuedAt)->utc()->format('Y-m-d\TH:i:s.uP'),
            'order_delivery_pricing_id' => $pricing->id,
            'currency_id' => $currency->id,
            'currency_decimal_places' => $currency->decimal_places,
            'primary_order_captain_id' => $primary->id,
            'primary_captain_id' => $primary->captain_id,
            'items_subtotal' => (string) $subtotal->toScale(4),
            'captain_allocations' => $rows,
        ];

        return $payload + ['payload_hash' => $this->hash($payload)];
    }

    /** @return array<string, mixed> */
    public function read(Invoice $invoice): array
    {
        $snapshot = OrderFinancialSourceSnapshot::query()->where('invoice_id', $invoice->id)->first();
        if ($snapshot) {
            $lines = $snapshot->lines()->orderBy('order_captain_id')->get();
            $payload = [
                'fingerprint_version' => $snapshot->fingerprint_version,
                'invoice_id' => $snapshot->invoice_id,
                'invoice_version' => $snapshot->invoice_version,
                'order_id' => $snapshot->order_id,
                'branch_id' => $snapshot->branch_id,
                'issued_at' => CarbonImmutable::parse($snapshot->issued_at)->utc()->format('Y-m-d\TH:i:s.uP'),
                'order_delivery_pricing_id' => $snapshot->order_delivery_pricing_id,
                'currency_id' => $snapshot->currency_id,
                'currency_decimal_places' => $snapshot->currency_decimal_places,
                'primary_order_captain_id' => $snapshot->primary_order_captain_id,
                'primary_captain_id' => $lines->firstWhere('is_primary', true)?->captain_id,
                'items_subtotal' => $snapshot->items_subtotal,
                'captain_allocations' => $lines->map(fn ($line) => [
                    'order_captain_id' => $line->order_captain_id,
                    'captain_id' => $line->captain_id,
                    'is_primary' => $line->is_primary,
                    'product_funding_share' => $line->product_funding_share,
                    'captain_entitlement' => $line->captain_entitlement,
                    'customer_delivery_amount_allocated' => $line->customer_delivery_amount_allocated,
                    'company_collectible' => $line->company_collectible,
                    'captain_shortfall' => $line->captain_shortfall,
                ])->all(),
            ];
            $databaseHash = (string) DB::scalar('SELECT financial_order_snapshot_expected_hash(?)', [$snapshot->id]);
            if (! hash_equals($snapshot->payload_hash, $databaseHash)
                || ! hash_equals($databaseHash, $this->hash($payload))
                || $invoice->version !== $snapshot->invoice_version
                || $invoice->order_id !== $snapshot->order_id || $invoice->currency_id !== $snapshot->currency_id
                || ! $invoice->issued_at || ! CarbonImmutable::parse(DB::table('invoices')->where('id', $invoice->id)->value('issued_at'))->equalTo(CarbonImmutable::parse($snapshot->issued_at))
                || $invoice->source_order_delivery_pricing_id !== $snapshot->order_delivery_pricing_id
                || ! BigDecimal::of($invoice->items_subtotal)->isEqualTo(BigDecimal::of($snapshot->items_subtotal))
                || Currency::query()->whereKey($snapshot->currency_id)->value('decimal_places') !== $snapshot->currency_decimal_places) {
                throw new FinancialIdempotencyConflictException("Invoice {$invoice->id} differs from its immutable financial source snapshot.");
            }

            return $payload + ['payload_hash' => $snapshot->payload_hash];
        }

        return $this->build($invoice);
    }

    public function capture(Invoice $invoice): OrderFinancialSourceSnapshot
    {
        return DB::transaction(function () use ($invoice): OrderFinancialSourceSnapshot {
            Order::query()->lockForUpdate()->findOrFail($invoice->order_id);
            $existing = OrderFinancialSourceSnapshot::query()->where('invoice_id', $invoice->id)->first();
            if ($existing) {
                $this->read($invoice);

                return $existing;
            }
            $payload = $this->build($invoice);
            $rows = $payload['captain_allocations'];
            unset($payload['captain_allocations'], $payload['primary_captain_id']);
            $snapshot = OrderFinancialSourceSnapshot::query()->create([...$payload, 'captured_at' => now()]);
            foreach ($rows as $row) {
                $snapshot->lines()->create($row);
            }

            return $snapshot;
        });
    }

    private function hash(array $payload): string
    {
        $lines = $payload['captain_allocations'];
        unset($payload['captain_allocations'], $payload['payload_hash']);

        return (string) DB::scalar('SELECT financial_order_snapshot_hash_parts(CAST(? AS jsonb), CAST(? AS jsonb))', [
            json_encode($payload, JSON_THROW_ON_ERROR),
            json_encode($lines, JSON_THROW_ON_ERROR),
        ]);
    }
}
