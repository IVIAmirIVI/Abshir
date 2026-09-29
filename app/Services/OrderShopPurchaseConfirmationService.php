<?php

namespace App\Services;

use App\Exceptions\FinancialLedgerException;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\OrderShop;
use App\Models\OrderShopPurchaseConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderShopPurchaseConfirmationService
{
    public function __construct(
        private readonly FinancialPostingService $posting,
        private readonly OrderReturnEvidenceService $evidence,
        private readonly OrderMutationGuard $guard,
    ) {}

    public function record(User $actor, Order $order, OrderShop $shop, array $data, UploadedFile $file): OrderShopPurchaseConfirmation
    {
        Gate::forUser($actor)->authorize('order_shop_purchases.confirm');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($shop->order_id !== $order->id, 404);
        Validator::make($data, [
            'order_captain_id' => ['required', 'integer'], 'currency_id' => ['required', 'integer'],
            'amount' => ['required', 'string'], 'purchased_at' => ['required', 'date'],
            'request_key' => ['required', 'uuid'], 'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ])->validate();
        $metadata = $this->evidence->inspect($file);
        $purchasedAt = CarbonImmutable::parse($data['purchased_at'])->utc();
        if ($purchasedAt->isFuture()) {
            throw ValidationException::withMessages(['purchased_at' => 'Purchase time cannot be in the future.']);
        }
        $path = null;
        try {
            $confirmation = DB::transaction(function () use ($actor, $order, $shop, $data, $file, $metadata, $purchasedAt, &$path): OrderShopPurchaseConfirmation {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                $shop = OrderShop::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($shop->id);
                $assignment = OrderCaptain::query()->where('order_id', $order->id)->findOrFail($data['order_captain_id']);
                $currency = Currency::query()->lockForUpdate()->findOrFail($data['currency_id']);
                try {
                    $amount = $this->posting->validatePlannedAmount($currency, $data['amount']);
                } catch (FinancialLedgerException $exception) {
                    throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
                }
                $facts = [
                    'order_id' => $order->id, 'order_shop_id' => $shop->id, 'order_captain_id' => $assignment->id,
                    'branch_id' => $order->branch_id, 'currency_id' => $currency->id,
                    'amount' => $amount, 'purchased_at' => $purchasedAt->format('Y-m-d H:i:s.uP'),
                    'recorded_by' => $actor->id, 'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'evidence_sha256' => $metadata['sha256'], 'evidence_mime' => $metadata['mime'],
                    'evidence_size' => $metadata['size'],
                ];
                $existing = OrderShopPurchaseConfirmation::query()->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    $this->assertSame($existing, $facts);

                    return $existing;
                }
                $this->guard->assertOperationalEvidenceMutableLocked($order);
                if (OrderShopPurchaseConfirmation::query()->where('order_shop_id', $shop->id)->exists()) {
                    throw ValidationException::withMessages(['purchase' => 'An authoritative purchase already exists for this Shop.']);
                }
                $path = $this->evidence->store($file, $metadata);

                $id = DB::table('order_shop_purchase_confirmations')->insertGetId($facts + [
                    'request_key' => $data['request_key'], 'evidence_path' => $path, 'evidence_name' => $metadata['name'],
                    'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
                ]);

                return OrderShopPurchaseConfirmation::query()->findOrFail($id);
            });

            return $confirmation;
        } catch (\Throwable $exception) {
            if ($path) {
                $this->evidence->delete($path);
            }
            throw $exception;
        }
    }

    private function assertSame(OrderShopPurchaseConfirmation $confirmation, array $facts): void
    {
        foreach ($facts as $key => $value) {
            $existing = $key === 'purchased_at' ? $confirmation->purchased_at->utc()->format('Y-m-d H:i:s.uP') : (string) $confirmation->{$key};
            if ($existing !== (string) $value) {
                throw ValidationException::withMessages(['request_key' => 'Purchase request key has different immutable '.$key.' or evidence.']);
            }
        }
    }
}
