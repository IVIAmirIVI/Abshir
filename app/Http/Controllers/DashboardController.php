<?php

namespace App\Http\Controllers;

use App\Services\OperationalDashboardQuery;
use App\Support\CaptainSelfView;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OperationalDashboardQuery $dashboard): View
    {
        return view('dashboard', ['showOperationalDashboard' => $dashboard->canView($request->user()),
            'showCaptainPortal' => app(CaptainSelfView::class)->resolve($request->user()) !== null]);
    }
}
