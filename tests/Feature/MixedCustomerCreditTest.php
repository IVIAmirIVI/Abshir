<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderOperationalStatus;
use App\Livewire\Orders\Workflow;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\CustomerCreditEntry;
use App\Models\FinancialTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\CustomerCreditService;
use App\Services\CustomerReturnService;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\MixedCustomerCreditService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCancellationService;
use App\Services\OrderStatusService;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CustomerReturnFixtures;
use Tests\TestCase;

class MixedCustomerCreditTest extends TestCase
{
    use CustomerReturnFixtures, RefreshDatabase;

    private function grant(array $c, string $amount = '250'): CustomerCreditEntry
    {
        return app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
            'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'reason' => 'OVERCHARGE', 'amount' => $amount, 'reference' => (string) str()->uuid(), 'notes' => 'UAT prior delivery overcharge',
            'occurred_at' => now()->subSecond()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function settlement(array $c, int $grantId, string $credit = '30'): array
    {
        return ['request_key' => (string) str()->uuid(), 'allocations' => [$grantId => $credit],
            'actual_amount' => (string) BigDecimal::of($c['invoice']->grand_total)->minus($credit)->toScale(4),
            'collected_at' => now()->format('Y-m-d H:i:s.uP'), 'notes' => 'UAT documented delivery credit settlement', 'payment_reference' => 'UAT actual payment'];
    }

    private function deliver(array $c): void
    {
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
    }

    #[DataProvider('contracts')]
    public function test_mixed_collection_preserves_invoice_entitlements_and_posts_only_actual_cash(int $count, InvoiceCollectionMethod $method, string $credit): void
    {
        $c = $this->sold($count, collect: false, deliveryFee: '100');
        $grant = $this->grant($c);
        $invoiceBefore = DB::table('invoices')->where('id', $c['invoice']->id)->sole();
        $snapshotBefore = DB::table('order_financial_source_snapshots')->where('invoice_id', $c['invoice']->id)->sole();
        $data = $this->settlement($c, $grant->id, $credit);
        $service = app(MixedCustomerCreditService::class);
        $collection = $service->record($c['actor'], $c['invoice'], $method, $data);
        $this->assertSame($data['actual_amount'], $collection->amount);
        $this->assertSame((string) BigDecimal::of($credit)->toScale(4), $service->creditAmount($collection));
        $this->assertSame((string) BigDecimal::of('250')->minus($credit)->toScale(4), app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        $this->deliver($c);
        $this->assertEquals($invoiceBefore, DB::table('invoices')->where('id', $c['invoice']->id)->sole());
        $this->assertEquals($snapshotBefore, DB::table('order_financial_source_snapshots')->where('invoice_id', $c['invoice']->id)->sole());
        $balances = app(FinancialBalanceQueryService::class);
        foreach ($c['captains'] as $index => $captain) {
            $rows = $balances->captainBalances($captain, $c['currency']);
            $receivable = $method === InvoiceCollectionMethod::ShamCash ? ($count === 1 ? '130' : '90')
                : ($count === 2 ? '50' : (BigDecimal::of($credit)->isGreaterThan('50') ? (string) BigDecimal::of($credit)->minus('50') : '0'));
            $this->assertSame((string) BigDecimal::of($receivable)->toScale(4), $rows[FinancialAccountKind::CaptainCompanyReceivable->value]);
            $custody = $count === 2 && $index === 0 && $method === InvoiceCollectionMethod::Cash ? (string) BigDecimal::of('200')->minus($credit) : '0';
            $this->assertSame((string) BigDecimal::of($custody)->toScale(4), $rows[FinancialAccountKind::CaptainCashCustody->value]);
            $deposit = $count === 1 && $method === InvoiceCollectionMethod::Cash && BigDecimal::of($credit)->isLessThan('50') ? (string) BigDecimal::of($credit)->minus('50') : '0';
            $this->assertSame((string) BigDecimal::of($deposit)->toScale(4), $rows[FinancialAccountKind::CaptainDeposit->value]);
        }
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $this->assertSame($collection->id, $service->record($c['actor'], $c['invoice'], $method, $data)->id);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('customer_credit_redemptions', 1);
        $this->assertSame('complete', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function contracts(): array
    {
        return [[1, InvoiceCollectionMethod::Cash, '30'], [1, InvoiceCollectionMethod::Cash, '80'],
            [1, InvoiceCollectionMethod::Cash, '100'], [1, InvoiceCollectionMethod::ShamCash, '30'],
            [2, InvoiceCollectionMethod::Cash, '30'], [2, InvoiceCollectionMethod::Cash, '200'], [2, InvoiceCollectionMethod::ShamCash, '30']];
    }

    public function test_workflow_collects_mixed_tender_in_one_existing_action_and_shows_actual_amounts(): void
    {
        $c = $this->sold(collect: false, deliveryFee: '100');
        $grant = $this->grant($c, '30');
        $this->actingAs($c['actor']);
        Livewire::test(Workflow::class, ['order' => $c['order']])
            ->call('openStep', 'invoice')->assertSee(__('credit.delivery_settlement'))
            ->set('creditAllocations', [$grant->id => '30'])->set('collectionActualAmount', '150')
            ->set('collectedAt', now()->format('Y-m-d\TH:i:s'))->call('collectInvoice')->assertHasNoErrors()
            ->call('collectInvoice')->assertHasNoErrors();
        $this->assertDatabaseCount('invoice_collections', 1);
        $this->assertDatabaseCount('customer_credit_redemptions', 1);
        $this->assertDatabaseHas('invoice_collections', ['invoice_id' => $c['invoice']->id, 'amount' => '150']);
        $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee(__('credit.actual_payment'))->assertSee(__('credit.redeemed'));
    }

    #[DataProvider('invoiceDisplaySettlements')]
    public function test_invoice_detail_distinguishes_actual_payment_from_credit_without_changing_financial_records(InvoiceCollectionMethod $method, string $products, string $credit, string $actual, string $total): void
    {
        $c = $this->sold(collect: false, productAmount: $products, deliveryFee: '100');
        $grant = $this->grant($c, $credit);
        app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], $method, $this->settlement($c, $grant->id, $credit));
        $tables = ['invoices', 'invoice_collections', 'customer_credit_settlements', 'customer_credit_redemptions', 'financial_transactions', 'financial_entries'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);

        $response = $this->actingAs($c['actor'])->get(route('orders.invoices.show', [$c['order'], $c['invoice']]));

        $response->assertOk()->assertSee(__('credit.fully_settled'))
            ->assertDontSee(__('financial.ui.collected_full'))
            ->assertSeeInOrder([__('financial.ui.total'), $total.' '.$c['currency']->code,
                __('credit.actual_payment'), __('financial.collection_methods.'.$method->value), $actual.' '.$c['currency']->code,
                __('credit.redeemed'), $credit.' '.$c['currency']->code,
                __('credit.settled_total'), $total.' '.$c['currency']->code])
            ->assertSee(__('credit.mixed_settlement_help'));
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $foreign->branches()->attach(Branch::factory()->create());
        $this->actingAs($foreign)->get(route('orders.invoices.show', [$c['order'], $c['invoice']]))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('orders.invoices.show', [$c['order'], $c['invoice']]))->assertForbidden();
    }

    public static function invoiceDisplaySettlements(): array
    {
        return [
            'CASH 270 plus credit 30' => [InvoiceCollectionMethod::Cash, '200', '30', '270', '300'],
            'SHAM_CASH 200 plus credit 50' => [InvoiceCollectionMethod::ShamCash, '150', '50', '200', '250'],
        ];
    }

    public function test_ordinary_invoice_detail_keeps_collection_display_even_with_unused_customer_credit(): void
    {
        $c = $this->sold(productAmount: '200', method: InvoiceCollectionMethod::Cash, deliveryFee: '100');
        $this->grant($c, '30');

        $response = $this->actingAs($c['actor'])->get(route('orders.invoices.show', [$c['order'], $c['invoice']]));

        $response->assertOk()->assertSee(__('financial.ui.collected_full'))
            ->assertSee(__('financial.collection_methods.CASH'))
            ->assertDontSee(__('credit.fully_settled'))->assertDontSee(__('credit.redeemed'));
        $this->assertDatabaseCount('customer_credit_settlements', 0);
        $this->assertDatabaseHas('invoice_collections', ['invoice_id' => $c['invoice']->id, 'amount' => '300.0000']);
    }

    #[DataProvider('badAmounts')]
    public function test_invalid_selection_or_actual_amount_rolls_back_without_consuming_credit(string $defect): void
    {
        $c = $this->sold(collect: false, deliveryFee: '100');
        $grant = $this->grant($c, '30');
        $data = $this->settlement($c, $grant->id);
        match ($defect) {
            'overspend' => $data = $this->settlement($c, $grant->id, '31'),
            'delivery cap' => $data = $this->settlement($c, $grant->id, '101'),
            'fake cash' => $data['actual_amount'] = '180',
            'negative' => $data['allocations'][$grant->id] = '-1',
            'fraction' => $data['allocations'][$grant->id] = '0.001',
            'missing grant' => $data['allocations'] = [999999 => '30'],
            'before grant' => $data['collected_at'] = now()->subMinute()->toIso8601String(),
        };
        $this->assertThrows(fn () => app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $data), ValidationException::class);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('customer_credit_settlements', 0);
        $this->assertDatabaseCount('customer_credit_redemptions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertSame('30.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
    }

    public static function badAmounts(): array
    {
        return array_map(fn ($value) => [$value], ['overspend', 'delivery cap', 'fake cash', 'negative', 'fraction', 'missing grant', 'before grant']);
    }

    public function test_notification_failure_rolls_back_collection_redemption_and_custody_then_retry_succeeds(): void
    {
        $c = $this->sold(2, collect: false, deliveryFee: '100');
        $grant = $this->grant($c);
        $data = $this->settlement($c, $grant->id);
        DB::unprepared("CREATE FUNCTION credit_fail_notice() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''credit notice failed''; END'; CREATE TRIGGER credit_fail_notice BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION credit_fail_notice()");
        try {
            $this->assertThrows(fn () => app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $data), QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER credit_fail_notice ON notifications; DROP FUNCTION credit_fail_notice()');
        }
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('customer_credit_redemptions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertSame('250.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $data);
        $this->assertDatabaseCount('invoice_collections', 1);
    }

    public function test_unfunded_return_credit_becomes_captain_debt_only_as_explicitly_redeemed(): void
    {
        $original = $this->sold();
        $originalTime = now()->toImmutable();
        $returnData = $this->data($original) + ['compensation_method' => 'CREDIT'];
        $returnData['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $original['assignments'][0]->id, 'amount' => '80']];
        $returns = app(CustomerReturnService::class);
        $case = $returns->record($original['actor'], $original['order'], $returnData);
        $grant = DB::table('customer_credit_entries')->where('source_return_case_id', $case->id)->sole();
        $this->assertSame('0.0000', $returns->summary($original['actor'], $original['order'])->sole()->responsibilities->sole()->outstanding);
        // A second legitimate Order reuses the Customer, Branch and Currency, preserving original financial sources.
        $c = $this->sold(collect: false, deliveryFee: '10', master: $original);
        $this->travelTo($originalTime->addSecond());
        $data = $this->settlement($c, $grant->id, '10');
        app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $data);
        $this->assertSame('10.0000', $returns->summary($original['actor'], $original['order'])->sole()->responsibilities->sole()->outstanding);
        $this->assertSame('10.0000', $returns->captainLiabilities($original['actor'], $original['captains'][0])->sole()->funded_amount);
        $this->assertThrows(fn () => $returns->move($original['actor'], $case, $this->movement($original, '11', 'RECOVERY') + ['responsibility_id' => $case->responsibilities()->sole()->id], $this->proof('over funded credit')), ValidationException::class);
        $returns->move($original['actor'], $case, $this->movement($original, '4', 'RECOVERY') + ['responsibility_id' => $case->responsibilities()->sole()->id], $this->proof('actual repayment'));
        $this->assertSame('6.0000', $returns->summary($original['actor'], $original['order'])->sole()->responsibilities->sole()->outstanding);
    }

    public function test_credit_permission_and_branch_boundaries_are_rechecked_server_side(): void
    {
        $c = $this->sold(collect: false, deliveryFee: '100');
        $grant = $this->grant($c);
        $data = $this->settlement($c, $grant->id);
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->detach(Permission::where('code', 'customer_credits.redeem')->sole());
        $service = app(MixedCustomerCreditService::class);
        $this->assertThrows(fn () => $service->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $data), AuthorizationException::class);
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->attach(Permission::where('code', 'customer_credits.redeem')->sole());
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $foreign->branches()->attach(Branch::factory()->create());
        try {
            $service->record($foreign, $c['invoice'], InvoiceCollectionMethod::Cash, $data);
            $this->fail('Foreign Branch accepted credit collection.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('customer_credit_redemptions', 0);
        $this->assertDatabaseCount('invoice_collections', 0);
    }

    public function test_credit_can_be_explicitly_split_across_grants_and_future_orders_without_implicit_fifo(): void
    {
        $first = $this->sold(collect: false, deliveryFee: '100');
        $firstTime = now()->toImmutable();
        $second = $this->sold(collect: false, master: $first);
        $this->travelTo($firstTime->addSecond());
        $a = $this->grant($first, '30');
        $b = $this->grant($first, '150');
        $service = app(MixedCustomerCreditService::class);
        $one = $this->settlement($first, $b->id, '70');
        $one['allocations'] = [$a->id => '20', $b->id => '50'];
        $service->record($first['actor'], $first['invoice'], InvoiceCollectionMethod::Cash, $one);
        $this->assertSame('110.0000', app(CustomerCreditService::class)->balances($first['actor'], $first['order']->customer)->sole()->available);
        $service->record($second['actor'], $second['invoice'], InvoiceCollectionMethod::ShamCash, $this->settlement($second, $b->id, '100'));
        $this->assertSame('10.0000', app(CustomerCreditService::class)->balances($first['actor'], $first['order']->customer)->sole()->available);
        $this->assertSame('20.0000', DB::table('customer_credit_redemptions')->where('grant_id', $a->id)->sum('amount'));
        $this->assertSame('150.0000', DB::table('customer_credit_redemptions')->where('grant_id', $b->id)->sum('amount'));
        $this->assertSame(2, app(CustomerCreditService::class)->history($first['actor'], $first['order']->customer)->where('reason', 'DELIVERY_REDEMPTION')->count());
    }

    public function test_cancel_before_collection_has_no_credit_reservation_and_settled_history_cannot_be_reversed(): void
    {
        $unbought = $this->sharedContext(2, 1, true, '100');
        $this->grant($unbought, '30');
        app(OrderCancellationService::class)->cancel($unbought['actor'], $unbought['order'],
            OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, 'UAT cancelled before use');
        $this->assertSame('30.0000', app(CustomerCreditService::class)->balances($unbought['actor'], $unbought['order']->customer)->sole()->available);
        $this->assertDatabaseCount('customer_credit_redemptions', 0);
        $c = $this->sold(collect: false, deliveryFee: '100');
        $grant = $this->grant($c);
        app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $this->settlement($c, $grant->id, '80'));
        $this->assertThrows(fn () => app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'],
            OrderCancellationReason::CustomerCancelled, 'No implicit credit release', (string) str()->uuid()), ValidationException::class);
        $this->deliver($c);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $transaction = FinancialTransaction::where('event_type', 'ORDER_V3_CASH_SHORTFALL_DUE')->sole();
        $this->assertThrows(fn () => app(FinancialPostingService::class)->reverse($transaction, 'ARBITRARY', 'UAT', 'ARBITRARY', 1, now(), 'No refund source', $c['branch'], $c['actor']), QueryException::class);
        $case = app(CustomerReturnService::class)->record($c['actor'], $c['order'], $this->data($c) + ['compensation_method' => 'CREDIT']);
        $this->assertSame('CREDIT', $case->compensation_method);
        foreach (['customer_credit_redemptions', 'customer_credit_settlements'] as $table) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table($table)->delete()), QueryException::class);
        }
        $this->assertSame('250.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
    }

    public function test_zero_credit_does_not_ignore_a_mismatched_actual_payment_in_workflow(): void
    {
        $c = $this->sold(collect: false, deliveryFee: '100');
        $grant = $this->grant($c);
        $this->actingAs($c['actor']);
        Livewire::test(Workflow::class, ['order' => $c['order']])
            ->set('creditAllocations', [$grant->id => '0.00'])->set('collectionActualAmount', '70')
            ->call('collectInvoice')->assertHasErrors('amount')->assertSet('collectionActualAmount', '70')
            ->assertSet('creditAllocations', [$grant->id => '0.00'])
            ->set('collectionActualAmount', 'invalid')->call('collectInvoice')->assertHasErrors('collectionActualAmount');
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('customer_credit_redemptions', 0);
    }

    public function test_cross_currency_customer_and_unauthorized_captain_credit_are_rejected(): void
    {
        $c = $this->sold(collect: false, deliveryFee: '100');
        $other = $this->sold(collect: false, deliveryFee: '100');
        $grant = $this->grant($other);
        $service = app(MixedCustomerCreditService::class);
        $this->assertThrows(fn () => $service->record($c['admin'], $c['invoice'], InvoiceCollectionMethod::Cash, $this->settlement($c, $grant->id)), ValidationException::class);
        $foreignCurrency = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'decimal_places' => 2]);
        $wrongCurrencyGrant = app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
            'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $foreignCurrency->id,
            'reason' => 'ERROR', 'amount' => '30', 'reference' => 'USD-ONLY', 'notes' => 'No conversion', 'occurred_at' => now()->toIso8601String(),
        ]);
        $this->assertThrows(fn () => $service->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $this->settlement($c, $wrongCurrencyGrant->id)), ValidationException::class);
        $captain = $c['captains'][0]->user;
        $captain->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->assertThrows(fn () => $service->record($captain, $c['invoice'], InvoiceCollectionMethod::Cash, $this->settlement($c, $grant->id)), AuthorizationException::class);
        $this->assertDatabaseCount('customer_credit_settlements', 0);
        $this->assertDatabaseCount('invoice_collections', 0);
    }

    public function test_sql_rejects_incomplete_credit_source_and_fictitious_full_delivery_custody(): void
    {
        $c = $this->sold(2, collect: false, deliveryFee: '100');
        $grant = $this->grant($c);
        $facts = ['request_key' => (string) str()->uuid(), 'payload_hash' => str_repeat('b', 64),
            'invoice_id' => $c['invoice']->id, 'order_id' => $c['order']->id, 'customer_id' => $c['order']->customer_id,
            'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id, 'recorded_by' => $c['actor']->id,
            'actual_amount' => '250', 'credit_amount' => '30', 'collection_method' => 'CASH',
            'collected_at' => now()->format('Y-m-d H:i:s.uP'), 'reason' => 'UAT malformed source'];
        foreach (['missing collection', 'missing debit', 'fake custody'] as $defect) {
            try {
                DB::transaction(function () use ($c, $grant, $facts, $defect): void {
                    $id = DB::table('customer_credit_settlements')->insertGetId($facts);
                    if ($defect !== 'missing collection') {
                        if ($defect !== 'missing debit') {
                            DB::table('customer_credit_redemptions')->insert(['settlement_id' => $id, 'grant_id' => $grant->id, 'amount' => '30']);
                        }
                        $collection = $c['invoice']->collection()->make();
                        $collection->setDateFormat('Y-m-d H:i:s.uP');
                        $collection->fill(['order_id' => $c['order']->id, 'captain_id' => $c['captains'][0]->id, 'order_captain_id' => $c['assignments'][0]->id,
                            'currency_id' => $c['currency']->id, 'amount' => '250', 'collection_method' => 'CASH', 'collected_at' => now(), 'recorded_by' => $c['actor']->id]);
                        $collection->save();
                        if ($defect === 'fake custody') {
                            $accounts = app(FinancialAccountService::class);
                            $account = $accounts->getOrCreate($accounts->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainCashCustody);
                            app(FinancialPostingService::class)->postEvent($c['currency'], 'INVOICE_COLLECTION', $collection->id,
                                'ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED', 2, now(),
                                [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => '200']],
                                $c['branch'], $c['actor'], null, DB::table('order_financial_source_snapshots')->where('invoice_id', $c['invoice']->id)->value('payload_hash'));
                        }
                    }
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('SQL accepted incomplete settlement or noncash credit as cash custody.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
                if ($defect === 'fake custody') {
                    $this->assertStringContainsString('V2 event has incorrect', $exception->getMessage());
                }
            }
        }
        $this->assertDatabaseCount('customer_credit_redemptions', 0);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    public function test_credit_funded_shortfall_uses_existing_source_payout_without_deposit_or_custody_offset(): void
    {
        $c = $this->sold(collect: false, deliveryFee: '100');
        $grant = $this->grant($c);
        app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $this->settlement($c, $grant->id, '80'));
        $this->deliver($c);
        Storage::fake('captain_financial_evidence');
        $balances = app(FinancialBalanceQueryService::class);
        $source = $balances->payableReceivableEntries($c['captains'][0], $c['branch'], $c['currency'])->sole();
        $this->assertSame('ORDER_V3_CASH_SHORTFALL_DUE', $source->event_type);
        $this->assertSame('30.0000', $source->remaining_amount);
        app(CaptainReceivablePayoutService::class)->record($c['admin'], $c['captains'][0], $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '30', 'occurred_at' => now()->format('Y-m-d H:i:s.uP')],
            [['financial_entry_id' => $source->financial_entry_id, 'amount' => '30']], $this->proof('actual company payout'));
        $this->assertSame(['CAPTAIN_DEPOSIT' => '0.0000', 'CAPTAIN_COMPANY_RECEIVABLE' => '0.0000', 'CAPTAIN_CASH_CUSTODY' => '0.0000'], $balances->captainBalances($c['captains'][0], $c['currency']));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_mixed_party_return_credit_cannot_be_partially_attributed_implicitly(): void
    {
        $original = $this->sold(deliveryFee: '100');
        $originalTime = now()->toImmutable();
        $data = $this->data($original) + ['compensation_method' => 'CREDIT'];
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $original['assignments'][0]->id, 'amount' => '50'], ['party' => 'COMPANY', 'amount' => '30']];
        $case = app(CustomerReturnService::class)->record($original['actor'], $original['order'], $data);
        $grant = DB::table('customer_credit_entries')->where('source_return_case_id', $case->id)->sole();
        $c = $this->sold(collect: false, master: $original);
        $this->travelTo($originalTime->addSecond());
        $this->assertThrows(fn () => app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $this->settlement($c, $grant->id, '10')), ValidationException::class);
        $this->assertDatabaseCount('customer_credit_redemptions', 0);
        $this->assertTrue(BigDecimal::of(app(CustomerReturnService::class)->captainLiabilities($original['actor'], $original['captains'][0])->sole()->outstanding)->isZero());
        app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $this->settlement($c, $grant->id, '80'));
        $this->assertSame('50.0000', app(CustomerReturnService::class)->captainLiabilities($original['actor'], $original['captains'][0])->sole()->outstanding);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_partial_credit_records_exact_responsibilities_and_only_funds_the_selected_captain_share(): void
    {
        $original = $this->sold(deliveryFee: '100');
        $time = now()->toImmutable();
        $data = $this->data($original) + ['compensation_method' => 'CREDIT'];
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $original['assignments'][0]->id, 'amount' => '50'], ['party' => 'COMPANY', 'amount' => '30']];
        $returns = app(CustomerReturnService::class);
        $case = $returns->record($original['actor'], $original['order'], $data);
        $grant = DB::table('customer_credit_entries')->where('source_return_case_id', $case->id)->sole();
        $captain = $case->responsibilities()->where('party', 'CAPTAIN')->sole();
        $company = $case->responsibilities()->where('party', 'COMPANY')->sole();
        $first = $this->sold(collect: false, deliveryFee: '100', master: $original);
        $second = $this->sold(collect: false, deliveryFee: '100', master: $original);
        $this->travelTo($time->addSecond());
        $payment = $this->settlement($first, $grant->id, '10');
        $payment['attributions'] = [$grant->id => [$captain->id => '4', $company->id => '6']];
        $service = app(MixedCustomerCreditService::class);

        foreach (['missing', 'incorrect sum', 'excess responsibility'] as $defect) {
            try {
                DB::transaction(function () use ($first, $grant, $captain, $defect): void {
                    $id = DB::table('customer_credit_settlements')->insertGetId([
                        'request_key' => (string) str()->uuid(), 'payload_hash' => str_repeat('c', 64),
                        'invoice_id' => $first['invoice']->id, 'order_id' => $first['order']->id, 'customer_id' => $first['order']->customer_id,
                        'branch_id' => $first['branch']->id, 'currency_id' => $first['currency']->id, 'recorded_by' => $first['actor']->id,
                        'actual_amount' => (string) BigDecimal::of($first['invoice']->grand_total)->minus('10'), 'credit_amount' => '10',
                        'collection_method' => 'CASH', 'collected_at' => now()->format('Y-m-d H:i:s.uP'), 'reason' => 'Negative attribution fixture',
                    ]);
                    $debit = DB::table('customer_credit_redemptions')->insertGetId(['settlement_id' => $id, 'grant_id' => $grant->id, 'amount' => '10']);
                    if ($defect !== 'missing') {
                        DB::table('customer_credit_responsibility_attributions')->insert([
                            'redemption_id' => $debit, 'responsibility_id' => $captain->id, 'amount' => $defect === 'incorrect sum' ? '9' : '51',
                        ]);
                    }
                    DB::select('SELECT assert_credit_attribution(?)', [$debit]);
                });
                $this->fail('PostgreSQL accepted '.$defect.' responsibility attribution.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
                $this->assertStringContainsString($defect === 'excess responsibility' ? 'excessive explicit' : 'exact explicit', $exception->getMessage());
            }
        }

        $collection = $service->record($first['actor'], $first['invoice'], InvoiceCollectionMethod::Cash, $payment);

        $this->assertSame($collection->id, $service->record($first['actor'], $first['invoice'], InvoiceCollectionMethod::Cash, $payment)->id);
        $this->assertDatabaseCount('customer_credit_responsibility_attributions', 2);
        $this->assertSame('4.0000', $returns->summary($original['actor'], $original['order'])->sole()->responsibilities->firstWhere('id', $captain->id)->funded_amount);
        $this->assertSame('4.0000', $returns->captainLiabilities($original['actor'], $original['captains'][0])->sole()->outstanding);
        $this->assertSame('70.0000', app(CustomerCreditService::class)->balances($original['actor'], $original['order']->customer)->sole()->available);
        $invalid = $this->settlement($second, $grant->id, '50');
        $invalid['attributions'] = [$grant->id => [$captain->id => '50']];
        $this->assertThrows(fn () => $service->record($second['actor'], $second['invoice'], InvoiceCollectionMethod::ShamCash, $invalid), ValidationException::class);
        $invalid['attributions'] = [$grant->id => [$captain->id => '1']];
        $this->assertThrows(fn () => $service->record($second['actor'], $second['invoice'], InvoiceCollectionMethod::ShamCash, $invalid), ValidationException::class);
        $invalid['attributions'] = [$grant->id => [$captain->id => ['forged' => '50']]];
        $this->assertThrows(fn () => $service->record($second['actor'], $second['invoice'], InvoiceCollectionMethod::ShamCash, $invalid), ValidationException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('customer_credit_responsibility_attributions')->update(['amount' => '1'])), QueryException::class);
        $service->record($second['actor'], $second['invoice'], InvoiceCollectionMethod::ShamCash, $this->settlement($second, $grant->id, '70'));
        $this->assertSame('50.0000', $returns->captainLiabilities($original['actor'], $original['captains'][0])->sole()->outstanding);
        $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($original['actor'], $original['order']->customer)->sole()->available);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
