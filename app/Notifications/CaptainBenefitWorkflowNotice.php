<?php

namespace App\Notifications;

use App\Models\CaptainBenefitReview;
use Illuminate\Notifications\Notification;
use Ramsey\Uuid\Uuid;

class CaptainBenefitWorkflowNotice extends Notification
{
    public function __construct(public readonly CaptainBenefitReview $review, int $recipientId)
    {
        $this->id = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'abshir:benefit-review:'.$review->id.':recipient:'.$recipientId);
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $proposal = $this->review->proposal;

        return ['event' => $this->review->action, 'request_type' => $proposal->kind,
            'request_id' => $proposal->id, 'review_id' => $this->review->id,
            'captain_id' => $proposal->captain_id, 'branch_id' => $proposal->branch_id];
    }
}
