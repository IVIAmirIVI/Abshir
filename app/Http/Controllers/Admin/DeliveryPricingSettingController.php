<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeliveryPricingSettingsRequest;
use App\Http\Requests\DeliveryUserLimitRequest;
use App\Models\DeliveryPricingUserLimit;
use App\Models\User;
use App\Services\DeliveryPricingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeliveryPricingSettingController extends Controller
{
    public function edit(DeliveryPricingSettings $settings): View
    {
        Gate::authorize('system_settings.view');

        return view('admin.delivery-pricing-settings.edit', ['share' => $settings->captainSharePercent(), 'threshold' => $settings->overrideThresholdPercent(), 'limits' => DeliveryPricingUserLimit::with('user')->get(), 'employees' => User::whereHas('roles', fn ($q) => $q->where('code', 'ORDER_EMPLOYEE'))->orderBy('name')->get()]);
    }

    public function update(DeliveryPricingSettingsRequest $request, DeliveryPricingSettings $settings): RedirectResponse
    {
        $settings->update($request->user(), $request->input('captain_share_percent'), $request->input('override_threshold_percent'));

        return back()->with('status', __('admin.ui.delivery_pricing_settings_updated'));
    }

    public function limit(DeliveryUserLimitRequest $request): RedirectResponse
    {
        DeliveryPricingUserLimit::query()->updateOrCreate(['user_id' => $request->integer('user_id')], ['max_discount_percent' => $request->input('max_discount_percent'), 'updated_by' => $request->user()->id]);

        return back()->with('status', __('admin.ui.employee_discount_limit_updated'));
    }
}
