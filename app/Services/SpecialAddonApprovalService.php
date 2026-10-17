<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SpecialAddonApprovalService
{
    public function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('orders.update');
        Gate::forUser($actor)->authorize('delivery_pricing.apply');
        abort_unless($actor->isSuperAdmin() || $actor->roles()->where('code', 'ORDER_EMPLOYEE')->exists(), 403);
    }

    public function approve(User $actor, Order $order, OrderShop $shop, OrderItem $source, string $reason): int
    {
        $this->authorize($actor);
        app(OrderService::class)->assertAccess($actor, $order);
        if (! Schema::hasTable('order_special_addon_approvals')) {
            $this->invalid();
        }

        return DB::transaction(function () use ($actor, $order, $shop, $source, $reason): int {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            app(OrderMutationGuard::class)->assertMutableLocked($order);
            abort_unless($shop->order_id === $order->id && $source->orderShop->order_id === $order->id, 404);
            if (trim($reason) === '' || mb_strlen($reason) > 1000 || ! $shop->items()->exists() || $shop->items()->whereNotNull('special_delivery_policy_id')->exists()) {
                $this->invalid();
            }
            $facts = DB::scalar('SELECT special_addon_facts(?,?)', [$shop->id, $source->id]);
            if ($facts === null) {
                $this->invalid();
            }
            $existing = DB::table('order_special_addon_approvals')->where('order_shop_id', $shop->id)
                ->where('source_order_item_id', $source->id)->whereRaw('facts=?::jsonb', [$facts])->value('id');

            return $existing ?? DB::table('order_special_addon_approvals')->insertGetId([
                'order_id' => $order->id, 'order_shop_id' => $shop->id, 'source_order_item_id' => $source->id,
                'approved_by' => $actor->id, 'approved_at' => now(), 'reason' => trim($reason), 'facts' => $facts,
            ]);
        });
    }

    public function ids(Order $order): array
    {
        if (! Schema::hasTable('order_special_addon_approvals')) {
            if ($order->shops()->count() > 1) {
                $this->invalid();
            }

            return [];
        }
        try {
            return DB::transaction(fn () => json_decode(DB::scalar('SELECT special_addon_approval_ids(?)', [$order->id]), true, flags: JSON_THROW_ON_ERROR));
        } catch (QueryException) {
            $this->invalid();
        }
    }

    public function draftStamp(array $groups, string $key, string $sourceKey, string $branchId, string $addressId): string
    {
        return hash('sha256', json_encode([$groups, $key, $sourceKey, $branchId, $addressId], JSON_THROW_ON_ERROR));
    }

    /** Returns the sole special source Shop key; every other group needs an explicit current approval. */
    public function validateDraft(User $actor, array $groups, array $sources, array $approvals, string $branchId, string $addressId): string|int|null
    {
        if ($sources === [] || count($groups) <= 1) {
            return null;
        }
        $this->authorize($actor);
        $sourceKeys = array_column($sources, 'key');
        $shops = array_unique(array_column($sources, 'shop_key'));
        if (count($shops) !== 1 || ! Schema::hasTable('order_special_addon_approvals')) {
            $this->invalid();
        }
        $anchor = reset($shops);
        foreach ($groups as $key => $group) {
            if ((string) $key === (string) $anchor) {
                continue;
            }
            $approval = $approvals[$key] ?? [];
            if (! in_array($approval['source_key'] ?? null, $sourceKeys, true) || trim($approval['reason'] ?? '') === ''
                || ($approval['stamp'] ?? '') !== $this->draftStamp($groups, (string) $key, $approval['source_key'], $branchId, $addressId)) {
                $this->invalid();
            }
        }

        return $anchor;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['special_delivery' => __('special.addon_required')]);
    }
}
