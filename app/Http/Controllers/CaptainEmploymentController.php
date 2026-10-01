<?php

namespace App\Http\Controllers;

use App\Http\Requests\RehireCaptainRequest;
use App\Http\Requests\TerminateCaptainRequest;
use App\Models\Captain;
use App\Services\CaptainEmploymentService;
use Illuminate\Http\RedirectResponse;

class CaptainEmploymentController extends Controller
{
    public function terminate(TerminateCaptainRequest $request, Captain $captain, CaptainEmploymentService $service): RedirectResponse
    {
        $service->terminate($request->user(), $captain, $request->validated('ended_at'), $request->validated('reason'));

        return back()->with('status', __('captains.flash_captain_employment_terminated'));
    }

    public function rehire(RehireCaptainRequest $request, Captain $captain, CaptainEmploymentService $service): RedirectResponse
    {
        $service->rehire(
            $request->user(),
            $captain,
            $request->validated('started_at'),
            $request->boolean('reactivate_account'),
        );

        return back()->with('status', __('captains.flash_captain_rehired'));
    }
}
