<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Models\FinancialTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BillPaymentFeeRuleService;
use App\Services\FinancialBalanceQueryService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCancellationService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderFinancialSourceSnapshotService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopReturnService;
use App\Services\OrderStatusService;
use App\Services\OrderWorkflowData;
use App\Services\SharedOrderShopPurchaseService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SharedOrderExceptionsTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public static function retirementModes(): array
    {
        return [['void'], ['replace']];
    }

    #[DataProvider('retirementModes')]
    public function test_uncollected_shared_invoice_retirement_preserves_original_snapshot_and_actual_payers(string $mode): void
    {
        $c = $this->context();
        $this->deliver($c);
        $invoice = $c['order']->currentInvoice()->sole();
        $before = DB::table('order_financial_source_snapshots')->where('invoice_id', $invoice->id)->sole();
        $lines = DB::table('order_financial_source_snapshot_lines')->orderBy('id')->get()->toJson();
        $service = app(InvoiceService::class);
        if ($mode === 'void') {
            $service->void($c['admin'], $c['order'], $invoice, 'Uncollected version retired');
            $this->assertSame(InvoiceStatus::Voided, $invoice->fresh()->status);
            $this->assertSame($c['admin']->id, $invoice->fresh()->voided_by);
            $this->assertNull($c['order']->currentInvoice()->first());
        } else {
            $replacement = $service->replace($c['admin'], $c['order'], $invoice, 'Uncollected replacement');
            $this->assertSame(InvoiceStatus::Replaced, $invoice->fresh()->status);
            $this->assertSame($replacement->id, $invoice->fresh()->replaced_by_invoice_id);
            $this->assertSame($invoice->version + 1, $replacement->version);
            $this->assertSame($invoice->grand_total, $replacement->grand_total);
            $this->assertSame(InvoiceStatus::Ready, $replacement->status);
            $this->assertSame($replacement->id, $c['order']->currentInvoice()->sole()->id);
            $issued = $service->issue($c['admin'], $c['order'], $replacement);
            $this->assertSame($c['collector']->id, app(OrderFinancialSourceSnapshotService::class)->read($issued)['primary_order_captain_id']);
        }
        $this->assertEquals($before, DB::table('order_financial_source_snapshots')->where('invoice_id', $invoice->id)->sole());
        $this->assertSame($lines, DB::table('order_financial_source_snapshot_lines')->where('order_financial_source_snapshot_id', $before->id)->orderBy('id')->get()->toJson());
        DB::select('SELECT validate_financial_snapshot_fingerprint(?)', [$before->id]);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['items_subtotal' => '1'])), QueryException::class);
        $this->assertThrows(fn () => $service->replace($c['admin'], $c['order'], $invoice, 'Duplicate'), ValidationException::class);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('order_shop_purchase_allocations', 2);
    }

    public function test_collected_shared_invoice_and_returns_stay_blocked_with_zero_writes_and_no_guessed_refund_recipient(): void
    {
        $c = $this->context();
        $this->deliver($c);
        $invoice = $c['order']->currentInvoice()->sole();
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $tables = ['orders', 'invoices', 'invoice_collections', 'financial_transactions', 'financial_entries', 'order_shop_purchase_allocations', 'order_financial_source_snapshots'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->assertThrows(fn () => app(InvoiceFinancialCorrectionService::class)->request($c['admin'], $invoice, InvoiceFinancialCorrectionKind::CancelAndRefund, [], 'Refund', 'Do not guess payer', (string) str()->uuid()), ValidationException::class);
        $this->assertThrows(fn () => app(InvoiceService::class)->void($c['admin'], $c['order'], $invoice, 'Refund'), ValidationException::class);
        $this->assertThrows(fn () => app(InvoiceService::class)->replace($c['admin'], $c['order'], $invoice, 'Refund'), ValidationException::class);
        $this->assertThrows(fn () => app(OrderCancellationIntentService::class)->create($c['admin'], $c['order'], OrderCancellationReason::CustomerCancelled, null, (string) str()->uuid()), ValidationException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'VOIDED', 'voided_by' => $c['admin']->id, 'voided_at' => now(), 'void_reason' => 'Forged', 'superseded_at' => now()])), QueryException::class);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $this->assertDatabaseCount('customer_refunds', 0);
        $this->assertDatabaseCount('order_shop_returns', 0);
        $this->assertDatabaseCount('invoice_financial_corrections', 0);
    }

    public function test_before_purchase_cancellation_cancels_pending_shared_bill_and_preserves_selection_without_financial_effect(): void
    {
        $c = $this->context();
        $service = app(OrderBillPaymentService::class);
        $request = $service->request($c['actor'], $c['order'], $c['currency']->id);
        app(OrderCancellationService::class)->cancel($c['actor'], $c['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);
        $this->assertSame('CANCELLED', $c['order']->fresh()->operational_status->value);
        $this->assertSame('CANCELLED', $request->fresh()->status->value);
        $this->assertDatabaseCount('order_captain_selections', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertThrows(fn () => $service->request($c['actor'], $c['order'], $c['currency']->id), ValidationException::class);
    }

    public function test_shared_after_purchase_returns_and_participation_changes_are_precisely_blocked_before_any_write(): void
    {
        $c = $this->context();
        $purchase = app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        $payers = $purchase->allocations()->orderBy('order_captain_id')->get()->toJson();
        $this->assertThrows(fn () => app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'], OrderCancellationReason::CustomerCancelled, null, (string) str()->uuid()), ValidationException::class);
        try {
            app(OrderShopReturnService::class)->record($c['actor'], $c['order'], $c['shop'], [
                'returning_order_captain_id' => $c['collector']->id, 'refund_amount' => '80',
                'returned_at' => now()->toIso8601String(), 'refunded_at' => now()->toIso8601String(),
                'refund_method' => 'CASH', 'request_key' => (string) str()->uuid(),
            ], ['RETURN_AND_REFUND_PROOF' => $this->sharedImage()]);
            $this->fail('A single-recipient return cannot represent two actual payers.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('shared_orders.shared_return_blocked'), $exception->errors()['order'][0]);
        }
        $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], [$c['captains'][0]->id], $c['captains'][0]->id, (string) str()->uuid(), 'Change after purchase'), ValidationException::class);
        $this->assertSame($payers, $purchase->allocations()->orderBy('order_captain_id')->get()->toJson());
        $this->assertDatabaseCount('order_shop_returns', 0);
        $this->assertDatabaseCount('order_cancellation_intents', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_shared_bill_cannot_bypass_missing_fee_configuration_or_inactive_activation(): void
    {
        $c = $this->context(false);
        $service = app(OrderBillPaymentService::class);
        $this->assertThrows(fn () => $service->request($c['actor'], $c['order'], $c['currency']->id), ValidationException::class);
        $this->assertSame(__('orders.ui_bill_fee_unavailable'), app(OrderWorkflowData::class)->load($c['actor'], $c['order'])['billPaymentRequestProblem']);
        DB::transaction(function () use ($c, $service): void {
            DB::statement('ALTER FUNCTION shared_bill_payment_collector(bigint) RENAME TO test_pending_shared_bill_collector');
            try {
                $this->assertSame(__('shared_orders.bill_activation_required'), $service->contractProblem($c['order']));
                $this->assertThrows(fn () => $service->request($c['actor'], $c['order'], $c['currency']->id), ValidationException::class);
            } finally {
                DB::statement('ALTER FUNCTION test_pending_shared_bill_collector(bigint) RENAME TO shared_bill_payment_collector');
            }
        });
        $this->assertDatabaseCount('order_bill_payment_requests', 0);
    }

    public function test_shared_bill_uses_selected_collector_and_existing_exact_syp_fee_without_invoice_effect(): void
    {
        $c = $this->context();
        $service = app(OrderBillPaymentService::class);
        $request = $service->request($c['actor'], $c['order'], $c['currency']->id);
        $this->assertNull($request->service_fee);
        $this->assertNull(app(OrderWorkflowData::class)->load($c['actor'], $c['order'])['billPaymentRequestProblem']);
        $this->deliver($c);
        $preview = $service->preview($c['actor'], $c['order'], $request, '1125');
        $this->assertSame(['principal' => '1125.0000', 'fee' => '25.0000', 'total' => '1150.0000'], $preview);
        $this->actingAs($c['actor'])->get(route('orders.workflow', $c['order']))->assertOk()->assertSee(__('shared_orders.bill_selected_collector'));
        $payment = $service->complete($c['actor'], $c['order'], $request, $this->billData($c), [$this->sharedImage()]);
        $this->assertSame($c['collector']->id, $payment->order_captain_id);
        $this->assertSame($c['captains'][1]->id, $payment->captain_id);
        $this->assertSame($preview['fee'], $payment->service_fee);
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('financial_entries', 3);
        $this->assertDatabaseCount('invoice_collections', 0);
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('-25.0000', $balances->captainBranchBalance($c['captains'][1], $c['branch'], $c['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $balances->captainBranchBalance($c['captains'][0], $c['branch'], $c['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $balances->captainBranchBalance($c['captains'][1], $c['branch'], $c['currency'], FinancialAccountKind::CaptainCashCustody));
        $this->assertSame(1, $payment->evidence()->count());
        DB::select('SELECT validate_completed_bill_payment_at_commit(?)', [$payment->id]);
        $this->assertThrows(fn () => $service->complete($c['actor'], $c['order'], $request, $this->billData($c), [$this->sharedImage()]), ValidationException::class);
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_bill_payments')->where('id', $payment->id)->update(['principal_amount' => '100'])), QueryException::class);
    }

    public function test_shared_bill_rejects_support_captain_and_wrong_branch_without_writes(): void
    {
        $c = $this->context();
        $service = app(OrderBillPaymentService::class);
        $request = $service->request($c['actor'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $support = $c['order']->captainAssignments()->whereNull('removed_at')->where('id', '<>', $c['collector']->id)->sole();
        $this->assertThrows(fn () => $service->complete($c['actor'], $c['order'], $request, [...$this->billData($c), 'order_captain_id' => $support->id], [$this->sharedImage()]), ValidationException::class);
        $outside = User::factory()->create();
        $outside->roles()->attach($c['actor']->roles()->pluck('roles.id'));
        $this->actingAs($outside)->post(route('orders.bill-payment-requests.store', $c['order']), ['currency_id' => $c['currency']->id])->assertForbidden();
        $this->actingAs($outside)->post(route('orders.bill-payment-requests.complete', [$c['order'], $request]), $this->billData($c) + ['evidence' => [$this->sharedImage()]])->assertForbidden();
        $this->assertDatabaseCount('order_bill_payments', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame([], Storage::disk(OrderBillPaymentService::DISK)->allFiles());
    }

    public function test_shared_bill_sql_cannot_substitute_a_different_collector(): void
    {
        $c = $this->context();
        $request = app(OrderBillPaymentService::class)->request($c['actor'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $support = $c['order']->captainAssignments()->whereNull('removed_at')->where('id', '<>', $c['collector']->id)->sole();
        $data = [...$this->billData($c), 'order_captain_id' => $support->id];
        unset($data['payment_reference']);
        try {
            DB::transaction(fn () => DB::table('order_bill_payments')->insert($data + [
                'order_id' => $c['order']->id, 'request_id' => $request->id, 'fee_rule_id' => $request->fee_rule_id,
                'captain_id' => $support->captain_id, 'service_fee' => '25', 'total_customer_cash' => '1150',
                'paid_by' => $c['actor']->id, 'payment_reference' => 'SQL', 'paid_at' => now(),
                'delivered_at' => $c['order']->statusHistory()->where('new_status', 'DELIVERED')->value('changed_at'),
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('SQL must enforce the selected collector, not merely an active participant.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('selected collecting Captain', $exception->getMessage());
        }
        $this->assertDatabaseCount('order_bill_payments', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_shared_bill_late_posting_failure_rolls_back_sources_and_cleans_private_evidence(): void
    {
        $c = $this->context();
        $request = app(OrderBillPaymentService::class)->request($c['actor'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION test_reject_shared_bill_fee() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.event_type='BILL_PAYMENT_SERVICE_FEE_RETAINED' THEN
        RAISE EXCEPTION 'Injected shared bill fee failure' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER test_reject_shared_bill_fee BEFORE UPDATE OF status ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION test_reject_shared_bill_fee();
SQL);
        try {
            app(OrderBillPaymentService::class)->complete($c['actor'], $c['order'], $request, $this->billData($c), [$this->sharedImage()]);
            $this->fail('A late posting failure must roll back the entire completion.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected shared bill fee failure', $exception->getMessage());
        }
        $this->assertSame('REQUESTED', $request->fresh()->status->value);
        foreach (['order_bill_payments', 'order_bill_payment_principal_handovers', 'order_bill_payment_evidence', 'financial_transactions', 'financial_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk(OrderBillPaymentService::DISK)->allFiles());
        $this->assertSame(InvoiceStatus::Issued, $c['order']->currentInvoice()->sole()->status);
        $this->assertDatabaseCount('order_shop_purchase_allocations', 2);
    }

    public function test_shared_bill_events_keep_canonical_source_type_and_legacy_order_posting_stays_closed(): void
    {
        $c = $this->context();
        $request = app(OrderBillPaymentService::class)->request($c['actor'], $c['order'], $c['currency']->id);
        $this->deliver($c);
        $payment = app(OrderBillPaymentService::class)->complete($c['actor'], $c['order'], $request, $this->billData($c), [$this->sharedImage()]);
        foreach (FinancialTransaction::get() as $event) {
            $this->assertSame($c['order']->id, (int) DB::scalar('SELECT order_financial_event_source_order_id(t) FROM financial_transactions t WHERE id=?', [$event->id]));
            $forged = ['transaction_kind' => 'EVENT', 'source_type' => 'UNSUPPORTED', 'source_id' => (string) $payment->id,
                'source_version' => 1, 'event_type' => $event->event_type];
            $this->assertThrows(fn () => DB::transaction(fn () => DB::select('SELECT order_financial_event_source_order_id(jsonb_populate_record(NULL::financial_transactions,?::jsonb))', [json_encode($forged)])), QueryException::class);
        }
        $invoice = $c['order']->currentInvoice()->sole();
        $this->assertThrows(fn () => DB::transaction(fn () => DB::select('SELECT order_financial_event_source_order_id(jsonb_populate_record(NULL::financial_transactions,?::jsonb))', [json_encode([
            'transaction_kind' => 'EVENT', 'source_type' => 'CUSTOMER_INVOICE', 'source_id' => (string) $invoice->id,
            'source_version' => 1, 'event_type' => 'ORDER_PRODUCT_REIMBURSEMENT',
        ])])), QueryException::class);
        $this->assertDatabaseCount('financial_transactions', 3);
    }

    public function test_shared_uncollected_retirement_preserves_existing_permission_and_branch_checks(): void
    {
        $c = $this->context();
        $this->deliver($c);
        $invoice = $c['order']->currentInvoice()->sole();
        $this->actingAs($c['actor'])->post(route('orders.invoices.replace', [$c['order'], $invoice]), ['reason' => 'Unapproved actor'])->assertForbidden();
        $role = Role::create(['code' => 'TEST_INVOICE_EXCEPTION', 'name' => 'Test Invoice exception']);
        $role->permissions()->attach(Permission::whereIn('code', ['invoices.void', 'invoices.replace'])->pluck('id'));
        $outside = User::factory()->create();
        $outside->roles()->attach($role);
        $this->actingAs($outside)->post(route('orders.invoices.replace', [$c['order'], $invoice]), ['reason' => 'Wrong branch'])->assertForbidden();
        $this->actingAs($outside)->post(route('orders.invoices.void', [$c['order'], $invoice]), ['reason' => 'Wrong branch'])->assertForbidden();
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    protected function context(bool $withFees = true): array
    {
        Storage::fake(OrderBillPaymentService::DISK);
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext(4);
        $c['currency']->update(['code' => 'SYP']);
        $selection = app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][1]->id, (string) str()->uuid(), 'Explicit collector');
        $c['collector'] = $c['order']->captainAssignments()->findOrFail($selection->collecting_order_captain_id);
        if ($withFees) {
            app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, null, now()->subDay(), BillPaymentFeeRuleService::sypTiers());
        }

        return $c;
    }

    protected function deliver(array $c): void
    {
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $service = app(InvoiceService::class);
        $service->issue($c['actor'], $c['order'], $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order'])));
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
    }

    protected function billData(array $c): array
    {
        return ['order_captain_id' => $c['collector']->id, 'currency_id' => $c['currency']->id,
            'bill_number' => 'SHARED-BILL', 'bill_date' => today()->toDateString(),
            'principal_amount' => '1125', 'payment_reference' => 'SHAM-REFERENCE'];
    }
}
