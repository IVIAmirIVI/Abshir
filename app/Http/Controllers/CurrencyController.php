<?php

namespace App\Http\Controllers;

use App\Http\Requests\CurrencyRequest;
use App\Models\Currency;
use App\Services\CurrencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CurrencyController extends Controller
{
    public function index(): View
    {
        Gate::authorize('currencies.view');

        return view('currencies.index', ['currencies' => Currency::query()->orderBy('code')->paginate(30)]);
    }

    public function create(): View
    {
        Gate::authorize('currencies.create');

        return view('currencies.form', ['currency' => new Currency]);
    }

    public function store(CurrencyRequest $request, CurrencyService $service): RedirectResponse
    {
        $service->save($request->user(), new Currency, $request->validated());

        return redirect()->route('currencies.index')->with('status', __('catalog.flash_currency_created'));
    }

    public function edit(Currency $currency): View
    {
        Gate::authorize('currencies.update');

        return view('currencies.form', compact('currency'));
    }

    public function update(CurrencyRequest $request, Currency $currency, CurrencyService $service): RedirectResponse
    {
        $service->save($request->user(), $currency, $request->validated());

        return redirect()->route('currencies.index')->with('status', __('catalog.flash_currency_updated'));
    }

    public function status(Request $request, Currency $currency, CurrencyService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $currency, (bool) $data['is_active']);

        return back()->with('status', __('catalog.flash_currency_status_updated'));
    }
}
