<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopCommissionDraft;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Independent commission receivables; never posts cash/bank assets or Captain entries. */
class ShopCommissionAccountingService
{
    public function __construct(private readonly ShopCommissionRuleResolver $resolver, private readonly CommissionCalculator $calculator) {}

    /** Called only in the authoritative issuance transaction, before other financial posting. */
    public function freezeIssued(Invoice $invoice, ?InvoiceFinancialCorrection $correction = null): void
    {
        if (! Schema::hasTable('shop_commission_invoice_snapshots') || DB::table('shop_commission_invoice_snapshots')->where('invoice_id', $invoice->id)->exists()) {
            return;
        }
        if (DB::table('shop_commission_legacy_invoices')->where('id', $invoice->id)->exists()) {
            return;
        }
        $items = $invoice->items()->with('orderItem.orderShop')->orderBy('id')->get();
        if (! BigDecimal::of($invoice->items_subtotal)->isEqualTo($items->reduce(fn (BigDecimal $sum, $item) => $sum->plus($item->line_total), BigDecimal::zero()))) {
            throw ValidationException::withMessages(['commission' => __('commissions.discount_attribution_blocked')]);
        }
        $shopIds = $items->map(fn ($item) => $item->orderItem->orderShop->shop_id)->filter()->unique()->sort()->values();
        foreach ($shopIds as $id) {
            DB::select('SELECT pg_advisory_xact_lock(161010, CAST(? AS integer))', [$id]);
            DB::select('SELECT id FROM shops WHERE id=? FOR KEY SHARE', [$id]);
        }
        $currency = Currency::query()->lockForUpdate()->findOrFail($invoice->currency_id);
        $frozenAt = $correction?->finalized_at ?? Carbon::parse(DB::table('shop_commission_issue_instants')->where('invoice_id', $invoice->id)->sole()->frozen_at);
        $snapshotId = DB::table('shop_commission_invoice_snapshots')->insertGetId([
            'invoice_id' => $invoice->id, 'order_id' => $invoice->order_id, 'branch_id' => $invoice->order->branch_id,
            'invoice_version' => $invoice->version, 'invoice_reference' => $invoice->invoice_number,
            'frozen_at' => $frozenAt->format('Y-m-d H:i:s.uP'), 'recorded_by' => $invoice->issued_by, 'correction_id' => $correction?->id,
        ]);
        foreach ($items as $item) {
            $orderItem = $item->orderItem;
            $shopId = $orderItem->orderShop->shop_id;
            $original = $correction ? DB::table('shop_commission_lines as l')->join('shop_commission_invoice_snapshots as b', 'b.id', '=', 'l.snapshot_id')
                ->join('invoice_items as ii', 'ii.id', '=', 'l.invoice_item_id')->where('b.invoice_id', $correction->original_invoice_id)
                ->where('ii.order_item_id', $orderItem->id)->select('l.*')->sole() : null;
            $rule = $original ? ($original->rule_id ? ShopCommissionDraft::findOrFail($original->rule_id) : null)
                : $this->resolver->resolveActive($shopId, $orderItem->product_id, $item->currency_id, $frozenAt);
            $activationId = $original?->activation_id ?? ($rule ? DB::table('shop_commission_activation_events')->where('draft_id', $rule->id)
                ->where('state', 'ACTIVE')->where('recorded_at', '<=', $frozenAt->format('Y-m-d H:i:s.uP'))->orderByDesc('id')->value('id') : null);
            $places = $original?->decimal_places ?? $currency->decimal_places;
            $amount = $this->calculator->calculate($rule, $item->quantity, $item->line_total, $places);
            if (BigDecimal::of($amount)->isGreaterThan(0)) {
                DB::table('shop_commission_accounts')->insertOrIgnore(['shop_id' => $shopId, 'branch_id' => $invoice->order->branch_id, 'currency_id' => $item->currency_id]);
            }
            DB::table('shop_commission_lines')->insert([
                'snapshot_id' => $snapshotId, 'invoice_item_id' => $item->id, 'shop_id' => $shopId, 'product_id' => $orderItem->product_id,
                'rule_id' => $rule?->id, 'activation_id' => $activationId, 'original_line_id' => $original?->id,
                'rule_version' => $rule?->version, 'rule_scope' => $rule ? ($rule->product_id ? 'PRODUCT' : ($rule->product_group_id ? 'PRODUCT_GROUP' : 'SHOP')) : null,
                'method' => $rule?->method, 'rule_value' => $rule?->commission_value, 'quantity' => $item->quantity,
                'discounted_base' => $item->line_total, 'amount' => $amount, 'currency_id' => $item->currency_id, 'decimal_places' => $places,
            ]);
        }
    }

    /** Both collection and delivery call this under the existing Order transaction. */
    public function postIfReady(Order $source): void
    {
        if (! Schema::hasTable('shop_commission_invoice_snapshots')) {
            return;
        }
        DB::transaction(function () use ($source): void {
            $order = Order::query()->lockForUpdate()->findOrFail($source->id);
            $invoice = $order->currentInvoice()->with(['collection', 'settledCorrection'])->first();
            if ($order->operational_status !== OrderOperationalStatus::Delivered || ! $invoice || $invoice->status !== InvoiceStatus::Issued
                || (! $invoice->collection && ! $invoice->settledCorrection)) {
                return;
            }
            $lines = DB::table('shop_commission_lines as l')->join('shop_commission_invoice_snapshots as b', 'b.id', '=', 'l.snapshot_id')
                ->where('b.invoice_id', $invoice->id)->where('l.amount', '>', 0)->orderBy('l.shop_id')->orderBy('l.currency_id')->orderBy('l.id')->select('l.*', 'b.branch_id')->get();
            foreach ($lines as $line) {
                $account = DB::table('shop_commission_accounts')->where('shop_id', $line->shop_id)->where('branch_id', $line->branch_id)->where('currency_id', $line->currency_id)->lockForUpdate()->sole();
                if (! DB::table('shop_commission_transactions')->where('line_id', $line->id)->exists()) {
                    DB::table('shop_commission_transactions')->insert([
                        'account_id' => $account->id, 'kind' => 'EARNING', 'line_id' => $line->id, 'amount' => $line->amount,
                        'occurred_at' => now()->format('Y-m-d H:i:s.uP'), 'recorded_by' => $invoice->collection?->recorded_by ?? $invoice->settledCorrection->finalized_by,
                    ]);
                }
            }
        });
    }

    /** The existing supported correction has already finalized; preserve receipts and reverse exact earnings. */
    public function correctLocked(InvoiceFinancialCorrection $correction): void
    {
        if (! Schema::hasTable('shop_commission_invoice_snapshots') || ! DB::table('shop_commission_invoice_snapshots')->where('invoice_id', $correction->original_invoice_id)->exists()) {
            return;
        }
        $earnings = DB::table('shop_commission_transactions as t')->join('shop_commission_lines as l', 'l.id', '=', 't.line_id')
            ->join('shop_commission_invoice_snapshots as b', 'b.id', '=', 'l.snapshot_id')->where('b.invoice_id', $correction->original_invoice_id)
            ->where('t.kind', 'EARNING')->orderBy('t.account_id')->orderBy('t.id')->select('t.*')->get();
        foreach ($earnings as $earning) {
            DB::table('shop_commission_accounts')->where('id', $earning->account_id)->lockForUpdate()->first();
            if (! DB::table('shop_commission_transactions')->where('reversal_of', $earning->id)->exists()) {
                DB::table('shop_commission_transactions')->insert([
                    'account_id' => $earning->account_id, 'kind' => 'REVERSAL', 'amount' => $earning->amount, 'reversal_of' => $earning->id,
                    'correction_id' => $correction->id, 'reason' => $correction->reason, 'recorded_by' => $correction->finalized_by,
                    'occurred_at' => $correction->finalized_at->format('Y-m-d H:i:s.uP'),
                ]);
            }
        }
        if ($correction->replacement_invoice_id) {
            $this->freezeIssued(Invoice::findOrFail($correction->replacement_invoice_id), $correction);
            $this->postIfReady($correction->order);
        }
    }

    public function assertAccountAccess(User $actor, Shop $shop, ?stdClass $account = null): void
    {
        Gate::forUser($actor)->authorize('shops.view');
        Gate::forUser($actor)->authorize('company_financials.view');
        abort_unless($actor->canAccessShop($shop), 403);
        if ($account) {
            abort_unless($account->shop_id === $shop->id, 404);
            abort_unless($actor->isSuperAdmin() || $actor->availableBranches()->whereKey($account->branch_id)->exists(), 403);
        }
    }

    public function balance(int $accountId): array
    {
        $totals = DB::table('shop_commission_transactions')->where('account_id', $accountId)->selectRaw("COALESCE(sum(amount) FILTER(WHERE kind='EARNING'),0)::text earned, COALESCE(sum(amount) FILTER(WHERE kind='REVERSAL'),0)::text reversed, COALESCE(sum(amount) FILTER(WHERE kind='PAYMENT'),0)::text paid")->first();
        $net = BigDecimal::of($totals->earned)->minus($totals->reversed)->minus($totals->paid);

        return ['earned' => $totals->earned, 'reversed' => $totals->reversed, 'paid' => $totals->paid,
            'outstanding' => (string) ($net->isGreaterThan(0) ? $net : BigDecimal::zero()), 'credit' => (string) ($net->isLessThan(0) ? $net->negated() : BigDecimal::zero())];
    }

    public function recordPayment(User $actor, Shop $shop, int $accountId, array $data): stdClass
    {
        $this->assertAccountAccess($actor, $shop);
        Gate::forUser($actor)->authorize('shop_partnerships.update');
        if (! Schema::hasTable('shop_commission_accounts')) {
            throw ValidationException::withMessages(['commission' => __('commissions.activation_required')]);
        }
        $account = DB::table('shop_commission_accounts')->find($accountId);
        abort_unless($account, 404);
        $this->assertAccountAccess($actor, $shop, $account);
        if (! is_string($data['amount'] ?? null) || ! preg_match('/^[0-9]{1,60}(\.[0-9]{1,30})?$/D', $data['amount'])
            || ! in_array($data['payment_method'] ?? null, ['CASH', 'TRANSFER'], true)
            || ! is_string($data['request_key'] ?? null) || ! Str::isUuid($data['request_key'])
            || ! is_string($data['occurred_at'] ?? null) || trim($data['occurred_at']) === ''
            || (isset($data['reference']) && ! is_string($data['reference'])) || mb_strlen($data['reference'] ?? '') > 255) {
            throw ValidationException::withMessages(['payment' => __('commissions.payment_invalid')]);
        }
        try {
            $time = Carbon::parse($data['occurred_at'], 'Asia/Damascus');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['payment' => __('commissions.payment_invalid')]);
        }
        try {
            return DB::transaction(function () use ($actor, $shop, $accountId, $data, $time, $account): stdClass {
                $currency = Currency::query()->sharedLock()->findOrFail($account->currency_id);
                $account = DB::table('shop_commission_accounts')->where('id', $accountId)->lockForUpdate()->sole();
                $this->assertAccountAccess($actor, $shop, $account);
                try {
                    $amount = BigDecimal::of($data['amount'])->toScale($currency->decimal_places, RoundingMode::Unnecessary);
                } catch (\Throwable) {
                    throw ValidationException::withMessages(['payment' => __('commissions.payment_invalid')]);
                }
                if (! $amount->isGreaterThan(0) || $time->isFuture()) {
                    throw ValidationException::withMessages(['payment' => __('commissions.payment_invalid')]);
                }
                $payload = ['account_id' => $accountId, 'amount' => (string) $amount, 'payment_method' => $data['payment_method'],
                    'reference' => trim($data['reference'] ?? ''), 'occurred_at' => $time->copy()->utc()->format('Y-m-d H:i:s.uP'), 'recorded_by' => $actor->id];
                $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $existing = DB::table('shop_commission_transactions')->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    if ($existing->account_id !== $accountId || ! hash_equals($existing->payload_hash, $hash)) {
                        throw ValidationException::withMessages(['payment' => __('commissions.retry_conflict')]);
                    }

                    return $existing;
                }
                if ($amount->isGreaterThan($this->balance($accountId)['outstanding'])) {
                    throw ValidationException::withMessages(['payment' => __('commissions.payment_exceeds')]);
                }
                $id = DB::table('shop_commission_transactions')->insertGetId($payload + [
                    'kind' => 'PAYMENT', 'request_key' => $data['request_key'], 'payload_hash' => $hash,
                ]);

                return DB::table('shop_commission_transactions')->find($id);
            }, 3);
        } catch (QueryException $exception) {
            if (! in_array($exception->getCode(), ['23505', '23514'], true)) {
                throw $exception;
            }
            throw ValidationException::withMessages(['payment' => __('commissions.retry_conflict')]);
        }
    }
}
