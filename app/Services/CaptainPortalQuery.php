<?php

namespace App\Services;

use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\User;
use App\Support\CaptainSelfView;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CaptainPortalQuery
{
    public function identity(User $actor): Captain
    {
        Gate::forUser($actor)->authorize(CaptainSelfView::ABILITY);
        $captain = app(CaptainSelfView::class)->resolve($actor);
        abort_unless($captain !== null, 403);

        return $captain;
    }

    public function listing(User $actor, array $input = []): array
    {
        $captain = $this->identity($actor);
        $filters = Validator::make($input, [
            'view' => ['nullable', Rule::in(['active', 'history'])], 'branch_id' => ['nullable', 'integer', 'min:1'],
            'orders_from' => ['nullable', 'date_format:Y-m-d'],
            'orders_to' => ['nullable', 'date_format:Y-m-d', Rule::when(filled($input['orders_from'] ?? null), 'after_or_equal:orders_from')],
            'orders_status' => ['nullable', Rule::enum(OrderOperationalStatus::class)],
            'orders_kind' => ['nullable', Rule::in(['single', 'shared'])],
            'orders_role' => ['nullable', Rule::in(['collector', 'support', 'former'])],
        ])->validate();
        $filters['view'] = $filters['view'] ?? 'active';
        $branches = Branch::query()->where(fn ($q) => $q->where('id', $captain->home_branch_id)
            ->orWhereIn('id', DB::table('orders')->whereExists(fn ($a) => $a->selectRaw('1')->from('order_captains')
                ->whereColumn('order_captains.order_id', 'orders.id')->where('captain_id', $captain->id))->select('branch_id')))
            ->orderBy('name')->get(['id', 'name']);
        abort_if(filled($filters['branch_id'] ?? null) && ! $branches->contains('id', (int) $filters['branch_id']), 403);
        $activeBranches = $captain->activeOperationalBranches()->where('is_active', true)->modelKeys();
        $active = $this->orders($captain)->whereIn('orders.branch_id', $activeBranches)
            ->when(! $captain->isEmployed(), fn ($q) => $q->whereRaw('1 = 0'))
            ->whereNotIn('orders.operational_status', ['DELIVERED', 'CANCELLED'])->whereExists(fn ($a) => $a->selectRaw('1')->from('order_captains')
            ->whereColumn('order_captains.order_id', 'orders.id')->where('captain_id', $captain->id)->whereNull('removed_at'));
        $metrics = (clone $active)->selectRaw('orders.operational_status, COUNT(*) AS total')->groupBy('orders.operational_status')->pluck('total', 'operational_status');
        if ($filters['view'] === 'history') {
            // Existing historical visibility intentionally survives transfers and ended assignments.
            $history = app(CaptainOrderHistoryQuery::class)->listing($actor, $captain, $filters, true);
            $page = $history['orders'];
            $page->setCollection($page->getCollection()->map(function ($order) {
                $assignment = $order->captainAssignments->first();

                return (object) ['id' => $order->id, 'order_number' => $order->order_number,
                    'ordered_at' => $order->ordered_at->toIso8601String(), 'operational_status' => $order->operational_status->value,
                    'branch_id' => $order->branch_id, 'history_shared' => $order->history_shared,
                    'assignment_role' => $assignment?->assignment_role->value, 'removed_at' => $assignment?->removed_at?->toIso8601String()];
            }));
        } else {
            $q = $this->filter($active, $filters, $captain);
            $page = $this->projection($q, $captain)->orderByDesc('orders.ordered_at')->orderByDesc('orders.id')
                ->paginate(20, ['*'], 'orders_page')->withQueryString();
        }

        return compact('captain', 'filters', 'branches', 'metrics', 'page');
    }

    private function orders(Captain $captain): Builder
    {
        return DB::table('orders')->whereExists(fn ($a) => $a->selectRaw('1')->from('order_captains')
            ->whereColumn('order_captains.order_id', 'orders.id')->where('captain_id', $captain->id));
    }

    private function projection(Builder $q, Captain $captain): Builder
    {
        return $q->select('orders.id', 'orders.order_number', 'orders.ordered_at', 'orders.operational_status', 'orders.branch_id')
            ->selectRaw(CaptainOrderHistoryQuery::sharedParticipationSql().' AS history_shared')
            ->selectSub(DB::table('order_captains')->whereColumn('order_id', 'orders.id')->where('captain_id', $captain->id)
                ->orderByDesc('assigned_at')->orderByDesc('id')->limit(1)->select('assignment_role'), 'assignment_role')
            ->selectSub(DB::table('order_captains')->whereColumn('order_id', 'orders.id')->where('captain_id', $captain->id)
                ->orderByDesc('assigned_at')->orderByDesc('id')->limit(1)->select('removed_at'), 'removed_at');
    }

    private function filter(Builder $q, array $filters, Captain $captain): Builder
    {
        $q->when(filled($filters['branch_id'] ?? null), fn ($q) => $q->where('orders.branch_id', $filters['branch_id']))
            ->when(filled($filters['orders_status'] ?? null), fn ($q) => $q->where('orders.operational_status', $filters['orders_status']));
        foreach (['orders_from' => '>=', 'orders_to' => '<'] as $key => $operator) {
            if (filled($filters[$key] ?? null)) {
                $date = CarbonImmutable::parse($filters[$key], OperationalDashboardQuery::TIMEZONE)->startOfDay();
                $q->where('orders.ordered_at', $operator, ($key === 'orders_to' ? $date->addDay() : $date)->utc()->toIso8601String());
            }
        }
        if (filled($filters['orders_kind'] ?? null)) {
            $q->whereRaw(($filters['orders_kind'] === 'single' ? 'NOT ' : '').CaptainOrderHistoryQuery::sharedParticipationSql());
        }
        if (filled($filters['orders_role'] ?? null)) {
            $q->whereExists(function ($a) use ($filters, $captain): void {
                $a->selectRaw('1')->from('order_captains')->whereColumn('order_captains.order_id', 'orders.id')
                    ->whereNull('removed_at')->where('captain_id', $captain->id);
                if ($filters['orders_role'] === 'former') {
                    $a->whereRaw('1 = 0');
                } else {
                    $a->where('assignment_role', $filters['orders_role'] === 'collector' ? 'PRIMARY_COLLECTION' : 'SUPPORT');
                }
            });
        }

        return $q;
    }

    public function detail(User $actor, int $id): array
    {
        $captain = $this->identity($actor);
        $order = $this->projection($this->orders($captain)->where('orders.id', $id), $captain)->first();
        abort_unless($order !== null, 404);
        $current = DB::table('order_captains')->where('order_id', $id)->where('captain_id', $captain->id)->whereNull('removed_at')->first(['id', 'assignment_role']);
        $work = $current !== null && $captain->isEmployed() && ! in_array($order->operational_status, ['DELIVERED', 'CANCELLED'], true)
            && $captain->activeOperationalBranches()->where('is_active', true)->contains('id', $order->branch_id);
        $branch = Branch::find($order->branch_id, ['id', 'name']);
        $assignment = DB::table('order_captains')->where('order_id', $id)->where('captain_id', $captain->id)
            ->orderByDesc('assigned_at')->orderByDesc('id')->first(['assigned_at', 'removed_at']);
        $history = DB::table('order_status_history')->where('order_id', $id)
            ->when(! $work, fn ($q) => $q->where('changed_at', '>=', $assignment->assigned_at)
                ->when($assignment->removed_at !== null, fn ($q) => $q->where('changed_at', '<=', $assignment->removed_at)))
            ->orderByDesc('changed_at')->orderByDesc('id')->limit(20)->get(['new_status', 'changed_at']);
        $contact = null;
        $shops = collect();
        $invoice = null;
        if ($work) {
            $contact = DB::table('orders')->where('id', $id)->first(['customer_name_snapshot', 'customer_phone_snapshot', 'recipient_name_snapshot',
                'area_name_snapshot', 'address_line_1_snapshot', 'address_line_2_snapshot', 'landmark_snapshot']);
            $shops = DB::table('order_shops')->where('order_id', $id)->orderBy('sort_order')->orderBy('id')
                ->select(['id', 'shop_name_snapshot', 'shop_phone_snapshot', 'shop_address_snapshot', 'area_name_snapshot', 'contacted_at', 'confirmed_at', 'ready_at', 'picked_up_at'])
                ->selectSub(DB::table('order_shop_receipts')->whereColumn('order_shop_id', 'order_shops.id')->selectRaw('COUNT(*)'), 'receipt_count')
                ->selectSub(DB::table('order_shop_purchase_confirmations')->whereColumn('order_shop_id', 'order_shops.id')->select('purchased_at')->limit(1), 'purchased_at')->get();
            $items = DB::table('order_items')->whereIn('order_shop_id', $shops->pluck('id'))->orderBy('id')
                ->get(['order_shop_id', 'product_name_snapshot', 'unit_name_snapshot', 'current_quantity'])->groupBy('order_shop_id');
            foreach ($shops as $shop) {
                $shop->items = $items->get($shop->id, collect());
            }
            if ($current->assignment_role === 'PRIMARY_COLLECTION') {
                $invoice = DB::table('invoices as i')->join('currencies as c', 'c.id', '=', 'i.currency_id')
                    ->where('i.order_id', $id)->whereNull('i.superseded_at')->where('i.status', 'ISSUED')
                    ->select('i.id', 'i.grand_total', 'c.code as currency')
                    ->selectSub(DB::table('invoice_collections')->whereColumn('invoice_id', 'i.id')->select('collection_method')->limit(1), 'collection_method')->first();
            }
        }

        return compact('captain', 'order', 'branch', 'history', 'work', 'contact', 'shops', 'invoice')
            + ['collector' => $current?->assignment_role === 'PRIMARY_COLLECTION'];
    }
}
