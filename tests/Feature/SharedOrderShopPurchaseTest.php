<?php

namespace Tests\Feature;

use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReturnService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderShopPurchaseService;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SharedOrderShopPurchaseTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_physical_halves_preserve_shop_collector_private_proof_and_idempotency_without_ledger_writes(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $collector = app(OrderCaptainService::class)->switchPrimary($c['actor'], $c['order'], $c['captains'][1], 'Designated collector');
        $collector->update(['assigned_at' => now()->subMinutes(5)]);
        app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $c['captains'][0], OrderCaptainRole::Support)
            ->update(['assigned_at' => now()->subMinutes(5)]);
        $data = $this->sharedData($c);
        $service = app(SharedOrderShopPurchaseService::class);
        $purchase = $service->record($c['actor'], $c['order'], $c['shop'], $data, $this->sharedImage());
        $this->assertNull($purchase->order_captain_id);
        $this->assertSame($collector->id, $purchase->collecting_order_captain_id);
        $this->assertSame($c['shop']->id, $purchase->order_shop_id);
        $this->assertCount(2, $purchase->allocations);
        $this->assertSame(['40.00', '40.00'], $purchase->allocations->pluck('amount')->all());
        $this->assertTrue($purchase->allocations->reduce(fn (BigDecimal $sum, $row) => $sum->plus($row->amount), BigDecimal::zero())->isEqualTo('80'));
        $this->assertSame($purchase->id, $purchase->allocations->first()->purchaseConfirmation->id);
        $this->assertSame($purchase->id, $service->record($c['actor'], $c['order'], $c['shop'], $data, $this->sharedImage())->id);
        $this->assertDatabaseCount('order_shop_purchase_confirmations', 1);
        $this->assertDatabaseCount('order_shop_purchase_allocations', 2);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertSame($this->sharedImage()->getContent(), $service->evidenceBytes($c['actor'], $c['order'], $c['shop'], $purchase));
        $this->assertFalse(config('filesystems.disks.order_returns.serve'));
        $this->assertThrows(fn () => $service->record($c['actor'], $c['order'], $c['shop'], array_replace($data, ['amount' => '82']), $this->sharedImage()), ValidationException::class);
        $this->assertThrows(fn () => $service->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage()), ValidationException::class);
    }

    #[DataProvider('precisionCases')]
    public function test_exact_currency_halves_and_reject_odd_minor_units(int $places, string $even, string $half, string $odd): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext($places);
        $service = app(SharedOrderShopPurchaseService::class);
        $this->assertThrows(fn () => $service->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, $odd), $this->sharedImage()), ValidationException::class);
        $this->assertDatabaseCount('order_shop_purchase_confirmations', 0);
        $purchase = $service->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, $even), $this->sharedImage());
        foreach ($purchase->allocations as $allocation) {
            $this->assertTrue(BigDecimal::of($allocation->amount)->isEqualTo($half));
        }
        $this->assertTrue(BigDecimal::of($purchase->amount)->isEqualTo(BigDecimal::of($half)->multipliedBy(2)));
    }

    public static function precisionCases(): array
    {
        return [[0, '2', '1', '1'], [2, '0.02', '0.01', '0.01'], [3, '1.234', '0.617', '1.235'], [4, '1.2346', '0.6173', '1.2345']];
    }

    public function test_invalid_money_and_unsupported_participant_count_have_zero_writes(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        foreach (['0', '-2', 'NaN', 'Infinity', '1.2345'] as $amount) {
            $this->assertThrows(fn () => app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, $amount), $this->sharedImage()), ValidationException::class);
        }
        app(OrderCaptainService::class)->remove($c['actor'], $c['order'], $c['assignments'][1], 'Only one');
        $this->assertThrows(fn () => app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage()), ValidationException::class);
        $this->assertDatabaseCount('order_shop_purchase_confirmations', 0);
    }

    public function test_each_shop_has_independent_exact_physical_shares_without_double_counting(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $otherShop = $c['shop']->replicate();
        $otherShop->save();
        $service = app(SharedOrderShopPurchaseService::class);
        $first = $service->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, '100'), $this->sharedImage());
        $second = $service->record($c['actor'], $c['order'], $otherShop, $this->sharedData($c, '200'), $this->sharedImage());
        $this->assertSame(['50.00', '50.00'], $first->allocations->pluck('amount')->all());
        $this->assertSame(['100.00', '100.00'], $second->allocations->pluck('amount')->all());
        $this->assertSame($first->collecting_order_captain_id, $second->collecting_order_captain_id);
        $this->assertNotSame($first->order_shop_id, $second->order_shop_id);
        $this->assertTrue(BigDecimal::of($first->amount)->plus($second->amount)->isEqualTo('300'));
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
    }

    public function test_permissions_branch_nested_ownership_and_super_admin_bypass(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $service = app(SharedOrderShopPurchaseService::class);
        $outsider = User::factory()->create();
        $outsider->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $outsider->branches()->attach(Branch::factory()->create());
        $this->assertThrows(fn () => $service->record($outsider, $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage()), HttpException::class);
        $this->assertThrows(fn () => $service->record(User::factory()->create(), $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage()), AuthorizationException::class);
        $purchase = $service->record($c['admin'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        $this->assertThrows(fn () => $service->evidenceBytes($outsider, $c['order'], $c['shop'], $purchase), HttpException::class);
        $other = $this->sharedContext();
        $this->assertThrows(fn () => $service->record($c['admin'], $c['order'], $other['shop'], $this->sharedData($c), $this->sharedImage()), HttpException::class);
        $this->assertThrows(fn () => $service->evidenceBytes($c['admin'], $other['order'], $other['shop'], $purchase), HttpException::class);
    }

    public function test_shared_history_is_immutable_and_all_unsupported_legacy_paths_fail_closed(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $purchase = app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        foreach ([
            fn () => app(InvoiceService::class)->createDraft($c['actor'], $c['order']),
            fn () => app(OrderBillPaymentService::class)->request($c['actor'], $c['order'], $c['currency']->id),
            fn () => app(OrderCaptainService::class)->switchPrimary($c['actor'], $c['order'], $c['captains'][1], 'Switch'),
            fn () => app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::CaptainAssigned, null),
            fn () => app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'], OrderCancellationReason::CustomerCancelled, null, (string) str()->uuid()),
            fn () => app(OrderDeliveryPricingService::class)->applyDiscount($c['actor'], $c['order'], '1', 'Discount'),
            fn () => app(OrderShopReturnService::class)->record($c['actor'], $c['order'], $c['shop'], [
                'returning_order_captain_id' => $c['assignments'][0]->id, 'refund_amount' => '80',
                'returned_at' => now()->toIso8601String(), 'refunded_at' => now()->toIso8601String(),
                'refund_method' => 'CASH', 'request_key' => (string) str()->uuid(),
            ], ['RETURN_AND_REFUND_PROOF' => $this->sharedImage()]),
        ] as $attempt) {
            $this->assertThrows($attempt, ValidationException::class);
        }
        foreach ([
            fn () => DB::table('order_shop_purchase_allocations')->where('id', $purchase->allocations->first()->id)->update(['amount' => '41']),
            fn () => DB::table('order_shop_purchase_allocations')->where('id', $purchase->allocations->first()->id)->delete(),
            fn () => DB::table('order_shop_purchase_confirmations')->where('id', $purchase->id)->update(['purchase_mode' => 'SINGLE_PAYER']),
            fn () => DB::table('order_captains')->where('id', $c['assignments'][1]->id)->update(['removed_at' => now()]),
            fn () => DB::table('orders')->where('id', $c['order']->id)->update(['branch_id' => Branch::factory()->create()->id]),
            fn () => DB::table('order_items')->where('order_shop_id', $c['shop']->id)->update(['actual_unit_price' => '81']),
            fn () => DB::table('currencies')->where('id', $c['currency']->id)->update(['decimal_places' => 4]),
        ] as $attempt) {
            $this->assertThrows(fn () => DB::transaction($attempt), QueryException::class);
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('order_shop_returns', 0);
    }

    public function test_order_visibility_alone_does_not_authorize_shared_purchase_evidence(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $service = app(SharedOrderShopPurchaseService::class);
        $purchase = $service->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        $role = Role::where('code', 'ORDER_EMPLOYEE')->sole();
        $permission = Permission::where('code', 'order_shop_purchases.view')->sole();
        $role->permissions()->detach($permission);
        $c['actor']->unsetRelation('roles');
        $this->assertTrue($c['actor']->can('orders.view'));
        $this->assertFalse($c['actor']->can('order_shop_purchases.view'));
        $this->assertThrows(fn () => $service->evidenceBytes($c['actor'], $c['order'], $c['shop'], $purchase), AuthorizationException::class);
        $this->actingAs($c['actor'])->get(route('orders.shops.purchases.evidence', [$c['order'], $c['shop'], $purchase]))->assertForbidden();
    }

    public function test_legacy_single_payer_remains_original_and_cannot_be_converted(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $data = $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id];
        $purchase = app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], $data, $this->sharedImage());
        $original = $purchase->getAttributes();
        $this->assertSame('SINGLE_PAYER', $purchase->purchase_mode);
        $this->assertSame($c['assignments'][0]->id, $purchase->order_captain_id);
        $this->assertNull($purchase->collecting_order_captain_id);
        $this->assertDatabaseCount('order_shop_purchase_allocations', 0);
        $this->assertThrows(fn () => app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage()), ValidationException::class);
        $this->assertSame($original, $purchase->fresh()->getAttributes());
    }

    public function test_postgresql_rejects_invalid_direct_money_incomplete_or_forged_physical_shares(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $otherShop = $c['shop']->replicate();
        $otherShop->save();
        $purchase = app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        $facts = $purchase->getAttributes();
        unset($facts['id']);
        $facts['order_shop_id'] = $otherShop->id;
        $facts['request_key'] = (string) str()->uuid();
        $facts['evidence_path'] = 'proof/'.str()->uuid().'.png';
        foreach (['0', '-1', 'NaN', 'Infinity', '-Infinity', '0.01', '0.001'] as $amount) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_shop_purchase_confirmations')->insert(array_replace($facts, ['amount' => $amount]))), QueryException::class);
        }
        foreach (['missing', 'wrong', 'duplicate', 'foreign'] as $case) {
            $this->assertThrows(fn () => DB::transaction(function () use ($c, $facts, $case): void {
                $id = DB::table('order_shop_purchase_confirmations')->insertGetId($facts);
                DB::table('order_shop_purchase_allocations')->insert(['purchase_confirmation_id' => $id,
                    'order_captain_id' => $c['assignments'][0]->id, 'amount' => $case === 'wrong' ? '39' : '40']);
                if ($case !== 'missing') {
                    $assignment = $case === 'foreign' ? $this->sharedContext()['assignments'][0] : $c['assignments'][$case === 'duplicate' ? 0 : 1];
                    DB::table('order_shop_purchase_allocations')->insert(['purchase_confirmation_id' => $id,
                        'order_captain_id' => $assignment->id, 'amount' => '40']);
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }), QueryException::class);
        }
        $this->assertDatabaseCount('order_shop_purchase_confirmations', 1);
        $this->assertDatabaseCount('order_shop_purchase_allocations', 2);
        $column = DB::selectOne("SELECT numeric_precision FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='order_shop_purchase_allocations' AND column_name='amount'");
        $this->assertNull($column->numeric_precision);
    }

    public function test_late_allocation_failure_rolls_back_both_source_and_private_evidence(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_second_shared_payer() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM order_shop_purchase_allocations WHERE purchase_confirmation_id=NEW.purchase_confirmation_id) THEN
        RAISE EXCEPTION 'Injected second payer failure' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER test_reject_second_shared_payer BEFORE INSERT ON order_shop_purchase_allocations
FOR EACH ROW EXECUTE FUNCTION reject_second_shared_payer();
SQL);
        $this->assertThrows(fn () => app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage()), QueryException::class);
        $this->assertDatabaseCount('order_shop_purchase_confirmations', 0);
        $this->assertDatabaseCount('order_shop_purchase_allocations', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame([], Storage::disk('order_returns')->allFiles());
    }

    public function test_sql_cannot_move_items_out_of_shared_order_or_insert_an_invoice(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $other = $this->sharedContext();
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        try {
            DB::transaction(fn () => DB::table('order_items')->where('order_shop_id', $c['shop']->id)->update(['order_shop_id' => $other['shop']->id]));
            $this->fail('A direct SQL writer moved a shared-purchase item out of the protected Order.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Shared purchase settlement and refund contract is not enabled', $exception->getMessage());
        }
        $invoice = app(InvoiceService::class)->createDraft($other['actor'], $other['order']);
        $row = $invoice->getAttributes();
        unset($row['id']);
        $row['order_id'] = $c['order']->id;
        $row['invoice_number'] = 'MC1-'.str()->uuid();
        try {
            DB::transaction(fn () => DB::table('invoices')->insert($row));
            $this->fail('A direct SQL writer created a legacy Invoice for a shared purchase.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Shared purchase settlement and refund contract is not enabled', $exception->getMessage());
        }
        $this->assertSame(0, $c['order']->invoices()->count());
        $this->assertSame(1, $c['shop']->items()->count());
    }

    public function test_existing_delivery_units_discount_invoice_and_entitlements_are_not_reinterpreted(): void
    {
        $c = $this->sharedContext();
        $pricing = app(OrderDeliveryPricingService::class)->applyDiscount($c['admin'], $c['order'], '2', 'Combined discount');
        $this->assertSame(2, $pricing->captain_count_snapshot);
        $this->assertTrue(BigDecimal::of($pricing->customer_delivery_total)->isEqualTo('18'));
        $this->assertCount(2, $pricing->allocations);
        foreach ($pricing->allocations as $allocation) {
            $this->assertTrue(BigDecimal::of($allocation->captain_entitlement)->isEqualTo('5'));
        }
        $invoice = app(InvoiceService::class)->refresh($c['actor'], $c['order'], app(InvoiceService::class)->createDraft($c['actor'], $c['order']));
        $this->assertTrue(BigDecimal::of($invoice->items_subtotal)->isEqualTo('80'));
        $this->assertTrue(BigDecimal::of($invoice->delivery_total)->isEqualTo('18'));
        $this->assertTrue(BigDecimal::of($invoice->grand_total)->isEqualTo('98'));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(1, $c['order']->captainAssignments()->whereNull('removed_at')->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->count());
        $this->assertDatabaseCount('financial_transactions', 0);
    }
}
