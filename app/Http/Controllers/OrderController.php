<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderRequest;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $r): View
    {
        Gate::authorize('orders.view');
        $q = trim($r->string('search')->toString());
        $term = '%'.mb_strtolower($q).'%';
        $orders = Order::query()->accessibleTo($r->user())->with('branch')->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $x) => $x->whereRaw('LOWER(order_number) LIKE ?', [$term])->orWhereRaw('LOWER(customer_name_snapshot) LIKE ?', [$term])->orWhere('customer_phone_snapshot', 'like', "%$q%")))->when($r->filled('branch_id'), fn (Builder $b) => $b->where('branch_id', $r->integer('branch_id')))->when($r->filled('operational_status'), fn (Builder $b) => $b->where('operational_status', $r->input('operational_status')))->when($r->filled('pricing_status'), fn (Builder $b) => $b->where('pricing_status', $r->input('pricing_status')))->when($r->filled('from'), fn (Builder $b) => $b->whereDate('ordered_at', '>=', $r->date('from')))->when($r->filled('to'), fn (Builder $b) => $b->whereDate('ordered_at', '<=', $r->date('to')))->latest('ordered_at')->paginate(25)->withQueryString();

        return view('orders.index', ['orders' => $orders, 'branches' => $r->user()->availableBranches()->orderBy('name')->get()]);
    }

    public function create(Request $r): View
    {
        Gate::authorize('orders.create');

        return view('orders.create', [
            'branches' => $r->user()->availableBranches()->where('is_active', true)->orderBy('name')->get(),
            'customers' => Customer::query()->accessibleTo($r->user())->where('is_active', true)->with([
                'phones',
                'addresses' => fn (Builder $q) => $q->where('is_active', true)->whereHas('area', fn (Builder $areas) => $areas->where('is_active', true)),
                'addresses.area.branch',
            ])->orderBy('name')->get(),
        ]);
    }

    public function store(OrderRequest $r, OrderService $s): RedirectResponse
    {
        $o = $s->create($r->user(), $r->validated());

        return redirect()->route('orders.show', $o)->with('status', 'Order created. Add Shops and Items.');
    }

    public function show(Request $r, Order $order, OrderService $s): View
    {
        $s->assertAccess($r->user(), $order);
        $order->load(['branch', 'shops.shop', 'shops.items.estimatedSourceShop', 'shops.items.estimatedCurrency', 'shops.items.actualCurrency', 'shops.items.changes.changedBy', 'captainAssignments.captain.user', 'statusHistory.changedBy']);
        $captains = Captain::query()->accessibleTo($r->user())->with(['user', 'employmentPeriods', 'branchAssignments'])->where('operational_status', 'AVAILABLE')->whereHas('user', fn (Builder $q) => $q->where('is_active', true))->whereHas('employmentPeriods', fn (Builder $q) => $q->whereNull('ended_at'))->get()->filter(fn (Captain $c) => $c->activeOperationalBranches()->contains('id', $order->branch_id));

        $shops = Shop::query()->accessibleTo($r->user())->where('is_active', true)
            ->where(function (Builder $query) use ($r, $order): void {
                $query->whereHas('area', fn (Builder $areas) => $areas->where('branch_id', $order->branch_id));
                if ($r->user()->isSuperAdmin()) {
                    $query->orWhereNull('area_id');
                }
            })->orderBy('name')->get();

        return view('orders.show', ['order' => $order, 'shops' => $shops, 'products' => Product::where('is_active', true)->orderBy('name')->get(), 'units' => Unit::where('is_active', true)->orderBy('name')->get(), 'currencies' => Currency::where('is_active', true)->orderBy('code')->get(), 'captains' => $captains]);
    }
}
