<?php

namespace App\Http\Controllers;

use App\Enums\CaptainOperationalStatus;
use App\Http\Requests\CaptainStatusRequest;
use App\Models\Captain;
use App\Services\CaptainStatusService;
use Illuminate\Http\RedirectResponse;

class CaptainStatusController extends Controller
{
    public function __invoke(CaptainStatusRequest $request, Captain $captain, CaptainStatusService $service): RedirectResponse
    {
        $service->update(
            $request->user(),
            $captain,
            CaptainOperationalStatus::from($request->validated('operational_status')),
            $request->validated('reason'),
        );

        return back()->with('status', __('captains.flash_captain_operational_status_updated'));
    }
}
