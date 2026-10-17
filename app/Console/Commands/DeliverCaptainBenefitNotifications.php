<?php

namespace App\Console\Commands;

use App\Services\CaptainBenefitNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeliverCaptainBenefitNotifications extends Command
{
    protected $signature = 'abshir:deliver-benefit-notifications';

    protected $description = 'Retry pending internal Captain request notifications (no external channels).';

    public function handle(CaptainBenefitNotificationService $service): int
    {
        if (! Schema::hasTable($service::TABLE) || ! Schema::hasTable('notifications')) {
            $this->error(__('internal.activation_required'));

            return self::FAILURE;
        }
        $failed = false;
        DB::table($service::TABLE)->whereNull('delivered_at')->orderBy('review_id')->chunkById(100, function ($events) use ($service, &$failed): void {
            foreach ($events as $event) {
                try {
                    $service->deliver($event->review_id);
                } catch (\Throwable $exception) {
                    report($exception);
                    $failed = true;
                    $this->error('Internal notification delivery pending for review #'.$event->review_id);
                }
            }
        }, 'review_id');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
