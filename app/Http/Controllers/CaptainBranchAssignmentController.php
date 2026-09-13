<?php

namespace App\Http\Controllers;

use App\Http\Requests\PermanentTransferRequest;
use App\Http\Requests\TemporaryAssignmentRequest;
use App\Models\Captain;
use App\Models\CaptainBranchAssignment;
use App\Services\CaptainBranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CaptainBranchAssignmentController extends Controller
{
    public function create(Request $request, Captain $captain): View
    {
        Gate::authorize('captain_branches.assign');
        $this->authorizeCaptain($request, $captain);

        return view('captains.assignments.form', [
            'captain' => $captain,
            'branches' => $request->user()->availableBranches()->where('is_active', true)->whereKeyNot($captain->home_branch_id)->orderBy('name')->get(),
        ]);
    }

    public function store(TemporaryAssignmentRequest $request, Captain $captain, CaptainBranchService $service): RedirectResponse
    {
        $service->assignTemporary($request->user(), $captain, $request->validated());

        return redirect()->route('captains.show', $captain)->with('status', 'Temporary assignment created.');
    }

    public function end(Request $request, Captain $captain, CaptainBranchAssignment $assignment, CaptainBranchService $service): RedirectResponse
    {
        $service->endTemporary($request->user(), $captain, $assignment);

        return back()->with('status', 'Temporary assignment ended; its history was preserved.');
    }

    public function transferForm(Request $request, Captain $captain): View
    {
        Gate::authorize('captain_branches.transfer');
        $this->authorizeCaptain($request, $captain);

        return view('captains.assignments.transfer', [
            'captain' => $captain,
            'branches' => $request->user()->availableBranches()->where('is_active', true)->whereKeyNot($captain->home_branch_id)->orderBy('name')->get(),
        ]);
    }

    public function transfer(PermanentTransferRequest $request, Captain $captain, CaptainBranchService $service): RedirectResponse
    {
        $service->transfer($request->user(), $captain, $request->validated());

        return redirect()->route('captains.show', $captain)->with('status', 'Permanent transfer recorded and home branch updated.');
    }

    private function authorizeCaptain(Request $request, Captain $captain): void
    {
        abort_unless($request->user()->canAccessCaptain($captain), 403);
    }
}
