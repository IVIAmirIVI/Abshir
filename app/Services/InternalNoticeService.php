<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\SecurityAudit;
use App\Models\User;
use App\Notifications\CaptainBenefitWorkflowNotice;
use App\Notifications\InternalStaffNotice;
use App\Support\StaffInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InternalNoticeService
{
    public function assertStaff(User $actor): void
    {
        abort_unless(StaffInbox::allowed($actor), 403);
    }

    public function visible(User $actor): Builder
    {
        $this->assertStaff($actor);

        return $actor->notifications()->getQuery()->where(function ($query) use ($actor): void {
            $query->whereIn('notifications.id', $this->manualVisible($actor)->select('notifications.id'))
                ->orWhereIn('notifications.id', app(CaptainBenefitNotificationService::class)->visible($actor)->select('notifications.id'));
        });
    }

    private function manualVisible(User $actor): Builder
    {
        $this->assertStaff($actor);
        $branches = $actor->availableBranches()->pluck('branches.id')->all();
        $query = $actor->notifications()->getQuery()->where('type', InternalStaffNotice::class)
            ->when(! StaffInbox::manualAllowed($actor), fn ($q) => $q->whereRaw('1 = 0'))
            ->where(fn ($q) => $q->whereNull('data->branch_id')->orWhereIn('data->branch_id', $branches));
        if (! $actor->can('orders.view')) {
            return $query->whereNull('data->order_id');
        }

        return $query->where(function ($q) use ($branches): void {
            $q->whereNull('data->order_id')->orWhereExists(function ($orders) use ($branches): void {
                $orders->selectRaw('1')->from('orders')
                    ->whereRaw("orders.id::text = (notifications.data::jsonb->>'order_id')")
                    ->whereRaw("orders.branch_id::text = (notifications.data::jsonb->>'branch_id')")
                    ->whereIn('orders.branch_id', $branches);
            });
        });
    }

    public function unreadCount(User $actor): int
    {
        if (! StaffInbox::allowed($actor) || ! Schema::hasTable('notifications')) {
            return 0;
        }

        return $this->visible($actor)->whereNull('read_at')->count();
    }

    public function send(User $actor, array $data): SecurityAudit
    {
        Gate::forUser($actor)->authorize('manage-authorization');
        if (array_diff(array_keys($data), ['request_key', 'title', 'body', 'recipient_ids', 'branch_id', 'order_id'])) {
            throw ValidationException::withMessages(['notice' => __('internal.invalid_fields')]);
        }
        $data = Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:2000'],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:50'],
            'recipient_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ])->validate();
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('security_audits')) {
            throw ValidationException::withMessages(['notice' => __('internal.activation_required')]);
        }
        $recipients = array_map('intval', $data['recipient_ids']);
        sort($recipients);

        return DB::transaction(function () use ($actor, $data, $recipients): SecurityAudit {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['internal-notice:'.$data['request_key']]);
            $order = filled($data['order_id'] ?? null) ? Order::findOrFail($data['order_id']) : null;
            $branchId = filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null;
            if ($order) {
                if ($branchId !== null && $branchId !== $order->branch_id) {
                    throw ValidationException::withMessages(['branch_id' => __('internal.invalid_scope')]);
                }
                $branchId = $order->branch_id;
            }
            $branch = $branchId ? Branch::findOrFail($branchId) : null;
            if ($branch) {
                abort_unless($actor->canAccessBranch($branch), 403);
            }
            $users = User::whereKey($recipients)->orderBy('id')->lockForUpdate()->get();
            foreach ($users as $user) {
                if (! StaffInbox::manualAllowed($user) || ($branch && ! $user->canAccessBranch($branch))
                    || ($order && ! $user->can('orders.view'))) {
                    throw ValidationException::withMessages(['recipient_ids' => __('internal.invalid_recipient')]);
                }
            }
            if ($users->count() !== count($recipients)) {
                throw ValidationException::withMessages(['recipient_ids' => __('internal.invalid_recipient')]);
            }
            $payload = ['request_key' => $data['request_key'], 'title' => trim($data['title']), 'body' => trim($data['body']),
                'branch_id' => $branchId, 'order_id' => $order?->id];
            $hash = hash('sha256', json_encode([$payload, $actor->id, $recipients], JSON_THROW_ON_ERROR));
            $existing = SecurityAudit::where('operation_key', $data['request_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => __('internal.conflict')]);
                }

                return $existing;
            }
            Notification::sendNow($users, new InternalStaffNotice($payload), ['database']);

            return app(SecurityAuditService::class)->record($actor, 'NOTICE', $data['request_key'], 'NOTICE_SENT', [],
                ['recipient_ids' => $recipients, 'branch_id' => $branchId, 'resource_type' => $order ? 'ORDER' : null,
                    'resource_id' => $order?->id], $branchId, $data['request_key'], $hash);
        });
    }

    public function own(User $actor, string $id): DatabaseNotification
    {
        $this->assertStaff($actor);
        abort_unless(Schema::hasTable('notifications'), 404);

        return $this->visible($actor)->whereKey($id)->firstOrFail();
    }

    public function destination(User $actor, DatabaseNotification $notice): string
    {
        $notice = $this->own($actor, $notice->id);
        if ($notice->type === CaptainBenefitWorkflowNotice::class) {
            return app(CaptainBenefitNotificationService::class)->destination($actor, $notice->data);
        }
        abort_unless(filled($notice->data['order_id'] ?? null), 404);
        $order = Order::findOrFail($notice->data['order_id']);
        Gate::forUser($actor)->authorize('orders.view');
        abort_unless($order->branch_id === (int) $notice->data['branch_id'] && $actor->canAccessBranch($order->branch), 403);

        return route('orders.workflow', $order);
    }
}
