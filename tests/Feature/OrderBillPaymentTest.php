<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderShopType;
use App\Exceptions\FinancialLedgerException;
use App\Livewire\Orders\BillPaymentFeePreview;
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
use Brick\Math\BigDecimal;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
        $this->actingAs($c['employee'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee(__('orders.ui_bill_pending'))->assertDontSee(__('orders.ui_additional_complete'));
        $service->complete($c['employee'], $c['order'], $second, [...$this->paymentData($c), 'bill_number' => 'B-101'], [$this->image()]);
        $this->assertSame(2, $c['order']->billPayments()->count());
        $this->assertSame(6, FinancialTransaction::count());
        $this->assertTrue($c['order']->additionalServicesComplete());
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee(__('orders.ui_additional_complete'))
            ->assertDontSee(__('orders.ui_bill_not_requested'))
            ->assertDontSee('action="'.route('orders.bill-payment-requests.complete', [$c['order'], $first]).'"', false)
            ->assertDontSee('action="'.route('orders.bill-payment-requests.cancel', [$c['order'], $first]).'"', false);
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

    public function test_tiered_syp_boundaries_complete_each_bill_independently_with_exact_financial_sources(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture('SYP', 4);
        $this->assertSame('abshir_testing', DB::selectOne('SELECT current_database() AS name')->name);
        $rules = app(BillPaymentFeeRuleService::class);
        $rule = $rules->create($c['admin'], $c['currency']->id, null, now()->subDay(), $rules::sypTiers());
        $payments = app(OrderBillPaymentService::class);
        $cases = ['299.9999' => '10.0000', '300' => '20.0000', '999.9999' => '20.0000',
            '1000' => '20.0000', '1025' => '20.0000', '1100' => '20.0000', '1124.9999' => '20.0000',
            '1125' => '25.0000', '1125.0001' => '25.0000', '1250' => '25.0000', '1350' => '25.0000',
            '1374.9999' => '25.0000', '1375' => '30.0000', '1375.0001' => '30.0000',
            '1500' => '30.0000', '10000' => '200.0000', '100000' => '2000.0000'];
        $requests = [];
        foreach ($cases as $principal => $fee) {
            $requests[] = $payments->request($c['employee'], $c['order'], $c['currency']->id);
            $this->assertNull(end($requests)->service_fee);
            $this->assertSame($rule->id, end($requests)->fee_rule_id);
        }
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->deliver($c);
        $this->actingAs($c['employee'])->get(route('orders.workflow', $c['order']))->assertOk()
            ->assertSee(__('bill_fees.calculated_later'))
            ->assertSeeHtml('wire:model.live.debounce.250ms="principal"');
        foreach (array_keys($cases) as $index => $principal) {
            $principal = (string) $principal;
            $request = $requests[$index];
            $preview = $payments->preview($c['employee'], $c['order']->fresh(), $request, $principal);
            $this->assertSame($cases[$principal], $preview['fee']);
            Livewire::actingAs($c['employee'])->test(BillPaymentFeePreview::class, ['order' => $c['order'], 'request' => $request])
                ->set('principal', $principal)->assertViewHas('preview', fn ($value) => $value === $preview);
            $sql = DB::selectOne('SELECT calculated_bill_payment_fee(?, ?::numeric)::text AS fee', [$rule->id, $principal]);
            $this->assertTrue(BigDecimal::of($sql->fee)->isEqualTo($cases[$principal]));
            $payment = $payments->complete($c['employee'], $c['order'], $request,
                [...$this->paymentData($c), 'principal_amount' => $principal, 'service_fee' => '99999'], [$this->image()]);
            $this->assertSame($preview['fee'], $payment->service_fee);
            $this->assertSame($preview['total'], $payment->total_customer_cash);
            $this->assertSame($preview['principal'], $payment->principal_amount);
            $this->assertNull($request->fresh()->service_fee);
            DB::select('SELECT validate_completed_bill_payment_at_commit(?)', [$payment->id]);
            $this->assertSame($preview['fee'], FinancialTransaction::where('source_type', 'ORDER_BILL_PAYMENT')
                ->where('source_id', (string) $payment->id)->where('event_type', 'BILL_PAYMENT_SERVICE_FEE_RETAINED')->sole()->entries()->sole()->amount);
        }
        $this->assertDatabaseCount('financial_transactions', count($cases) * 3);
        $this->assertDatabaseCount('invoice_collections', 0);
    }

    public function test_tier_version_is_bound_before_principal_and_completed_history_is_immutable(): void
    {
        Storage::fake(OrderBillPaymentService::DISK);
        $c = $this->fixture('SYP', 0);
        $fees = app(BillPaymentFeeRuleService::class);
        $rule = $fees->create($c['admin'], $c['currency']->id, null, now()->subDay(), $fees::sypTiers());
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $tiers = $fees::sypTiers();
        $tiers[2]['value'] = '3';
        $next = $fees->version($c['admin'], $rule, null, now()->addMinute(), $tiers);
        $this->assertSame($next->id, $rule->fresh()->superseded_by);
        $this->assertSame($next->id, $fees->applicable($c['currency']->id, $next->effective_from)->id);
        $this->assertSame($rule->id, $fees->applicable($c['currency']->id, $next->effective_from->copy()->subMicrosecond())->id);
        $this->travel(2)->minutes();
        $later = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->assertSame($next->id, $later->fee_rule_id);
        $this->deliver($c);
        $payment = app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request,
            [...$this->paymentData($c), 'principal_amount' => '1500'], [$this->image()]);
        $this->assertSame('30.0000', $payment->service_fee);
        $this->assertSame('45.0000', app(OrderBillPaymentService::class)->preview($c['employee'], $c['order']->fresh(), $later, '1500')['fee']);
        try {
            DB::transaction(fn () => DB::table('bill_payment_fee_rules')->where('id', $rule->id)->update(['fee_tiers' => json_encode($tiers)]));
            $this->fail('An immutable schedule was rewritten.');
        } catch (QueryException) {
            $this->assertSame('30.0000', $payment->fresh()->service_fee);
        }
        DB::select('SELECT validate_completed_bill_payment_at_commit(?)', [$payment->id]);
        $this->travelBack();
    }

    public function test_tiered_syp_fee_rounds_once_to_five_with_halfway_up(): void
    {
        $c = $this->fixture('SYP', 0);
        $fees = app(BillPaymentFeeRuleService::class);
        $rule = $fees->create($c['admin'], $c['currency']->id, null, now()->subDay(), $fees::sypTiers());
        $this->assertSame('20.0000', $fees->calculate($rule, $c['currency'], '1025'));
        $this->assertSame('20.0000', $fees->calculate($rule, $c['currency'], '1024'));
        $this->assertSame('20', DB::selectOne('SELECT calculated_bill_payment_fee(?, 1025)::text AS fee', [$rule->id])->fee);
        $this->assertSame('25.0000', $fees->calculate($rule, $c['currency'], '1125'));
        $this->assertSame('25', DB::selectOne('SELECT calculated_bill_payment_fee(?, 1125)::text AS fee', [$rule->id])->fee);
        $this->assertSame(0, $c['currency']->fresh()->decimal_places);
        foreach (['299.9999', '0', '-1', 'NaN', 'Infinity', '1.00001'] as $invalid) {
            try {
                $fees->calculate($rule, $c['currency'], $invalid);
                $this->fail('Invalid money accepted.');
            } catch (FinancialLedgerException) {
                $this->assertDatabaseCount('financial_transactions', 0);
            }
        }
    }

    public function test_fixed_syp_and_other_currency_fees_are_never_rounded_to_five(): void
    {
        $c = $this->fixture('SYP', 4);
        $fees = app(BillPaymentFeeRuleService::class);
        $syp = $fees->create($c['admin'], $c['currency']->id, '22.5001', now()->subDay());
        $other = Currency::create(['name' => 'Dollar', 'code' => 'USD', 'decimal_places' => 4]);
        $usd = $fees->create($c['admin'], $other->id, '22.5001', now()->subDay());
        foreach ([[$syp, $c['currency']], [$usd, $other]] as [$rule, $currency]) {
            $this->assertSame('22.5001', $fees->calculate($rule, $currency, '1125.1234'));
            $this->assertTrue(BigDecimal::of(DB::selectOne('SELECT calculated_bill_payment_fee(?, 1125.1234)::text AS fee', [$rule->id])->fee)->isEqualTo('22.5001'));
            $this->assertSame(4, $currency->fresh()->decimal_places);
        }
    }

    public function test_postgresql_tier_configuration_is_syp_only_on_insert_and_update(): void
    {
        $c = $this->fixture('SYP', 4);
        $fees = app(BillPaymentFeeRuleService::class);
        $other = Currency::create(['name' => 'Dollar', 'code' => 'USD', 'decimal_places' => 4]);
        try {
            DB::transaction(fn () => DB::table('bill_payment_fee_rules')->insert(['currency_id' => $other->id,
                'fee_mode' => 'TIERED', 'fixed_fee' => null, 'fee_tiers' => json_encode($fees::sypTiers()),
                'effective_from' => now()->subDay(), 'created_by' => $c['admin']->id]));
            $this->fail('PostgreSQL accepted non-SYP tiers on INSERT.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Tiered SYP bill fee', $exception->getMessage());
        }
        $rule = $fees->create($c['admin'], $c['currency']->id, null, now()->subDay(), $fees::sypTiers());
        try {
            DB::transaction(fn () => DB::table('bill_payment_fee_rules')->where('id', $rule->id)->update(['currency_id' => $other->id]));
            $this->fail('PostgreSQL accepted non-SYP tiers on UPDATE.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Tiered SYP bill fee', $exception->getMessage());
        }
        $this->assertSame($c['currency']->id, $rule->fresh()->currency_id);
    }

    public function test_ambiguous_and_non_syp_tiers_are_rejected_in_service_and_postgresql(): void
    {
        $c = $this->fixture('SYP', 4);
        $fees = app(BillPaymentFeeRuleService::class);
        $invalids = [];
        foreach (['299', '301'] as $from) {
            $tiers = $fees::sypTiers();
            $tiers[1]['from'] = $from;
            $invalids[] = $tiers;
        }
        $tiers = $fees::sypTiers();
        $tiers[2]['until'] = '2000';
        $invalids[] = $tiers;
        $tiers = $fees::sypTiers();
        $tiers[2]['value'] = 'NaN';
        $invalids[] = $tiers;
        foreach ($invalids as $tiers) {
            $this->expectValidation(fn () => $fees->create($c['admin'], $c['currency']->id, null, now()->subDay(), $tiers));
            try {
                DB::transaction(fn () => DB::table('bill_payment_fee_rules')->insert(['currency_id' => $c['currency']->id,
                    'fee_mode' => 'TIERED', 'fee_tiers' => json_encode($tiers), 'fixed_fee' => null,
                    'effective_from' => now()->subDay(), 'created_by' => $c['admin']->id]));
                $this->fail('PostgreSQL accepted malformed or overlapping ranges.');
            } catch (QueryException) {
                $this->assertDatabaseCount('bill_payment_fee_rules', 0);
            }
        }
        $other = Currency::create(['name' => 'Other', 'code' => 'USD', 'decimal_places' => 2]);
        $this->expectValidation(fn () => $fees->create($c['admin'], $other->id, null, now()->subDay(), $fees::sypTiers()));
        $fees->create($c['admin'], $c['currency']->id, null, now()->subDay(), $fees::sypTiers());
        $this->expectValidation(fn () => $fees->create($c['admin'], $c['currency']->id, null, now(), $fees::sypTiers()));
    }

    public function test_tiered_admin_http_configuration_and_preview_permissions(): void
    {
        $c = $this->fixture('SYP', 0);
        $this->actingAs($c['employee'])->post(route('admin.bill-payment-fees.store'), [])->assertForbidden();
        $this->actingAs($c['admin'])->get(route('admin.bill-payment-fees.index'))->assertOk()->assertSee(__('bill_fees.tiered'));
        $this->post(route('admin.bill-payment-fees.store'), ['currency_id' => $c['currency']->id, 'fee_mode' => 'TIERED',
            'fee_tiers' => BillPaymentFeeRuleService::sypTiers(), 'effective_from' => now()->subDay()->toDateTimeString()])->assertSessionHasNoErrors()->assertRedirect();
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        Livewire::actingAs($c['employee'])->test(BillPaymentFeePreview::class, ['order' => $c['order'], 'request' => $request])
            ->set('principal', '1500')->assertSee('30.0000')->assertSee('1530.0000')->assertSee(__('bill_fees.total'))
            ->set('principal', '0')->assertDontSeeHtml('data-bill-fee-preview')
            ->set('principal', '300')->assertSee('320')->set('principal', '')->assertSee(__('bill_fees.enter_principal'));
        $other = User::factory()->create();
        $other->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        Livewire::actingAs($other)->test(BillPaymentFeePreview::class, ['order' => $c['order'], 'request' => $request])->assertNotFound();
        $unauthorized = User::factory()->create();
        Livewire::actingAs($unauthorized)->test(BillPaymentFeePreview::class, ['order' => $c['order'], 'request' => $request])->assertForbidden();
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertNull($request->fresh()->service_fee);
    }

    public function test_postgresql_rejects_forged_fee_and_request_snapshot_for_tiered_schedule(): void
    {
        $c = $this->fixture('SYP', 0);
        $fees = app(BillPaymentFeeRuleService::class);
        $rule = $fees->create($c['admin'], $c['currency']->id, null, now()->subDay(), $fees::sypTiers());
        try {
            DB::transaction(fn () => $c['order']->billPaymentRequests()->create(['currency_id' => $c['currency']->id,
                'fee_rule_id' => $rule->id, 'service_fee' => '10', 'status' => 'REQUESTED', 'requested_at' => now(), 'requested_by' => $c['employee']->id]));
            $this->fail('A fabricated tiered request fee was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('order_bill_payment_requests', 0);
        }
        $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        try {
            DB::transaction(fn () => DB::table('order_bill_payments')->insert(['request_id' => $request->id,
                'order_id' => $c['order']->id, 'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['captain']->id,
                'currency_id' => $c['currency']->id, 'fee_rule_id' => $rule->id, 'bill_number' => 'FORGED', 'bill_date' => today(),
                'principal_amount' => '1025', 'service_fee' => '21', 'total_customer_cash' => '1046',
                'delivered_at' => $c['order']->statusHistory()->where('new_status', 'DELIVERED')->sole()->changed_at,
                'paid_at' => now(), 'paid_by' => $c['employee']->id, 'payment_reference' => 'FORGED']));
            $this->fail('PostgreSQL accepted a forged completed fee.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('does not match its request', $exception->getMessage());
            $this->assertDatabaseCount('order_bill_payments', 0);
        }
    }

    private function fixture(?string $code = null, int $precision = 2): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['name' => 'Currency', 'code' => $code ?? strtoupper(str()->random(3)), 'decimal_places' => $precision]);
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
