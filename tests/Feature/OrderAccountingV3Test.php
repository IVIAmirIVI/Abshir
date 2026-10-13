<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\DeliveryPricingRule;
use App\Models\FinancialTransaction;
use App\Services\CaptainDepositManualAdjustmentEvidenceService;
use App\Services\CaptainDepositManualAdjustmentService;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\ManagementReportQuery;
use App\Services\OrderAccountingV2Service;
use App\Services\OrderCancellationService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialSourceSnapshotService;
use App\Services\OrderPricingService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class OrderAccountingV3Test extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
        config(['order_accounting.enable_v3_single_captain' => true]);
    }

    #[DataProvider('cashBalances')]
    public function test_cash_retains_delivery_and_debits_only_frozen_company_share(string $starting, string $captainPercent, ?string $discount, string $expectedDeposit, string $expectedDue): void
    {
        [$c, $invoice] = $this->prepared($captainPercent, $discount);
        $this->assertSame(3, (int) $c['order']->fresh()->accounting_contract_version);
        if ($starting !== '0') {
            $this->opening($c, ltrim($starting, '-'), str_starts_with($starting, '-') ? 'DECREASE' : 'INCREASE');
        }
        $collection = $this->collect($c, $invoice, 'CASH');
        $this->assertSame(0, DB::table('financial_transactions')->where('source_type', 'INVOICE_COLLECTION')->count());
        $this->deliver($c);
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame($expectedDeposit, $balances->captainBranchBalance($c['captains'][0], $c['branch'], $c['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame($expectedDue, $balances->captainBranchBalance($c['captains'][0], $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', $balances->captainBranchBalance($c['captains'][0], $c['branch'], $c['currency'], FinancialAccountKind::CaptainCashCustody));
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame('complete', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        $snapshot = app(OrderFinancialSourceSnapshotService::class)->read($invoice);
        foreach (FinancialTransaction::where('source_type', 'INVOICE_COLLECTION')->get() as $event) {
            $this->assertSame((string) $collection->id, $event->source_id);
            $this->assertSame(3, $event->source_version);
            $this->assertSame($c['branch']->id, $event->branch_id);
            $this->assertSame($snapshot['payload_hash'], $event->notes);
            $this->assertSame($c['actor']->id, $event->recorded_by_user_id);
        }
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertDatabaseCount('captain_deposit_receipts', 0);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertThrows(fn () => $this->collect($c, $invoice, 'CASH'), ValidationException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function cashBalances(): array
    {
        return [
            'zero' => ['0', '50', null, '-60.0000', '0.0000'],
            'positive to negative' => ['40', '50', null, '-20.0000', '0.0000'],
            'negative to more negative' => ['-100', '50', null, '-160.0000', '0.0000'],
            'company 30 percent' => ['0', '70', null, '-36.0000', '0.0000'],
            'company-funded discount' => ['0', '50', '30', '-30.0000', '0.0000'],
            'company-funded shortfall' => ['0', '50', '90', '0.0000', '30.0000'],
        ];
    }

    public function test_sham_cash_preserves_real_obligations_without_cash_debit_or_custody(): void
    {
        [$c, $invoice] = $this->prepared();
        $this->collect($c, $invoice, 'SHAM_CASH');
        $this->deliver($c);
        $balances = app(FinancialBalanceQueryService::class);
        $actual = $balances->captainBalances($c['captains'][0], $c['currency']);
        $this->assertSame('160.0000', $actual['CAPTAIN_COMPANY_RECEIVABLE']);
        $this->assertSame('0.0000', $actual['CAPTAIN_DEPOSIT']);
        $this->assertSame('0.0000', $actual['CAPTAIN_CASH_CUSTODY']);
        $this->assertSame(['ORDER_V3_SHAM_DELIVERY_ENTITLEMENT_DUE', 'ORDER_V3_SHAM_PRODUCT_REIMBURSEMENT_DUE'], FinancialTransaction::orderBy('event_type')->pluck('event_type')->all());
        Storage::fake('captain_financial_evidence');
        $source = $balances->payableReceivableEntries($c['captains'][0], $c['branch'], $c['currency'])->firstWhere('event_type', 'ORDER_V3_SHAM_DELIVERY_ENTITLEMENT_DUE');
        $this->assertNotNull($source);
        app(CaptainReceivablePayoutService::class)->record($c['admin'], $c['captains'][0], $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '60', 'occurred_at' => now()->format('Y-m-d H:i:s.uP')],
            [['financial_entry_id' => $source->financial_entry_id, 'amount' => '60']], $this->sharedImage());
        $this->assertSame('100.0000', $balances->captainBranchBalance($c['captains'][0], $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_branch_currency_isolation_and_management_balances_read_actual_ledger(): void
    {
        [$c, $invoice] = $this->prepared();
        $otherBranch = Branch::factory()->create(['default_currency_id' => $c['currency']->id]);
        $c['captains'][0]->branchAssignments()->create(['assignment_type' => 'TEMPORARY', 'branch_id' => $otherBranch->id,
            'starts_at' => now()->subDay(), 'reason' => 'Independent branch balance fixture']);
        $otherCurrency = Currency::create(['name' => 'Other currency', 'code' => 'OTH', 'decimal_places' => 0]);
        $this->opening($c, '40', 'INCREASE', $otherBranch);
        $this->opening($c, '100', 'INCREASE', null, $otherCurrency);
        $this->collect($c, $invoice, 'CASH');
        $this->deliver($c);
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('-60.0000', $balances->captainBranchBalance($c['captains'][0], $c['branch'], $c['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('40.0000', $balances->captainBranchBalance($c['captains'][0], $otherBranch, $c['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('100.0000', $balances->captainBranchBalance($c['captains'][0], $c['branch'], $otherCurrency, FinancialAccountKind::CaptainDeposit));
        $report = app(ManagementReportQuery::class)->snapshot($c['actor'], ['from' => '2026-10-08', 'to' => '2026-10-08', 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id]);
        $this->assertSame('-60.0000', $report['financial']['balances']['rows'][0]['CAPTAIN_DEPOSIT']);
        $this->assertSame(3, $report['financial']['collections']['versions'][0]['version']);
        $this->assertSame('0', $report['financial']['balances']['rows'][0]['CAPTAIN_CASH_CUSTODY']);
        $this->assertSame('0', $report['financial']['balances']['rows'][0]['CAPTAIN_COMPANY_RECEIVABLE']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_v2_binding_and_postings_are_unchanged_after_v3_application_activation(): void
    {
        config(['order_accounting.enable_v3_single_captain' => false]);
        [$c, $invoice] = $this->prepared();
        $this->assertSame(2, (int) $c['order']->fresh()->accounting_contract_version);
        config(['order_accounting.enable_v3_single_captain' => true]);
        $this->collect($c, $invoice, 'CASH');
        $this->deliver($c);
        $this->assertSame(['ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED', 'ORDER_V2_DELIVERY_ENTITLEMENT_DUE'], FinancialTransaction::orderBy('event_type')->pluck('event_type')->all());
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $c['order']->id)->update(['accounting_contract_version' => 3])), QueryException::class);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_shared_v3_is_blocked_in_services_and_sql(): void
    {
        $c = $this->sharedContext(0, 1, true);
        $other = Captain::factory()->create(['home_branch_id' => $c['branch']->id, 'operational_status' => 'AVAILABLE']);
        $other->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $this->assertThrows(fn () => app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $other, OrderCaptainRole::Support), ValidationException::class);
        $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], [$c['captains'][0]->id, $other->id], $c['captains'][0]->id, (string) str()->uuid()), ValidationException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_captains')->insert([
            'order_id' => $c['order']->id, 'captain_id' => $other->id, 'assignment_role' => 'SUPPORT', 'assigned_at' => now(), 'assigned_by' => $c['actor']->id,
        ])), QueryException::class);
        $this->assertSame(1, $c['order']->captainAssignments()->whereNull('removed_at')->count());
        $this->assertDatabaseCount('financial_transactions', 0);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_posting_requires_delivery_and_collection_and_blocks_unattributed_reversal_or_correction(): void
    {
        [$c, $invoice] = $this->prepared();
        $this->assertSame('waiting_collection', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        $this->deliver($c);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->collect($c, $invoice, 'CASH');
        $event = FinancialTransaction::sole();
        $this->assertThrows(fn () => app(OrderAccountingV2Service::class)->postIfReady($c['order']), FinancialLedgerException::class);
        $this->assertThrows(fn () => app(InvoiceFinancialCorrectionService::class)->request($c['admin'], $invoice,
            InvoiceFinancialCorrectionKind::CancelAndRefund, [], 'Refund', 'Unapproved settlement', (string) str()->uuid()), ValidationException::class);
        $this->assertThrows(fn () => app(FinancialPostingService::class)->reverse($event, 'MANUAL', '1', 'REVERSAL', 1, now(), 'Unsupported refund', $c['branch'], $c['admin']), QueryException::class);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('invoice_financial_corrections', 0);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_unpurchased_v3_order_can_be_cancelled_without_a_financial_reversal(): void
    {
        $c = $this->sharedContext(0, 1, true);
        app(OrderCancellationService::class)->cancel($c['admin'], $c['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, 'No purchase or payment');
        $this->assertSame(OrderOperationalStatus::Cancelled, $c['order']->fresh()->operational_status);
        $this->assertDatabaseCount('financial_transactions', 0);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('invalidEvents')]
    public function test_sql_rejects_legacy_or_malformed_v3_events_without_writes(string $eventType, int $version, string $source): void
    {
        [$c, $invoice] = $this->prepared();
        $collection = $this->collect($c, $invoice, 'CASH');
        $accounts = app(FinancialAccountService::class);
        $account = $accounts->getOrCreate($accounts->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainDeposit);
        $snapshot = app(OrderFinancialSourceSnapshotService::class)->read($invoice);
        $this->assertThrows(fn () => DB::transaction(function () use ($c, $source, $collection, $eventType, $version, $account, $snapshot): void {
            app(FinancialPostingService::class)->postEvent($c['currency'], $source, $collection->id, $eventType, $version, now(),
                [['account' => $account, 'direction' => FinancialEntryDirection::Decrease, 'amount' => '60']], $c['branch'], $c['actor'], null, $snapshot['payload_hash']);
            // The real PostgreSQL boundary is transaction commit; RefreshDatabase holds an outer transaction.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }), QueryException::class);
        $this->assertDatabaseCount('financial_transactions', 0);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_postgresql_refuses_a_delivered_cash_collection_missing_its_operating_debit(): void
    {
        [$c, $invoice] = $this->prepared();
        $this->deliver($c);
        $this->assertThrows(fn () => DB::transaction(function () use ($c, $invoice): void {
            DB::table('invoice_collections')->insert([
                'order_id' => $c['order']->id, 'invoice_id' => $invoice->id, 'collection_method' => 'CASH',
                'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total, 'collected_at' => now(),
                'recorded_by' => $c['actor']->id, 'captain_id' => $c['captains'][0]->id, 'order_captain_id' => $c['assignments'][0]->id,
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }), fn (QueryException $exception) => $exception->errorInfo[0] === '23514', 'V3 financial set is incomplete');
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->collect($c, $invoice, 'CASH');
        $event = FinancialTransaction::sole();
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_entries')->where('financial_transaction_id', $event->id)->update(['amount' => '1'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_transactions')->where('id', $event->id)->delete()), QueryException::class);
        $this->assertSame('60.0000', DB::table('financial_entries')->sole()->amount);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function invalidEvents(): array
    {
        return [
            'V1 on V3' => ['ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT', 1, 'INVOICE_COLLECTION'],
            'V2 on V3' => ['ORDER_V2_DELIVERY_ENTITLEMENT_DUE', 2, 'INVOICE_COLLECTION'],
            'wrong V3 version' => ['ORDER_V3_CASH_COMPANY_SHARE_DEBIT', 2, 'INVOICE_COLLECTION'],
            'unsupported source' => ['ORDER_V3_CASH_COMPANY_SHARE_DEBIT', 3, 'UNSUPPORTED'],
            'premature effect' => ['ORDER_V3_CASH_COMPANY_SHARE_DEBIT', 3, 'INVOICE_COLLECTION'],
        ];
    }

    private function prepared(string $captainPercent = '50', ?string $discount = null): array
    {
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext(0, 1, true);
        $c['shop']->items()->sole()->update(['actual_unit_price' => '100']);
        app(OrderPricingService::class)->recalculate($c['order']);
        $old = DeliveryPricingRule::where('currency_id', $c['currency']->id)->sole();
        app(DeliveryPricingRuleService::class)->version($c['admin'], $old, [
            'from_area_id' => $old->from_area_id, 'to_area_id' => $old->to_area_id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '120', 'effective_from' => now()->subHours(2),
        ]);
        app(DeliveryPricingSettings::class)->update($c['admin'], $captainPercent, '0');
        app(OrderDeliveryPricingService::class)->price($c['admin'], $c['order'], $c['shop']->originArea, null, $discount ?? '0', $discount !== null ? 'Company-funded discount' : null);
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, '100') + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $service = app(InvoiceService::class);
        $invoice = $service->issue($c['actor'], $c['order'], $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order'])));

        return [$c, $invoice];
    }

    private function collect(array $c, $invoice, string $method): object
    {
        return app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::from($method), $invoice->grand_total,
            $c['currency']->id, now(), null, $method === 'SHAM_CASH' ? 'Direct company payment' : null);
    }

    private function deliver(array $c): void
    {
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
    }

    private function opening(array $c, string $amount, string $direction, ?Branch $branch = null, ?Currency $currency = null): void
    {
        Storage::fake(CaptainDepositManualAdjustmentEvidenceService::DISK);
        app(CaptainDepositManualAdjustmentService::class)->record($c['admin'], $c['captains'][0], $branch ?? $c['branch'], $currency ?? $c['currency'], [
            'request_key' => (string) str()->uuid(), 'direction' => $direction, 'amount' => $amount, 'reason' => 'Independent opening balance fixture', 'occurred_at' => now()->subSecond()->format('Y-m-d H:i:s.uP'),
        ], $this->sharedImage());
    }
}
