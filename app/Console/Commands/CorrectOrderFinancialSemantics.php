<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderFinancialSemanticsCorrectionService;
use Illuminate\Console\Command;

class CorrectOrderFinancialSemantics extends Command
{
    protected $signature = 'financial:correct-order-financial-semantics {--apply : Reverse verified legacy events and post corrected events when ready}';

    protected $description = 'Dry-run or correct published Phase 9B/9B.6 Order events to final Phase 9B.7 semantics';

    public function handle(OrderFinancialSemanticsCorrectionService $correction): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode (zero writes)');
        $counts = [];
        Order::query()->orderBy('id')->chunkById(100, function ($orders) use ($correction, $apply, &$counts): void {
            foreach ($orders as $order) {
                try {
                    $result = $correction->correct($order, $apply);
                    $state = strtoupper($result['state']);
                    $counts[$state] = ($counts[$state] ?? 0) + 1;
                    $this->line("{$state} Order {$order->id} Invoice ".($result['invoice_id'] ?? 'none').'/v'.($result['invoice_version'] ?? 'none')
                        .' Collection '.collect($result['collections'])->map(fn ($collection) => $collection['id'].'/'.$collection['method'])->implode(',')
                        .' Legacy '.implode(',', $result['legacy_event_ids']).' Reverse '.implode(',', $result['reverse_ids'])
                        .' Obsolete Custody '.implode(',', $result['obsolete_held_ids']).' Reverse '.implode(',', $result['reverse_held_ids'])
                        .' Corrected '.implode(',', $result['corrected_events'] ?? [])
                        .' Readiness '.($result['corrected_state'] ?? 'unknown')
                        .(isset($result['reason']) ? " {$result['reason']}" : ''));
                } catch (\Throwable $exception) {
                    $counts['ERROR'] = ($counts['ERROR'] ?? 0) + 1;
                    $this->error("ERROR Order {$order->id}: {$exception->getMessage()}");
                }
            }
        });
        $this->line(collect($counts)->map(fn ($count, $state) => "{$state}={$count}")->implode(' '));

        return isset($counts['ERROR']) || isset($counts['CONFLICT']) || isset($counts['UNRESOLVED']) ? self::FAILURE : self::SUCCESS;
    }
}
