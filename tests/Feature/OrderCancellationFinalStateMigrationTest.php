<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderCancellationFinalizationService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCaptainService;
use App\Services\OrderService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReturnService;
use App\Services\OrderShopService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\HistoricalOrderFixtures;
use Tests\TestCase;

class OrderCancellationFinalStateMigrationTest extends TestCase
{
    use HistoricalOrderFixtures;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function test_staggered_upgrade_rejects_standalone_final_history_without_repair(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture();
            $cancellationId = DB::table('order_cancellations')->insertGetId([
                'order_id' => $fixture['order']->id, 'classification' => 'AFTER_PURCHASE',
                'reason' => 'CUSTOMER_CANCELLED', 'cancelled_by' => $fixture['actor']->id,
                'cancelled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->assertNotSame('CANCELLED', DB::table('orders')->where('id', $fixture['order']->id)->value('operational_status'));

            try {
                $this->upgrade();
                $this->fail('Upgrade accepted standalone final cancellation history.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('HISTORY_STATUS_SPLIT', $exception->getMessage());
                $this->assertStringContainsString('order_id='.$fixture['order']->id, $exception->getMessage());
                $this->assertStringContainsString('intent_id='.$fixture['intent']->id, $exception->getMessage());
                $this->assertStringContainsString('cancellation_id='.$cancellationId, $exception->getMessage());
            }
            $this->assertDatabaseHas('order_cancellations', ['id' => $cancellationId]);
            $this->assertNotSame('CANCELLED', DB::table('orders')->where('id', $fixture['order']->id)->value('operational_status'));
        });
    }

    public function test_staggered_upgrade_preserves_valid_final_history(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture();
            app(OrderShopReturnService::class)->record($fixture['actor'], $fixture['order'], $fixture['shop'], [
                'returning_order_captain_id' => $fixture['assignment']->id, 'refund_amount' => '10.00',
                'returned_at' => now()->subMinutes(2)->toIso8601String(),
                'refunded_at' => now()->subMinute()->toIso8601String(), 'refund_method' => 'CASH',
                'request_key' => (string) Str::uuid(),
            ], ['RETURN_AND_REFUND_PROOF' => $this->image()]);
            $cancellation = app(OrderCancellationFinalizationService::class)->finalize($fixture['actor'], $fixture['order']);
            $history = DB::table('order_cancellations')->where('id', $cancellation->id)->first();

            $this->upgrade();

            $this->assertEquals($history, DB::table('order_cancellations')->where('id', $cancellation->id)->first());
            $this->assertSame('CANCELLED', DB::table('orders')->where('id', $fixture['order']->id)->value('operational_status'));
            $this->assertSame(1, DB::table('order_shop_returns')->where('order_id', $fixture['order']->id)->count());
        });
    }

    public function test_empty_staggered_upgrade_installs_both_final_state_triggers(): void
    {
        $this->withOldSchema(function (): void {
            $this->upgrade();
            $triggers = DB::select("SELECT tgname FROM pg_trigger WHERE tgrelid IN ('orders'::regclass, 'order_cancellations'::regclass) AND NOT tgisinternal");
            $names = array_column($triggers, 'tgname');
            $this->assertContains('phase9c2_order_final_check', $names);
            $this->assertContains('phase9c2_cancellation_final_check', $names);
            $this->assertContains('phase9c2_cancellation_immutable', $names);
            $this->assertNotContains('order_cancel_final_complete', $names);
        });
    }

    public function test_staggered_upgrade_preserves_legacy_cancellation_without_an_intent(): void
    {
        $this->withOldSchema(function (): void {
            $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
            $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
            $customer = Customer::factory()->create();
            $order = Order::create([
                'branch_id' => $branch->id, 'customer_id' => $customer->id,
                'order_number' => 'LEGACY-'.str()->random(8), 'ordered_at' => now(),
                'source' => 'MANUAL', 'entry_mode' => 'LIVE',
                'customer_name_snapshot' => $customer->name, 'customer_phone_snapshot' => '000',
                'area_name_snapshot' => 'Area', 'area_path_snapshot' => 'Area',
                'address_line_1_snapshot' => 'Street',
            ]);
            $cancellationId = DB::transaction(function () use ($order): int {
                $id = DB::table('order_cancellations')->insertGetId([
                    'order_id' => $order->id, 'classification' => 'BEFORE_PURCHASE',
                    'reason' => 'CUSTOMER_CANCELLED', 'cancelled_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('orders')->where('id', $order->id)->update(['operational_status' => 'CANCELLED']);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

                return $id;
            });

            $this->upgrade();

            $this->assertDatabaseHas('order_cancellations', ['id' => $cancellationId, 'order_id' => $order->id]);
            $this->assertDatabaseMissing('order_cancellation_intents', ['order_id' => $order->id]);
            $this->assertSame('CANCELLED', DB::table('orders')->where('id', $order->id)->value('operational_status'));
        });
    }

    public function test_receipt_ownership_upgrade_rejects_a_weak_window_reassignment_without_repair(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture(withReceipt: true, withUnfrozenShop: true);
            $receiptId = $fixture['receiptId'];
            $this->assertSame(1, DB::table('order_shop_receipts')->where('id', $receiptId)
                ->update(['order_shop_id' => $fixture['unfrozenShop']->id]));
            $this->assertSame($fixture['unfrozenShop']->id,
                (int) DB::table('order_shop_receipts')->where('id', $receiptId)->value('order_shop_id'));

            try {
                $this->upgradeReceiptOwnership();
                $this->fail('Upgrade accepted a moved receipt outside the frozen purchased-Shop set.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('RECEIPT_OUTSIDE_FROZEN_SET', $exception->getMessage());
                $this->assertStringContainsString('order_id='.$fixture['order']->id, $exception->getMessage());
                $this->assertStringContainsString('intent_id='.$fixture['intent']->id, $exception->getMessage());
                $this->assertStringContainsString('order_shop_id='.$fixture['unfrozenShop']->id, $exception->getMessage());
                $this->assertStringContainsString('receipt_id='.$receiptId, $exception->getMessage());
            }
            $this->assertSame($fixture['unfrozenShop']->id,
                (int) DB::table('order_shop_receipts')->where('id', $receiptId)->value('order_shop_id'));
            $this->assertSame(1, $fixture['intent']->requiredShops()->count());
        }, '2026_09_29_000200');
    }

    public function test_receipt_ownership_upgrade_accepts_valid_intent_history(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture(withReceipt: true, withUnfrozenShop: true);
            $this->upgradeReceiptOwnership();
            $this->assertSame($fixture['shop']->id,
                (int) DB::table('order_shop_receipts')->where('id', $fixture['receiptId'])->value('order_shop_id'));
            $this->assertSame(1, $fixture['intent']->requiredShops()->count());
        }, '2026_09_29_000200');
    }

    public function test_receipt_ownership_upgrade_accepts_historical_receipt_without_intent(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture(withReceipt: true, createIntent: false, withPurchase: false);
            $this->upgradeReceiptOwnership();
            $this->assertDatabaseHas('order_shop_receipts', [
                'id' => $fixture['receiptId'], 'order_shop_id' => $fixture['shop']->id,
            ]);
            $this->assertDatabaseMissing('order_cancellation_intents', ['order_id' => $fixture['order']->id]);
            $this->assertDatabaseMissing('order_shop_purchase_confirmations', ['order_shop_id' => $fixture['shop']->id]);
        }, '2026_09_29_000200');
    }

    public function test_empty_receipt_ownership_upgrade_installs_one_guard_each(): void
    {
        $this->withOldSchema(function (): void {
            $this->upgradeReceiptOwnership();
            $triggers = DB::select("SELECT tgname FROM pg_trigger WHERE tgrelid='order_shop_receipts'::regclass AND NOT tgisinternal");
            $names = array_column($triggers, 'tgname');
            $this->assertSame(1, count(array_filter($names, fn ($name) => $name === 'phase9c2_receipt_insert_guard')));
            $this->assertSame(1, count(array_filter($names, fn ($name) => $name === 'phase9c2_receipt_owner_immutable')));
            $this->assertSame(1, count(array_filter($names, fn ($name) => $name === 'phase9c2_receipt_intent_delete_guard')));
        }, '2026_09_29_000200');
    }

    private function fixture(
        bool $withReceipt = false,
        bool $withUnfrozenShop = false,
        bool $createIntent = true,
        bool $withPurchase = true,
    ): array {
        Storage::fake('order_returns');
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Migration customer']);
        $phone = $customer->phones()->create(['phone' => '963900001234', 'normalized_phone' => '963900001234']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Migration street']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $order = app(OrderService::class)->create($actor, [
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id,
            'source' => 'MANUAL', 'entry_mode' => 'LIVE',
        ]);
        $captainUser = User::factory()->create();
        $captain = Captain::create([
            'user_id' => $captainUser->id, 'home_branch_id' => $branch->id,
            'operational_status' => CaptainOperationalStatus::Available,
        ]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $assignment = app(OrderCaptainService::class)->assign($actor, $order, $captain, OrderCaptainRole::PrimaryCollection);
        $shop = Schema::hasColumn('order_shops', 'origin_area_id')
            ? app(OrderShopService::class)->add($actor, $order, ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Purchased Shop'])
            : $this->historicalShop($order, 'Purchased Shop');
        if ($withPurchase) {
            app(OrderShopPurchaseConfirmationService::class)->record($actor, $order, $shop, [
                'order_captain_id' => $assignment->id, 'currency_id' => $currency->id,
                'amount' => '10.00', 'purchased_at' => now()->subMinutes(5)->toIso8601String(),
                'request_key' => (string) Str::uuid(),
            ], $this->image());
        }
        $unfrozenShop = $withUnfrozenShop ? (Schema::hasColumn('order_shops', 'origin_area_id')
            ? app(OrderShopService::class)->add($actor, $order, ['shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Unfrozen Shop'])
            : $this->historicalShop($order, 'Unfrozen Shop')) : null;
        $receiptId = $withReceipt ? DB::table('order_shop_receipts')->insertGetId([
            'order_shop_id' => $shop->id, 'storage_path' => 'migration/'.Str::uuid().'.png',
            'original_filename' => 'receipt.png', 'mime_type' => 'image/png', 'file_size' => 68,
            'uploaded_by' => $actor->id, 'uploaded_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3),
        ]) : null;
        $intent = $createIntent ? app(OrderCancellationIntentService::class)->create($actor, $order,
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid()) : null;

        return compact('actor', 'order', 'shop', 'assignment', 'intent', 'unfrozenShop', 'receiptId');
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG));
    }

    private function upgrade(): void
    {
        DB::transaction(fn () => (require database_path('migrations/2026_09_29_000100_harden_order_cancellation_final_state.php'))->up());
    }

    private function upgradeReceiptOwnership(): void
    {
        DB::transaction(fn () => (require database_path('migrations/2026_09_29_000200_harden_order_shop_receipt_ownership.php'))->up());
    }

    private function withOldSchema(callable $callback, string $cutoff = '2026_09_29_000100'): void
    {
        $name = (string) config('database.default');
        $key = "database.connections.{$name}.search_path";
        $original = (string) config($key);
        $schema = 'order_final_upgrade_'.str()->lower(str()->random(10));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($name);
            DB::reconnect($name);
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (basename($path) < $cutoff) {
                    if (basename($path) === '2026_09_29_000100_harden_order_cancellation_final_state.php') {
                        DB::transaction(fn () => (require $path)->up());
                    } else {
                        (require $path)->up();
                    }
                }
            }
            $callback();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge($name);
            config([$key => $original]);
            DB::reconnect($name);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }
}
