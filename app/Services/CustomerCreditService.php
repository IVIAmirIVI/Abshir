<?php

namespace App\Services;

use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditEntry;
use App\Models\CustomerReturnCase;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerCreditNotice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerCreditService
{
    public function __construct(private readonly FinancialPostingService $money) {}

    public function authorize(User $actor, Customer $customer, bool $write = false): void
    {
        Gate::forUser($actor)->authorize('customers.view');
        Gate::forUser($actor)->authorize($write ? 'customer_credits.grant' : 'customer_credits.view');
        abort_unless($actor->canAccessCustomer($customer), 403);
        if (! Schema::hasTable('customer_credit_entries')) {
            throw ValidationException::withMessages(['credit' => __('credit.activation')]);
        }
    }

    public function grant(User $actor, Customer $customer, array $data): CustomerCreditEntry
    {
        return $this->write($actor, $customer, $data);
    }

    public function fromReturn(User $actor, CustomerReturnCase $case): CustomerCreditEntry
    {
        $order = Order::findOrFail($case->order_id);

        return $this->write($actor, Customer::findOrFail($order->customer_id), [
            'request_key' => $case->request_key, 'branch_id' => $case->branch_id, 'currency_id' => $case->currency_id,
            'source_order_id' => $order->id, 'reason' => 'RETURN', 'notes' => $case->notes,
            'reference' => 'RET-'.$case->id, 'amount' => $case->amount, 'occurred_at' => $case->occurred_at->format('Y-m-d H:i:s.uP'),
        ], $case);
    }

    private function write(User $actor, Customer $customer, array $data, ?CustomerReturnCase $case = null): CustomerCreditEntry
    {
        $this->authorize($actor, $customer, true);
        $data = Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'branch_id' => ['required', 'integer'], 'currency_id' => ['required', 'integer'],
            'source_order_id' => ['nullable', 'integer'], 'reason' => ['required', $case ? 'in:RETURN' : 'in:OVERCHARGE,ERROR'],
            'notes' => ['required', 'string', 'max:5000'], 'reference' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'string'], 'occurred_at' => ['required', 'date'],
        ])->validate();
        try {
            return DB::transaction(function () use ($actor, $customer, $data, $case): CustomerCreditEntry {
                $order = filled($data['source_order_id'] ?? null) ? Order::query()->lockForUpdate()->findOrFail($data['source_order_id']) : null;
                if ($order) {
                    Gate::forUser($actor)->authorize('orders.view');
                    abort_unless(Order::accessibleTo($actor)->whereKey($order)->exists(), 403);
                }
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                $this->authorize($actor, $customer, true);
                $branch = Branch::findOrFail($data['branch_id']);
                abort_unless($actor->canAccessBranch($branch), 403);
                abort_unless(DB::scalar('SELECT customer_credit_customer_visible(?,?,?)', [$customer->id, $branch->id, $actor->id]), 403);
                $currency = Currency::findOrFail($data['currency_id']);
                $amount = $this->money->validatePlannedAmount($currency, $data['amount']);
                $occurred = CarbonImmutable::parse($data['occurred_at'], 'Asia/Damascus');
                if ($occurred->isFuture()) {
                    $this->invalid();
                }
                $facts = ['kind' => 'GRANT', 'customer_id' => $customer->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                    'source_order_id' => $order?->id, 'source_return_case_id' => $case?->id, 'reason' => $data['reason'],
                    'amount' => $amount, 'reference' => trim($data['reference']), 'notes' => trim($data['notes']),
                    'occurred_at' => $occurred->utc()->format('Y-m-d H:i:s.uP'), 'recorded_by' => $actor->id];
                $hash = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
                if ($existing = CustomerCreditEntry::where('request_key', $data['request_key'])->first()) {
                    if (! hash_equals($existing->payload_hash, $hash)) {
                        $this->invalid();
                    }

                    return $existing;
                }
                $entry = CustomerCreditEntry::create($facts + ['request_key' => $data['request_key'], 'payload_hash' => $hash]);
                $recipients = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'SUPER_ADMIN'))->get()
                    ->filter(fn ($u) => $u->can('customer_credits.view') && $u->canAccessBranch($branch));
                Notification::sendNow($recipients, new CustomerCreditNotice([
                    'customer_id' => $customer->id, 'branch_id' => $branch->id, 'order_id' => $order?->id,
                    'request_key' => $data['request_key'], 'title' => __('credit.notice'),
                    'body' => __('credit.granted').' · '.$amount.' '.$currency->code.' · '.$facts['notes'].' · '.$facts['reference'].' · #'.$actor->id,
                ]), ['database']);

                return $entry;
            });
        } catch (FinancialLedgerException) {
            $this->invalid();
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0] ?? '', ['23514', '23505', '23503', 'P0002'], true)) {
                $this->invalid();
            }
            throw $e;
        }
    }

    public function balances(User $actor, Customer $customer, ?int $branchId = null): Collection
    {
        $this->authorize($actor, $customer);

        $spent = Schema::hasTable('customer_credit_redemptions') ? 'COALESCE((SELECT sum(d.amount) FROM customer_credit_redemptions d WHERE d.grant_id=e.id),0)' : '0';
        if (Schema::hasTable('customer_credit_cash_payouts')) {
            $spent = 'customer_credit_grant_spent(e.id)';
        }

        return $this->visible($actor, $customer, $branchId)->join('branches as b', 'b.id', '=', 'e.branch_id')->join('currencies as c', 'c.id', '=', 'e.currency_id')
            ->groupBy('e.branch_id', 'e.currency_id', 'b.name', 'c.code')->select('e.branch_id', 'e.currency_id', 'b.name as branch_name', 'c.code as currency_code')
            ->selectRaw('sum(e.amount-('.$spent.')) as available')->orderBy('e.branch_id')->orderBy('e.currency_id')->get();
    }

    public function redeemDelivery(User $actor, Order $order): never
    {
        Gate::forUser($actor)->authorize('customer_credits.redeem');
        Gate::forUser($actor)->authorize('orders.view');
        abort_unless(Order::accessibleTo($actor)->whereKey($order)->exists(), 403);
        $this->authorize($actor, Customer::findOrFail($order->customer_id));
        throw ValidationException::withMessages(['credit' => __('credit.redemption_blocked')]);
    }

    public function cashPayout(User $actor, Customer $customer): never
    {
        Gate::forUser($actor)->authorize('customer_credits.payout');
        $this->authorize($actor, $customer);
        throw ValidationException::withMessages(['credit' => __('credit.payout_blocked')]);
    }

    public function history(User $actor, Customer $customer, ?int $branchId = null): Collection
    {
        $this->authorize($actor, $customer);

        $grants = $this->visible($actor, $customer, $branchId)->join('branches as b', 'b.id', '=', 'e.branch_id')->join('currencies as c', 'c.id', '=', 'e.currency_id')
            ->select('e.*', 'b.name as branch_name', 'c.code as currency_code')->orderByDesc('e.id')->limit(30)->get();
        if (! Schema::hasTable('customer_credit_settlements')) {
            return $grants;
        }
        $uses = DB::table('customer_credit_settlements as s')->join('branches as b', 'b.id', '=', 's.branch_id')->join('currencies as c', 'c.id', '=', 's.currency_id')
            ->where('s.customer_id', $customer->id)->whereIn('s.branch_id', $actor->availableBranches()->select('branches.id'))
            ->when($branchId !== null, fn ($query) => $query->where('s.branch_id', $branchId))
            ->select('s.credit_amount as amount', 's.order_id as source_order_id', 's.recorded_by', 's.collected_at as occurred_at',
                's.recorded_at', 's.reason as notes', 's.request_key as reference', 'b.name as branch_name', 'c.code as currency_code')
            ->selectRaw("'DELIVERY_REDEMPTION' AS reason")->orderByDesc('s.id')->limit(30)->get();

        $payouts = Schema::hasTable('customer_credit_cash_payouts') ? DB::table('customer_credit_cash_payouts as p')
            ->join('branches as b', 'b.id', '=', 'p.branch_id')->join('currencies as c', 'c.id', '=', 'p.currency_id')
            ->where('p.customer_id', $customer->id)->whereIn('p.branch_id', $actor->availableBranches()->select('branches.id'))
            ->when($branchId !== null, fn ($query) => $query->where('p.branch_id', $branchId))
            ->select('p.amount', 'p.recorded_by', 'p.occurred_at', 'p.recorded_at', 'p.reason as notes', 'p.reference', 'b.name as branch_name', 'c.code as currency_code', 'p.id as cash_payout_id')
            ->selectRaw("NULL AS source_order_id, 'CASH_PAYOUT' AS reason")->orderByDesc('p.id')->limit(30)->get() : collect();

        return $grants->concat($uses)->concat($payouts)->sortByDesc('recorded_at')->take(30)->values();
    }

    private function visible(User $actor, Customer $customer, ?int $branchId): Builder
    {
        return DB::table('customer_credit_entries as e')->where('e.customer_id', $customer->id)
            ->whereIn('e.branch_id', $actor->availableBranches()->select('branches.id'))
            ->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId));
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['credit' => __('credit.invalid')]);
    }
}
