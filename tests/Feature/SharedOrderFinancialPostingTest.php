<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Livewire\Orders\CaptainAssignments;
use App\Livewire\Orders\Workflow;
use App\Models\FinancialTransaction;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainInformationQuery;
use App\Services\CaptainReceivablePayoutService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialSourceSnapshotService;
use App\Services\OrderMutationGuard;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderFinancialPostingService;
use App\Services\SharedOrderShopPurchaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SharedOrderFinancialPostingTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    #[DataProvider('methods')]
    public function test_actual_collection_and_delivery_recognize_each_claim_once_without_deposit_or_fake_payment(string $method): void
    {
        [$c, $invoice] = $this->prepared();
        $collection = app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::from($method), '100', $c['currency']->id, now(), null, $method === 'SHAM_CASH' ? 'Reference' : null);
        $balances = app(FinancialBalanceQueryService::class);
        foreach ($c['captains'] as $index => $captain) {
            $actual = $balances->captainBalances($captain, $c['currency']);
            $this->assertSame('0.0000', $actual[FinancialAccountKind::CaptainDeposit->value]);
            $this->assertSame('0.0000', $actual[FinancialAccountKind::CaptainCompanyReceivable->value]);
            $this->assertSame($method === 'CASH' && $index === 0 ? '100.0000' : '0.0000', $actual[FinancialAccountKind::CaptainCashCustody->value]);
        }
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops,
            OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], $status, null);
        }
        DB::select('SELECT assert_shared_order_financial_set(?)', [$c['order']->id]);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        app(SharedOrderFinancialPostingService::class)->postIfReady($c['order']);
        app(SharedOrderFinancialPostingService::class)->postIfReady($c['order']);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('financial_transactions', $method === 'CASH' ? 3 : 2);
        $this->assertDatabaseCount('financial_entries', $method === 'CASH' ? 5 : 4);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertDatabaseCount('captain_deposit_receipts', 0);
        $this->assertDatabaseCount('order_bill_payment_principal_handovers', 0);
        foreach ($c['captains'] as $captain) {
            $actual = $balances->captainBalances($captain, $c['currency']);
            $this->assertSame('45.0000', $actual[FinancialAccountKind::CaptainCompanyReceivable->value]);
            $this->assertSame('0.0000', $actual[FinancialAccountKind::CaptainDeposit->value]);
        }
        $this->assertSame($method === 'CASH' ? $c['assignments'][0]->id : null, $collection->order_captain_id);
        $this->assertThrows(fn () => app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::from($method), '100', $c['currency']->id, now()), ValidationException::class);
        Storage::fake('captain_financial_evidence');
        $captain = $c['captains'][1];
        // Company settlement is off-system policy: no new selectable payout sources or fake payment.
        $this->assertCount(0, $balances->payableReceivableEntries($captain, $c['branch'], $c['currency']));
        $this->assertCount(2, $balances->gatedCorrectedReceivableEntries($captain, $c['branch'], $c['currency']));
        $product = DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
            ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->where('o.captain_id', $captain->id)->where('t.event_type', 'SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE')->value('e.id');
        $this->assertThrows(fn () => app(CaptainReceivablePayoutService::class)->record($c['admin'], $captain, $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '20', 'occurred_at' => now()->format('Y-m-d H:i:s.uP')],
            [['financial_entry_id' => $product, 'amount' => '20']], $this->sharedImage()), ValidationException::class);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertDatabaseCount('captain_receivable_payout_allocations', 0);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        foreach ($c['captains'] as $index => $participant) {
            $participant->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
            $self = app(CaptainInformationQuery::class)->selfSnapshot($participant->user, 'today');
            $this->assertSame($participant->id, $self['captain']->id);
            $this->assertSame(1, $self['activity']['metrics']['delivered']);
            $this->assertCount(1, $self['earnings']);
            $this->assertSame('5.0000', $self['earnings']->sole()->amount);
            $this->assertCount(1, $self['balanceRows']);
            $this->assertSame('45.0000', $self['balanceRows'][0]['values'][FinancialAccountKind::CaptainCompanyReceivable->value]);
            $this->assertSame($method === 'CASH' && $index === 0 ? '100.0000' : '0.0000', $self['balanceRows'][0]['values'][FinancialAccountKind::CaptainCashCustody->value]);
        }
        $this->actingAs($captain->user)->get(route('captain.self-information').'?captain_id='.$c['captains'][0]->id)
            ->assertOk()->assertViewHas('captain', fn ($record) => $record->id === $captain->id);
    }

    public function test_simple_shared_workflow_selects_collector_issues_one_invoice_and_delivers_without_settlement_forms(): void
    {
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext();
        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])
            ->set('participantIds', array_map('strval', array_column($c['captains'], 'id')))
            ->set('collectorId', (string) $c['captains'][1]->id)->set('selectionReason', 'Explicit collecting Captain')
            ->call('saveSelection')->assertHasNoErrors()->assertRedirect(route('orders.workflow', $c['order']));
        app(OrderDeliveryPricingService::class)->price($c['admin'], $c['order'], $c['shop']->originArea, null, '2', 'Approved combined discount');
        $workflow = Livewire::actingAs($c['actor'])->test(Workflow::class, ['order' => $c['order']]);
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING'] as $status) {
            $workflow->call('advance')->assertHasNoErrors();
            $this->assertSame($status, $c['order']->fresh()->operational_status->value);
        }
        $this->actingAs($c['actor'])->post(route('orders.shops.purchases.store', [$c['order'], $c['shop']]),
            $this->sharedData($c) + ['also_shop_receipt' => true, 'evidence' => $this->sharedImage()])
            ->assertSessionHasNoErrors();
        $workflow->call('pickup', $c['shop']->id)->assertHasNoErrors()
            ->call('createInvoice')->assertHasNoErrors()->call('refreshInvoice')->assertHasNoErrors()
            ->call('issueInvoice')->assertHasNoErrors();
        $invoice = $c['order']->currentInvoice()->sole();
        $this->assertSame('80.0000', $invoice->items_subtotal);
        $this->assertSame('98.0000', $invoice->grand_total);
        $snapshot = app(OrderFinancialSourceSnapshotService::class)->read($invoice)['payload_hash'];
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
        $workflow->call('advance')->assertHasNoErrors()->call('advance')->assertHasNoErrors();
        $this->assertSame('DELIVERED', $c['order']->fresh()->operational_status->value);
        $workflow->set('collectedAt', now()->format('Y-m-d\TH:i:s.u'))->call('collectInvoice')->assertHasNoErrors();
        $collection = $invoice->collection()->sole();
        $this->assertSame($c['captains'][1]->id, $collection->captain_id);
        $this->assertSame('98.0000', $collection->amount);
        $this->assertSame($snapshot, app(OrderFinancialSourceSnapshotService::class)->read($invoice->fresh())['payload_hash']);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertDatabaseCount('captain_deposit_receipts', 0);
        $this->assertDatabaseCount('order_bill_payment_principal_handovers', 0);
        $this->assertFalse(Schema::hasTable('invoice_cash_handovers'));
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('98.0000', $balances->captainBalances($c['captains'][1], $c['currency'])[FinancialAccountKind::CaptainCashCustody->value]);
        $this->assertSame('0.0000', $balances->captainBalances($c['captains'][0], $c['currency'])[FinancialAccountKind::CaptainCashCustody->value]);
        DB::select('SELECT assert_shared_order_financial_set(?)', [$c['order']->id]);
    }

    public function test_shared_unpaid_claims_do_not_block_unrelated_existing_single_order_payouts(): void
    {
        [$c, $invoice] = $this->prepared();
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::ShamCash, '100', $c['currency']->id, now(), null, 'Shared reference');
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
        $captain = $c['captains'][0];
        $order = app(OrderService::class)->create($c['actor'], $c['order']->only([
            'branch_id', 'customer_id', 'customer_phone_id', 'customer_address_id',
        ]) + ['source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $shop = OrderShop::create($c['shop']->only([
            'shop_type', 'business_classification_snapshot', 'shop_name_snapshot', 'origin_area_id', 'sort_order',
        ]) + ['order_id' => $order->id]);
        OrderItem::create(array_replace($c['shop']->items()->sole()->only([
            'item_type', 'product_name_snapshot', 'unit_name_snapshot', 'requested_quantity', 'current_quantity',
            'actual_price_source', 'actual_currency_id', 'actual_price_confirmed_at', 'actual_price_confirmed_by',
        ]), ['order_shop_id' => $shop->id, 'actual_unit_price' => '10']));
        $order->update(['pricing_class' => 'SINGLE_SHOP', 'ordered_at' => now()->subHour()]);
        app(OrderPricingService::class)->recalculate($order);
        $assignment = app(OrderCaptainService::class)->assign($c['actor'], $order, $captain, OrderCaptainRole::PrimaryCollection);
        $assignment->update(['assigned_at' => now()->subMinutes(10)]);
        app(OrderDeliveryPricingService::class)->price($c['actor'], $order, $shop->originArea);
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $order, $shop,
            ['order_captain_id' => $assignment->id, 'currency_id' => $c['currency']->id, 'amount' => '10',
                'purchased_at' => now()->subMinute()->format('Y-m-d H:i:s.uP'), 'request_key' => (string) str()->uuid()], $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $order, $shop, $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $order, $shop, 'picked_up_at');
        $invoices = app(InvoiceService::class);
        $singleInvoice = $invoices->issue($c['actor'], $order, $invoices->refresh($c['actor'], $order, $invoices->createDraft($c['actor'], $order)));
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $order, OrderOperationalStatus::from($status), null);
        }
        app(InvoiceCollectionService::class)->record($c['actor'], $singleInvoice, InvoiceCollectionMethod::ShamCash, '20', $c['currency']->id, now(), null, 'Single reference');
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('60.0000', $balances->captainBalances($captain, $c['currency'])[FinancialAccountKind::CaptainCompanyReceivable->value]);
        $payable = $balances->payableReceivableEntries($captain, $c['branch'], $c['currency']);
        $this->assertCount(2, $payable);
        $this->assertFalse($payable->contains(fn ($row) => str_starts_with($row->event_type, 'SHARED_')));
        $source = $payable->firstWhere('event_type', 'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE');
        Storage::fake('captain_financial_evidence');
        $payout = app(CaptainReceivablePayoutService::class)->record($c['admin'], $captain, $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '5', 'occurred_at' => now()->format('Y-m-d H:i:s.uP')],
            [['financial_entry_id' => $source->financial_entry_id, 'amount' => '5']], $this->sharedImage());
        DB::select("SELECT validate_captain_financial_operation('PAYOUT', ?)", [$payout->id]);
        $this->assertSame('55.0000', $balances->captainBalances($captain, $c['currency'])[FinancialAccountKind::CaptainCompanyReceivable->value]);
        $this->assertCount(2, $balances->gatedCorrectedReceivableEntries($captain, $c['branch'], $c['currency']));
        $this->assertDatabaseCount('captain_receivable_payouts', 1);
    }

    public static function methods(): array
    {
        return [['CASH'], ['SHAM_CASH']];
    }

    public function test_late_claim_failure_rolls_back_delivery_and_all_new_claims_but_preserves_real_prior_collection(): void
    {
        [$c, $invoice] = $this->prepared();
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash, '100', $c['currency']->id, now());
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops,
            OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], $status, null);
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION mc4_test_fail_entitlement() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN IF NEW.event_type='SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE' THEN RAISE EXCEPTION 'Injected final claim failure' USING ERRCODE='23514'; END IF; RETURN NEW; END;
$$;
CREATE TRIGGER mc4_test_fail_entitlement BEFORE INSERT ON financial_transactions FOR EACH ROW EXECUTE FUNCTION mc4_test_fail_entitlement();
SQL);
        $this->assertThrows(fn () => app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::Delivered, null), QueryException::class);
        $this->assertSame(OrderOperationalStatus::HeadingToCustomer, $c['order']->fresh()->operational_status);
        $this->assertSame(0, $c['order']->statusHistory()->where('new_status', 'DELIVERED')->count());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('financial_entries', 1);
        $this->assertDatabaseCount('invoice_collections', 1);
    }

    public function test_sql_rejects_missing_financial_companions_and_wrong_canonical_source_without_writes(): void
    {
        [$c,$invoice] = $this->prepared();
        $facts = ['order_id' => $c['order']->id, 'invoice_id' => $invoice->id, 'collection_method' => 'CASH', 'currency_id' => $c['currency']->id,
            'amount' => '100', 'collected_at' => now(), 'recorded_by' => $c['actor']->id, 'captain_id' => $c['captains'][0]->id, 'order_captain_id' => $c['assignments'][0]->id];
        $this->assertThrows(fn () => DB::transaction(function () use ($facts): void {
            DB::table('invoice_collections')->insert($facts);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }), QueryException::class);
        $this->assertDatabaseCount('invoice_collections', 0);
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash, '100', $c['currency']->id, now());
        $row = DB::table('financial_transactions')->first();
        $data = (array) $row;
        unset($data['id']);
        $data['source_type'] = 'UNSUPPORTED';
        $this->assertThrows(fn () => DB::transaction(function () use ($data): void {
            DB::table('financial_transactions')->insert($data);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }), QueryException::class);
        $this->assertThrows(fn () => app(FinancialPostingService::class)->reverse(FinancialTransaction::findOrFail($row->id),
            'MANUAL', '1', 'REVERSAL', 1, now(), 'No refund source', $c['branch'], $c['admin']), FinancialIdempotencyConflictException::class);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('financial_entries', 1);
    }

    public function test_zero_purchase_share_never_posts_zero_or_duplicates_company_funded_entitlement(): void
    {
        [$c,$invoice] = $this->prepared(0, '1', '20');
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::ShamCash, '1', $c['currency']->id, now());
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $target) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($target), null);
        }
        DB::select('SELECT assert_shared_order_financial_set(?)', [$c['order']->id]);
        $this->assertDatabaseCount('financial_entries', 3);
        $this->assertSame(0, DB::table('financial_entries')->where('amount', '<=', '0')->count());
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('6.0000', $balances->captainBalances($c['captains'][0], $c['currency'])[FinancialAccountKind::CaptainCompanyReceivable->value]);
        $this->assertSame('5.0000', $balances->captainBalances($c['captains'][1], $c['currency'])[FinancialAccountKind::CaptainCompanyReceivable->value]);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
    }

    public function test_shared_collection_rejects_wrong_time_collector_currency_amount_and_branch(): void
    {
        [$c, $invoice] = $this->prepared();
        $facts = ['order_id' => $c['order']->id, 'invoice_id' => $invoice->id, 'collection_method' => 'SHAM_CASH',
            'currency_id' => $c['currency']->id, 'amount' => '100', 'collected_at' => now(), 'recorded_by' => $c['actor']->id];
        foreach (['collected_at' => DB::scalar("SELECT clock_timestamp() + interval '1 minute'"), 'amount' => '99', 'captain_id' => $c['captains'][1]->id] as $field => $value) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('invoice_collections')->insert(array_replace($facts, [$field => $value]))), QueryException::class);
        }
        $foreign = $this->sharedContext();
        $this->assertThrows(fn () => app(InvoiceCollectionService::class)->record($foreign['actor'], $invoice, InvoiceCollectionMethod::Cash, '100', $c['currency']->id, now()), HttpException::class);
        $this->assertThrows(fn () => app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash, '100', $foreign['currency']->id, now()), ValidationException::class);
        $unauthorized = User::factory()->create();
        $unauthorized->branches()->attach($c['branch']);
        $this->assertThrows(fn () => app(InvoiceCollectionService::class)->record($unauthorized, $invoice, InvoiceCollectionMethod::Cash, '100', $c['currency']->id, now()), AuthorizationException::class);
        $this->assertDatabaseCount('invoice_collections', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertTrue(app(OrderMutationGuard::class)->isPricingFrozen($c['order']));
    }

    protected function prepared(int $places = 2, string $principal = '80', ?string $discount = null): array
    {
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext($places);
        $c['shop']->items()->sole()->update(['actual_unit_price' => $principal]);
        app(OrderPricingService::class)->recalculate($c['order']);
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
        if ($discount !== null) {
            app(OrderDeliveryPricingService::class)->price($c['admin'], $c['order'], $c['shop']->originArea, null, $discount, 'Approved company-funded discount');
        }
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, $principal), $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($c['actor'], $c['order'], $invoices->refresh($c['actor'], $c['order'], $invoices->createDraft($c['actor'], $c['order'])));

        return [$c, $invoice];
    }
}
