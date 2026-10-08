<?php

namespace App\Http\Controllers;

use App\Models\Captain;
use App\Services\CaptainInformationQuery;
use App\Support\CaptainSelfView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CaptainInformationController extends Controller
{
    public function __invoke(Request $request, Captain $captain, CaptainInformationQuery $query): View
    {
        abort_unless($query->canView($request->user()) && $request->user()->canAccessCaptain($captain), 403);
        $filters = $this->filters($request);
        $snapshot = $query->snapshot($request->user(), $captain, $filters['period'] ?? '7days',
            isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);
        $captain->load('user:id,name');

        return view('captains.information', compact('captain', 'snapshot'));
    }

    public function self(Request $request, CaptainInformationQuery $query): View
    {
        Gate::forUser($request->user())->authorize(CaptainSelfView::ABILITY);
        $filters = $this->filters($request);
        $snapshot = $query->selfSnapshot($request->user(), $filters['period'] ?? '7days',
            isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);
        $captain = $snapshot['captain']->load('user:id,name');

        return view('captains.information', compact('captain', 'snapshot'));
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'period' => ['sometimes', Rule::in(['today', '7days', '30days'])],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
