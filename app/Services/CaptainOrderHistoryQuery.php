<?php

namespace App\Services;

use App\Enums\OrderCaptainRole;
use App\Models\Captain;
use App\Models\Order;
use App\Models\User;
use App\Support\CaptainSelfView;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CaptainOrderHistoryQuery
{
    /** Historical participation, including removed assignments, without widening Order-detail access. */
    public function listing(User $actor, Captain $captain, array $filters = [], bool $selfView = false): array
    {
        if ($selfView) {
            Gate::forUser($actor)->authorize(CaptainSelfView::ABILITY);
            abort_unless(app(CaptainSelfView::class)->resolve($actor)?->id === $captain->id, 403);
        } else {
            abort_unless(app(CaptainInformationQuery::class)->canView($actor) && $actor->canAccessCaptain($captain), 403);
            Gate::forUser($actor)->authorize('orders.view');
        }

        $participation = fn (Builder $q) => $q->where('captain_id', $captain->id);
        $orders = Order::query()->select(['orders.id', 'order_number', 'ordered_at', 'operational_status', 'branch_id', 'customer_id'])
            ->whereHas('captainAssignments', $participation);
        if (! $selfView) {
            $orders->accessibleTo($actor);
        }
        $orders->when(filled($filters['branch_id'] ?? null), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']));
        foreach (['orders_from' => '>=', 'orders_to' => '<'] as $key => $operator) {
            if (filled($filters[$key] ?? null)) {
                $instant = CarbonImmutable::parse($filters[$key], OperationalDashboardQuery::TIMEZONE)->startOfDay();
                if ($key === 'orders_to') {
                    $instant = $instant->addDay();
                }
                $orders->where('ordered_at', $operator, $instant->utc()->toIso8601String());
            }
        }
        $orders->when(filled($filters['orders_status'] ?? null), fn (Builder $q) => $q->where('operational_status', $filters['orders_status']));
        if (filled($filters['orders_role'] ?? null)) {
            $orders->whereHas('captainAssignments', function (Builder $q) use ($captain, $filters): void {
                $q->where('captain_id', $captain->id);
                if ($filters['orders_role'] === 'former') {
                    $q->whereNotNull('removed_at');
                } else {
                    $q->where('assignment_role', $filters['orders_role'] === 'collector' ? OrderCaptainRole::PrimaryCollection : OrderCaptainRole::Support);
                }
            });
        }

        // Simultaneous participation, not the count of all historical replacements.
        $shared = "EXISTS (SELECT 1 FROM order_captains a JOIN order_captains b ON b.order_id=a.order_id AND b.id>a.id AND b.captain_id<>a.captain_id WHERE a.order_id=orders.id AND a.assigned_at<COALESCE(b.removed_at,'infinity'::timestamptz) AND b.assigned_at<COALESCE(a.removed_at,'infinity'::timestamptz))";
        $orders->selectRaw($shared.' AS history_shared');
        if (filled($filters['orders_kind'] ?? null)) {
            $orders->whereRaw(($filters['orders_kind'] === 'single' ? 'NOT ' : '').$shared);
        }
        $canOpenOrders = $actor->can('orders.view');
        $superAdmin = $actor->isSuperAdmin();
        $canCustomer = $canOpenOrders && $actor->can('customers.view');
        if ($canCustomer) {
            $orders->with(['customer' => fn ($q) => $q->accessibleTo($actor)->select(['id', 'name'])]);
        }
        $orders->with(['captainAssignments' => fn ($q) => $q->where('captain_id', $captain->id)
            ->select(['id', 'order_id', 'assignment_role', 'assigned_at', 'removed_at'])->orderByDesc('assigned_at')->orderByDesc('id')]);
        $page = $orders->orderByDesc('ordered_at')->orderByDesc('orders.id')->paginate(20, ['*'], 'orders_page')->withQueryString();
        $detailBranches = $canOpenOrders ? $actor->availableBranches()->pluck('id')->all() : [];
        foreach ($page as $order) {
            $order->setAttribute('history_can_open', $canOpenOrders && ($superAdmin || in_array($order->branch_id, $detailBranches, true)));
            $order->setAttribute('history_customer_name', $canCustomer && $order->history_can_open ? $order->customer?->name : null);
        }

        $financial = $selfView || $actor->can('captain_financials.view');

        return ['orders' => $page, 'filters' => $filters, 'financial' => $financial,
            'methods' => DB::table('invoice_collections as c')->join('invoices as i', 'i.id', '=', 'c.invoice_id')
                ->whereIn('c.order_id', $page->getCollection()->modelKeys())
                ->selectRaw('DISTINCT ON (c.order_id) c.order_id, c.collection_method')
                ->orderBy('c.order_id')->orderByDesc('i.version')->orderByDesc('c.id')->get()->pluck('collection_method', 'order_id'),
            'fees' => $financial ? $this->fees($captain, $page) : collect()];
    }

    /** The last authoritative pricing allocation for this participant, never an inferred balance or payout. */
    private function fees(Captain $captain, LengthAwarePaginator $page): Collection
    {
        return DB::table('order_delivery_allocations as a')->join('order_delivery_pricings as p', 'p.id', '=', 'a.order_delivery_pricing_id')
            ->join('currencies as c', 'c.id', '=', 'a.currency_id')
            ->where('a.captain_id', $captain->id)->whereIn('p.order_id', $page->getCollection()->modelKeys())
            ->selectRaw('DISTINCT ON (p.order_id) p.order_id, a.captain_entitlement::text AS amount, c.code AS currency_code')
            ->orderBy('p.order_id')->orderByDesc('p.version')->orderByDesc('a.id')->get()->keyBy('order_id');
    }
}
