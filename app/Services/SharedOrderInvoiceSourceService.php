<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal source reconciliation. Call only while holding the authoritative Order lock. */
class SharedOrderInvoiceSourceService
{
    public function fundingLocked(Order $order, int $currencyId, int $pricingId, string $subtotal): array
    {
        if (! $order->purchaseConfirmations()->where('purchase_mode', SharedPurchaseBoundary::MODE)->exists()) {
            throw ValidationException::withMessages(['purchase' => __('shared_orders.complete_purchases_required')]);
        }
        try {
            DB::select('SELECT assert_shared_order_invoice_inputs(?, ?, ?, CAST(? AS numeric))', [$order->id, $currencyId, $pricingId, $subtotal]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23514') {
                throw $exception;
            }
            throw ValidationException::withMessages(['purchase' => __('shared_orders.source_mismatch')]);
        }

        return DB::table('order_shop_purchase_allocations as a')
            ->join('order_shop_purchase_confirmations as p', 'p.id', '=', 'a.purchase_confirmation_id')
            ->where('p.order_id', $order->id)->where('p.purchase_mode', SharedPurchaseBoundary::MODE)
            ->groupBy('a.order_captain_id')->selectRaw('a.order_captain_id, sum(a.amount)::text AS amount')
            ->pluck('amount', 'order_captain_id')->all();
    }
}
