<?php

namespace App\Services;

use App\Enums\OrderCancellationClassification;
use App\Enums\OrderOperationalStatus;
use App\Models\Order;
use App\Models\OrderShop;
use App\Models\OrderShopReceipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderShopReceiptService
{
    private const DISK = 'order_receipts';

    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly OrderMutationGuard $mutationGuard) {}

    public function upload(User $actor, Order $order, OrderShop $orderShop, UploadedFile $file, ?string $note): OrderShopReceipt
    {
        Gate::forUser($actor)->authorize('order_shop_receipts.upload');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($orderShop->order_id !== $order->id, 404);
        $mime = (string) $file->getMimeType();
        if (! $file->isValid() || ! isset(self::MIME_EXTENSIONS[$mime])) {
            throw ValidationException::withMessages(['receipt' => 'Receipt evidence must be a JPEG, PNG, or WebP image.']);
        }
        if (($file->getSize() ?? 0) > self::MAX_FILE_SIZE) {
            throw ValidationException::withMessages(['receipt' => 'Receipt evidence may not exceed 5 MB.']);
        }

        return DB::transaction(function () use ($actor, $order, $orderShop, $file, $note, $mime): OrderShopReceipt {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $order->load('cancellation');
            if ($order->operational_status === OrderOperationalStatus::Cancelled) {
                if ($order->cancellation?->classification !== OrderCancellationClassification::AfterPurchase) {
                    throw ValidationException::withMessages(['receipt' => 'Receipt evidence may only be added after an AFTER_PURCHASE cancellation.']);
                }
            } else {
                $this->mutationGuard->assertOperationalEvidenceMutableLocked($order);
            }
            $orderShop = OrderShop::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($orderShop->id);
            $filename = Str::uuid().'.'.self::MIME_EXTENSIONS[$mime];
            $path = $file->storeAs($order->id.'/'.$orderShop->id, $filename, self::DISK);
            if (! $path) {
                throw ValidationException::withMessages(['receipt' => 'Receipt evidence could not be stored.']);
            }

            try {
                return $orderShop->receipts()->create([
                    'storage_path' => $path,
                    'original_filename' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'mime_type' => $mime,
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                    'note' => $note,
                ]);
            } catch (\Throwable $exception) {
                Storage::disk(self::DISK)->delete($path);
                throw $exception;
            }
        });
    }

    public function authorizeView(User $actor, Order $order, OrderShop $orderShop, OrderShopReceipt $receipt): void
    {
        Gate::forUser($actor)->authorize('order_shop_receipts.view');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);
        abort_if($orderShop->order_id !== $order->id || $receipt->order_shop_id !== $orderShop->id, 404);
    }

    public function disk(): string
    {
        return self::DISK;
    }
}
