<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\DeliveryPricingRule;
use App\Models\FinancialTransaction;
use App\Models\InvoiceCollection;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBenefitRequestService;
use App\Services\CaptainBenefitReviewService;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\DeliveryPricingRuleService;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialSourceSnapshotService;
use App\Services\OrderPricingService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderShopPurchaseService;
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

class OrderAccountingV2Test extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    #[DataProvider('scenarios')]
    public function test_persisted_four_scenarios_preserve_invoice_collector_accounts_and_idempotency(int $count, string $method, string $total, string $custody, string $receivable, int $events, int $entries): void
    {
        [$c, $invoice] = $this->prepared($count);
        $snapshot = DB::table('order_financial_source_snapshots')->where('invoice_id', $invoice->id)->sole();
        $this->assertSame(2, (int) $c['order']->fresh()->accounting_contract_version);
        $this->assertSame($total, $invoice->grand_total);
        $collection = $this->collect($c, $invoice, $method);
        $this->assertSame($total, $collection->amount);
        $this->assertSame($method === 'CASH' ? $c['captains'][0]->id : null, $collection->captain_id);
        $this->assertSame(0, DB::table('financial_entries')->whereIn('financial_transaction_id', DB::table('financial_transactions')
            ->where('event_type', 'ORDER_V2_DELIVERY_ENTITLEMENT_DUE')->select('id'))->count());
        $this->deliver($c);
        foreach ($c['captains'] as $index => $captain) {
            $balances = app(FinancialBalanceQueryService::class)->captainBalances($captain, $c['currency']);
            $this->assertSame($receivable, $balances[FinancialAccountKind::CaptainCompanyReceivable->value]);
            $this->assertSame($index === 0 ? $custody : '0.0000', $balances[FinancialAccountKind::CaptainCashCustody->value]);
            $this->assertSame('0.0000', $balances[FinancialAccountKind::CaptainDeposit->value]);
        }
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame('complete', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        $this->assertDatabaseCount('financial_transactions', $events);
        $this->assertDatabaseCount('financial_entries', $entries);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_collections', 1);
        $this->assertSame([2], DB::table('financial_transactions')->distinct()->pluck('source_version')->all());
        $this->assertSame($method === 'CASH' ? 0 : 1, DB::table('financial_transactions')->where('event_type', 'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE')->count());
        $this->assertSame((array) $snapshot, (array) DB::table('order_financial_source_snapshots')->where('id', $snapshot->id)->sole());
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertDatabaseCount('captain_deposit_receipts', 0);
        $this->assertDatabaseCount('order_bill_payment_principal_handovers', 0);
        $this->assertSame(0, DB::table('financial_entries')->where('direction', 'DECREASE')->count());
        $this->assertThrows(fn () => $this->collect($c, $invoice, $method), ValidationException::class);
        $this->assertDatabaseCount('financial_transactions', $events);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function scenarios(): array
    {
        return [
            'single CASH' => [1, 'CASH', '220.0000', '120.0000', '60.0000', 2, 2],
            'shared CASH' => [2, 'CASH', '340.0000', '240.0000', '60.0000', 2, 3],
            'single SHAM_CASH' => [1, 'SHAM_CASH', '220.0000', '0.0000', '160.0000', 2, 2],
            'shared SHAM_CASH' => [2, 'SHAM_CASH', '340.0000', '0.0000', '110.0000', 2, 4],
        ];
    }

    #[DataProvider('receivableConcepts')]
    public function test_postgresql_prevents_equal_captain_a_entries_substituting_for_captain_b(string $event, string $amount): void
    {
        [$c, $invoice] = $this->prepared(2);
        $this->deliver($c);
        $accounts = app(FinancialAccountService::class);
        $owner = $accounts->ownerForCaptain($c['captains'][0]);
        $account = $accounts->getOrCreate($owner, $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        $snapshot = app(OrderFinancialSourceSnapshotService::class)->read($invoice);

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_owners')->insert([
            'owner_type' => 'CAPTAIN', 'captain_id' => $c['captains'][0]->id,
        ])), fn (QueryException $e) => $e->errorInfo[0] === '23505', 'financial_owners_captain_id_unique');
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_accounts')->insert([
            'financial_owner_id' => $owner->id, 'currency_id' => $c['currency']->id,
            'account_kind' => 'CAPTAIN_COMPANY_RECEIVABLE',
        ])), fn (QueryException $e) => $e->errorInfo[0] === '23505', 'financial_accounts_identity_unique');
        $this->assertThrows(fn () => DB::transaction(function () use ($c, $invoice, $event, $amount, $account, $snapshot): void {
            $collectionId = DB::table('invoice_collections')->insertGetId([
                'order_id' => $c['order']->id, 'invoice_id' => $invoice->id, 'collection_method' => 'SHAM_CASH',
                'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total,
                'collected_at' => now(), 'recorded_by' => $c['actor']->id, 'payment_reference' => 'Direct SQL adversarial fixture',
            ]);
            $transactionId = DB::table('financial_transactions')->insertGetId([
                'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'currency_id' => $c['currency']->id,
                'branch_id' => $c['branch']->id, 'source_type' => 'INVOICE_COLLECTION', 'source_id' => (string) $collectionId,
                'event_type' => $event, 'source_version' => 2, 'payload_hash' => str_repeat('0', 64),
                'occurred_at' => now(), 'recorded_by_user_id' => $c['actor']->id, 'notes' => $snapshot['payload_hash'],
            ]);
            $entry = ['financial_transaction_id' => $transactionId, 'financial_account_id' => $account->id,
                'currency_id' => $c['currency']->id, 'direction' => 'INCREASE', 'amount' => $amount];
            DB::table('financial_entries')->insert($entry);
            // Direct SQL reaches the actual UNIQUE constraint, without PHP deduplication or a POSTED-row guard.
            DB::table('financial_entries')->insert($entry);
        }), fn (QueryException $e) => $e->errorInfo[0] === '23505', 'financial_entries_transaction_account_unique');
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('financial_entries', 0);

        $this->collect($c, $invoice, 'SHAM_CASH');
        $actual = DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->where('t.event_type', $event)->orderBy('o.captain_id')->pluck('e.amount', 'o.captain_id')->all();
        $expected = [$c['captains'][0]->id => $amount, $c['captains'][1]->id => $amount];
        ksort($expected);
        $this->assertSame($expected, $actual);
        $this->assertSame(2, DB::table('financial_entries')->whereIn('financial_transaction_id',
            DB::table('financial_transactions')->where('event_type', $event)->select('id'))->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function receivableConcepts(): array
    {
        return [
            'delivery entitlement' => ['ORDER_V2_DELIVERY_ENTITLEMENT_DUE', '60.0000'],
            'product reimbursement' => ['ORDER_V2_PRODUCT_REIMBURSEMENT_DUE', '50.0000'],
        ];
    }

    #[DataProvider('invalidPostings')]
    public function test_postgresql_rejects_incorrect_v2_source_or_money_with_zero_writes(string $defect): void
    {
        [$c, $invoice] = $this->prepared(2);
        $this->assertThrows(fn () => DB::transaction(function () use ($c, $invoice, $defect): void {
            $collection = InvoiceCollection::create(['order_id' => $c['order']->id, 'invoice_id' => $invoice->id,
                'collection_method' => 'CASH', 'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total,
                'collected_at' => now(), 'recorded_by' => $c['actor']->id, 'captain_id' => $c['captains'][0]->id,
                'order_captain_id' => $c['assignments'][0]->id]);
            $snapshot = app(OrderFinancialSourceSnapshotService::class)->read($invoice);
            $kind = match ($defect) {
                'deposit' => FinancialAccountKind::CaptainDeposit, 'receivable' => FinancialAccountKind::CaptainCompanyReceivable, default => FinancialAccountKind::CaptainCashCustody
            };
            $captain = $c['captains'][$defect === 'noncollector' ? 1 : 0];
            $accounts = app(FinancialAccountService::class);
            $currency = $defect === 'wrong currency' ? Currency::create(['code' => 'ZZZ', 'name' => 'Other', 'decimal_places' => 0]) : $c['currency'];
            $account = $accounts->getOrCreate($accounts->ownerForCaptain($captain), $currency, $kind);
            $event = match ($defect) {
                'cash products' => 'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE', 'premature earnings' => 'ORDER_V2_DELIVERY_ENTITLEMENT_DUE', 'V1 event' => 'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT', default => 'ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED'
            };
            $transaction = app(FinancialPostingService::class)->postEvent($currency, $defect === 'unsupported source' ? 'ARBITRARY' : 'INVOICE_COLLECTION',
                $defect === 'missing source' ? 999999 : $collection->id, $event, $defect === 'wrong version' ? 1 : 2,
                $defect === 'wrong time' ? now()->subSecond() : $collection->collected_at,
                [['account' => $account, 'direction' => $defect === 'decrease' ? FinancialEntryDirection::Decrease : FinancialEntryDirection::Increase,
                    'amount' => match ($defect) {
                        'full invoice' => '340', 'company share' => '120', default => '240'
                    }]],
                $defect === 'wrong branch' ? Branch::factory()->create() : $c['branch'],
                $defect === 'wrong actor' ? $c['admin'] : $c['actor'], null, $defect === 'wrong snapshot' ? 'wrong' : $snapshot['payload_hash']);
            DB::select('SELECT validate_order_accounting_v2_event(?)', [$transaction->id]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }), QueryException::class);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    public static function invalidPostings(): array
    {
        return array_combine($values = ['full invoice', 'company share', 'noncollector', 'deposit', 'receivable', 'decrease', 'cash products',
            'premature earnings', 'wrong version', 'unsupported source', 'missing source', 'V1 event', 'wrong time', 'wrong branch', 'wrong currency', 'wrong actor', 'wrong snapshot'],
            array_map(fn ($value) => [$value], $values));
    }

    public function test_missing_companion_and_version_downgrade_are_rejected_by_sql(): void
    {
        [$c, $invoice] = $this->prepared(1);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $c['order']->id)->update(['accounting_contract_version' => 1])), QueryException::class);
        $facts = $c['order']->getAttributes();
        unset($facts['id']);
        $facts['order_number'] .= '-FORGED';
        $facts['accounting_contract_version'] = 1;
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('orders')->insert($facts)), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(function () use ($c, $invoice): void {
            DB::table('invoice_collections')->insert(['order_id' => $c['order']->id, 'invoice_id' => $invoice->id, 'collection_method' => 'CASH',
                'currency_id' => $c['currency']->id, 'amount' => '220', 'collected_at' => now(), 'recorded_by' => $c['actor']->id,
                'captain_id' => $c['captains'][0]->id, 'order_captain_id' => $c['assignments'][0]->id]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }), QueryException::class);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertSame(2, (int) $c['order']->fresh()->accounting_contract_version);
    }

    #[DataProvider('discounts')]
    public function test_combined_discount_changes_actual_custody_once_without_reducing_full_entitlement(string $discount, string $custody, int $events): void
    {
        [$c, $invoice] = $this->prepared(2, $discount);
        $this->collect($c, $invoice, 'CASH');
        $this->deliver($c);
        foreach ($c['captains'] as $index => $captain) {
            $balances = app(FinancialBalanceQueryService::class)->captainBalances($captain, $c['currency']);
            $this->assertSame('60.0000', $balances['CAPTAIN_COMPANY_RECEIVABLE']);
            $this->assertSame($index === 0 ? $custody : '0.0000', $balances['CAPTAIN_CASH_CUSTODY']);
            $this->assertSame('0.0000', $balances['CAPTAIN_DEPOSIT']);
        }
        $this->assertDatabaseCount('financial_transactions', $events);
        $this->assertSame(0, DB::table('financial_entries')->where('amount', '<=', 0)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function discounts(): array
    {
        return ['once' => ['15', '225.0000', 2], 'company shortfall' => ['230', '10.0000', 2], 'zero custody' => ['240', '0.0000', 1]];
    }

    public function test_final_event_failure_rolls_back_collection_and_every_prior_event(): void
    {
        [$c, $invoice] = $this->prepared(2);
        $this->deliver($c);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION test_fail_v2_earned() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN IF NEW.event_type='ORDER_V2_DELIVERY_ENTITLEMENT_DUE' THEN RAISE EXCEPTION 'Injected final failure' USING ERRCODE='23514'; END IF; RETURN NEW; END; $$;
CREATE TRIGGER test_fail_v2_earned BEFORE INSERT ON financial_transactions FOR EACH ROW EXECUTE FUNCTION test_fail_v2_earned();
SQL);
        $this->assertThrows(fn () => $this->collect($c, $invoice, 'CASH'), QueryException::class);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertSame(OrderOperationalStatus::Delivered, $c['order']->fresh()->operational_status);
    }

    public function test_v2_financial_reads_and_writes_keep_branch_and_role_authorization(): void
    {
        [$c, $invoice] = $this->prepared(1);
        $other = $this->sharedContext(2, 1);
        $this->assertThrows(fn () => app(InvoiceCollectionService::class)->record($other['actor'], $invoice, InvoiceCollectionMethod::Cash, '220', $c['currency']->id, now()), HttpException::class);
        $this->assertThrows(fn () => app(InvoiceCollectionService::class)->record(User::factory()->create(), $invoice, InvoiceCollectionMethod::Cash, '220', $c['currency']->id, now()), AuthorizationException::class);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->collect($c, $invoice, 'CASH');
        $this->deliver($c);
        $this->assertSame('60.0000', app(FinancialBalanceQueryService::class)->captainBalances($c['captains'][0], $c['currency'])['CAPTAIN_COMPANY_RECEIVABLE']);
    }

    public function test_collected_v2_correction_and_unattributed_reversal_fail_closed(): void
    {
        [$c, $invoice] = $this->prepared(1);
        $this->collect($c, $invoice, 'CASH');
        $this->assertThrows(fn () => app(InvoiceFinancialCorrectionService::class)->request($c['admin'], $invoice,
            InvoiceFinancialCorrectionKind::CancelAndRefund, [], 'Customer request', 'No assumed settlement', (string) str()->uuid()), ValidationException::class);
        $event = FinancialTransaction::sole();
        $this->assertThrows(fn () => app(FinancialPostingService::class)->reverse($event, 'MANUAL', '1', 'REVERSAL', 1, now(), 'Unsupported refund', $c['branch'], $c['admin']), QueryException::class);
        $this->assertDatabaseCount('invoice_financial_corrections', 0);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    #[DataProvider('paymentMethods')]
    public function test_existing_evidenced_single_captain_payout_is_explicit_and_does_not_net_custody_or_deposit(string $method): void
    {
        [$c, $invoice] = $this->prepared(1);
        $this->collect($c, $invoice, $method);
        $this->deliver($c);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        Storage::fake('captain_financial_evidence');
        $balances = app(FinancialBalanceQueryService::class);
        $source = $balances->payableReceivableEntries($c['captains'][0], $c['branch'], $c['currency'])->firstWhere('event_type', 'ORDER_V2_DELIVERY_ENTITLEMENT_DUE');
        $this->assertNotNull($source);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $payout = app(CaptainReceivablePayoutService::class)->record($c['admin'], $c['captains'][0], $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '60', 'occurred_at' => now()->format('Y-m-d H:i:s.uP')],
            [['financial_entry_id' => $source->financial_entry_id, 'amount' => '60']], $this->sharedImage());
        DB::select("SELECT validate_captain_financial_operation('PAYOUT', ?)", [$payout->id]);
        $actual = $balances->captainBalances($c['captains'][0], $c['currency']);
        $this->assertSame($method === 'CASH' ? '0.0000' : '100.0000', $actual['CAPTAIN_COMPANY_RECEIVABLE']);
        $this->assertSame($method === 'CASH' ? '120.0000' : '0.0000', $actual['CAPTAIN_CASH_CUSTODY']);
        $this->assertSame('0.0000', $actual['CAPTAIN_DEPOSIT']);
        $this->assertDatabaseCount('captain_receivable_payouts', 1);
        $paidEvent = DB::table('financial_transactions')->where('source_type', 'CAPTAIN_RECEIVABLE_PAYOUT')->where('source_id', (string) $payout->id)->value('id');
        $this->assertSame($before, DB::table('financial_entries')->where('financial_transaction_id', '<>', $paidEvent)->orderBy('id')->get()->toJson());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function paymentMethods(): array
    {
        return [['CASH'], ['SHAM_CASH']];
    }

    public function test_shared_order_payout_stays_blocked_while_independent_approved_bonus_is_payable(): void
    {
        [$c, $invoice] = $this->prepared(2);
        $this->collect($c, $invoice, 'CASH');
        $this->deliver($c);
        Storage::fake('captain_financial_evidence');
        $captain = $c['captains'][0];
        $proposal = app(CaptainBenefitRequestService::class)->save($c['admin'], $captain, [
            'request_key' => (string) str()->uuid(), 'kind' => 'BONUS', 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'amount' => '20', 'description' => 'Independent bonus',
        ]);
        $reviewer = User::factory()->create();
        $reviewer->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $reviews = app(CaptainBenefitReviewService::class);
        $reviews->act($c['admin'], $captain, $proposal, ['request_key' => (string) str()->uuid(), 'action' => 'SUBMIT']);
        $reviews->act($reviewer, $captain, $proposal, ['request_key' => (string) str()->uuid(), 'action' => 'APPROVE']);
        $balances = app(FinancialBalanceQueryService::class);
        $payable = $balances->payableReceivableEntries($captain, $c['branch'], $c['currency'])->sole();
        $this->assertSame('CAPTAIN_BONUS_DUE', $payable->event_type);
        $gated = $balances->gatedCorrectedReceivableEntries($captain, $c['branch'], $c['currency'])->first();
        $service = app(CaptainReceivablePayoutService::class);
        $data = ['request_key' => (string) str()->uuid(), 'amount' => '20', 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            'payment_method' => 'CASH', 'received_by_user_id' => $captain->user_id];
        $this->assertThrows(fn () => $service->record($c['admin'], $captain, $c['branch'], $c['currency'], $data,
            [['financial_entry_id' => $gated->financial_entry_id, 'amount' => '20']], $this->sharedImage()), ValidationException::class);
        $payout = $service->record($c['admin'], $captain, $c['branch'], $c['currency'], $data,
            [['financial_entry_id' => $payable->financial_entry_id, 'amount' => '20']], $this->sharedImage());
        $this->assertSame('60.0000', $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('240.0000', $balances->captainBranchBalance($captain, $c['branch'], $c['currency'], FinancialAccountKind::CaptainCashCustody));
        try {
            DB::transaction(function () use ($payout, $gated): void {
                $facts = $payout->getRawOriginal();
                unset($facts['id']);
                $facts['request_key'] = (string) str()->uuid();
                $facts['amount'] = '1';
                $id = DB::table('captain_receivable_payouts')->insertGetId($facts);
                DB::table('captain_receivable_payout_allocations')->insert(['captain_receivable_payout_id' => $id,
                    'financial_entry_id' => $gated->financial_entry_id, 'amount' => '1', 'created_at' => now()]);
            });
            $this->fail('SQL accepted a shared V2 payout');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Shared V2 payout', $exception->getMessage());
        }
        $this->assertDatabaseCount('captain_receivable_payouts', 1);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    protected function prepared(int $count = 2, ?string $discount = null): array
    {
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext(0, $count);
        $c['shop']->items()->sole()->update(['actual_unit_price' => '100']);
        app(OrderPricingService::class)->recalculate($c['order']);
        $old = DeliveryPricingRule::where('currency_id', $c['currency']->id)->sole();
        app(DeliveryPricingRuleService::class)->version($c['admin'], $old, [
            'from_area_id' => $old->from_area_id, 'to_area_id' => $old->to_area_id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '120', 'effective_from' => now()->subHours(2)]);
        app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['shop']->originArea);
        if ($count === 2) {
            app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
        }
        if ($discount !== null) {
            app(OrderDeliveryPricingService::class)->price($c['admin'], $c['order'], $c['shop']->originArea, null, $discount, 'Approved company-funded discount');
        }
        $data = $this->sharedData($c, '100');
        $service = $count === 2 ? SharedOrderShopPurchaseService::class : OrderShopPurchaseConfirmationService::class;
        app($service)->record($c['actor'], $c['order'], $c['shop'], $data + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($c['actor'], $c['order'], $invoices->refresh($c['actor'], $c['order'], $invoices->createDraft($c['actor'], $c['order'])));

        return [$c, $invoice];
    }

    protected function collect(array $c, $invoice, string $method): InvoiceCollection
    {
        return app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::from($method), $invoice->grand_total,
            $c['currency']->id, now(), null, $method === 'SHAM_CASH' ? 'Actual direct payment' : null);
    }

    protected function deliver(array $c): void
    {
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
    }
}
