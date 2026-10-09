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
use App\Models\InvoiceCollection;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Actual collections and earned obligations, never simulated payments or company handovers. */
class SharedOrderFinancialPostingService
{
    public function __construct(
        private readonly OrderFinancialSourceSnapshotService $snapshots,
        private readonly FinancialAccountService $accounts,
        private readonly FinancialPostingService $posting,
    ) {}

    public function postIfReady(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $invoice = $order->currentInvoice()->lockForUpdate()->first();
            if (! $invoice || $invoice->status !== InvoiceStatus::Issued || ! $invoice->issued_at) {
                return [];
            }
            $collection = $invoice->collection()->lockForUpdate()->first();
            if (! $collection) {
                return [];
            }
            $snapshot = $this->snapshots->read($invoice);
            $currency = Currency::query()->lockForUpdate()->findOrFail($snapshot['currency_id']);
            $branch = Branch::findOrFail($snapshot['branch_id']);
            $collectedAt = CarbonImmutable::parse(DB::table('invoice_collections')->where('id', $collection->id)->value('collected_at'));
            $events = [];
            if ($collection->collection_method === InvoiceCollectionMethod::Cash) {
                $primary = collect($snapshot['captain_allocations'])->sole('is_primary', true);
                $events[] = $this->post($currency, $branch, $collection, FinancialEventType::SharedInvoiceCashCustodyReceived,
                    $collectedAt, [$this->movement($currency, $primary['captain_id'], FinancialAccountKind::CaptainCashCustody, $collection->amount)], $snapshot['payload_hash']);
            }
            // Preserve existing earned-obligation timing: issued, collected AND delivered.
            if ($order->operational_status !== OrderOperationalStatus::Delivered) {
                DB::select('SELECT assert_shared_order_financial_set(?)', [$order->id]);

                return $events;
            }
            $deliveredAt = $order->statusHistory()->where('new_status', 'DELIVERED')->orderByDesc('changed_at')->orderByDesc('id')->value('changed_at');
            if (! $deliveredAt) {
                throw new FinancialLedgerException('Shared earned obligations require authoritative delivery history.');
            }
            $occurredAt = max($collectedAt, CarbonImmutable::parse($snapshot['issued_at']), CarbonImmutable::parse($deliveredAt));
            foreach ([FinancialEventType::SharedOrderProductReimbursementDue->value => 'product_funding_share',
                FinancialEventType::SharedOrderDeliveryEntitlementDue->value => 'captain_entitlement'] as $event => $field) {
                $movements = [];
                foreach ($snapshot['captain_allocations'] as $row) {
                    if (BigDecimal::of($row[$field])->isGreaterThan(0)) {
                        $movements[] = $this->movement($currency, $row['captain_id'], FinancialAccountKind::CaptainCompanyReceivable, $row[$field]);
                    }
                }
                if ($movements !== []) {
                    $events[] = $this->post($currency, $branch, $collection, FinancialEventType::from($event), $occurredAt, $movements, $snapshot['payload_hash']);
                }
            }

            DB::select('SELECT assert_shared_order_financial_set(?)', [$order->id]);

            return $events;
        }, 3);
    }

    public function assess(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $invoice = $order->currentInvoice()->first();
            if (! $invoice || $invoice->status !== InvoiceStatus::Issued) {
                return ['state' => 'waiting_invoice', 'invoice_id' => $invoice?->id];
            }
            $collection = $invoice->collection()->first();
            if (! $collection) {
                return ['state' => 'waiting_collection', 'invoice_id' => $invoice->id];
            }
            try {
                DB::transaction(function () use ($invoice, $order): void {
                    $this->snapshots->read($invoice);
                    DB::select('SELECT assert_shared_order_financial_set(?)', [$order->id]);
                });
            } catch (QueryException|FinancialLedgerException $exception) {
                return ['state' => 'conflict', 'reason' => 'Shared financial source set requires reconciliation.'];
            }

            return ['state' => $order->operational_status === OrderOperationalStatus::Delivered ? 'complete' : 'waiting_delivery',
                'invoice_id' => $invoice->id, 'collection_id' => $collection->id];
        });
    }

    private function movement(Currency $currency, int $captainId, FinancialAccountKind $kind, string $amount): array
    {
        $captain = Captain::findOrFail($captainId);
        $account = $this->accounts->getOrCreate($this->accounts->ownerForCaptain($captain), $currency, $kind);

        return ['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $amount];
    }

    private function post(Currency $currency, Branch $branch, InvoiceCollection $collection, FinancialEventType $event, CarbonImmutable $time, array $movements, string $sourceHash)
    {
        return $this->posting->postEvent($currency, 'INVOICE_COLLECTION', $collection->id, $event->value, 1,
            $time, $movements, $branch, $collection->recordedBy, null, $sourceHash);
    }
}
