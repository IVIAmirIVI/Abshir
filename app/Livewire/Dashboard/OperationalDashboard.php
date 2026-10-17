<?php

namespace App\Livewire\Dashboard;

use App\Services\OperationalDashboardQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class OperationalDashboard extends Component
{
    #[Url(as: 'period', history: true, except: '7days')]
    public string $period = '7days';

    #[Url(as: 'branch_id', history: true, except: '')]
    public string $branchId = '';

    public function boot(): void
    {
        abort_unless(auth()->check(), 403);
        abort_unless(app(OperationalDashboardQuery::class)->canView(auth()->user()), 403);
    }

    public function updatedPeriod(): void
    {
        $this->resetValidation('period');
    }

    public function render(): View
    {
        try {
            $snapshot = app(OperationalDashboardQuery::class)->snapshot(auth()->user(), $this->period, $this->branchId);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $snapshot = null;
        }

        return view('livewire.dashboard.operational-dashboard', [
            'snapshot' => $snapshot,
            'branches' => auth()->user()->availableBranches()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
