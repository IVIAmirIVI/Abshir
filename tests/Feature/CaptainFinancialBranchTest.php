<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaptainFinancialBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_balances_are_exact_separate_negative_and_sum_to_global(): void
    {
        $f = $this->fixture();
        $posting = app(FinancialPostingService::class);
        $balances = app(FinancialBalanceQueryService::class);
        $this->postMovement($f, $f['branchA'], FinancialAccountKind::CaptainDeposit, 'INCREASE', '150', 'opening-a');
        $this->postMovement($f, $f['branchB'], FinancialAccountKind::CaptainDeposit, 'INCREASE', '40', 'opening-b');
        $this->postMovement($f, $f['branchA'], FinancialAccountKind::CaptainDeposit, 'DECREASE', '20', 'order-a');
        $this->assertSame('130.0000', $balances->captainBranchBalance($f['captain'], $f['branchA'], $f['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('40.0000', $balances->captainBranchBalance($f['captain'], $f['branchB'], $f['currency'], FinancialAccountKind::CaptainDeposit));
        $this->postMovement($f, $f['branchA'], FinancialAccountKind::CaptainDeposit, 'DECREASE', '200', 'order-a-negative');
        $this->assertSame('-70.0000', $balances->captainBranchBalance($f['captain'], $f['branchA'], $f['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('40.0000', $balances->captainBranchBalance($f['captain'], $f['branchB'], $f['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('-30.0000', $balances->captainBalances($f['captain'], $f['currency'])[FinancialAccountKind::CaptainDeposit->value]);
        $this->postMovement($f, $f['branchA'], FinancialAccountKind::CaptainCompanyReceivable, 'INCREASE', '20', 'shortfall-a');
        $this->postMovement($f, $f['branchB'], FinancialAccountKind::CaptainCompanyReceivable, 'INCREASE', '50', 'product-b');
        $this->postMovement($f, $f['branchB'], FinancialAccountKind::CaptainCompanyReceivable, 'INCREASE', '30', 'entitlement-b');
        $this->assertSame('20.0000', $balances->captainBranchBalance($f['captain'], $f['branchA'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('80.0000', $balances->captainBranchBalance($f['captain'], $f['branchB'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('100.0000', $balances->captainBalances($f['captain'], $f['currency'])[FinancialAccountKind::CaptainCompanyReceivable->value]);
        $this->assertSame('-70.0000', $balances->captainBalancesByBranch($f['captain'], $f['currency'])[$f['branchA']->id][FinancialAccountKind::CaptainDeposit->value]);
        $this->assertSame('0.0000', $balances->captainBranchBalance($f['captain'], $f['branchB'], $f['currency'], FinancialAccountKind::CaptainCashCustody));
    }

    public function test_reversal_uses_original_branch_and_sql_cannot_move_or_remove_it(): void
    {
        $f = $this->fixture();
        $original = $this->postMovement($f, $f['branchA'], FinancialAccountKind::CaptainDeposit, 'INCREASE', '100', 'original');
        $posting = app(FinancialPostingService::class);
        $this->assertThrows(fn () => $posting->reverse($original, 'TEST', 'wrong', 'REVERSE', 1, now(), 'Correction', $f['branchB']), FinancialLedgerException::class);
        $this->assertSame(0, DB::table('financial_transactions')->whereNotNull('reversal_of_transaction_id')->count());
        foreach ([$f['branchB']->id, null] as $branchId) {
            $this->assertThrows(fn () => DB::transaction(function () use ($f, $original, $branchId): void {
                $id = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'REVERSAL', 'status' => 'DRAFT', 'currency_id' => $f['currency']->id,
                    'branch_id' => $branchId, 'source_type' => 'DIRECT_SQL', 'source_id' => str()->uuid()->toString(),
                    'event_type' => 'REVERSAL', 'source_version' => 1, 'payload_hash' => str_repeat('a', 64),
                    'occurred_at' => now(), 'reversal_of_transaction_id' => $original->id,
                    'reason' => 'Malicious Branch change', 'created_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $id, 'financial_account_id' => $f['deposit']->id,
                    'currency_id' => $f['currency']->id, 'direction' => 'DECREASE', 'amount' => '100', 'created_at' => now(),
                ]);
                DB::table('financial_transactions')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now()]);
                DB::statement('SET CONSTRAINTS captain_financial_transaction_branch_guard, captain_financial_entry_branch_guard IMMEDIATE');
            }), QueryException::class);
        }
        $reversal = $posting->reverse($original, 'TEST', 'proper', 'REVERSE', 1, now(), 'Correction');
        $this->assertSame($f['branchA']->id, $reversal->branch_id);
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('0.0000', $balances->captainBranchBalance($f['captain'], $f['branchA'], $f['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $balances->captainBranchBalance($f['captain'], $f['branchB'], $f['currency'], FinancialAccountKind::CaptainDeposit));
    }

    public function test_database_rejects_branchless_captain_entry_and_wrong_invoice_branch(): void
    {
        $f = $this->fixture();
        $this->assertThrows(fn () => DB::transaction(function () use ($f): void {
            $this->postMovement($f, null, FinancialAccountKind::CaptainDeposit, 'INCREASE', '1', 'branchless');
            DB::statement('SET CONSTRAINTS captain_financial_transaction_branch_guard, captain_financial_entry_branch_guard IMMEDIATE');
        }), QueryException::class);
        $invoice = $this->draftInvoice($f);
        $this->assertThrows(fn () => DB::transaction(function () use ($f, $invoice): void {
            app(FinancialPostingService::class)->postEvent(
                $f['currency'], 'CUSTOMER_INVOICE', $invoice->id, 'ORDER_COMPANY_DELIVERY_SHARE', 1, now(),
                [['account' => $f['deposit'], 'direction' => FinancialEntryDirection::Decrease, 'amount' => '1.00']], $f['branchB'],
            );
            DB::statement('SET CONSTRAINTS captain_financial_transaction_branch_guard, captain_financial_entry_branch_guard IMMEDIATE');
        }), QueryException::class);
        $correct = app(FinancialPostingService::class)->postEvent(
            $f['currency'], 'CUSTOMER_INVOICE', $invoice->id, 'ORDER_COMPANY_DELIVERY_SHARE', 1, now(),
            [['account' => $f['deposit'], 'direction' => FinancialEntryDirection::Decrease, 'amount' => '1.00']], $f['branchA'],
        );
        $this->assertSame($f['branchA']->id, $correct->branch_id);
    }

    public function test_known_order_event_cannot_use_arbitrary_or_wrong_source_family(): void
    {
        $f = $this->fixture();
        $invoice = $this->draftInvoice($f);
        $posting = app(FinancialPostingService::class);
        foreach ([
            ['DIRECT_SQL', 'unrelated', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value],
            ['CUSTOMER_INVOICE', (string) $invoice->id, FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value],
            ['INVOICE_COLLECTION', '999999999', FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value],
        ] as [$sourceType, $sourceId, $eventType]) {
            $this->assertThrows(fn () => DB::transaction(function () use ($posting, $f, $sourceType, $sourceId, $eventType): void {
                $posting->postEvent(
                    $f['currency'], $sourceType, $sourceId, $eventType, 1, now(),
                    [['account' => $f['deposit'], 'direction' => FinancialEntryDirection::Decrease, 'amount' => '1.00']],
                    $f['branchB'],
                );
                DB::statement('SET CONSTRAINTS order_financial_event_source_contract IMMEDIATE');
            }), QueryException::class);
        }
        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_direct_sql_cannot_disguise_a_known_order_event_as_an_unrelated_source(): void
    {
        $f = $this->fixture();
        $this->assertThrows(fn () => DB::transaction(function () use ($f): void {
            $id = DB::table('financial_transactions')->insertGetId([
                'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'currency_id' => $f['currency']->id,
                'branch_id' => $f['branchB']->id, 'source_type' => 'DIRECT_SQL',
                'source_id' => 'forged',
                'event_type' => FinancialEventType::OrderCashCompanyDeliveryShareDepositDebit->value,
                'source_version' => 1, 'payload_hash' => str_repeat('a', 64),
                'occurred_at' => now(), 'created_at' => now(),
            ]);
            DB::table('financial_entries')->insert([
                'financial_transaction_id' => $id, 'financial_account_id' => $f['deposit']->id,
                'currency_id' => $f['currency']->id, 'direction' => 'DECREASE',
                'amount' => '1.00', 'created_at' => now(),
            ]);
            DB::table('financial_transactions')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now()]);
            DB::statement('SET CONSTRAINTS order_financial_event_source_contract IMMEDIATE');
        }), QueryException::class);
        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_order_branch_can_change_before_financial_binding_but_not_after_posting(): void
    {
        $f = $this->fixture();
        $invoice = $this->draftInvoice($f);
        DB::table('orders')->where('id', $invoice->order_id)->update(['branch_id' => $f['branchB']->id]);
        $this->assertSame($f['branchB']->id, (int) DB::table('orders')->where('id', $invoice->order_id)->value('branch_id'));
        app(FinancialPostingService::class)->postEvent(
            $f['currency'], 'CUSTOMER_INVOICE', $invoice->id,
            FinancialEventType::OrderCompanyDeliveryShare->value, 1, now(),
            [['account' => $f['deposit'], 'direction' => FinancialEntryDirection::Decrease, 'amount' => '1.00']],
            $f['branchB'],
        );
        $this->assertThrows(fn () => DB::table('orders')->where('id', $invoice->order_id)
            ->update(['branch_id' => $f['branchA']->id]), QueryException::class);
    }

    private function postMovement(array $f, ?Branch $branch, FinancialAccountKind $kind, string $direction, string $amount, string $sourceId): FinancialTransaction
    {
        $account = match ($kind) {
            FinancialAccountKind::CaptainDeposit => $f['deposit'],
            FinancialAccountKind::CaptainCompanyReceivable => $f['receivable'],
            FinancialAccountKind::CaptainCashCustody => $f['custody'],
        };

        return app(FinancialPostingService::class)->postEvent($f['currency'], 'BRANCH_TEST', $sourceId, 'TEST', 1, now(), [
            ['account' => $account, 'direction' => $direction, 'amount' => $amount],
        ], $branch);
    }

    private function fixture(): array
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branchA = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $branchB = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branchA->id]);
        $accounts = app(FinancialAccountService::class);
        $owner = $accounts->ownerForCaptain($captain);
        $deposit = $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit);
        $receivable = $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCompanyReceivable);
        $custody = $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCashCustody);

        return compact('currency', 'branchA', 'branchB', 'captain', 'deposit', 'receivable', 'custody');
    }

    private function draftInvoice(array $f): Invoice
    {
        $customer = Customer::factory()->create();
        $order = Order::create([
            'branch_id' => $f['branchA']->id, 'customer_id' => $customer->id,
            'order_number' => 'BRANCH-'.str()->random(8), 'ordered_at' => now(), 'source' => 'MANUAL', 'entry_mode' => 'LIVE',
            'customer_name_snapshot' => $customer->name, 'customer_phone_snapshot' => '000',
            'area_name_snapshot' => 'Area', 'area_path_snapshot' => 'Area', 'address_line_1_snapshot' => 'Street',
        ]);

        return Invoice::create([
            'order_id' => $order->id, 'invoice_number' => 'BRANCH-'.str()->random(8), 'version' => 1,
            'status' => 'DRAFT', 'customer_name_snapshot' => $customer->name, 'customer_phone_snapshot' => '000',
            'address_snapshot' => 'Street', 'order_number_snapshot' => $order->order_number,
            'ordered_at_snapshot' => $order->ordered_at,
        ]);
    }
}
