<?php

namespace Tests\Support;

use App\Enums\OrderOperationalStatus;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopProduct;
use App\Models\Unit;
use App\Services\InvoiceService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderPricingService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderShopPurchaseService;
use App\Services\ShopCommissionActivationService;
use App\Services\ShopCommissionDraftService;
use App\Services\ShopPartnershipService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait ShopCommissionFixtures
{
    use SharedPurchaseFixtures;

    protected function commissionOrder(int $count = 1, string $method = 'PERCENTAGE', string $value = '10', int $places = 2, string $quantity = '1', string $price = '80', bool $active = true, ?callable $beforeIssue = null): array
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext($places, $count);
        $master = Shop::create(['name' => 'Commission Shop', 'area_id' => $c['shop']->origin_area_id, 'business_classification' => 'SHOP']);
        $unit = Unit::create(['name' => 'Kilogram', 'code' => str()->uuid(), 'allows_decimal' => true]);
        $product = Product::create(['name' => 'Commission Product', 'default_unit_id' => $unit->id]);
        ShopProduct::create(['shop_id' => $master->id, 'product_id' => $product->id, 'unit_id' => $unit->id]);
        $c['shop']->update(['shop_type' => 'REGISTERED', 'shop_id' => $master->id]);
        $c['shop']->items()->sole()->update(['item_type' => 'CATALOG_ITEM', 'product_id' => $product->id, 'unit_id' => $unit->id, 'requested_quantity' => $quantity, 'current_quantity' => $quantity, 'actual_unit_price' => $price]);
        app(OrderPricingService::class)->recalculate($c['order']);
        $partnership = app(ShopPartnershipService::class)->start($c['admin'], $master, ['starts_at' => now()->subDays(2), 'status' => 'ACTIVE', 'mobile_enabled' => false, 'online_orders_enabled' => false]);
        $rule = app(ShopCommissionDraftService::class)->create($c['admin'], $master, $partnership, ['product_id' => $product->id,
            'currency_id' => $c['currency']->id, 'terms' => 'Approved UAT agreement', 'method' => $method, 'commission_value' => $value, 'effective_from' => now()->subDay()->toIso8601String()]);
        if ($active) {
            app(ShopCommissionActivationService::class)->change($c['admin'], $master, $partnership, $rule->id, true, 'Explicit activation');
        }
        $c += compact('master', 'partnership', 'product', 'unit', 'rule');
        if ($beforeIssue) {
            $beforeIssue($c);
        }
        if ($count === 2) {
            app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
        }
        foreach ($c['order']->shops()->get() as $orderShop) {
            $amount = $orderShop->items()->get()->reduce(fn (BigDecimal $sum, $item) => $sum->plus(BigDecimal::of($item->current_quantity)->multipliedBy($item->actual_unit_price)->toScale($places, RoundingMode::HalfUp)), BigDecimal::zero());
            $purchase = $count === 2 ? SharedOrderShopPurchaseService::class : OrderShopPurchaseConfirmationService::class;
            app($purchase)->record($c['actor'], $c['order'], $orderShop, $this->sharedData($c, (string) $amount) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
            app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $orderShop, $this->sharedImage(), null);
            app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $orderShop, 'picked_up_at');
        }
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($c['actor'], $c['order'], $invoices->refresh($c['actor'], $c['order'], $invoices->createDraft($c['actor'], $c['order'])));

        return $c + compact('invoice');
    }

    protected function deliverCommissionOrder(array $c): void
    {
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
    }
}
