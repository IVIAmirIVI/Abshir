<?php

namespace App\Services;

use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerReturnCase;
use App\Models\CustomerReturnMovement;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerReturnNotice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerReturnService
{
    public function __construct(private readonly FinancialPostingService $money, private readonly CustomerRefundEvidenceService $evidence) {}

    public function authorize(User $actor, Order $order, bool $write = false): void
    {
        Gate::forUser($actor)->authorize('orders.view');
        Gate::forUser($actor)->authorize($write ? 'order_shop_returns.create' : 'order_shop_returns.view');
        if ($write) {
            Gate::forUser($actor)->authorize('invoices.view');
        }
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        if (! Schema::hasTable('customer_return_cases')) {
            throw ValidationException::withMessages(['return' => __('returns.activation')]);
        }
        if ($write && ! Schema::hasTable('customer_credit_entries')) {
            throw ValidationException::withMessages(['return' => __('credit.activation')]);
        }
        if ($write && ! Schema::hasColumn('customer_return_cases', 'compensation_contract_version') && Schema::hasTable('customer_credit_settlements') && DB::table('customer_credit_settlements')->where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['return' => __('credit.refund_blocked')]);
        }
    }

    public function record(User $actor, Order $source, array $data): CustomerReturnCase
    {
        $this->authorize($actor, $source, true);
        Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'invoice_id' => ['required', 'integer'], 'currency_id' => ['required', 'integer'],
            'occurred_at' => ['required', 'date'], 'reason' => ['required', 'in:QUALITY,DAMAGE,WRONG_ITEM,OTHER'], 'notes' => ['required', 'string', 'max:5000'],
            'compensation_method' => ['sometimes', 'in:CASH,TRANSFER,CREDIT'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.kind' => ['required', 'in:PRODUCT,DELIVERY,BILL_SERVICE'],
            'lines.*.invoice_item_id' => ['nullable', 'integer'], 'lines.*.bill_payment_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^[0-9]{1,18}(\.[0-9]{1,4})?$/D'], 'lines.*.amount' => ['required', 'string'],
            'responsibilities' => ['required', 'array', 'min:1', 'max:25'], 'responsibilities.*.party' => ['required', 'in:COMPANY,SHOP,CAPTAIN'],
            'responsibilities.*.order_captain_id' => ['nullable', 'integer'], 'responsibilities.*.order_shop_id' => ['nullable', 'integer'],
            'responsibilities.*.amount' => ['required', 'string'],
        ])->validate();

        return $this->checked(fn () => DB::transaction(function () use ($actor, $source, $data): CustomerReturnCase {
            $order = Order::query()->lockForUpdate()->findOrFail($source->id);
            $this->authorize($actor, $order, true);
            if (($data['compensation_method'] ?? 'CASH') === 'CREDIT') {
                // Credit writers lock Customer before the SQL guards acquire Currency locks.
                Customer::query()->lockForUpdate()->findOrFail($order->customer_id);
            }
            $invoice = $order->invoices()->with('collection', 'currency', 'items')->findOrFail($data['invoice_id']);
            if (! $invoice->collection || (int) $data['currency_id'] !== $invoice->currency_id) {
                $this->invalid();
            }
            $currency = $invoice->currency;
            $lines = collect($data['lines'])->map(fn ($line): array => [
                'kind' => $line['kind'], 'invoice_item_id' => filled($line['invoice_item_id'] ?? null) ? (int) $line['invoice_item_id'] : null,
                'bill_payment_id' => filled($line['bill_payment_id'] ?? null) ? (int) $line['bill_payment_id'] : null,
                'quantity' => (string) BigDecimal::of($line['quantity'])->toScale(4), 'amount' => $this->amount($currency, $line['amount'], true),
            ])->sortBy(fn ($line) => $line['kind'].':'.($line['invoice_item_id'] ?? $line['bill_payment_id'] ?? 0))->values()->all();
            $parties = collect($data['responsibilities'])->map(fn ($party): array => [
                'party' => $party['party'], 'order_captain_id' => filled($party['order_captain_id'] ?? null) ? (int) $party['order_captain_id'] : null,
                'order_shop_id' => filled($party['order_shop_id'] ?? null) ? (int) $party['order_shop_id'] : null,
                'amount' => $this->amount($currency, $party['amount']),
            ])->sortBy(fn ($party) => $party['party'].':'.($party['order_captain_id'] ?? $party['order_shop_id'] ?? 0))->values()->all();
            $total = collect($lines)->reduce(fn (BigDecimal $sum, array $line) => $sum->plus($line['amount']), BigDecimal::zero());
            $products = collect($lines)->where('kind', 'PRODUCT')->keyBy('invoice_item_id');
            $fullProducts = $invoice->items->every(fn ($item) => isset($products[$item->id]) && BigDecimal::of($products[$item->id]['quantity'])->isEqualTo($item->quantity));
            $invoiceBase = collect($lines)->where('kind', '!=', 'BILL_SERVICE')->reduce(fn (BigDecimal $sum, array $line) => $sum->plus($line['amount']), BigDecimal::zero());
            $facts = ['order_id' => $order->id, 'invoice_id' => $invoice->id, 'collection_id' => $invoice->collection->id,
                'product_scope' => $products->isEmpty() ? 'NONE' : ($fullProducts ? 'FULL' : 'PARTIAL'),
                'invoice_refund_scope' => $invoiceBase->isZero() ? 'NONE' : ($invoiceBase->isEqualTo($invoice->grand_total) ? 'FULL' : 'PARTIAL'),
                'branch_id' => $order->branch_id, 'currency_id' => $currency->id, 'recorded_by' => $actor->id,
                'occurred_at' => $this->time($data['occurred_at']), 'reason' => $data['reason'], 'notes' => trim($data['notes']),
                'amount' => $this->amount($currency, (string) $total)];
            $facts['compensation_method'] = $data['compensation_method'] ?? 'CASH';
            $hash = hash('sha256', json_encode([$facts, $lines, $parties], JSON_THROW_ON_ERROR));
            if ($existing = CustomerReturnCase::where('request_key', $data['request_key'])->first()) {
                $this->same($existing->payload_hash, $hash);

                return $existing;
            }
            if (! $total->isEqualTo(collect($parties)->reduce(fn (BigDecimal $sum, array $party) => $sum->plus($party['amount']), BigDecimal::zero()))) {
                $this->invalid();
            }
            DB::select('SELECT ret_assert_no_legacy(?)', [$order->id]);
            $case = CustomerReturnCase::create($facts + ['request_key' => $data['request_key'], 'payload_hash' => $hash]);
            DB::table('customer_return_lines')->insert(array_map(fn ($line) => $line + ['case_id' => $case->id], $lines));
            $case->responsibilities()->createMany($parties);
            $case->update(['sealed_at' => now()]);
            if ($case->compensation_method === 'CREDIT') {
                app(CustomerCreditService::class)->fromReturn($actor, $case);
            }
            DB::statement('SET CONSTRAINTS ret_complete IMMEDIATE');
            DB::statement('SET CONSTRAINTS ret_complete DEFERRED');
            DB::statement('SET CONSTRAINTS credit_return_complete IMMEDIATE');
            DB::statement('SET CONSTRAINTS credit_return_complete DEFERRED');
            $this->notify($actor, $case, $data['request_key'], 'RECORDED', ['lines' => $lines, 'responsibilities' => $parties]);

            return $case->fresh();
        }));
    }

    /** An attested actual company cash/bank movement, never a Treasury or Captain ledger posting. */
    public function move(User $actor, CustomerReturnCase $source, array $data, UploadedFile $proof): CustomerReturnMovement
    {
        $order = Order::findOrFail($source->order_id);
        $this->authorize($actor, $order, true);
        Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'kind' => ['required', 'in:COMPENSATION,RECOVERY'], 'method' => ['required', 'in:CASH,TRANSFER'],
            'amount' => ['required', 'string'], 'currency_id' => ['required', 'integer'], 'responsibility_id' => ['nullable', 'integer'],
            'funding_source' => ['required', 'string', 'max:255'], 'reference' => ['required', 'string', 'max:191'],
            'occurred_at' => ['required', 'date'], 'actual_confirmed' => ['required', 'accepted'],
        ])->validate();
        $meta = $this->evidence->inspect($proof);
        $path = null;
        try {
            return $this->checked(function () use ($actor, $source, $order, $data, $proof, $meta, &$path): CustomerReturnMovement {
                return DB::transaction(function () use ($actor, $source, $order, $data, $proof, $meta, &$path): CustomerReturnMovement {
                    Order::query()->lockForUpdate()->findOrFail($order->id);
                    $this->authorize($actor, $order, true);
                    $case = CustomerReturnCase::query()->lockForUpdate()->findOrFail($source->id);
                    if ($data['kind'] === 'COMPENSATION' && $data['method'] !== $case->compensation_method) {
                        throw ValidationException::withMessages(['return' => __('credit.method_conflict')]);
                    }
                    if ((int) $data['currency_id'] !== $case->currency_id) {
                        $this->invalid();
                    }
                    $facts = ['case_id' => $case->id, 'branch_id' => $case->branch_id, 'currency_id' => $case->currency_id,
                        'recorded_by' => $actor->id, 'kind' => $data['kind'], 'method' => $data['method'],
                        'responsibility_id' => filled($data['responsibility_id'] ?? null) ? (int) $data['responsibility_id'] : null,
                        'amount' => $this->amount(Currency::findOrFail($case->currency_id), $data['amount']),
                        'reference' => trim($data['reference']), 'funding_source' => trim($data['funding_source']),
                        'occurred_at' => $this->time($data['occurred_at']), 'evidence_sha256' => $meta['sha256'], 'actual_confirmed' => true];
                    $hash = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
                    if ($existing = CustomerReturnMovement::where('request_key', $data['request_key'])->first()) {
                        $this->same($existing->payload_hash, $hash);

                        return $existing;
                    }
                    $path = $this->evidence->store($proof, $meta);
                    $movement = CustomerReturnMovement::create($facts + ['request_key' => $data['request_key'], 'payload_hash' => $hash,
                        'evidence_path' => $path, 'evidence_name' => $meta['name'], 'evidence_size' => $meta['size'], 'evidence_mime' => $meta['mime']]);
                    $this->notify($actor, $case, $data['request_key'], $data['kind'], $facts);

                    return $movement;
                });
            });
        } catch (\Throwable $exception) {
            if ($path) {
                $this->evidence->delete($path);
            }
            throw $exception;
        }
    }

    public function summary(User $actor, Order $order): Collection
    {
        $this->authorize($actor, $order);

        $cases = CustomerReturnCase::where('order_id', $order->id)->with('responsibilities', 'movements')->orderByDesc('id')->limit(50)->get();
        $lines = DB::table('customer_return_lines as l')->leftJoin('invoice_items as i', 'i.id', '=', 'l.invoice_item_id')
            ->whereIn('l.case_id', $cases->modelKeys())->select('l.*', 'i.description', 'i.unit_name_snapshot')->orderBy('l.id')->get()->groupBy('case_id');
        $fundedAmounts = DB::table('customer_return_responsibilities')->whereIn('case_id', $cases->modelKeys())
            ->select('id')->selectRaw('ret_funded_responsibility(id) AS funded_amount')->get()->keyBy('id');

        return $cases->each(function ($case) use ($lines, $fundedAmounts): void {
            $case->setRelation('lines', $lines->get($case->id, collect()));
            $paid = $case->movements->where('kind', 'COMPENSATION')->reduce(fn (BigDecimal $sum, $m) => $sum->plus($m->amount), BigDecimal::zero());
            $credited = Schema::hasTable('customer_credit_entries') ? DB::table('customer_credit_entries')->where('source_return_case_id', $case->id)->sum('amount') : '0';
            $case->setAttribute('credit_granted', (string) BigDecimal::of($credited)->toScale(4));
            $case->setAttribute('unpaid_compensation', (string) BigDecimal::of($case->amount)->minus($paid)->minus($credited));
            $used = Schema::hasTable('customer_credit_redemptions') ? DB::table('customer_credit_redemptions as d')
                ->join('customer_credit_entries as g', 'g.id', '=', 'd.grant_id')->join('customer_credit_settlements as s', 's.id', '=', 'd.settlement_id')
                ->join('invoice_collections as col', 'col.invoice_id', '=', 's.invoice_id')->where('g.source_return_case_id', $case->id)->sum('d.amount') : '0';
            if (Schema::hasTable('customer_credit_cash_payouts')) {
                $cashPaid = DB::table('customer_credit_cash_payout_grants as d')->join('customer_credit_entries as g', 'g.id', '=', 'd.grant_id')
                    ->where('g.source_return_case_id', $case->id)->sum('d.amount');
                $used = (string) BigDecimal::of($used)->plus($cashPaid);
            }
            $case->setAttribute('credit_used', (string) BigDecimal::of($used)->toScale(4));
            foreach ($case->responsibilities as $party) {
                $received = $case->movements->where('responsibility_id', $party->id)->reduce(fn (BigDecimal $sum, $m) => $sum->plus($m->amount), BigDecimal::zero());
                $funded = BigDecimal::of($fundedAmounts[$party->id]->funded_amount);
                $party->setAttribute('funded_amount', (string) $funded->toScale(4));
                $party->setAttribute('repaid_amount', (string) $received->toScale(4));
                $party->setAttribute('unfunded_amount', (string) BigDecimal::of($party->amount)->minus($funded)->toScale(4));
                $recoverable = $party->party === 'CAPTAIN' ? $funded : BigDecimal::of($party->amount);
                $party->setAttribute('outstanding', (string) $recoverable->minus($received)->toScale(4));
            }
        });
    }

    public function captainLiabilities(User $actor, Captain $captain): Collection
    {
        Gate::forUser($actor)->authorize('captain_financials.view');
        Gate::forUser($actor)->authorize('order_shop_returns.view');
        // Historical liabilities belong to their recorded Branch, even after the Captain transfers.
        // The query below scopes every returned record to the reader's authorized Branches.
        if (! Schema::hasTable('customer_return_cases')) {
            return collect();
        }

        return DB::table('customer_return_responsibilities as r')->join('customer_return_cases as c', 'c.id', '=', 'r.case_id')
            ->join('order_captains as oc', 'oc.id', '=', 'r.order_captain_id')->where('r.party', 'CAPTAIN')->where('oc.captain_id', $captain->id)
            ->whereIn('c.branch_id', $actor->availableBranches()->select('branches.id'))
            ->join('branches as b', 'b.id', '=', 'c.branch_id')->join('currencies as cu', 'cu.id', '=', 'c.currency_id')
            ->select('r.id', 'c.order_id', 'c.branch_id', 'c.currency_id', 'r.amount', 'b.name as branch_name', 'cu.code as currency_code')
            ->selectRaw('ret_funded_responsibility(r.id) AS funded_amount')
            ->selectRaw('COALESCE((SELECT sum(m.amount) FROM customer_return_movements m WHERE m.responsibility_id=r.id),0) AS repaid_amount')
            ->selectRaw('r.amount-ret_funded_responsibility(r.id) AS unfunded_amount')
            ->selectRaw('ret_funded_responsibility(r.id)-COALESCE((SELECT sum(m.amount) FROM customer_return_movements m WHERE m.responsibility_id=r.id),0) AS outstanding')
            ->orderByDesc('r.id')->paginate(20)->getCollection();
    }

    private function notify(User $actor, CustomerReturnCase $case, string $key, string $event, array $details): void
    {
        $recipients = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'SUPER_ADMIN'))->get()
            ->filter(fn ($user) => $user->can('orders.view') && $user->can('order_shop_returns.view') && $user->canAccessBranch(Branch::findOrFail($case->branch_id)));
        Notification::sendNow($recipients, new CustomerReturnNotice([
            'request_key' => $key, 'event' => $event, 'case_id' => $case->id, 'order_id' => $case->order_id, 'branch_id' => $case->branch_id,
            'title' => __('returns.notice'), 'body' => json_encode(['event' => __('returns.'.$event), 'return' => $case->id,
                'reason' => __('returns.reasons.'.$case->reason), 'notes' => $case->notes, 'currency_id' => $case->currency_id,
                'amount' => $details['amount'] ?? $case->amount, 'case_amount' => $case->amount,
                'responsibility_policy' => __('returns.liability_help'), 'actor_id' => $actor->id, 'invoice_id' => $case->invoice_id,
                'collection_id' => $case->collection_id, 'occurred_at' => $case->occurred_at->toIso8601String(),
                'product_scope' => $case->product_scope, 'invoice_refund_scope' => $case->invoice_refund_scope,
                'details' => $details], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ]), ['database']);
    }

    private function amount(Currency $currency, mixed $raw, bool $zero = false): string
    {
        if ($zero && is_string($raw) && preg_match('/^0(?:\.0+)?$/D', $raw)) {
            return '0.0000';
        }
        try {
            return $this->money->validatePlannedAmount($currency, $raw);
        } catch (FinancialLedgerException) {
            $this->invalid();
        }
    }

    private function time(string $raw): string
    {
        $time = CarbonImmutable::parse($raw, 'Asia/Damascus');
        if ($time->isFuture()) {
            $this->invalid();
        }

        return $time->utc()->format('Y-m-d H:i:s.uP');
    }

    private function same(string $stored, string $requested): void
    {
        if (! hash_equals($stored, $requested)) {
            throw ValidationException::withMessages(['request_key' => __('returns.conflict')]);
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['return' => __('returns.invalid')]);
    }

    private function checked(callable $action): mixed
    {
        try {
            return $action();
        } catch (QueryException $exception) {
            foreach (['RET legacy conflict' => 'legacy_conflict', 'RET ambiguous partial funding' => 'ambiguous_funding', 'RET recovery exceeds funded obligation' => 'unfunded_recovery'] as $message => $key) {
                if (str_contains($exception->getMessage(), $message)) {
                    throw ValidationException::withMessages(['return' => __('returns.'.$key)]);
                }
            }
            if (in_array($exception->errorInfo[0] ?? '', ['23514', '23505', '23503', 'P0002'], true)) {
                $this->invalid();
            }
            throw $exception;
        }
    }
}
