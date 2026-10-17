<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\ShopCommissionAccountingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ShopCommissionAccountController extends Controller
{
    public function show(Request $request, Shop $shop, ShopCommissionAccountingService $service): View
    {
        $service->assertAccountAccess($request->user(), $shop);
        $installed = Schema::hasTable('shop_commission_accounts');
        $accounts = $installed ? DB::table('shop_commission_accounts as a')->join('currencies as c', 'c.id', '=', 'a.currency_id')
            ->join('branches as b', 'b.id', '=', 'a.branch_id')->where('a.shop_id', $shop->id)
            ->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->whereIn('a.branch_id', $request->user()->availableBranches()->select('branches.id')))
            ->orderBy('a.branch_id')->orderBy('a.currency_id')->get(['a.*', 'c.code', 'b.name as branch_name']) : collect();
        foreach ($accounts as $account) {
            $account->totals = $service->balance($account->id);
        }
        $history = $installed ? DB::table('shop_commission_transactions as t')->join('users as u', 'u.id', '=', 't.recorded_by')
            ->join('shop_commission_accounts as a', 'a.id', '=', 't.account_id')->join('currencies as cur', 'cur.id', '=', 'a.currency_id')
            ->leftJoin('shop_commission_transactions as original', 'original.id', '=', 't.reversal_of')
            ->leftJoin('shop_commission_lines as l', fn ($join) => $join->on('l.id', '=', DB::raw('COALESCE(t.line_id,original.line_id)')))
            ->leftJoin('shop_commission_invoice_snapshots as snap', 'snap.id', '=', 'l.snapshot_id')->whereIn('t.account_id', $accounts->pluck('id'))
            ->orderByDesc('t.id')->select(['t.*', 'u.name as actor_name', 'cur.code', 'snap.order_id', 'snap.invoice_id', 'snap.invoice_reference'])
            ->paginate(25)->withQueryString() : null;

        return view('shops.commission-account', compact('shop', 'installed', 'accounts', 'history'));
    }

    public function payment(Request $request, Shop $shop, int $account, ShopCommissionAccountingService $service): RedirectResponse
    {
        $service->assertAccountAccess($request->user(), $shop);
        $data = $request->validate([
            'amount' => ['required', 'string'], 'payment_method' => ['required', 'in:CASH,TRANSFER'],
            'request_key' => ['required', 'uuid'], 'occurred_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:255'],
        ]);
        $service->recordPayment($request->user(), $shop, $account, $data);

        return redirect()->route('shops.commission-account', $shop)->with('status', __('commissions.payment_saved'));
    }
}
