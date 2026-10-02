<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PriceEstimationSettingRequest;
use App\Services\PriceEstimationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PriceEstimationSettingController extends Controller
{
    public function edit(PriceEstimationSettings $settings): View
    {
        Gate::authorize('system_settings.view');

        return view('admin.pricing-settings.edit', ['windowDays' => $settings->windowDays()]);
    }

    public function update(PriceEstimationSettingRequest $request, PriceEstimationSettings $settings): RedirectResponse
    {
        $settings->updateWindowDays($request->user(), $request->integer('estimation_window_days'));

        return back()->with('status', __('admin.ui.price_estimation_window_updated'));
    }
}
