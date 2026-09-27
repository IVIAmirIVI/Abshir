<?php

namespace App\Services;

use App\Enums\OrderCancellationClassification;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;

class OrderFinancialBackfillService
{
    public function __construct(private readonly CorrectedOrderFinancialService $financials) {}

    public function run(bool $apply, callable $report): array
    {
        $summary = ['eligible' => 0, 'posted' => 0, 'complete' => 0, 'waiting' => 0, 'legacy_correction_required' => 0, 'unresolved' => 0];
        Order::query()->orderBy('id')->chunkById(100, function ($orders) use ($apply, $report, &$summary): void {
            foreach ($orders as $order) {
                try {
                    if ($order->operational_status === OrderOperationalStatus::Cancelled
                        && $order->cancellation?->classification === OrderCancellationClassification::AfterPurchase
                        && ! $order->invoices()->where('status', 'ISSUED')->exists()) {
                        $summary['unresolved']++;
                        $report("UNRESOLVED AFTER_PURCHASE cancelled Order {$order->id}: deferred to Phase 9C.");

                        continue;
                    }
                    $assessment = $this->financials->assess($order);
                    $state = $assessment['state'];
                    if ($state === 'legacy_correction_required') {
                        $summary['legacy_correction_required']++;
                        $report("LEGACY_CORRECTION_REQUIRED Order {$order->id}: use financial:correct-order-financial-semantics");
                    } elseif ($state === 'eligible') {
                        $summary['eligible']++;
                        $report(($apply ? 'POST' : 'WOULD POST')." Order {$order->id}: "
                            .implode(', ', collect($assessment['concepts'])->filter(fn ($value) => $value === 'missing')->keys()->all()));
                        if ($apply) {
                            $this->financials->postIfReady($order);
                            $summary['posted']++;
                        }
                    } elseif ($state === 'complete') {
                        $summary['complete']++;
                        $report("COMPLETE Order {$order->id}");
                    } elseif (str_starts_with($state, 'waiting_')) {
                        $summary['waiting']++;
                        $report(strtoupper($state)." Order {$order->id}");
                    } else {
                        $summary['unresolved']++;
                        $report(strtoupper($state)." Order {$order->id}: ".($assessment['reason'] ?? 'invalid financial source'));
                    }
                } catch (\Throwable $exception) {
                    $summary['unresolved']++;
                    $report("UNRESOLVED Order {$order->id}: {$exception->getMessage()}");
                }
            }
        });

        return $summary;
    }
}
