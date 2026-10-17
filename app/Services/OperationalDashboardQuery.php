<?php

namespace App\Services;

use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalDashboardQuery
{
    public const TIMEZONE = 'Asia/Damascus';

    public const PERIODS = ['today' => 1, '7days' => 7, '30days' => 30];

    public function canView(User $actor): bool
    {
        return $actor->is_active && $actor->can('orders.view') && ($actor->isSuperAdmin()
            || ($actor->roles()->where('code', 'ORDER_EMPLOYEE')->exists()
                && ! $actor->roles()->where('code', 'CAPTAIN')->exists()));
    }

    public function snapshot(User $actor, string $period, string $branchId): array
    {
        abort_unless($this->canView($actor), 403);
        if (! array_key_exists($period, self::PERIODS)) {
            throw ValidationException::withMessages(['period' => __('dashboard.invalid_period')]);
        }
        if ($branchId !== '' && (filter_var($branchId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]) === false || ! $actor->availableBranches()->whereKey($branchId)->exists())) {
            abort(403);
        }

        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $start = $today->subDays(self::PERIODS[$period] - 1);
        $end = $today->addDay();
        // PostgreSQL timestamptz comparisons retain an explicit offset even when
        // the connection's session timezone differs from the application's UTC.
        $fromUtc = $start->utc()->toIso8601String();
        $untilUtc = $end->utc()->toIso8601String();
        $orders = Order::query()->accessibleTo($actor)->when($branchId !== '', fn (Builder $q) => $q->where('branch_id', $branchId));
        $open = (clone $orders)->whereNotIn('operational_status', ['DELIVERED', 'CANCELLED']);
        $awaiting = $this->awaitingCaptain(clone $orders);
        $attention = $this->attention($actor, clone $orders);

        // Final outcomes are counted by actual status-history time, never ordered_at.
        // DISTINCT avoids multiplying an Order through related history records.
        $events = DB::table('order_status_history as h')
            ->joinSub((clone $orders)->select(['id', 'operational_status']), 'o', 'o.id', '=', 'h.order_id')
            ->whereColumn('h.new_status', 'o.operational_status')
            ->whereIn('h.new_status', ['DELIVERED', 'CANCELLED'])
            ->where('h.changed_at', '>=', $fromUtc)->where('h.changed_at', '<', $untilUtc);
        $outcomes = (clone $events)->selectRaw('h.new_status, COUNT(DISTINCT h.order_id) AS total')->groupBy('h.new_status')->pluck('total', 'h.new_status');
        $metrics = [
            // Laravel's created_at is a timestamp without timezone, stored in UTC.
            'created_today' => (clone $orders)->where('created_at', '>=', $today->utc()->format('Y-m-d H:i:s'))->where('created_at', '<', $end->utc()->format('Y-m-d H:i:s'))->count(),
            'open' => (clone $open)->count(),
            'delivered' => (int) ($outcomes['DELIVERED'] ?? 0),
            'cancelled' => (int) ($outcomes['CANCELLED'] ?? 0),
            'attention' => (clone $attention)->count(),
        ];
        if ($actor->can('orders.captains.assign')) {
            $metrics['awaiting_captain'] = (clone $awaiting)->count();
        }
        $dailyOrders = (clone $orders)->where('ordered_at', '>=', $fromUtc)->where('ordered_at', '<', $untilUtc)
            ->selectRaw('(ordered_at AT TIME ZONE ?)::date AS day, COUNT(*) AS total', [self::TIMEZONE])
            ->groupBy('day')->pluck('total', 'day');
        $dailyEvents = (clone $events)->selectRaw('(h.changed_at AT TIME ZONE ?)::date AS day, h.new_status, COUNT(DISTINCT h.order_id) AS total', [self::TIMEZONE])
            ->groupBy('day', 'h.new_status')->get()->keyBy(fn ($row) => $row->day.'-'.$row->new_status);
        $trend = [];
        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $trend[] = ['date' => $date, 'orders' => (int) ($dailyOrders[$date] ?? 0),
                'delivered' => (int) ($dailyEvents[$date.'-DELIVERED']->total ?? 0),
                'cancelled' => (int) ($dailyEvents[$date.'-CANCELLED']->total ?? 0)];
        }
        $counts = (clone $orders)->selectRaw('operational_status, COUNT(*) AS total')->groupBy('operational_status')->pluck('total', 'operational_status');
        $distribution = array_map(fn ($status) => ['status' => $status->value, 'label' => __('orders.statuses.'.$status->value), 'count' => (int) ($counts[$status->value] ?? 0)], OrderOperationalStatus::cases());
        $followUp = (clone $attention)->with('branch:id,name')
            ->withExists([
                'captainAssignments as has_primary' => fn ($q) => $q->whereNull('removed_at')->where('assignment_role', 'PRIMARY_COLLECTION'),
                'currentInvoice as has_issued_invoice' => fn ($q) => $q->where('status', 'ISSUED'),
            ])
            ->orderBy('ordered_at')->orderBy('id')->limit(10)
            ->get(['orders.id', 'order_number', 'customer_name_snapshot', 'branch_id', 'operational_status', 'ordered_at']);
        $canConfirm = $actor->can('orders.status.update');
        $canAssign = $actor->can('orders.captains.assign');
        foreach ($followUp as $order) {
            $order->setAttribute('attention_action', match (true) {
                $canConfirm && $order->operational_status === OrderOperationalStatus::New => 'confirm',
                $canAssign && $order->operational_status === OrderOperationalStatus::Confirmed && ! $order->has_primary && ! $order->has_issued_invoice => 'assign',
                default => 'receipt',
            });
        }

        return compact('metrics', 'trend', 'distribution', 'followUp') + ['from' => $start->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
    }

    private function awaitingCaptain(Builder $query): Builder
    {
        return $query->where('operational_status', 'CONFIRMED')->whereDoesntHave('cancellationIntent')
            ->whereDoesntHave('currentInvoice', fn ($q) => $q->where('status', 'ISSUED'))
            ->whereDoesntHave('captainAssignments', fn ($q) => $q->whereNull('removed_at')->where('assignment_role', 'PRIMARY_COLLECTION'));
    }

    private function attention(User $actor, Builder $query): Builder
    {
        $confirm = $actor->can('orders.status.update');
        $assign = $actor->can('orders.captains.assign');
        $receipt = $actor->can('order_shop_receipts.upload');

        return $query->whereNotIn('operational_status', ['DELIVERED', 'CANCELLED'])->whereDoesntHave('cancellationIntent')
            ->where(function (Builder $q) use ($confirm, $assign, $receipt): void {
                $q->whereRaw('1 = 0');
                if ($confirm) {
                    $q->orWhere('operational_status', 'NEW');
                }
                if ($assign) {
                    $q->orWhere(fn (Builder $q) => $this->awaitingCaptain($q));
                }
                if ($receipt) {
                    $q->orWhereHas('shops', fn ($shops) => $shops->whereHas('purchaseConfirmation')->whereDoesntHave('receipts'));
                }
            });
    }
}
