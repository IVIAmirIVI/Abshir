<?php

namespace App\Services;

use App\Enums\InvoiceCollectionMethod;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerCreditNotice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MixedCustomerCreditService
{
    public function __construct(private readonly FinancialPostingService $money) {}

    public function grants(User $actor, Order $order, int $currencyId): Collection
    {
        Gate::forUser($actor)->authorize('orders.view');
        abort_unless(Order::accessibleTo($actor)->whereKey($order)->exists(), 403);
        app(CustomerCreditService::class)->authorize($actor, $order->customer);
        if (! Schema::hasTable('customer_credit_redemptions')) {
            return collect();
        }

        $spent = Schema::hasTable('customer_credit_cash_payouts') ? 'customer_credit_grant_spent(g.id)' : 'COALESCE((SELECT sum(d.amount) FROM customer_credit_redemptions d WHERE d.grant_id=g.id),0)';
        $grants = DB::table('customer_credit_entries as g')->where('g.customer_id', $order->customer_id)
            ->where('g.branch_id', $order->branch_id)->where('g.currency_id', $currencyId)
            ->select('g.*')->selectRaw('g.amount-('.$spent.') AS available')
            ->whereRaw('g.amount>('.$spent.')')
            ->orderBy('g.id')->get();
        if (Schema::hasTable('customer_credit_responsibility_attributions')) {
            $parties = DB::table('customer_return_responsibilities as r')
                ->leftJoin('order_captains as oc', 'oc.id', '=', 'r.order_captain_id')
                ->leftJoin('captains as ca', 'ca.id', '=', 'oc.captain_id')->leftJoin('users as u', 'u.id', '=', 'ca.user_id')
                ->leftJoin('order_shops as os', 'os.id', '=', 'r.order_shop_id')
                ->whereIn('r.case_id', $grants->pluck('source_return_case_id')->filter())
                ->select('r.id', 'r.case_id', 'r.party')->selectRaw("COALESCE(u.name,os.shop_name_snapshot,'') AS name")
                ->selectRaw('r.amount-ret_funded_responsibility(r.id) AS available')->orderBy('r.id')->get()->groupBy('case_id');
            foreach ($grants as $grant) {
                $grant->responsibilities = $parties->get($grant->source_return_case_id, collect());
            }
        }

        return $grants;
    }

    /** @param array<string, mixed> $data Explicit per-grant amounts; never chooses a FIFO or liability split. */
    public function record(User $actor, Invoice $source, InvoiceCollectionMethod $method, array $data): InvoiceCollection
    {
        Gate::forUser($actor)->authorize('customer_credits.redeem');
        Gate::forUser($actor)->authorize('invoice_collections.create');
        Gate::forUser($actor)->authorize('invoices.view');
        abort_unless(Invoice::accessibleTo($actor)->whereKey($source)->exists(), 403);
        app(CustomerCreditService::class)->authorize($actor, $source->order->customer);
        if (! Schema::hasTable('customer_credit_settlements')) {
            $this->invalid();
        }
        $data = Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'actual_amount' => ['required', 'string'],
            'allocations' => ['required', 'array', 'min:1'], 'collected_at' => ['required', 'date'],
            'attributions' => ['sometimes', 'array'], 'attributions.*' => ['array'],
            'notes' => ['nullable', 'string', 'max:2000'], 'payment_reference' => ['nullable', 'string', 'max:255'],
        ], ['actual_amount.required' => __('credit.settlement_invalid'), 'allocations.required' => __('credit.settlement_invalid')])->validate();
        try {
            return DB::transaction(function () use ($actor, $source, $method, $data): InvoiceCollection {
                // The Customer lock serializes consumption across different Orders as well as grant creation.
                $order = Order::query()->lockForUpdate()->findOrFail($source->order_id);
                Customer::query()->lockForUpdate()->findOrFail($order->customer_id);
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($source->id);
                abort_unless(Order::accessibleTo($actor)->whereKey($order)->exists(), 403);
                app(CustomerCreditService::class)->authorize($actor, $order->customer);
                if (! in_array($order->accounting_contract_version, [2, 3], true)) {
                    $this->invalid();
                }
                // Take the financial lock before SQL guards, avoiding a SHARE-to-UPDATE upgrade during posting.
                $currency = Currency::query()->lockForUpdate()->findOrFail($invoice->currency_id);
                $allocations = [];
                foreach ($data['allocations'] as $id => $raw) {
                    if ($raw === '' || $raw === null || (is_string($raw) && preg_match('/^0(?:\.0+)?$/D', $raw))) {
                        continue;
                    }
                    if (! ctype_digit((string) $id) || (int) $id < 1 || ! is_string($raw) || isset($allocations[(int) $id])) {
                        $this->invalid();
                    }
                    $allocations[(int) $id] = $this->money->validatePlannedAmount($currency, $raw);
                }
                ksort($allocations, SORT_NUMERIC);
                $attributions = [];
                foreach ($data['attributions'] ?? [] as $grantId => $parties) {
                    foreach ($parties as $partyId => $raw) {
                        if ($raw === '' || $raw === null || (is_string($raw) && preg_match('/^0(?:\.0+)?$/D', $raw))) {
                            continue;
                        }
                        if (! ctype_digit((string) $grantId) || ! ctype_digit((string) $partyId) || ! isset($allocations[(int) $grantId]) || (int) $partyId < 1 || ! is_string($raw)) {
                            $this->invalid();
                        }
                        $attributions[(int) $grantId][(int) $partyId] = $this->money->validatePlannedAmount($currency, $raw);
                    }
                    if (isset($attributions[(int) $grantId])) {
                        ksort($attributions[(int) $grantId], SORT_NUMERIC);
                    }
                }
                ksort($attributions, SORT_NUMERIC);
                $credit = array_reduce($allocations, fn (BigDecimal $sum, string $amount) => $sum->plus($amount), BigDecimal::zero());
                $actual = preg_match('/^0(?:\.0+)?$/D', $data['actual_amount']) ? '0.0000' : $this->money->validatePlannedAmount($currency, $data['actual_amount']);
                if (! $credit->isGreaterThan(0) || $credit->isGreaterThan($invoice->delivery_total)
                    || ! $credit->plus($actual)->isEqualTo($invoice->grand_total)) {
                    $this->invalid();
                }
                // Match the existing collection input timezone; persist the absolute instant with its offset.
                $time = CarbonImmutable::parse($data['collected_at'])->utc();
                $facts = ['invoice_id' => $invoice->id, 'order_id' => $order->id, 'customer_id' => $order->customer_id,
                    'branch_id' => $order->branch_id, 'currency_id' => $currency->id, 'recorded_by' => $actor->id,
                    'credit_amount' => (string) $credit->toScale(4), 'actual_amount' => $actual, 'collection_method' => $method->value,
                    'collected_at' => $time->format('Y-m-d H:i:s.uP'), 'reason' => filled($data['notes'] ?? null) ? trim($data['notes']) : 'Invoice #'.$invoice->id.' delivery credit',
                    'payment_reference' => filled($data['payment_reference'] ?? null) ? trim($data['payment_reference']) : null];
                $hashFacts = [$facts, $allocations];
                if ($attributions !== []) {
                    $hashFacts[] = $attributions;
                }
                $hash = hash('sha256', json_encode($hashFacts, JSON_THROW_ON_ERROR));
                if ($existing = DB::table('customer_credit_settlements')->where('request_key', $data['request_key'])->first()) {
                    if (! hash_equals($existing->payload_hash, $hash)) {
                        $this->invalid();
                    }

                    return InvoiceCollection::where('invoice_id', $existing->invoice_id)->sole();
                }
                if (! app(InvoiceCollectionService::class)->availability($actor, $invoice)['available']) {
                    $this->invalid();
                }
                $grants = $this->grants($actor, $order, $currency->id)->keyBy('id');
                $explicitAttribution = Schema::hasTable('customer_credit_responsibility_attributions');
                if (! $explicitAttribution && $attributions !== []) {
                    $this->invalid();
                }
                $resolved = [];
                foreach ($allocations as $id => $amount) {
                    if (! $grants->has($id) || BigDecimal::of($amount)->isGreaterThan($grants[$id]->available)) {
                        $this->invalid();
                    }
                    if (! $explicitAttribution && $grants[$id]->source_return_case_id && DB::table('customer_return_responsibilities')->where('case_id', $grants[$id]->source_return_case_id)->count() > 1
                        && ! BigDecimal::of($amount)->isEqualTo($grants[$id]->available)) {
                        throw ValidationException::withMessages(['credit' => __('credit.attribution_blocked')]);
                    }
                    if ($explicitAttribution) {
                        $parties = $grants[$id]->responsibilities->keyBy('id');
                        $resolved[$id] = $attributions[$id] ?? [];
                        if ($resolved[$id] === [] && $parties->isNotEmpty()) {
                            if ($parties->count() === 1) {
                                $resolved[$id] = [$parties->keys()->sole() => $amount];
                            } elseif (BigDecimal::of($amount)->isEqualTo($grants[$id]->available)) {
                                $resolved[$id] = $parties->filter(fn ($party) => BigDecimal::of($party->available)->isGreaterThan(0))->mapWithKeys(fn ($party) => [$party->id => (string) BigDecimal::of($party->available)->toScale(4)])->all();
                            } else {
                                throw ValidationException::withMessages(['credit' => __('credit.attribution_required')]);
                            }
                        }
                        foreach ($resolved[$id] as $partyId => $part) {
                            if (! $parties->has($partyId) || BigDecimal::of($part)->isGreaterThan($parties[$partyId]->available)) {
                                $this->invalid();
                            }
                        }
                        $attributed = array_reduce($resolved[$id], fn (BigDecimal $sum, string $part) => $sum->plus($part), BigDecimal::zero());
                        if ($parties->isNotEmpty() ? ! $attributed->isEqualTo($amount) : ! $attributed->isZero()) {
                            $this->invalid();
                        }
                    }
                }
                $id = DB::table('customer_credit_settlements')->insertGetId($facts + ['request_key' => $data['request_key'], 'payload_hash' => $hash]);
                foreach ($allocations as $grantId => $amount) {
                    $redemptionId = DB::table('customer_credit_redemptions')->insertGetId(['settlement_id' => $id, 'grant_id' => $grantId, 'amount' => $amount]);
                    foreach ($resolved[$grantId] ?? [] as $partyId => $part) {
                        DB::table('customer_credit_responsibility_attributions')->insert(['redemption_id' => $redemptionId, 'responsibility_id' => $partyId, 'amount' => $part]);
                    }
                }
                $collection = app(InvoiceCollectionService::class)->record($actor, $invoice, $method, $actual, $currency->id, $time,
                    $data['notes'] ?? null, $facts['payment_reference']);
                DB::select('SELECT assert_credit_settlement(?)', [$id]);
                $recipients = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'SUPER_ADMIN'))->get()
                    ->filter(fn ($user) => $user->can('customer_credits.view') && $user->canAccessBranch(Branch::findOrFail($order->branch_id)));
                Notification::sendNow($recipients, new CustomerCreditNotice([
                    'customer_id' => $order->customer_id, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
                    'request_key' => $data['request_key'], 'title' => __('credit.redeemed'),
                    'body' => __('credit.delivery_settlement').' · '.$credit.' '.$currency->code.' · '.$facts['reason'].' · #'.$actor->id,
                ]), ['database']);

                return $collection;
            });
        } catch (FinancialLedgerException) {
            $this->invalid();
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? '', ['23514', '23505', '23503', 'P0002'], true)) {
                $this->invalid();
            }
            throw $exception;
        }
    }

    public function creditAmount(InvoiceCollection $collection): string
    {
        return Schema::hasTable('customer_credit_settlements')
            ? (string) (DB::table('customer_credit_settlements')->where('invoice_id', $collection->invoice_id)->value('credit_amount') ?? '0') : '0';
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['credit' => __('credit.settlement_invalid')]);
    }
}
