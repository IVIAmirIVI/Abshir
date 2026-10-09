<?php

namespace App\Services;

use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialLedgerException;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\OrderShopPurchaseConfirmation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Backend-only physical purchase facts. Does not authorize settlement or post ledger events. */
class SharedOrderShopPurchaseService
{
    public function __construct(
        private readonly FinancialPostingService $posting,
        private readonly OrderReturnEvidenceService $evidence,
    ) {}

    public function record(User $actor, Order $order, OrderShop $shop, array $data, UploadedFile $file): OrderShopPurchaseConfirmation
    {
        Gate::forUser($actor)->authorize('order_shop_purchases.confirm');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($shop->order_id !== $order->id, 404);
        Validator::make($data, [
            'currency_id' => ['required', 'integer'], 'amount' => ['required', 'string'],
            'purchased_at' => ['required', 'date'], 'request_key' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string'],
        ])->validate();
        $data['request_key'] = strtolower($data['request_key']);
        $metadata = $this->evidence->inspect($file);
        // The existing purchase source stores timestamp(0). Canonicalize before hashing/comparing retries.
        $time = CarbonImmutable::parse($data['purchased_at'])->utc()->startOfSecond();
        if ($time->isFuture()) {
            throw ValidationException::withMessages(['purchased_at' => __('domain.purchase_time_cannot_be_in_the_future')]);
        }
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $order, $shop, $data, $file, $metadata, $time, &$path): OrderShopPurchaseConfirmation {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
                // Shared and legacy sources use the same globally unique purchase key.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['order-shop-purchase:'.$data['request_key']]);
                $shop = $order->shops()->lockForUpdate()->findOrFail($shop->id);
                $assignments = $order->captainAssignments()->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get();
                $primary = $assignments->where('assignment_role', OrderCaptainRole::PrimaryCollection);
                if ($assignments->count() !== 2 || $primary->count() !== 1) {
                    throw ValidationException::withMessages(['captains' => __('domain.shared_purchase_requires_two_captains')]);
                }
                if ($assignments->contains(fn ($assignment) => $time->lessThan($assignment->assigned_at))) {
                    throw ValidationException::withMessages(['purchased_at' => __('domain.shared_purchase_before_assignment')]);
                }
                $currency = Currency::query()->lockForUpdate()->findOrFail($data['currency_id']);
                try {
                    $amount = $this->posting->validatePlannedAmount($currency, $data['amount']);
                    $share = (string) BigDecimal::of($amount)->dividedBy(2, $currency->decimal_places, RoundingMode::Unnecessary);
                } catch (FinancialLedgerException|MathException $exception) {
                    throw ValidationException::withMessages(['amount' => __('domain.shared_purchase_exact_halves_required')]);
                }
                $facts = [
                    'order_id' => $order->id, 'order_shop_id' => $shop->id, 'branch_id' => $order->branch_id,
                    'currency_id' => $currency->id, 'purchase_mode' => SharedPurchaseBoundary::MODE,
                    'order_captain_id' => null, 'collecting_order_captain_id' => $primary->sole()->id,
                    'amount' => $amount, 'recorded_by' => $actor->id, 'purchased_at' => $time->format('Y-m-d H:i:s.uP'),
                    'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'evidence_sha256' => $metadata['sha256'], 'evidence_mime' => $metadata['mime'], 'evidence_size' => $metadata['size'],
                ];
                $existing = OrderShopPurchaseConfirmation::query()->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    foreach ($facts as $key => $value) {
                        $actual = $key === 'purchased_at' ? $existing->purchased_at->utc()->format('Y-m-d H:i:s.uP') : $existing->{$key};
                        if ((string) $actual !== (string) $value) {
                            throw ValidationException::withMessages(['request_key' => __('domain.purchase_identity', ['field' => $key])]);
                        }
                    }

                    return $existing->load('allocations');
                }
                // No conversion of an existing financial/legacy source to this new contract.
                if (in_array($order->operational_status, [OrderOperationalStatus::Delivered, OrderOperationalStatus::Cancelled], true)) {
                    throw ValidationException::withMessages(['order' => __('domain.shared_purchase_requires_unsettled_order')]);
                }
                if ($order->invoices()->exists() || $order->billPaymentRequests()->exists() || $order->cancellationIntent()->exists()
                    || $order->purchaseConfirmations()->where('purchase_mode', 'SINGLE_PAYER')->exists()
                    || $order->purchaseConfirmations()->where('currency_id', '<>', $currency->id)->exists()
                    || $shop->purchaseConfirmation()->exists()) {
                    throw ValidationException::withMessages(['order' => __('domain.shared_purchase_requires_unsettled_order')]);
                }
                $path = $this->evidence->store($file, $metadata);
                $id = DB::table('order_shop_purchase_confirmations')->insertGetId($facts + [
                    'request_key' => $data['request_key'], 'evidence_path' => $path, 'evidence_name' => $metadata['name'],
                    'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
                ]);
                foreach ($assignments as $assignment) {
                    DB::table('order_shop_purchase_allocations')->insert([
                        'purchase_confirmation_id' => $id, 'order_captain_id' => $assignment->id,
                        'amount' => $share, 'created_at' => DB::raw('clock_timestamp()'),
                    ]);
                }
                DB::select('SELECT assert_shared_shop_purchase_complete(?)', [$id]);

                return OrderShopPurchaseConfirmation::query()->with('allocations')->findOrFail($id);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                $this->evidence->delete($path);
            }
            throw $exception;
        }
    }

    public function evidenceBytes(User $actor, Order $order, OrderShop $shop, OrderShopPurchaseConfirmation $purchase): string
    {
        Gate::forUser($actor)->authorize('order_shop_purchases.view');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        $purchase = OrderShopPurchaseConfirmation::query()->findOrFail($purchase->id);
        abort_unless($shop->order_id === $order->id && $purchase->order_id === $order->id
            && $purchase->order_shop_id === $shop->id && $purchase->purchase_mode === SharedPurchaseBoundary::MODE, 404);

        return $this->evidence->bytes($purchase->evidence_path, $purchase->evidence_sha256, $purchase->evidence_size, $purchase->evidence_mime);
    }
}
