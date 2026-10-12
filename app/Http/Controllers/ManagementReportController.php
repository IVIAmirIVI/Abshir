<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Services\ManagementReportCsv;
use App\Services\ManagementReportDetailQuery;
use App\Services\ManagementReportQuery;
use App\Services\OperationalDashboardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementReportController extends Controller
{
    public function __invoke(Request $request, ManagementReportQuery $reports): View
    {
        abort_unless($reports->canView($request->user()), 403);

        return view('reports.index');
    }

    public function detail(Request $request, string $type, ManagementReportDetailQuery $details): View
    {
        $report = $details->report($request->user(), $type, $this->filters($request));

        return view('reports.detail', ['report' => $report, 'rows' => $report['query']?->paginate(25)->appends($report['filters']),
            'details' => $details, 'branches' => $request->user()->availableBranches()->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::orderBy('code')->get(['id', 'code'])]);
    }

    public function export(Request $request, string $type, ManagementReportDetailQuery $details, ManagementReportCsv $csv): StreamedResponse
    {
        return $csv->download($details->report($request->user(), $type, $this->filters($request)));
    }

    private function filters(Request $request): array
    {
        $today = CarbonImmutable::now(OperationalDashboardQuery::TIMEZONE);

        return ['from' => $request->query('from', $today->subDays(29)->format('Y-m-d')),
            'to' => $request->query('to', $today->format('Y-m-d')),
            'branch_id' => $request->query('branch_id', ''), 'currency_id' => $request->query('currency_id', '')];
    }
}
