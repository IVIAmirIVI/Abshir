<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderShopType;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\BillPaymentFeeRuleService;
use App\Services\FinancialBalanceQueryService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderStatusService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderBillPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_snapshot_delivery_and_successful_bill_post_three_distinct_events_without_invoice_effect(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        $rule = app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2000', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->assertSame($rule->id, $request->fee_rule_id);
        $this->assertSame('2000.0000', $request->service_fee);
        $this->assertTrue($c['order']->hasPendingBillPaymentRequests());
        $this->assertDatabaseCount('order_bill_payments', 0);
        $this->assertDatabaseCount('financial_transactions', 0);

        $this->deliver($c);
        $deliveredAt = $c['order']->statusHistory()->where('new_status', 'DELIVERED')->sole()->changed_at;
        $this->assertTrue($c['order']->hasPendingBillPaymentRequests());
        $this->assertSame('DELIVERED', $c['order']->fresh()->operational_status->value);

        $payment = app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [$this->image()]);
        try {
            app(OrderCancellationIntentService::class)->create($c['employee'], $c['order'],
                OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
            $this->fail('Completed bill-payment history allowed cancellation intent.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('FINANCIAL_CORRECTION_REQUIRED', $exception->errors()['order'][0]);
        }
        $this->assertDatabaseMissing('order_cancellation_intents', ['order_id' => $c['order']->id]);
        $handoverFacts = DB::selectOne(<<<'SQL'
SELECT t.occurred_at::text AS event_time, h.occurred_at::text AS handover_time,
       t.branch_id AS event_branch, o.branch_id AS order_branch,
       t.currency_id AS event_currency, p.currency_id AS payment_currency,
       t.source_id AS event_source, h.id::text AS handover_source,
       t.source_version, t.transaction_kind, t.status, e.amount AS event_amount,
       p.principal_amount, e.direction, a.account_kind, owner.captain_id AS account_captain,
       p.captain_id AS payment_captain,
       (t.occurred_at = h.occurred_at) AS same_time
FROM order_bill_payments p
JOIN orders o ON o.id = p.order_id
JOIN order_bill_payment_principal_handovers h ON h.bill_payment_id = p.id
JOIN financial_transactions t ON t.source_type = 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER'
  AND t.source_id = h.id::text AND t.event_type = 'BILL_PAYMENT_PRINCIPAL_HANDED_OVER'
JOIN financial_entries e ON e.financial_transaction_id = t.id
JOIN financial_accounts a ON a.id = e.financial_account_id
JOIN financial_owners owner ON owner.id = a.financial_owner_id
WHERE p.id = ?
SQL, [$payment->id]);
        $this->assertTrue((bool) $handoverFacts->same_time, json_encode($handoverFacts));
        $this->assertSame('102000.0000', $payment->total_customer_cash);
        $this->assertSame($deliveredAt->toISOString(), $c['order']->statusHistory()->where('new_status', 'DELIVERED')->sole()->changed_at->toISOString());
        $this->assertTrue($c['order']->additionalServicesComplete());
        $this->assertSame(1, $payment->handover()->count());
        $this->assertSame(1, $payment->evidence()->count());
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertSame(0, $c['order']->invoices()->count());

        $events = FinancialTransaction::with('entries.account')->get()->keyBy('event_type');
        $this->assertSame('100000.0000', $events[FinancialEventType::BillPaymentCashCollected->value]->entries->sole()->amount);
        $this->assertSame(FinancialEntryDirection::Increase, $events[FinancialEventType::BillPaymentCashCollected->value]->entries->sole()->direction);
        $this->assertSame(FinancialAccountKind::CaptainCashCustody, $events[FinancialEventType::BillPaymentCashCollected->value]->entries->sole()->account->account_kind);
        $this->assertSame(FinancialEntryDirection::Decrease, $events[FinancialEventType::BillPaymentPrincipalHandedOver->value]->entries->sole()->direction);
        $this->assertSame('100000.0000', $events[FinancialEventType::BillPaymentPrincipalHandedOver->value]->entries->sole()->amount);
        $this->assertSame(FinancialAccountKind::CaptainCashCustody, $events[FinancialEventType::BillPaymentPrincipalHandedOver->value]->entries->sole()->account->account_kind);
        $this->assertSame('2000.0000', $events[FinancialEventType::BillPaymentServiceFeeRetained->value]->entries->sole()->amount);
        $this->assertSame(FinancialAccountKind::CaptainDeposit, $events[FinancialEventType::BillPaymentServiceFeeRetained->value]->entries->sole()->account->account_kind);
        $this->assertSame(FinancialEntryDirection::Decrease, $events[FinancialEventType::BillPaymentServiceFeeRetained->value]->entries->sole()->direction);
        $this->assertSame('ORDER_BILL_PAYMENT', $events[FinancialEventType::BillPaymentCashCollected->value]->source_type);
        $this->assertSame((string) $payment->id, $events[FinancialEventType::BillPaymentCashCollected->value]->source_id);
        $this->assertSame('ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER', $events[FinancialEventType::BillPaymentPrincipalHandedOver->value]->source_type);
        $this->assertSame((string) $payment->handover->id, $events[FinancialEventType::BillPaymentPrincipalHandedOver->value]->source_id);
        $custody = FinancialAccount::query()->whereHas('owner', fn ($query) => $query->where('captain_id', $c['captain']->id))->where('account_kind', FinancialAccountKind::CaptainCashCustody->value)->sole();
        $deposit = FinancialAccount::query()->whereHas('owner', fn ($query) => $query->where('captain_id', $c['captain']->id))->where('account_kind', FinancialAccountKind::CaptainDeposit->value)->sole();
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->balance($custody));
        $this->assertSame('-2000.0000', app(FinancialBalanceQueryService::class)->balance($deposit));
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance(
            $c['captain'], $c['order']->branch, $c['currency'], FinancialAccountKind::CaptainCashCustody,
        ));
        $this->assertSame('-2000.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance(
            $c['captain'], $c['order']->branch, $c['currency'], FinancialAccountKind::CaptainDeposit,
        ));
        $this->assertSame(0, FinancialAccount::query()->whereHas('owner', fn ($query) => $query->where('captain_id', $c['captain']->id))->where('account_kind', FinancialAccountKind::CaptainCompanyReceivable->value)->count());

        try {
            app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [$this->image()]);
            $this->fail('A completed request cannot be completed again.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('financial_transactions', 3);
        }
    }

    public function test_version_change_does_not_reprice_request_and_cancel_has_no_financial_effect(): void
    {
        $c = $this->fixture();
        $rules = app(BillPaymentFeeRuleService::class);
        $old = $rules->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $rules->version($c['admin'], $old, '3', now());
        $this->assertSame('2.0000', $request->fresh()->service_fee);
        $this->assertSame('3.0000', app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id)->service_fee);
        app(OrderBillPaymentService::class)->cancel($c['employee'], $c['order'], $request, 'Customer withdrew request');
        $this->assertDatabaseCount('order_bill_payments', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('CANCELLED', $request->fresh()->status->value);
    }

    public function test_completion_requires_delivery_evidence_and_correct_branch_and_captain(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $service = app(OrderBillPaymentService::class);
        $this->expectValidation(fn () => $service->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [$this->image()]));
        $this->deliver($c);
        $this->expectValidation(fn () => $service->complete($c['employee'], $c['order'], $request, [...$this->paymentData($c), 'principal_amount' => '100000.001'], [$this->image()]));
        $this->expectValidation(fn () => $service->complete($c['employee'], $c['order'], $request, [...$this->paymentData($c), 'order_captain_id' => 999999], [$this->image()]));
        $otherOrder = Order::create(['branch_id' => $c['branch']->id, 'customer_id' => $c['order']->customer_id, 'order_number' => 'BILL-OTHER-'.str()->random(8), 'ordered_at' => now(), 'customer_name_snapshot' => 'Other', 'customer_phone_snapshot' => '123', 'area_name_snapshot' => 'Area', 'address_line_1_snapshot' => 'Street', 'created_by' => $c['employee']->id]);
        $foreignAssignment = OrderCaptain::create(['order_id' => $otherOrder->id, 'captain_id' => $c['captain']->id, 'assignment_role' => 'SUPPORT', 'assigned_at' => now()->subHour(), 'assigned_by' => $c['employee']->id]);
        $this->expectValidation(fn () => $service->complete($c['employee'], $c['order'], $request, [...$this->paymentData($c), 'order_captain_id' => $foreignAssignment->id], [$this->image()]));
        $this->expectValidation(fn () => $service->complete($c['employee'], $c['order'], $request, $this->paymentData($c), []));

        $outsider = User::factory()->create();
        $outsider->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->assertFalse(Order::query()->accessibleTo($outsider)->whereKey($c['order'])->exists());
        try {
            $service->complete($outsider, $c['order'], $request, $this->paymentData($c), [$this->image()]);
            $this->fail('Cross-branch access must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('order_bill_payments', 0);
    }

    public function test_completed_history_is_immutable_in_postgresql_and_evidence_is_nested_authorized(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $payment = app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [$this->image()]);
        $evidence = $payment->evidence()->sole();
        $route = route('orders.bill-payments.evidence.show', [$c['order'], $request, $payment, $evidence]);
        $this->actingAs($c['employee'])->get($route)->assertOk();
        $this->actingAs($c['admin'])->get($route)->assertOk();
        $outsider = User::factory()->create();
        $outsider->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->actingAs($outsider)->get($route)->assertForbidden();
        $this->actingAs($c['employee'])->get(route('orders.bill-payments.evidence.show', [$c['order'], $request, $payment, 999999]))->assertNotFound();

        if (DB::getDriverName() === 'pgsql') {
            foreach (['order_bill_payments' => $payment->id, 'order_bill_payment_principal_handovers' => $payment->handover->id, 'order_bill_payment_evidence' => $evidence->id] as $table => $id) {
                try {
                    DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                    $this->fail("{$table} must be immutable.");
                } catch (QueryException) {
                    $this->assertDatabaseHas($table, ['id' => $id]);
                }
                try {
                    DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['updated_at' => now()->addMinute()]));
                    $this->fail("{$table} must reject historical updates.");
                } catch (QueryException) {
                    $this->assertDatabaseHas($table, ['id' => $id]);
                }
            }
            $delivery = $c['order']->statusHistory()->where('new_status', 'DELIVERED')->sole();
            try {
                DB::transaction(fn () => DB::table('order_status_history')->where('id', $delivery->id)->update(['changed_at' => now()->addMinute()]));
                $this->fail('The historical delivery timestamp cannot change after bill payment.');
            } catch (QueryException) {
                $this->assertSame($delivery->changed_at->toISOString(), $delivery->fresh()->changed_at->toISOString());
            }
            foreach ([['new_status' => 'NEW'], ['order_id' => $c['order']->id + 999999]] as $rewrite) {
                try {
                    DB::transaction(fn () => DB::table('order_status_history')->where('id', $delivery->id)->update($rewrite));
                    $this->fail('The authoritative DELIVERED source cannot change status or Order.');
                } catch (QueryException) {
                    $this->assertDatabaseHas('order_status_history', ['id' => $delivery->id, 'order_id' => $c['order']->id, 'new_status' => 'DELIVERED']);
                }
            }
            try {
                DB::transaction(fn () => DB::table('order_status_history')->where('id', $delivery->id)->delete());
                $this->fail('The authoritative DELIVERED source cannot be deleted.');
            } catch (QueryException) {
                $this->assertDatabaseHas('order_status_history', ['id' => $delivery->id]);
            }
            DB::table('order_status_history')->where('id', $delivery->id)->update(['reason' => 'Delivery annotation']);
            $this->assertSame('Delivery annotation', $delivery->fresh()->reason);
            $older = $c['order']->statusHistory()->create([
                'previous_status' => OrderOperationalStatus::HeadingToCustomer,
                'new_status' => OrderOperationalStatus::Delivered,
                'changed_by' => $c['employee']->id,
                'changed_at' => $delivery->changed_at->copy()->subDay(),
            ]);
            $this->assertSame($older->id, $older->fresh()->id);
            try {
                DB::transaction(fn () => DB::table('order_status_history')->insert([
                    'order_id' => $c['order']->id, 'new_status' => 'DELIVERED',
                    'changed_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('A new delivery timestamp cannot supersede the payment source.');
            } catch (QueryException) {
                $this->assertSame(2, $c['order']->statusHistory()->where('new_status', 'DELIVERED')->count());
            }
            try {
                DB::transaction(fn () => DB::table('orders')->where('id', $c['order']->id)->update(['operational_status' => 'NEW']));
                $this->fail('The Order cannot leave DELIVERED after successful bill payment.');
            } catch (QueryException) {
                $this->assertSame('DELIVERED', $c['order']->fresh()->operational_status->value);
            }
            try {
                DB::transaction(fn () => DB::table('order_captains')->where('id', $c['assignment']->id)->update(['removed_at' => now()]));
                $this->fail('The participating OrderCaptain cannot change after payment.');
            } catch (QueryException) {
                $this->assertNull($c['assignment']->fresh()->removed_at);
            }
            $otherBranch = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
            try {
                DB::transaction(fn () => DB::table('orders')->where('id', $c['order']->id)->update(['branch_id' => $otherBranch->id]));
                $this->fail('The bill-payment Branch cannot change after posting.');
            } catch (QueryException) {
                $this->assertSame($c['branch']->id, $c['order']->fresh()->branch_id);
            }
        }
    }

    public function test_missing_fee_and_invalid_evidence_cannot_create_successful_payment(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        try {
            app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
            $this->fail('A request without an applicable fee must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('order_bill_payment_requests', 0);
        }
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $service = app(OrderBillPaymentService::class);
        $this->expectValidation(fn () => $service->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [UploadedFile::fake()->createWithContent('disguised.png', '<svg><script>alert(1)</script></svg>')]));
        $this->expectValidation(fn () => $service->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [UploadedFile::fake()->createWithContent('large.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=').str_repeat('x', (5 * 1024 * 1024) + 1))]));
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_multiple_requests_complete_independently_and_completed_request_cannot_cancel(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $first = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $second = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $service = app(OrderBillPaymentService::class);
        $service->complete($c['employee'], $c['order'], $first, $this->paymentData($c), [$this->image()]);
        $this->assertTrue($c['order']->hasPendingBillPaymentRequests());
        try {
            $service->cancel($c['employee'], $c['order'], $first, 'Too late');
            $this->fail('A completed bill-payment request cannot be cancelled.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('order_bill_payments', 1);
        }
        $service->complete($c['employee'], $c['order'], $second, [...$this->paymentData($c), 'bill_number' => 'B-101'], [$this->image()]);
        $this->assertSame(2, $c['order']->billPayments()->count());
        $this->assertSame(6, FinancialTransaction::count());
        $this->assertTrue($c['order']->additionalServicesComplete());
        $secondPayment = $second->fresh()->payment;
        $this->actingAs($c['employee'])->get(route('orders.bill-payments.evidence.show', [$c['order'], $first, $secondPayment, $secondPayment->evidence()->sole()]))->assertNotFound();
    }

    public function test_fee_overlap_and_direct_precision_changes_are_rejected_by_postgresql(): void
    {
        $c = $this->fixture();
        $rule = app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        try {
            app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '3', now());
            $this->fail('Overlapping fee rules must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('bill_payment_fee_rules', 1);
        }
        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::transaction(fn () => DB::table('bill_payment_fee_rules')->where('id', $rule->id)->update(['fixed_fee' => '2.001']));
                $this->fail('Historical fee mutation must fail.');
            } catch (QueryException) {
                $this->assertSame('2.0000', $rule->fresh()->fixed_fee);
            }
            try {
                DB::transaction(fn () => DB::table('bill_payment_fee_rules')->insert(['currency_id' => $c['currency']->id, 'fixed_fee' => '3.001', 'effective_from' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]));
                $this->fail('Currency precision must be enforced by PostgreSQL.');
            } catch (QueryException) {
                $this->assertDatabaseCount('bill_payment_fee_rules', 1);
            }
        }
    }

    public function test_late_ledger_failure_rolls_back_every_source_and_removes_stored_evidence(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_test_bill_payment_fee_event() RETURNS trigger AS $$
BEGIN
    IF NEW.event_type = 'BILL_PAYMENT_SERVICE_FEE_RETAINED' THEN
        RAISE EXCEPTION 'Injected late ledger failure' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER reject_test_bill_payment_fee_event BEFORE UPDATE OF status ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION reject_test_bill_payment_fee_event();
SQL);

        try {
            app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [$this->image()]);
            $this->fail('The injected third-event failure must abort completion.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected late ledger failure', $exception->getMessage());
        }

        $this->assertSame('REQUESTED', $request->fresh()->status->value);
        foreach (['order_bill_payments', 'order_bill_payment_principal_handovers', 'order_bill_payment_evidence', 'financial_transactions', 'financial_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk(OrderBillPaymentService::DISK)->allFiles());
    }

    public function test_failure_after_evidence_linkage_also_cleans_the_file(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_test_bill_payment_completion() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'COMPLETED' THEN
        RAISE EXCEPTION 'Injected final request failure' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER zz_reject_test_bill_payment_completion AFTER UPDATE ON order_bill_payment_requests
FOR EACH ROW EXECUTE FUNCTION reject_test_bill_payment_completion();
SQL);

        try {
            app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [$this->image()]);
            $this->fail('The injected final failure must abort completion.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected final request failure', $exception->getMessage());
        }
        $this->assertSame('REQUESTED', $request->fresh()->status->value);
        foreach (['order_bill_payments', 'order_bill_payment_principal_handovers', 'order_bill_payment_evidence', 'financial_transactions', 'financial_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk(OrderBillPaymentService::DISK)->allFiles());
    }

    public function test_evidence_bytes_are_authenticated_on_every_read(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $payment = app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [$this->image()]);
        $evidence = $payment->evidence()->sole();
        $this->assertSame(hash('sha256', Storage::disk(OrderBillPaymentService::DISK)->get($evidence->storage_path)), $evidence->sha256);
        $route = route('orders.bill-payments.evidence.show', [$c['order'], $request, $payment, $evidence]);
        $this->actingAs($c['employee'])->get($route)->assertOk();
        Storage::disk(OrderBillPaymentService::DISK)->put($evidence->storage_path, '<svg>altered</svg>');
        $this->actingAs($c['employee'])->get($route)->assertStatus(409);
        Storage::disk(OrderBillPaymentService::DISK)->delete($evidence->storage_path);
        $this->actingAs($c['employee'])->get($route)->assertStatus(409);
        try {
            DB::transaction(fn () => DB::table('order_bill_payment_evidence')->where('id', $evidence->id)->update(['sha256' => str_repeat('0', 64)]));
            $this->fail('Evidence integrity metadata cannot be changed.');
        } catch (QueryException) {
            $this->assertSame($evidence->sha256, $evidence->fresh()->sha256);
        }
    }

    public function test_direct_sql_rejects_nonfinite_and_unrounded_fee_money(): void
    {
        $c = $this->fixture();
        $valid = ['currency_id' => $c['currency']->id, 'fixed_fee' => '1.0000', 'effective_from' => now()->subDays(3), 'effective_until' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()];
        DB::table('bill_payment_fee_rules')->insert($valid);
        $this->assertDatabaseCount('bill_payment_fee_rules', 1);
        foreach (['1.001', '1.00004', 'NaN', 'Infinity', '-Infinity', '0', '-1'] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('bill_payment_fee_rules')->insert([...$valid, 'fixed_fee' => $invalid, 'effective_from' => now()->addDay(), 'effective_until' => null]));
                $this->fail("The database accepted invalid fee {$invalid}.");
            } catch (QueryException) {
                $this->assertDatabaseCount('bill_payment_fee_rules', 1);
            }
        }
        foreach ([0 => ['1', '1.1'], 4 => ['1.2345', '1.23456']] as $places => [$accepted, $rejected]) {
            $currency = Currency::create(['name' => 'Precision '.$places, 'code' => strtoupper(str()->random(3)), 'decimal_places' => $places]);
            DB::table('bill_payment_fee_rules')->insert([...$valid, 'currency_id' => $currency->id, 'fixed_fee' => $accepted]);
            try {
                DB::transaction(fn () => DB::table('bill_payment_fee_rules')->insert([...$valid, 'currency_id' => $currency->id, 'fixed_fee' => $rejected, 'effective_from' => now()->addDay(), 'effective_until' => null]));
                $this->fail("The database accepted excess precision for {$places}-decimal Currency.");
            } catch (QueryException) {
                $this->assertDatabaseHas('bill_payment_fee_rules', ['currency_id' => $currency->id, 'fixed_fee' => $accepted]);
            }
        }
    }

    public function test_direct_sql_rejects_invalid_payment_and_handover_money_without_rounding(): void
    {
        $c = $this->fixture();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $at = now()->startOfSecond();
        $payment = [
            'request_id' => $request->id, 'order_id' => $c['order']->id,
            'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['assignment']->captain_id,
            'currency_id' => $c['currency']->id, 'fee_rule_id' => $request->fee_rule_id,
            'bill_number' => 'SQL-MONEY', 'bill_date' => today()->toDateString(),
            'principal_amount' => '1.0000', 'service_fee' => $request->service_fee,
            'total_customer_cash' => '3.0000',
            'delivered_at' => $c['order']->statusHistory()->where('new_status', 'DELIVERED')->sole()->changed_at,
            'paid_at' => $at, 'paid_by' => $c['employee']->id,
            'payment_reference' => 'SQL-MONEY', 'created_at' => $at, 'updated_at' => $at,
        ];
        foreach (['1.001', '1.00004', 'NaN', 'Infinity', '-Infinity', '0', '-1'] as $invalid) {
            $matchingTotal = match ($invalid) {
                '1.001' => '3.001', '1.00004' => '3.00004', 'NaN' => 'NaN',
                'Infinity' => 'Infinity', '-Infinity' => '-Infinity', '0' => '2', '-1' => '1',
            };
            try {
                DB::transaction(fn () => DB::table('order_bill_payments')->insert([...$payment, 'principal_amount' => $invalid, 'total_customer_cash' => $matchingTotal]));
                $this->fail("The database accepted invalid principal {$invalid}.");
            } catch (QueryException) {
                $this->assertDatabaseCount('order_bill_payments', 0);
            }
        }
        foreach (['1.001', '1.00004', 'NaN', 'Infinity', '-Infinity', '0', '-1'] as $invalid) {
            try {
                DB::transaction(function () use ($payment, $invalid, $c, $at): void {
                    $paymentId = DB::table('order_bill_payments')->insertGetId($payment);
                    DB::table('order_bill_payment_principal_handovers')->insert([
                        'bill_payment_id' => $paymentId, 'order_id' => $c['order']->id,
                        'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['assignment']->captain_id,
                        'currency_id' => $c['currency']->id, 'principal_amount' => $invalid,
                        'received_by' => $c['employee']->id, 'occurred_at' => $at,
                        'created_at' => $at, 'updated_at' => $at,
                    ]);
                });
                $this->fail("The database accepted invalid handover {$invalid}.");
            } catch (QueryException) {
                $this->assertDatabaseCount('order_bill_payments', 0);
                $this->assertDatabaseCount('order_bill_payment_principal_handovers', 0);
            }
        }
    }

    public function test_four_decimal_bill_payment_preserves_exact_principal(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        $currency = Currency::create(['name' => 'Four decimal Currency', 'code' => strtoupper(str()->random(3)), 'decimal_places' => 4]);
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $currency->id, '0.0001', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $currency->id);
        $this->deliver($c);
        $payment = app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request,
            [...$this->paymentData($c), 'currency_id' => $currency->id, 'principal_amount' => '1.2345'], [$this->image()]);
        $this->assertSame('1.2345', $payment->principal_amount);
        $this->assertSame('0.0001', $payment->service_fee);
        $this->assertSame('1.2346', $payment->total_customer_cash);
        $this->assertSame('1.2345', $payment->handover->principal_amount);
    }

    public function test_bill_payment_source_branch_guard_rejects_wrong_branch_in_direct_sql(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2000', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $payment = app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, $this->paymentData($c), [$this->image()]);
        $wrongBranch = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        $account = FinancialTransaction::where('event_type', FinancialEventType::BillPaymentServiceFeeRetained->value)
            ->sole()->entries()->sole()->account;
        try {
            DB::transaction(function () use ($c, $payment, $wrongBranch, $account): void {
                $id = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'currency_id' => $c['currency']->id,
                    'branch_id' => $wrongBranch->id, 'source_type' => 'ORDER_BILL_PAYMENT',
                    'source_id' => (string) $payment->id, 'event_type' => FinancialEventType::BillPaymentServiceFeeRetained->value,
                    'source_version' => 2, 'payload_hash' => str_repeat('a', 64),
                    'occurred_at' => now(), 'created_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $id, 'financial_account_id' => $account->id,
                    'currency_id' => $c['currency']->id, 'direction' => 'DECREASE', 'amount' => '2000', 'created_at' => now(),
                ]);
                DB::select('SELECT validate_captain_financial_transaction_branch(?)', [$id]);
            });
            $this->fail('A misbranched bill event passed the PostgreSQL source guard.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('source/reversal Branch mismatch', $exception->getMessage());
        }
        $this->assertSame(3, FinancialTransaction::count());
    }

    private function fixture(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['name' => 'Dollar', 'code' => strtoupper(str()->random(3)), 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $customer = Customer::create(['name' => 'Bill customer']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $order = Order::create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'order_number' => 'BILL-'.str()->random(8), 'ordered_at' => now(), 'customer_name_snapshot' => 'Bill customer', 'customer_phone_snapshot' => '1234567', 'area_name_snapshot' => 'Area', 'address_line_1_snapshot' => 'Street', 'created_by' => $employee->id]);
        $captain = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $assignment = OrderCaptain::create(['order_id' => $order->id, 'captain_id' => $captain->id, 'assignment_role' => 'SUPPORT', 'assigned_at' => now()->subHour(), 'assigned_by' => $employee->id]);

        return compact('currency', 'branch', 'admin', 'employee', 'order', 'captain', 'assignment');
    }

    private function deliver(array $c): void
    {
        $shop = OrderShop::create(['order_id' => $c['order']->id, 'shop_type' => OrderShopType::OneTime, 'shop_name_snapshot' => 'Shop', 'sort_order' => 1, 'picked_up_at' => now()]);
        $shop->receipts()->create(['storage_path' => 'test/'.str()->uuid().'.png', 'original_filename' => 'test.png', 'mime_type' => 'image/png', 'file_size' => 1, 'uploaded_by' => $c['employee']->id, 'uploaded_at' => now()]);
        $c['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();
        app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), OrderOperationalStatus::Delivered, null);
    }

    private function paymentData(array $c): array
    {
        return ['order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id, 'bill_number' => 'B-100', 'bill_date' => today()->toDateString(), 'principal_amount' => '100000', 'payment_reference' => 'SHAM-123'];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }

    private function expectValidation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a controlled validation error.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('order_bill_payments', 0);
        }
    }
}
