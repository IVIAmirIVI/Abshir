<?php

namespace App\Http\Controllers;

use App\Enums\OrderOperationalStatus;
use App\Models\Captain;
use App\Services\CaptainInformationQuery;
use App\Services\CaptainOrderHistoryQuery;
use App\Support\CaptainSelfView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CaptainInformationController extends Controller
{
    public function __invoke(Request $request, Captain $captain, CaptainInformationQuery $query, CaptainOrderHistoryQuery $history): View
    {
        abort_unless($query->canView($request->user()) && $request->user()->canAccessCaptain($captain), 403);
        $filters = $this->filters($request);
        $snapshot = $query->snapshot($request->user(), $captain, $filters['period'] ?? '7days',
            isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);
        $captain->load('user:id,name');

        $orderHistory = $snapshot['performance'] ? $history->listing($request->user(), $captain, $filters) : null;

        return view('captains.information', compact('captain', 'snapshot', 'orderHistory'));
    }

    public function self(Request $request, CaptainInformationQuery $query, CaptainOrderHistoryQuery $history): View
    {
        Gate::forUser($request->user())->authorize(CaptainSelfView::ABILITY);
        $filters = $this->filters($request);
        $snapshot = $query->selfSnapshot($request->user(), $filters['period'] ?? '7days',
            isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);
        $captain = $snapshot['captain']->load('user:id,name');

        $orderHistory = $history->listing($request->user(), $captain, $filters, true);

        return view('captains.information', compact('captain', 'snapshot', 'orderHistory'));
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'period' => ['sometimes', Rule::in(['today', '7days', '30days'])],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'orders_from' => ['nullable', 'date_format:Y-m-d'],
            'orders_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('orders_from'), 'after_or_equal:orders_from')],
            'orders_status' => ['nullable', Rule::enum(OrderOperationalStatus::class)],
            'orders_kind' => ['nullable', Rule::in(['single', 'shared'])],
            'orders_role' => ['nullable', Rule::in(['collector', 'support', 'former'])],
        ]);
    }
}
