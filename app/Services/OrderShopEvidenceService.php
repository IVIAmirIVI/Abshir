<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShop;
use App\Models\OrderShopPurchaseConfirmation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Compose existing evidence actions; never infer pickup or a financial event. */
class OrderShopEvidenceService
{
    public function __construct(
        private readonly OrderShopPurchaseConfirmationService $purchases,
        private readonly OrderShopReceiptService $receipts,
        private readonly OrderReturnEvidenceService $evidence,
    ) {}

    public function record(User $actor, Order $order, OrderShop $shop, array $data, UploadedFile $file): OrderShopPurchaseConfirmation
    {
        Gate::forUser($actor)->authorize('order_shop_purchases.confirm');
        Gate::forUser($actor)->authorize('order_shop_receipts.upload');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($shop->order_id !== $order->id, 404);
        if (! filter_var($data['also_shop_receipt'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['also_shop_receipt' => __('order_execution.dual_confirmation')]);
        }
        $purchasePath = $receiptPath = null;
        try {
            return DB::transaction(function () use ($actor, $order, $shop, $data, $file, &$purchasePath, &$receiptPath) {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                $shop = $order->shops()->lockForUpdate()->findOrFail($shop->id);
                $existing = OrderShopPurchaseConfirmation::where('request_key', $data['request_key'] ?? null)->exists();
                $purchase = $this->purchases->record($actor, $order, $shop, $data, $file);
                if (! $existing) {
                    $purchasePath = $purchase->evidence_path;
                }
                // The same request can be retried without duplicating a receipt.
                // Compare bytes only within this authorized nested Shop, under its Order lock.
                $sameReceipt = $shop->receipts()->get()->contains(function ($receipt) use ($purchase) {
                    if ($receipt->mime_type !== $purchase->evidence_mime || $receipt->file_size !== $purchase->evidence_size) {
                        return false;
                    }
                    $disk = Storage::disk($this->receipts->disk());
                    if (! $disk->exists($receipt->storage_path)) {
                        return false;
                    }

                    return hash_equals($purchase->evidence_sha256, hash('sha256', $disk->get($receipt->storage_path)));
                });
                if (! $sameReceipt) {
                    $receipt = $this->receipts->upload($actor, $order, $shop, $file, $data['note'] ?? null);
                    $receiptPath = $receipt->storage_path;
                }

                return $purchase;
            });
        } catch (\Throwable $exception) {
            // Filesystems do not roll back with PostgreSQL, including deferred commit failures.
            if ($receiptPath) {
                Storage::disk($this->receipts->disk())->delete($receiptPath);
            }
            if ($purchasePath) {
                $this->evidence->delete($purchasePath);
            }
            throw $exception;
        }
    }
}
