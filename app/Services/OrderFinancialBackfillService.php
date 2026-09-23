<?php

namespace App\Services;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderOperationalStatus;
use App\Models\InvoiceCollection;
use App\Models\Order;

class OrderFinancialBackfillService
{
    public function __construct(private readonly OrderFinancialPostingService $financials) {}

    public function run(bool $apply, callable $report): array
    {
        $summary = ['eligible' => 0, 'posted' => 0, 'complete' => 0, 'sham_cash' => 0, 'unresolved' => 0];

        Order::query()->where('operational_status', OrderOperationalStatus::Delivered->value)->orderBy('id')->chunkById(100, function ($orders) use ($apply, $report, &$summary): void {
            foreach ($orders as $order) {
                try {
                    $state = $this->financials->assessOrder($order);
                    if ($state['state'] === 'unresolved') {
                        $summary['unresolved']++;
                        $report("UNRESOLVED Order {$order->id}: {$state['reason']}");
                    } elseif ($state['state'] === 'conflict') {
                        $summary['unresolved']++;
                        $report("CONFLICT Order {$order->id}: ".implode(', ', $state['conflicts']));
                    } elseif ($state['state'] === 'complete') {
                        $summary['complete']++;
                        $details = $state['no_effect'] === [] ? '' : '; NO_FINANCIAL_EFFECT '.implode(', ', $state['no_effect']);
                        $report("COMPLETE Order {$order->id}{$details}");
                    } else {
                        $summary['eligible']++;
                        $details = $state['no_effect'] === [] ? '' : '; NO_FINANCIAL_EFFECT '.implode(', ', $state['no_effect']);
                        $report(($apply ? 'POST' : 'WOULD POST')." Order {$order->id}: MISSING ".implode(', ', $state['missing']).$details);
                        if ($apply) {
                            $this->financials->postOrderDerivedIfReady($order);
                            $summary['posted']++;
                        }
                    }
                } catch (\Throwable $exception) {
                    $summary['unresolved']++;
                    $report("UNRESOLVED Order {$order->id}: {$exception->getMessage()}");
                }
            }
        });

        InvoiceCollection::query()->orderBy('id')->chunkById(100, function ($collections) use ($apply, $report, &$summary): void {
            foreach ($collections as $collection) {
                if ($collection->collection_method === InvoiceCollectionMethod::ShamCash) {
                    $summary['sham_cash']++;
                    $report("SHAM_CASH_NO_CUSTODY Collection {$collection->id}");

                    continue;
                }
                if ($collection->collection_method !== InvoiceCollectionMethod::Cash) {
                    $summary['unresolved']++;
                    $report("UNRESOLVED Collection {$collection->id}: unknown legacy collection method.");

                    continue;
                }
                try {
                    $state = $this->financials->assessCashCollection($collection);
                } catch (\Throwable $exception) {
                    $summary['unresolved']++;
                    $report("UNRESOLVED CASH Collection {$collection->id}: {$exception->getMessage()}");

                    continue;
                }
                if ($state['state'] === 'unresolved') {
                    $summary['unresolved']++;
                    $report("UNRESOLVED CASH Collection {$collection->id}: {$state['reason']}");

                    continue;
                }
                if ($state['state'] === 'conflict') {
                    $summary['unresolved']++;
                    $report("CONFLICT CASH Collection {$collection->id}");

                    continue;
                }
                if ($state['state'] === 'exact') {
                    $summary['complete']++;
                    $report("COMPLETE CASH Collection {$collection->id}");

                    continue;
                }
                $summary['eligible']++;
                $report(($apply ? 'POST' : 'WOULD POST')." CASH Collection {$collection->id}: MISSING custody event");
                if ($apply) {
                    try {
                        $this->financials->postCashCollectionCustody($collection);
                        $summary['posted']++;
                    } catch (\Throwable $exception) {
                        $summary['unresolved']++;
                        $report("UNRESOLVED Collection {$collection->id}: {$exception->getMessage()}");
                    }
                }
            }
        });

        Order::query()->where('operational_status', OrderOperationalStatus::Cancelled->value)
            ->whereHas('cancellation', fn ($query) => $query->where('classification', OrderCancellationClassification::AfterPurchase->value))
            ->whereDoesntHave('invoices', fn ($query) => $query->where('status', 'ISSUED'))
            ->orderBy('id')->chunkById(100, function ($orders) use ($report, &$summary): void {
                foreach ($orders as $order) {
                    $summary['unresolved']++;
                    $report("UNRESOLVED AFTER_PURCHASE cancelled Order {$order->id}: deferred to Phase 9C.");
                }
            });

        return $summary;
    }
}
