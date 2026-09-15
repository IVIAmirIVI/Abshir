<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ProductImageService
{
    public function add(User $actor, Product $product, UploadedFile $file, array $data): ProductImage
    {
        Gate::forUser($actor)->authorize('products.update');
        $path = $file->store("products/{$product->id}", 'public');

        try {
            return DB::transaction(function () use ($product, $path, $data): ProductImage {
                Product::query()->lockForUpdate()->findOrFail($product->id);
                $primary = (bool) ($data['is_primary'] ?? false) || ! $product->images()->exists();
                if ($primary) {
                    $product->images()->update(['is_primary' => false]);
                }

                $image = $product->images()->make([
                    'storage_path' => $path,
                    'alt_text' => $data['alt_text'] ?? null,
                    'sort_order' => $data['sort_order'] ?? 0,
                ]);
                $image->is_primary = $primary;
                $image->save();

                return $image;
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
    }

    public function makePrimary(User $actor, Product $product, ProductImage $image): void
    {
        Gate::forUser($actor)->authorize('products.update');
        abort_if($image->product_id !== $product->id, 404);
        DB::transaction(function () use ($product, $image): void {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $product->images()->update(['is_primary' => false]);
            $image->is_primary = true;
            $image->save();
        });
    }

    public function remove(User $actor, Product $product, ProductImage $image): void
    {
        Gate::forUser($actor)->authorize('products.update');
        abort_if($image->product_id !== $product->id, 404);

        $path = $image->storage_path;
        DB::transaction(function () use ($product, $image): void {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $wasPrimary = $image->is_primary;
            $image->delete();
            if ($wasPrimary) {
                $replacement = $product->images()->first();
                if ($replacement) {
                    $replacement->is_primary = true;
                    $replacement->save();
                }
            }
        });
        Storage::disk('public')->delete($path);
    }
}
