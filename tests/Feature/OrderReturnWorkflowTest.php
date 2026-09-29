<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\OrderShopPurchaseConfirmation;
use App\Models\Role;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\OrderCancellationFinalizationService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCaptainService;
use App\Services\OrderService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReturnService;
use App\Services\OrderShopService;
use App\Services\OrderStatusService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function context(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Return customer']);
        $phone = $customer->phones()->create(['phone' => '963900009902', 'normalized_phone' => '963900009902']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Return street']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $order = app(OrderService::class)->create($actor, ['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id,
            'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $captainUser = User::factory()->create();
        $captain = Captain::create(['user_id' => $captainUser->id, 'home_branch_id' => $branch->id,
            'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $assignment = app(OrderCaptainService::class)->assign($actor, $order, $captain, OrderCaptainRole::PrimaryCollection);

        return compact('currency', 'branch', 'actor', 'order', 'assignment', 'captain');
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG));
    }

    public function test_two_shop_returns_freeze_order_and_final_cancellation_has_no_ledger_effect(): void
    {
        Storage::fake('order_returns');
        $c = $this->context();
        $makeSupport = function () use ($c) {
            $user = User::factory()->create();
            $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $c['branch']->id,
                'operational_status' => CaptainOperationalStatus::Available]);
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

            return app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $captain, OrderCaptainRole::Support);
        };
        $secondAssignment = $makeSupport();
        $thirdAssignment = $makeSupport();
        $shops = collect(['First', 'Second'])->map(fn ($name) => app(OrderShopService::class)->add($c['actor'], $c['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => $name,
        ]));
        foreach ($shops as $index => $shop) {
            $purchase = app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $shop, [
                'order_captain_id' => $index ? $secondAssignment->id : $c['assignment']->id,
                'currency_id' => $c['currency']->id,
                'amount' => $index ? '25.00' : '40.00', 'purchased_at' => now()->subMinutes(5)->toIso8601String(),
                'request_key' => (string) Str::uuid(),
            ], $this->image());
            $this->assertSame($index ? $secondAssignment->id : $c['assignment']->id, $purchase->order_captain_id);
        }
        $feeRuleId = DB::table('bill_payment_fee_rules')->insertGetId([
            'currency_id' => $c['currency']->id, 'fixed_fee' => '1.00',
            'effective_from' => DB::raw("clock_timestamp() - interval '1 hour'"),
            'created_by' => $c['actor']->id, 'created_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $pendingId = DB::table('order_bill_payment_requests')->insertGetId([
            'order_id' => $c['order']->id, 'currency_id' => $c['currency']->id,
            'fee_rule_id' => $feeRuleId, 'service_fee' => '1.00', 'status' => 'REQUESTED',
            'requested_by' => $c['actor']->id, 'requested_at' => DB::raw('clock_timestamp()'),
            'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
        ]);
        $intent = app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'], OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $this->assertCount(2, $intent->requiredShops);
        $this->assertNotSame(OrderOperationalStatus::Cancelled, $c['order']->fresh()->operational_status);
        $this->assertDatabaseHas('order_bill_payment_requests', ['id' => $pendingId, 'status' => 'CANCELLED',
            'cancellation_reason' => 'ORDER_CANCELLATION_INTENT #'.$intent->id]);
        try {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::CaptainAssigned, null);
            $this->fail('Intent permitted normal Order advancement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        try {
            app(OrderShopReturnService::class)->record($c['actor'], $c['order'], $shops->first(), [
                'returning_order_captain_id' => $thirdAssignment->id, 'refund_amount' => '40.00',
                'returned_at' => $shops->first()->purchaseConfirmation->purchased_at->subSecond()->toIso8601String(),
                'refunded_at' => now()->toIso8601String(), 'refund_method' => 'CASH',
                'request_key' => (string) Str::uuid(),
            ], ['RETURN_AND_REFUND_PROOF' => $this->image()]);
            $this->fail('Return was recorded before the verified purchase.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('returned_at', $exception->errors());
        }
        foreach ($shops as $index => $shop) {
            $returnData = [
                'returning_order_captain_id' => $index ? $secondAssignment->id : $thirdAssignment->id,
                'refund_amount' => $index ? '25.00' : '40.00',
                'returned_at' => now()->subMinutes(2)->toIso8601String(),
                'refunded_at' => now()->subMinute()->toIso8601String(),
                'refund_method' => 'CASH', 'shortfall_reason' => null,
                'shortfall_acknowledged' => false,
                'request_key' => (string) Str::uuid(),
            ];
            $return = app(OrderShopReturnService::class)->record($c['actor'], $c['order'], $shop,
                $returnData, ['RETURN_AND_REFUND_PROOF' => $this->image()]);
            $this->assertSame($index ? $secondAssignment->id : $c['assignment']->id, $return->refund_recipient_order_captain_id);
            if ($index === 0) {
                try {
                    app(OrderCancellationFinalizationService::class)->finalize($c['actor'], $c['order']);
                    $this->fail('A missing Shop return allowed cancellation.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('returns', $exception->errors());
                }
            }
            $lastShop = $shop;
            $lastReturn = $return;
            $lastReturnData = $returnData;
        }
        app(OrderCancellationFinalizationService::class)->finalize($c['actor'], $c['order']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(OrderOperationalStatus::Cancelled, $c['order']->fresh()->operational_status);
        $this->assertSame(0, DB::table('financial_transactions')->count());
        $retry = app(OrderShopReturnService::class)->record($c['actor'], $c['order'], $lastShop,
            $lastReturnData, ['RETURN_AND_REFUND_PROOF' => $this->image()]);
        $this->assertSame($lastReturn->id, $retry->id);
        $proof = $lastReturn->evidence()->firstOrFail();
        $this->actingAs($c['actor'])->get(route('orders.shops.returns.evidence', [$c['order'], $lastShop, $lastReturn, $proof]))->assertOk();
        $this->get(route('orders.shops.returns.evidence', [$c['order'], $shops->first(), $lastReturn, $proof]))->assertNotFound();
    }

    public function test_purchase_http_nested_auth_private_evidence_idempotency_and_sql_immutability(): void
    {
        $privateRoot = str_replace('\\', '/', (string) config('filesystems.disks.order_returns.root'));
        $localRoot = str_replace('\\', '/', (string) config('filesystems.disks.local.root'));
        $publicRoot = str_replace('\\', '/', (string) config('filesystems.disks.public.root'));
        $this->assertFalse((bool) config('filesystems.disks.order_returns.serve'));
        $this->assertFalse(str_starts_with(strtolower($privateRoot).'/', strtolower($localRoot).'/'));
        $this->assertFalse(str_starts_with(strtolower($privateRoot).'/', strtolower($publicRoot).'/'));
        Storage::fake('order_returns');
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Private purchased Shop',
        ]);
        $otherShop = app(OrderShopService::class)->add($c['actor'], $c['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Other Shop',
        ]);
        $key = (string) Str::uuid();
        $when = now()->subMinute()->toIso8601String();
        $payload = ['order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id,
            'amount' => '12.50', 'purchased_at' => $when, 'request_key' => $key];
        $this->actingAs($c['actor'])->post(route('orders.shops.purchases.store', [$c['order'], $shop]),
            $payload + ['evidence' => $this->image()])->assertRedirect();
        $purchase = OrderShopPurchaseConfirmation::where('order_shop_id', $shop->id)->sole();
        $this->get(route('orders.shops.purchases.evidence', [$c['order'], $shop, $purchase]))->assertOk();
        $this->get(route('orders.shops.purchases.evidence', [$c['order'], $otherShop, $purchase]))->assertNotFound();

        $same = app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $shop, $payload, $this->image());
        $this->assertSame($purchase->id, $same->id);
        $this->assertSame(1, OrderShopPurchaseConfirmation::count());
        $this->actingAs($c['actor'])->post(route('orders.shops.purchases.store', [$c['order'], $shop]),
            array_replace($payload, ['amount' => '13.00']) + ['evidence' => $this->image()])->assertSessionHasErrors('request_key');
        try {
            DB::transaction(fn () => DB::table('order_shop_purchase_confirmations')->where('id', $purchase->id)->update(['amount' => '13.00']));
            $this->fail('Direct SQL changed an immutable purchase.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }
        $outsider = User::factory()->create();
        $outsider->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $outsider->branches()->attach(Branch::factory()->create());
        $this->actingAs($outsider)->get(route('orders.shops.purchases.evidence', [$c['order'], $shop, $purchase]))->assertForbidden();
        $this->post(route('orders.shops.purchases.store', [$c['order'], $shop]), $payload + ['evidence' => $this->image()])->assertForbidden();
    }

    public function test_exceptional_shortfall_requires_reason_and_confirmation_without_ledger_entry(): void
    {
        Storage::fake('order_returns');
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Shortfall Shop',
        ]);
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $shop, [
            'order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id,
            'amount' => '40.00', 'purchased_at' => now()->subMinutes(5)->toIso8601String(),
            'request_key' => (string) Str::uuid(),
        ], $this->image());
        app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'],
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $data = ['returning_order_captain_id' => $c['assignment']->id,
            'returned_at' => now()->subMinutes(2)->toIso8601String(),
            'refunded_at' => now()->subMinute()->toIso8601String(),
            'refund_method' => 'CASH', 'request_key' => (string) Str::uuid()];
        foreach ([
            ['refund_amount' => '0'], ['refund_amount' => '41.00'],
            ['refund_amount' => '39.00'],
            ['refund_amount' => '39.00', 'shortfall_reason' => 'One dollar retained'],
        ] as $change) {
            try {
                app(OrderShopReturnService::class)->record($c['actor'], $c['order'], $shop,
                    array_replace($data, $change, ['request_key' => (string) Str::uuid()]),
                    ['RETURN_AND_REFUND_PROOF' => $this->image()]);
                $this->fail('An invalid or unacknowledged refund was completed.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $return = app(OrderShopReturnService::class)->record($c['actor'], $c['order'], $shop,
            array_replace($data, ['refund_amount' => '39.00', 'shortfall_reason' => 'One dollar retained',
                'shortfall_acknowledged' => true]), ['RETURN_AND_REFUND_PROOF' => $this->image()]);
        $this->assertSame('1.0000', $return->shortfallAmount());
        app(OrderCancellationFinalizationService::class)->finalize($c['actor'], $c['order']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(OrderOperationalStatus::Cancelled, $c['order']->fresh()->operational_status);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_postgresql_rejects_direct_final_cancellation_before_all_returns(): void
    {
        Storage::fake('order_returns');
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Unreturned Shop',
        ]);
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $shop, [
            'order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id,
            'amount' => '12.50', 'purchased_at' => now()->subMinute()->toIso8601String(),
            'request_key' => (string) Str::uuid(),
        ], $this->image());
        app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'],
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        try {
            DB::transaction(function () use ($c): void {
                DB::table('order_cancellations')->insert([
                    'order_id' => $c['order']->id, 'classification' => 'AFTER_PURCHASE',
                    'reason' => 'CUSTOMER_CANCELLED', 'cancelled_by' => $c['actor']->id,
                    'cancelled_at' => DB::raw('clock_timestamp()'), 'created_at' => DB::raw('clock_timestamp()'),
                    'updated_at' => DB::raw('clock_timestamp()'),
                ]);
                DB::table('orders')->where('id', $c['order']->id)->update(['operational_status' => 'CANCELLED']);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Direct SQL finalized an Order without its required return.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('final cancellation incomplete', $exception->getMessage());
        }
        $this->assertDatabaseMissing('order_cancellations', ['order_id' => $c['order']->id]);
        $this->assertNotSame(OrderOperationalStatus::Cancelled, $c['order']->fresh()->operational_status);
    }

    public function test_postgresql_rejects_invalid_purchase_intent_and_return_sources(): void
    {
        Storage::fake('order_returns');
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Purchased',
        ]);
        $otherShop = app(OrderShopService::class)->add($c['actor'], $c['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Unpurchased',
        ]);
        $foreignOrder = app(OrderService::class)->create($c['actor'], [
            'branch_id' => $c['branch']->id, 'customer_id' => $c['order']->customer_id,
            'customer_phone_id' => $c['order']->customer_phone_id,
            'customer_address_id' => $c['order']->customer_address_id,
            'source' => 'MANUAL', 'entry_mode' => 'LIVE',
        ]);
        $foreignAssignment = app(OrderCaptainService::class)->assign($c['actor'], $foreignOrder,
            $c['assignment']->captain, OrderCaptainRole::PrimaryCollection);
        $foreignBranch = Branch::factory()->create();
        $foreignCurrency = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2]);

        $purchaseData = [
            'order_id' => $c['order']->id, 'order_shop_id' => $shop->id,
            'order_captain_id' => $c['assignment']->id, 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'amount' => '10.00',
            'purchased_at' => DB::raw("clock_timestamp() - interval '5 minutes'"),
            'recorded_by' => $c['actor']->id, 'request_key' => (string) Str::uuid(),
            'evidence_path' => 'test/'.Str::uuid().'.png', 'evidence_name' => 'proof.png',
            'evidence_mime' => 'image/png', 'evidence_size' => 68, 'evidence_sha256' => str_repeat('a', 64),
            'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
        ];
        $reject = function (string $table, array $data): void {
            try {
                DB::transaction(function () use ($table, $data): void {
                    DB::table($table)->insert($data);
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('PostgreSQL accepted an invalid '.$table.' source.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        };

        foreach ([
            ['order_id' => $foreignOrder->id], ['order_captain_id' => $foreignAssignment->id],
            ['branch_id' => $foreignBranch->id], ['currency_id' => 999999],
            ['amount' => '0'], ['amount' => '-1'], ['amount' => '1.001'],
            ['amount' => 'NaN'], ['amount' => 'Infinity'], ['amount' => '-Infinity'],
            ['evidence_path' => null],
        ] as $change) {
            $reject('order_shop_purchase_confirmations', array_replace($purchaseData,
                ['request_key' => (string) Str::uuid(), 'evidence_path' => 'test/'.Str::uuid().'.png'], $change));
        }

        $purchase = app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $shop, [
            'order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id,
            'amount' => '10.00', 'purchased_at' => now()->subMinutes(5)->toIso8601String(),
            'request_key' => (string) Str::uuid(),
        ], $this->image());
        try {
            DB::transaction(fn () => DB::table('currencies')->where('id', $c['currency']->id)->update(['decimal_places' => 4]));
            $this->fail('PostgreSQL changed Currency precision after a verified purchase.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        try {
            app(CurrencyService::class)->save($admin, $c['currency'], [
                'code' => $c['currency']->code, 'name' => $c['currency']->name,
                'decimal_places' => 4,
            ]);
            $this->fail('Application changed Currency precision after a verified purchase.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decimal_places', $exception->errors());
        }
        $reject('order_shop_purchase_confirmations', array_replace($purchaseData, ['request_key' => (string) Str::uuid(),
            'evidence_path' => 'test/'.Str::uuid().'.png']));
        try {
            DB::transaction(fn () => DB::table('order_shop_purchase_confirmations')->where('id', $purchase->id)->delete());
            $this->fail('PostgreSQL deleted an immutable purchase.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $intentData = ['order_id' => $c['order']->id, 'classification' => 'AFTER_PURCHASE',
            'reason' => 'CUSTOMER_CANCELLED', 'requested_by' => $c['actor']->id,
            'intent_at' => DB::raw('clock_timestamp()'), 'frozen_at' => DB::raw('clock_timestamp()'),
            'request_key' => (string) Str::uuid(), 'created_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()')];
        $reject('order_cancellation_intents', $intentData);
        $intent = app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'],
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        try {
            DB::transaction(fn () => DB::table('order_cancellation_intent_shops')
                ->where('order_cancellation_intent_id', $intent->id)->delete());
            $this->fail('PostgreSQL deleted the frozen required set.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $returnData = [
            'order_id' => $c['order']->id, 'order_cancellation_intent_id' => $intent->id,
            'order_shop_id' => $shop->id, 'purchase_confirmation_id' => $purchase->id,
            'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'purchasing_order_captain_id' => $c['assignment']->id,
            'returning_order_captain_id' => $c['assignment']->id,
            'refund_recipient_order_captain_id' => $c['assignment']->id,
            'recorded_by' => $c['actor']->id, 'purchase_amount' => '10.00',
            'refund_amount' => '10.00', 'returned_at' => DB::raw('clock_timestamp()'),
            'refunded_at' => DB::raw('clock_timestamp()'), 'refund_method' => 'CASH',
            'request_key' => (string) Str::uuid(), 'sealed_at' => DB::raw('clock_timestamp()'),
            'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('clock_timestamp()'),
        ];
        foreach ([
            ['order_id' => $foreignOrder->id], ['order_shop_id' => $otherShop->id],
            ['purchase_confirmation_id' => 999999], ['branch_id' => $foreignBranch->id],
            ['currency_id' => $foreignCurrency->id], ['purchase_amount' => '9.00'],
            ['purchasing_order_captain_id' => $foreignAssignment->id],
            ['returning_order_captain_id' => $foreignAssignment->id],
            ['refund_recipient_order_captain_id' => $foreignAssignment->id],
            ['refund_amount' => '0'], ['refund_amount' => '-1'], ['refund_amount' => '11.00'],
            ['refund_amount' => '9.999'], ['refund_amount' => 'NaN'],
            ['refund_amount' => '9.00'],
        ] as $change) {
            $reject('order_shop_returns', array_replace($returnData, $change, ['request_key' => (string) Str::uuid()]));
        }
        $reject('order_shop_returns', $returnData);
        $this->assertDatabaseCount('order_shop_returns', 0);
    }

    public function test_standalone_final_history_cannot_commit_with_an_unreturned_shop(): void
    {
        [$context, $shops] = $this->preparedIntent(3);
        $this->completeReturn($context, $shops[0]);
        $this->completeReturn($context, $shops[1]);

        $this->assertThrows(fn () => $this->insertFinalHistoryOnly($context), QueryException::class);
        $this->assertDatabaseMissing('order_cancellations', ['order_id' => $context['order']->id]);
        $this->assertNotSame(OrderOperationalStatus::Cancelled, $context['order']->fresh()->operational_status);
        $this->assertSame(3, $context['order']->cancellationIntent->requiredShops()->count());
    }

    public function test_complete_returns_still_cannot_commit_history_without_cancelled_status(): void
    {
        [$context, $shops] = $this->preparedIntent(1);
        $this->completeReturn($context, $shops[0]);

        $this->assertThrows(fn () => $this->insertFinalHistoryOnly($context), QueryException::class);
        $this->assertDatabaseMissing('order_cancellations', ['order_id' => $context['order']->id]);
        $this->assertNotSame(OrderOperationalStatus::Cancelled, $context['order']->fresh()->operational_status);
    }

    public function test_complete_returns_cannot_commit_cancelled_status_without_final_history(): void
    {
        [$context, $shops] = $this->preparedIntent(1);
        $this->completeReturn($context, $shops[0]);

        $this->assertThrows(fn () => DB::transaction(function () use ($context): void {
            DB::table('orders')->where('id', $context['order']->id)->update(['operational_status' => 'CANCELLED']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }), QueryException::class);
        $this->assertDatabaseMissing('order_cancellations', ['order_id' => $context['order']->id]);
        $this->assertNotSame(OrderOperationalStatus::Cancelled, $context['order']->fresh()->operational_status);
    }

    public function test_intent_based_final_cancellation_history_cannot_be_updated_or_deleted(): void
    {
        [$context, $shops] = $this->preparedIntent(1);
        $this->completeReturn($context, $shops[0]);
        $cancellation = app(OrderCancellationFinalizationService::class)->finalize($context['actor'], $context['order']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_cancellations')
            ->where('id', $cancellation->id)->update(['notes' => 'Changed'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_cancellations')
            ->where('id', $cancellation->id)->delete()), QueryException::class);
        $this->assertDatabaseHas('order_cancellations', ['id' => $cancellation->id, 'order_id' => $context['order']->id]);
    }

    public function test_postgresql_freezes_pickup_and_normal_receipts_after_intent(): void
    {
        Storage::fake('order_returns');
        $context = $this->context();
        $purchasedShop = app(OrderShopService::class)->add($context['actor'], $context['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Earlier purchase',
        ]);
        $otherShop = app(OrderShopService::class)->add($context['actor'], $context['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Frozen out',
        ]);
        app(OrderShopPurchaseConfirmationService::class)->record($context['actor'], $context['order'], $purchasedShop, [
            'order_captain_id' => $context['assignment']->id, 'currency_id' => $context['currency']->id,
            'amount' => '10.00', 'purchased_at' => now()->subMinutes(5)->toIso8601String(),
            'request_key' => (string) Str::uuid(),
        ], $this->image());
        DB::table('order_shops')->where('id', $purchasedShop->id)->update(['picked_up_at' => now()->subMinutes(3)]);
        DB::table('order_shop_receipts')->insert([
            'order_shop_id' => $purchasedShop->id, 'storage_path' => 'test/'.Str::uuid().'.png',
            'original_filename' => 'before.png', 'mime_type' => 'image/png', 'file_size' => 68,
            'uploaded_by' => $context['actor']->id, 'uploaded_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
        ]);
        $intent = app(OrderCancellationIntentService::class)->create($context['actor'], $context['order'],
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $this->assertSame(1, $intent->requiredShops()->count());

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_shops')->where('id', $otherShop->id)
            ->update(['picked_up_at' => now()])), QueryException::class);
        $this->assertNull($otherShop->fresh()->picked_up_at);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_shop_receipts')->insert([
            'order_shop_id' => $otherShop->id, 'storage_path' => 'test/'.Str::uuid().'.png',
            'original_filename' => 'after.png', 'mime_type' => 'image/png', 'file_size' => 68,
            'uploaded_by' => $context['actor']->id, 'uploaded_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ])), QueryException::class);
        $this->assertDatabaseMissing('order_shop_receipts', ['order_shop_id' => $otherShop->id]);
        $this->assertSame(1, $intent->requiredShops()->count());
        $this->assertDatabaseHas('order_shop_receipts', ['order_shop_id' => $purchasedShop->id]);
    }

    public function test_old_receipt_cannot_be_moved_into_unfrozen_shop_after_intent_even_with_older_timestamp(): void
    {
        Storage::fake('order_returns');
        $context = $this->context();
        $purchasedShop = app(OrderShopService::class)->add($context['actor'], $context['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Purchased',
        ]);
        $unfrozenShop = app(OrderShopService::class)->add($context['actor'], $context['order'], [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Unfrozen',
        ]);
        app(OrderShopPurchaseConfirmationService::class)->record($context['actor'], $context['order'], $purchasedShop, [
            'order_captain_id' => $context['assignment']->id, 'currency_id' => $context['currency']->id,
            'amount' => '10.00', 'purchased_at' => now()->subMinutes(30)->toIso8601String(),
            'request_key' => (string) Str::uuid(),
        ], $this->image());
        $oldTime = now()->subMinutes(20);
        $receiptId = DB::table('order_shop_receipts')->insertGetId([
            'order_shop_id' => $purchasedShop->id, 'storage_path' => 'test/'.Str::uuid().'.png',
            'original_filename' => 'old.png', 'mime_type' => 'image/png', 'file_size' => 68,
            'uploaded_by' => $context['actor']->id, 'uploaded_at' => $oldTime,
            'created_at' => $oldTime, 'updated_at' => $oldTime,
        ]);

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_shop_receipts')
            ->where('id', $receiptId)->update(['order_shop_id' => $unfrozenShop->id])), QueryException::class);
        $this->assertSame($purchasedShop->id, (int) DB::table('order_shop_receipts')->where('id', $receiptId)->value('order_shop_id'));
        $this->assertSame(1, DB::table('order_shop_receipts')->where('id', $receiptId)
            ->update(['order_shop_id' => $purchasedShop->id]));

        $intent = app(OrderCancellationIntentService::class)->create($context['actor'], $context['order'],
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $this->assertTrue($oldTime->lessThan($intent->intent_at));
        $this->assertSame(1, $intent->requiredShops()->count());
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_shop_receipts')
            ->where('id', $receiptId)->update(['order_shop_id' => $unfrozenShop->id])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_shop_receipts')
            ->where('id', $receiptId)->delete()), QueryException::class);
        $this->assertDatabaseHas('order_shop_receipts', ['id' => $receiptId, 'order_shop_id' => $purchasedShop->id]);
        $this->assertDatabaseMissing('order_shop_receipts', ['order_shop_id' => $unfrozenShop->id]);
        $this->assertSame(1, $intent->requiredShops()->count());
        $this->assertNotSame(OrderOperationalStatus::Cancelled, $context['order']->fresh()->operational_status);
    }

    private function preparedIntent(int $count): array
    {
        Storage::fake('order_returns');
        $context = $this->context();
        $shops = [];
        for ($number = 1; $number <= $count; $number++) {
            $shop = app(OrderShopService::class)->add($context['actor'], $context['order'], [
                'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'Purchased '.$number,
            ]);
            app(OrderShopPurchaseConfirmationService::class)->record($context['actor'], $context['order'], $shop, [
                'order_captain_id' => $context['assignment']->id, 'currency_id' => $context['currency']->id,
                'amount' => '10.00', 'purchased_at' => now()->subMinutes(5)->toIso8601String(),
                'request_key' => (string) Str::uuid(),
            ], $this->image());
            $shops[] = $shop;
        }
        app(OrderCancellationIntentService::class)->create($context['actor'], $context['order'],
            OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());

        return [$context, $shops];
    }

    private function completeReturn(array $context, $shop): void
    {
        app(OrderShopReturnService::class)->record($context['actor'], $context['order'], $shop, [
            'returning_order_captain_id' => $context['assignment']->id, 'refund_amount' => '10.00',
            'returned_at' => now()->subMinutes(2)->toIso8601String(),
            'refunded_at' => now()->subMinute()->toIso8601String(), 'refund_method' => 'CASH',
            'request_key' => (string) Str::uuid(),
        ], ['RETURN_AND_REFUND_PROOF' => $this->image()]);
    }

    private function insertFinalHistoryOnly(array $context): void
    {
        DB::transaction(function () use ($context): void {
            DB::table('order_cancellations')->insert([
                'order_id' => $context['order']->id, 'classification' => 'AFTER_PURCHASE',
                'reason' => 'CUSTOMER_CANCELLED', 'cancelled_by' => $context['actor']->id,
                'cancelled_at' => DB::raw('clock_timestamp()'), 'created_at' => DB::raw('clock_timestamp()'),
                'updated_at' => DB::raw('clock_timestamp()'),
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }
}
