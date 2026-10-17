<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCaptainSelection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SharedPurchaseBoundary
{
    public const MODE = 'SHARED_TWO_V1';

    public function hasSelectedSharedContract(Order $order): bool
    {
        return Schema::hasTable('order_captain_selections')
            && OrderCaptainSelection::where('order_id', $order->id)->latest('id')->value('contract') === 'SHARED_COMPANY_V1';
    }

    /** Selection alone must not silently invoke the older two-Captain CASH accounting contract. */
    public function financialProblem(Order $order): ?string
    {
        return $this->hasSelectedSharedContract($order) ? __('shared_orders.pending_finance') : $this->problem($order);
    }

    public function operationalProblem(Order $order): ?string
    {
        return $this->hasSelectedSharedContract($order) ? null : $this->problem($order);
    }

    public function problem(Order $order): ?string
    {
        if (Schema::hasTable('order_shop_purchase_allocations')
            && $order->purchaseConfirmations()->where('purchase_mode', self::MODE)->exists()) {
            return __('shared_orders.shared_return_blocked');
        }

        return null;
    }

    /** The caller must hold the Order lock, before reading dependent financial facts. */
    public function assertLegacyFlowAllowedLocked(Order $order): void
    {
        if ($problem = $this->problem($order)) {
            throw ValidationException::withMessages(['order' => $problem]);
        }
    }
}
