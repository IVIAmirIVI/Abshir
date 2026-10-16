<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\InvoiceCollection;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Single-Captain retained CASH: company share debits operating Deposit; SHAM_CASH remains a genuine obligation. */
class OrderAccountingV3Service
{
    public function __construct(
        private readonly OrderFinancialSourceSnapshotService $snapshots,
        private readonly FinancialAccountService $accounts,
        private readonly FinancialPostingService $posting,
    ) {}

    /** @return array<int, FinancialTransaction> */
    public function postIfReady(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $order->accounting_contract_version !== 3) {
                throw new FinancialLedgerException('V3 posting requires its immutable Order contract binding.');
            }
            $invoice = $order->currentInvoice()->lockForUpdate()->first();
            if (! $invoice || $invoice->status !== InvoiceStatus::Issued) {
                return [];
            }
            $collection = $invoice->collection()->lockForUpdate()->first();
            if (! $collection) {
                return [];
            }
            $snapshot = $this->snapshots->read($invoice);
            if (count($snapshot['captain_allocations']) !== 1) {
                throw new FinancialLedgerException('V3 requires one Captain; shared settlement policy is not approved.');
            }
            $currency = Currency::query()->lockForUpdate()->findOrFail($snapshot['currency_id']);
            $branch = Branch::findOrFail($snapshot['branch_id']);
            $collectedAt = CarbonImmutable::parse(DB::table('invoice_collections')->where('id', $collection->id)->value('collected_at'));
            $events = [];
            if ($order->operational_status === OrderOperationalStatus::Delivered) {
                $deliveredAt = $order->statusHistory()->where('new_status', 'DELIVERED')->orderByDesc('changed_at')->orderByDesc('id')->value('changed_at');
                if (! $deliveredAt) {
                    throw new FinancialLedgerException('V3 earned obligations require authoritative delivery history.');
                }
                $occurredAt = max($collectedAt, CarbonImmutable::parse($snapshot['issued_at']), CarbonImmutable::parse($deliveredAt));
                $concepts = $collection->collection_method === InvoiceCollectionMethod::Cash
                    ? [FinancialEventType::OrderV3CashCompanyShareDebit->value => 'company_collectible',
                        FinancialEventType::OrderV3CashShortfallDue->value => 'captain_shortfall']
                    : [FinancialEventType::OrderV3ShamDeliveryEntitlementDue->value => 'captain_entitlement'];
                if ($collection->collection_method === InvoiceCollectionMethod::ShamCash) {
                    $concepts[FinancialEventType::OrderV3ShamProductReimbursementDue->value] = 'product_funding_share';
                }
                foreach ($concepts as $event => $field) {
                    $movements = [];
                    foreach ($snapshot['captain_allocations'] as $row) {
                        if ($collection->collection_method === InvoiceCollectionMethod::Cash) {
                            $deliveryCash = BigDecimal::of($row['customer_delivery_amount_allocated'])->minus(app(MixedCustomerCreditService::class)->creditAmount($collection));
                            $difference = $deliveryCash->minus($row['captain_entitlement']);
                            $row['company_collectible'] = (string) ($difference->isGreaterThan(0) ? $difference : BigDecimal::zero());
                            $row['captain_shortfall'] = (string) ($difference->isLessThan(0) ? $difference->negated() : BigDecimal::zero());
                        }
                        if (BigDecimal::of($row[$field])->isGreaterThan(0)) {
                            $movements[] = $this->movement($currency, $row['captain_id'],
                                $field === 'company_collectible' ? FinancialAccountKind::CaptainDeposit : FinancialAccountKind::CaptainCompanyReceivable,
                                $row[$field], $field === 'company_collectible' ? FinancialEntryDirection::Decrease : FinancialEntryDirection::Increase);
                        }
                    }
                    if ($movements !== []) {
                        $events[] = $this->post($currency, $branch, $collection, FinancialEventType::from($event), $occurredAt, $movements, $snapshot['payload_hash']);
                    }
                }
            }
            DB::select('SELECT assert_order_accounting_v3_set(?)', [$order->id]);

            return $events;
        }, 3);
    }

    /** Read-only reconciliation of the database-enforced complete source set. */
    public function assess(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $order->accounting_contract_version !== 3) {
                throw new FinancialLedgerException('V3 assessment requires its immutable Order contract binding.');
            }
            $invoice = $order->currentInvoice()->first();
            if (! $invoice || $invoice->status !== InvoiceStatus::Issued) {
                return ['state' => 'waiting_invoice', 'invoice_id' => $invoice?->id];
            }
            $collection = $invoice->collection()->first();
            if (! $collection) {
                return ['state' => 'waiting_collection', 'invoice_id' => $invoice->id];
            }
            try {
                DB::transaction(function () use ($order, $invoice): void {
                    $this->snapshots->read($invoice);
                    DB::select('SELECT assert_order_accounting_v3_set(?)', [$order->id]);
                });
            } catch (QueryException|FinancialLedgerException $exception) {
                return ['state' => 'conflict', 'reason' => 'V3 source set requires reconciliation.'];
            }

            return ['state' => $order->operational_status === OrderOperationalStatus::Delivered ? 'complete' : 'waiting_delivery',
                'invoice_id' => $invoice->id, 'collection_id' => $collection->id];
        });
    }

    private function movement(Currency $currency, int $captainId, FinancialAccountKind $kind, string $amount, FinancialEntryDirection $direction): array
    {
        $captain = Captain::findOrFail($captainId);
        $account = $this->accounts->getOrCreate($this->accounts->ownerForCaptain($captain), $currency, $kind);

        return ['account' => $account, 'direction' => $direction, 'amount' => $amount];
    }

    private function post(Currency $currency, Branch $branch, InvoiceCollection $collection, FinancialEventType $event,
        CarbonImmutable $time, array $movements, string $sourceHash): FinancialTransaction
    {
        return $this->posting->postEvent($currency, 'INVOICE_COLLECTION', $collection->id, $event->value, 3,
            $time, $movements, $branch, $collection->recordedBy, null, $sourceHash);
    }
}
