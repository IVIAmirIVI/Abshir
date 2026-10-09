<?php

namespace Tests\Support;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderPricingClass;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Http\UploadedFile;

trait SharedPurchaseFixtures
{
    protected function sharedContext(int $places = 2, int $count = 2): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => strtoupper(str()->random(3)), 'name' => 'Test Currency', 'decimal_places' => $places]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create();
        $to = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Shared purchase fixture']);
        $phone = $customer->phones()->create(['phone' => '0912345678', 'normalized_phone' => '0912345678']);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Test']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $order = app(OrderService::class)->create($actor, ['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime,
            'business_classification_snapshot' => ShopBusinessClassification::Shop,
            'shop_name_snapshot' => 'Test shop', 'origin_area_id' => $from->id, 'sort_order' => 1]);
        $order->update(['pricing_class' => OrderPricingClass::SingleShop, 'ordered_at' => now()->subHour()]);
        OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime,
            'product_name_snapshot' => 'Test item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1', 'current_quantity' => '1',
            'actual_unit_price' => '80', 'actual_price_source' => ActualPriceSource::ManualVerified,
            'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $actor->id]);
        app(OrderPricingService::class)->recalculate($order);
        $assignments = [];
        $captains = [];
        for ($index = 0; $index < $count; $index++) {
            $captain = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $branch->id,
                'operational_status' => CaptainOperationalStatus::Available]);
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
            $assignments[] = app(OrderCaptainService::class)->assign($actor, $order, $captain,
                $index === 0 ? OrderCaptainRole::PrimaryCollection : OrderCaptainRole::Support);
            $assignments[$index]->update(['assigned_at' => now()->subMinutes(10)]);
            $captains[] = $captain;
        }
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id,
            'currency_id' => $currency->id, 'order_pricing_class' => OrderPricingClass::SingleShop->value,
            'delivery_unit_fee' => '10', 'effective_from' => now()->subDay()]);
        app(DeliveryPricingSettings::class)->update($admin, '50', '0');
        app(OrderDeliveryPricingService::class)->price($actor, $order, $from);

        return compact('currency', 'branch', 'actor', 'admin', 'order', 'shop', 'assignments', 'captains');
    }

    protected function sharedData(array $c, string $amount = '80'): array
    {
        return ['currency_id' => $c['currency']->id, 'amount' => $amount, 'purchased_at' => now()->subMinute()->format('Y-m-d H:i:s.uP'),
            'request_key' => (string) str()->uuid()];
    }

    protected function sharedImage(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
