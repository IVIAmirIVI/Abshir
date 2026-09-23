<?php

namespace App\Console\Commands;

use App\Services\OrderFinancialBackfillService;
use Illuminate\Console\Command;

class BackfillOrderFinancialEvents extends Command
{
    protected $signature = 'financial:backfill-order-events {--apply : Write missing immutable ledger events}';

    protected $description = 'Report or post missing order-derived Captain financial events';

    public function handle(OrderFinancialBackfillService $backfill): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode (no financial writes)');
        $summary = $backfill->run($apply, fn (string $line) => $this->line($line));
        $this->newLine();
        $this->line(collect($summary)->map(fn ($value, $key) => "{$key}={$value}")->implode(' '));

        return self::SUCCESS;
    }
}
