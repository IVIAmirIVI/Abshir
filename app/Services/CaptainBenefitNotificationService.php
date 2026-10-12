<?php

namespace App\Services;

use App\Models\CaptainBenefitRequest;
use App\Models\CaptainBenefitReview;
use App\Models\User;
use App\Notifications\CaptainBenefitWorkflowNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class CaptainBenefitNotificationService
{
    public const TABLE = 'captain_benefit_notification_events';

    public function reviewers(CaptainBenefitRequest $proposal): Collection
    {
        return User::query()->where('is_active', true)->get()->filter(fn (User $user): bool => app(CaptainBenefitReviewService::class)->approvalRequests($user)->whereKey($proposal->id)->exists());
    }

    public function enqueue(CaptainBenefitReview $review): void
    {
        if (! in_array($review->action, ['SUBMIT', 'APPROVE', 'REJECT', 'CANCEL'], true)
            || ! Schema::hasTable(self::TABLE) || ! Schema::hasTable('notifications')) {
            return;
        }
        $proposal = $review->proposal;
        $submission = $proposal->reviews()->where('action', 'SUBMIT')->first();
        if ($review->action === 'CANCEL' && ! $submission) {
            return;
        }
        $recipients = in_array($review->action, ['SUBMIT', 'CANCEL'], true)
            ? $this->reviewers($proposal)->pluck('id')->values()->all()
            : [$submission->actor_id];
        DB::table(self::TABLE)->insertOrIgnore(['review_id' => $review->id,
            'recipient_ids' => json_encode($recipients, JSON_THROW_ON_ERROR)]);
        DB::afterCommit(function () use ($review): void {
            try {
                $this->deliver($review->id);
            } catch (\Throwable $exception) {
                // The committed outbox stays pending for the scheduled retry; never undo a committed approval.
                report($exception);
            }
        });
    }

    public function deliver(int $reviewId): void
    {
        DB::transaction(function () use ($reviewId): void {
            $event = DB::table(self::TABLE)->where('review_id', $reviewId)->lockForUpdate()->first();
            if (! $event || $event->delivered_at) {
                return;
            }
            $review = CaptainBenefitReview::with('proposal')->findOrFail($reviewId);
            foreach (json_decode($event->recipient_ids, true, flags: JSON_THROW_ON_ERROR) as $recipientId) {
                $user = User::find($recipientId);
                if (! $user || ! $this->permittedRequests($user, $review->action)->whereKey($review->proposal->id)->exists()) {
                    continue;
                }
                $notice = new CaptainBenefitWorkflowNotice($review, $user->id);
                if (! $user->notifications()->whereKey($notice->id)->exists()) {
                    Notification::sendNow($user, $notice, ['database']);
                }
            }
            DB::table(self::TABLE)->where('review_id', $reviewId)->update(['delivered_at' => now()]);
        }, 3);
    }

    public function permittedRequests(User $actor, string $event): Builder
    {
        if (in_array($event, ['SUBMIT', 'CANCEL'], true)) {
            return app(CaptainBenefitReviewService::class)->approvalRequests($actor);
        }

        return app(CaptainBenefitRequestService::class)->accessibleRecords($actor)
            ->whereHas('reviews', fn ($q) => $q->where('action', 'SUBMIT')->where('actor_id', $actor->id));
    }

    public function visible(User $actor): Builder
    {
        return $actor->notifications()->getQuery()->where('type', CaptainBenefitWorkflowNotice::class)
            ->where(function ($query) use ($actor): void {
                foreach (['SUBMIT', 'APPROVE', 'REJECT', 'CANCEL'] as $event) {
                    $query->orWhere(fn ($q) => $q->where('data->event', $event)
                        ->whereIn(DB::raw("(notifications.data->>'request_id')::bigint"), $this->permittedRequests($actor, $event)->select('captain_benefit_requests.id')));
                }
            });
    }

    public function destination(User $actor, array $data): string
    {
        $proposal = $this->permittedRequests($actor, $data['event'])->whereKey($data['request_id'])->firstOrFail();
        app(CaptainBenefitRequestService::class)->assertRecord($actor, $proposal->captain, $proposal);

        return route('captains.requests.show', [$proposal->captain, $proposal]);
    }

    public function text(array $data): array
    {
        return ['title' => __('internal.workflow.'.$data['request_type'].'.'.$data['event']),
            'body' => __('internal.workflow_body', ['id' => $data['request_id']])];
    }
}
