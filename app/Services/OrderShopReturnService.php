<?php

namespace App\Services;

use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialLedgerException;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\OrderShop;
use App\Models\OrderShopReturn;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderShopReturnService
{
    private const EVIDENCE_TYPES = ['RETURN_PROOF', 'REFUND_PROOF', 'RETURN_AND_REFUND_PROOF'];

    public function __construct(
        private readonly FinancialPostingService $posting,
        private readonly OrderReturnEvidenceService $evidence,
        private readonly OrderCancellationSafetyService $safety,
    ) {}

    /** @param array<string, UploadedFile> $files */
    public function record(User $actor, Order $order, OrderShop $shop, array $data, array $files): OrderShopReturn
    {
        Gate::forUser($actor)->authorize('order_shop_returns.create');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($shop->order_id !== $order->id, 404);
        Validator::make($data, [
            'returning_order_captain_id' => ['required', 'integer'],
            'refund_amount' => ['required', 'string'], 'returned_at' => ['required', 'date'],
            'refunded_at' => ['required', 'date'], 'refund_method' => ['required', 'string', 'max:40'],
            'refund_reference' => ['nullable', 'string', 'max:255'], 'shortfall_reason' => ['nullable', 'string', 'max:3000'],
            'note' => ['nullable', 'string'], 'request_key' => ['required', 'uuid'],
        ])->validate();
        if (array_diff(array_keys($files), self::EVIDENCE_TYPES) !== []
            || ! (isset($files['RETURN_AND_REFUND_PROOF'])
                || (isset($files['RETURN_PROOF']) && isset($files['REFUND_PROOF'])))) {
            throw ValidationException::withMessages(['evidence' => 'Evidence must prove both the return and refund.']);
        }
        $metadata = [];
        foreach ($files as $type => $file) {
            $metadata[$type] = $this->evidence->inspect($file);
        }
        ksort($metadata);
        $returnedAt = CarbonImmutable::parse($data['returned_at'])->utc();
        $refundedAt = CarbonImmutable::parse($data['refunded_at'])->utc();
        if ($returnedAt->isFuture() || $refundedAt->isFuture() || $refundedAt->lessThan($returnedAt)) {
            throw ValidationException::withMessages(['returned_at' => 'Return and refund times must be ordered and not in the future.']);
        }
        $storedPaths = [];
        try {
            return DB::transaction(function () use ($actor, $order, $shop, $data, $files, $metadata, $returnedAt, $refundedAt, &$storedPaths): OrderShopReturn {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                $intent = $order->cancellationIntent()->lockForUpdate()->first();
                if (! $intent || ! $intent->frozen_at) {
                    throw ValidationException::withMessages(['order' => 'An accepted AFTER_PURCHASE intent is required.']);
                }
                $shop = OrderShop::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($shop->id);
                $purchase = $shop->purchaseConfirmation()->first();
                if (! $purchase || ! $intent->requiredShops()->where('purchase_confirmation_id', $purchase->id)->exists()) {
                    throw ValidationException::withMessages(['order_shop' => 'This Shop is not in the frozen return set.']);
                }
                if ($returnedAt->lessThan($purchase->purchased_at)) {
                    throw ValidationException::withMessages(['returned_at' => 'Return time cannot precede the verified purchase.']);
                }
                $returner = OrderCaptain::query()->where('order_id', $order->id)->findOrFail($data['returning_order_captain_id']);
                $currency = Currency::query()->lockForUpdate()->findOrFail($purchase->currency_id);
                try {
                    $refundAmount = $this->posting->validatePlannedAmount($currency, $data['refund_amount']);
                } catch (FinancialLedgerException $exception) {
                    throw ValidationException::withMessages(['refund_amount' => $exception->getMessage()]);
                }
                if (BigDecimal::of($refundAmount)->isGreaterThan(BigDecimal::of($purchase->amount))) {
                    throw ValidationException::withMessages(['refund_amount' => 'Refund cannot exceed the verified purchase amount.']);
                }
                $shortfall = BigDecimal::of($refundAmount)->isLessThan(BigDecimal::of($purchase->amount));
                if ($shortfall && blank($data['shortfall_reason'] ?? null)) {
                    throw ValidationException::withMessages(['shortfall_reason' => 'A shortfall reason is required.']);
                }
                if ($shortfall && ! filter_var($data['shortfall_acknowledged'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw ValidationException::withMessages(['shortfall_acknowledged' => 'Confirm the exceptional shortfall before recording the completed return.']);
                }
                $facts = [
                    'order_id' => $order->id, 'order_cancellation_intent_id' => $intent->id,
                    'order_shop_id' => $shop->id, 'purchase_confirmation_id' => $purchase->id,
                    'branch_id' => $order->branch_id, 'currency_id' => $purchase->currency_id,
                    'purchasing_order_captain_id' => $purchase->order_captain_id,
                    'returning_order_captain_id' => $returner->id,
                    'refund_recipient_order_captain_id' => $purchase->order_captain_id,
                    'recorded_by' => $actor->id, 'purchase_amount' => $purchase->amount,
                    'refund_amount' => $refundAmount,
                    'returned_at' => $returnedAt->format('Y-m-d H:i:s.uP'),
                    'refunded_at' => $refundedAt->format('Y-m-d H:i:s.uP'),
                    'refund_method' => trim($data['refund_method']),
                    'refund_reference' => filled($data['refund_reference'] ?? null) ? trim($data['refund_reference']) : null,
                    'shortfall_reason' => $shortfall ? trim($data['shortfall_reason']) : null,
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                ];
                $existing = OrderShopReturn::query()->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    $this->assertSame($existing, $facts, $metadata);

                    return $existing;
                }
                if (in_array($order->operational_status, [OrderOperationalStatus::Delivered, OrderOperationalStatus::Cancelled], true)) {
                    throw ValidationException::withMessages(['order' => 'A finalized Order cannot record a new return.']);
                }
                $this->safety->assertNoFinancialCorrectionRequiredLocked($order, true);
                if ($shop->shopReturn()->exists()) {
                    throw ValidationException::withMessages(['return' => 'A completed return already exists for this purchased Shop.']);
                }
                $returnId = DB::table('order_shop_returns')->insertGetId($facts + [
                    'request_key' => $data['request_key'],
                    'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
                ]);
                $return = OrderShopReturn::query()->findOrFail($returnId);
                foreach ($metadata as $type => $meta) {
                    $path = $this->evidence->store($files[$type], $meta);
                    $storedPaths[] = $path;
                    DB::table('order_shop_return_evidence')->insert([
                        'order_shop_return_id' => $return->id, 'evidence_type' => $type, 'storage_path' => $path,
                        'original_filename' => $meta['name'], 'mime_type' => $meta['mime'],
                        'file_size' => $meta['size'], 'sha256' => $meta['sha256'], 'uploaded_at' => DB::raw('clock_timestamp()'),
                        'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
                    ]);
                }
                DB::table('order_shop_returns')->where('id', $return->id)->update(['sealed_at' => DB::raw('clock_timestamp()')]);

                return $return->fresh('evidence');
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                $this->evidence->delete($path);
            }
            throw $exception;
        }
    }

    private function assertSame(OrderShopReturn $return, array $facts, array $metadata): void
    {
        foreach ($facts as $key => $value) {
            $existing = in_array($key, ['returned_at', 'refunded_at'], true)
                ? $return->{$key}->utc()->format('Y-m-d H:i:s.uP') : (string) $return->{$key};
            if ($existing !== (string) $value) {
                throw ValidationException::withMessages(['request_key' => 'Return request key has different immutable facts.']);
            }
        }
        $existingEvidence = $return->evidence()->pluck('sha256', 'evidence_type')->sortKeys()->all();
        $requestedEvidence = collect($metadata)->mapWithKeys(fn ($meta, $type) => [$type => $meta['sha256']])->sortKeys()->all();
        if ($existingEvidence !== $requestedEvidence) {
            throw ValidationException::withMessages(['request_key' => 'Return request key has different evidence.']);
        }
    }
}
