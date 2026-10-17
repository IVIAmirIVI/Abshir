<?php

namespace App\Livewire\Reports;

use App\Models\Currency;
use App\Services\ManagementReportQuery;
use App\Services\OperationalDashboardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ManagementReports extends Component
{
    use WithPagination;

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Url(as: 'branch_id', except: '')]
    public string $branchId = '';

    #[Url(as: 'currency_id', except: '')]
    public string $currencyId = '';

    public function boot(): void
    {
        abort_unless(auth()->check() && app(ManagementReportQuery::class)->canView(auth()->user()), 403);
    }

    public function mount(): void
    {
        $today = CarbonImmutable::now(OperationalDashboardQuery::TIMEZONE);
        $this->from = $this->from ?: $today->subDays(29)->format('Y-m-d');
        $this->to = $this->to ?: $today->format('Y-m-d');
    }

    public function applyFilters(): void
    {
        $this->resetValidation();
        $this->resetPage('captainsPage');
        $this->resetPage('shopsPage');
    }

    public function render(): View
    {
        try {
            $report = app(ManagementReportQuery::class)->snapshot(auth()->user(), ['from' => $this->from, 'to' => $this->to,
                'branch_id' => $this->branchId, 'currency_id' => $this->currencyId]);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $report = null;
        }

        return view('livewire.reports.management-reports', ['report' => $report,
            'branches' => auth()->user()->availableBranches()->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::orderBy('code')->get(['id', 'code'])]);
    }
}
