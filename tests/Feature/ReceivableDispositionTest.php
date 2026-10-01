<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Services\CaptainBranchService;
use App\Services\CustomerRefundService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\OrderStatusService;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReceivableDispositionFixtures;
use Tests\TestCase;

class ReceivableDispositionTest extends TestCase
{
    use ReceivableDispositionFixtures, RefreshDatabase;

    public static function examples(): array
    {
        return [
            '100/40->70' => ['40', '70', '100', '60', '30', '0'],
            '100/40->120' => ['40', '120', '80', '60', '80', '0'],
            '100/70->80' => ['70', '80', '100', '30', '10', '0'],
            '100/70->60' => ['70', '60', '100', '30', '0', '10'],
            '100/100->60' => ['100', '60', '100', '0', '0', '40'],
            '100/100->120' => ['100', '120', '80', '0', '20', '0'],
        ];
    }

    #[DataProvider('examples')]
    public function test_exact_paid_source_outcomes_preserve_real_payout_history(string $paid, string $corrected, string $delivery, string $unpaid, string $residual, string $recovery): void
    {
        $f = $this->fixture('USD');
        $entry = $this->productEntry($f);
        $this->payout($f, $entry, $paid);
        $history = $this->moneyHistory();
        $c = $this->prepareAndRefund($f, $this->correction($f, $corrected, $delivery));
        $d = $c->receivableDispositions()->sole();
        $this->assertSame(2, $c->receivable_contract_version);
        foreach (['original_entitlement' => '100', 'historical_paid' => $paid, 'corrected_entitlement' => $corrected, 'original_unpaid' => $unpaid,
            'residual_payable' => $residual, 'recovery_required' => $recovery] as $column => $amount) {
            $this->assertSame($this->decimal($amount), $this->decimal($d->{$column}));
        }
        $this->assertCount(1, $d->allocations);
        $this->assertSame('READY_TO_FINALIZE', $c->state->value);
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertCount(0, $balances->payableReceivableEntries($f['captain'], $f['branch'], $f['currency']));
        if ($recovery !== '0') {
            try {
                app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
                $this->fail('Unrecovered excess completed.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('CAPTAIN_RECOVERY_REQUIRED', $e->errors()['correction'][0]);
            }
            $this->assertSame('READY_TO_FINALIZE', $c->fresh()->state->value);
            $this->assertSame('ISSUED', $f['invoice']->fresh()->status->value);
            $this->assertDatabaseCount('customer_refunds', 1);
            $this->assertDatabaseCount('invoice_correction_reversals', 0);
            $this->assertSame(0, DB::table('financial_transactions')->where('source_type', 'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION')->count());
            $this->expectInvalidSQL(fn () => DB::table('invoice_financial_corrections')->where('id', $c->id)->update(['state' => 'ABANDONED']));
        } else {
            $c = app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
            $this->assertSame('COMPLETED', $c->state->value);
            $events = DB::table('financial_transactions as t')->join('financial_entries as e', 'e.financial_transaction_id', '=', 't.id')
                ->where('t.source_type', 'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION')->where('t.source_id', (string) $d->id)
                ->select('t.event_type', 'e.amount', 'e.direction')->get();
            $this->assertCount(($unpaid !== '0' ? 1 : 0) + ($residual !== '0' ? 1 : 0), $events);
            if ($unpaid !== '0') {
                $event = $events->firstWhere('event_type', 'RECEIVABLE_UNPAID_REMAINDER_RETIRED');
                $this->assertSame($this->decimal($unpaid), $this->decimal($event->amount));
                $this->assertSame('DECREASE', $event->direction);
            }
            $event = $events->firstWhere('event_type', 'RECEIVABLE_CORRECTED_RESIDUAL_DUE');
            $this->assertSame($this->decimal($residual), $this->decimal($event->amount));
            $this->assertSame('INCREASE', $event->direction);
            $this->assertSame($this->decimal($residual), $balances->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertCount(1, $balances->payableReceivableEntries($f['captain'], $f['branch'], $f['currency']));
            $this->assertSame(0, DB::table('financial_transactions')->where('reversal_of_transaction_id', $entry->financial_transaction_id)->count());
        }
        $this->assertEquals($history, $this->moneyHistory());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_multiple_payouts_have_exact_immutable_excess_slices(): void
    {
        $f = $this->fixture('USD');
        $entry = $this->productEntry($f);
        $this->payout($f, $entry, '30');
        $this->payout($f, $entry, '20');
        $history = $this->moneyHistory();
        $c = $this->prepareAndRefund($f, $this->correction($f, '35'));
        $d = $c->receivableDispositions()->sole();
        $this->assertSame('50.0000', $this->decimal($d->historical_paid));
        $this->assertSame('15.0000', $this->decimal($d->recovery_required));
        $slices = $d->allocations()->orderBy('payout_occurred_at')->orderBy('captain_receivable_payout_id')->orderBy('captain_receivable_payout_allocation_id')->get();
        $this->assertCount(2, $slices);
        $this->assertSame(['30.0000', '5.0000'], $slices->map(fn ($r) => $this->decimal($r->covered_amount))->all());
        $this->assertSame(['0.0000', '15.0000'], $slices->map(fn ($r) => $this->decimal($r->excess_amount))->all());
        $this->assertEquals($history, $this->moneyHistory());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_original_source_is_blocked_and_only_exact_residual_can_be_paid(): void
    {
        $f = $this->fixture('USD');
        $old = $this->productEntry($f);
        $this->payout($f, $old, '40');
        $c = $this->prepareAndRefund($f, $this->correction($f, '70'));
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        try {
            $this->payout($f, $old, '1');
            $this->fail('Disposed source remained payable.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }
        $this->expectInvalidSQL(fn () => DB::table('captain_receivable_payout_allocations')->insert([
            'captain_receivable_payout_id' => DB::table('captain_receivable_payouts')->value('id'), 'financial_entry_id' => $old->id, 'amount' => '1']));
        $new = DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->where('t.event_type', 'RECEIVABLE_CORRECTED_RESIDUAL_DUE')->select('e.*')->sole();
        $this->payout($f, $new, '30');
        $this->assertCount(0, app(FinancialBalanceQueryService::class)->payableReceivableEntries($f['captain'], $f['branch'], $f['currency']));
        try {
            $this->payout($f, $new, '1');
            $this->fail('Residual was paid twice.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_reserved_source_does_not_block_unrelated_same_account_source_and_unknown_still_conflicts(): void
    {
        $f = $this->fixture('USD');
        $other = $this->fixture('USD', $f);
        $entry = $this->productEntry($f);
        $this->payout($f, $entry, '40');
        $c = $this->prepareAndRefund($f, $this->correction($f, '70'));
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('160.0000', $balances->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('60.0000', $this->decimal($balances->reservedReceivableEntries($f['captain'], $f['branch'], $f['currency'])->sole()->remaining_amount));
        $this->payout($other, $this->productEntry($other), '50');
        app(FinancialPostingService::class)->postEvent($f['currency'], 'UNKNOWN', '1', 'UNKNOWN', 1, now(),
            [['account' => FinancialAccount::findOrFail($entry->financial_account_id), 'direction' => FinancialEntryDirection::Increase, 'amount' => '7']], $f['branch']);
        try {
            $this->payout($other, $this->productEntry($other), '1');
            $this->fail('Unknown Receivable was ignored.');
        } catch (FinancialIdempotencyConflictException $e) {
            $this->assertStringContainsString('unattributed', $e->getMessage());
        }
        $this->assertSame('40.0000', $this->decimal($c->receivableDispositions()->sole()->historical_paid));
    }

    public function test_multiple_captains_are_independent_and_any_recovery_blocks_the_whole_correction(): void
    {
        $f = $this->fixture('USD', null, '0', 2);
        $this->payout($f, $this->productEntry($f), '90');
        $c = $this->prepareAndRefund($f, $this->correction($f, '140'));
        $rows = $c->receivableDispositions()->orderBy('captain_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('20.0000', $this->decimal($rows[0]->recovery_required));
        $this->assertSame('70.0000', $this->decimal($rows[1]->residual_payable));
        try {
            app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
            $this->fail('Another Captain offset recovery.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('CAPTAIN_RECOVERY_REQUIRED', $e->errors()['correction'][0]);
        }
        $this->assertSame(0, DB::table('financial_transactions')->where('source_type', 'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_sql_rejects_missing_set_and_all_disposition_mutation(): void
    {
        $f = $this->fixture('USD');
        $this->payout($f, $this->productEntry($f), '40');
        $c = $this->correction($f, '70');
        $this->expectInvalidSQL(fn () => DB::table('invoice_financial_corrections')->where('id', $c->id)->update([
            'state' => 'PREPARED', 'receivable_contract_version' => 2, 'receivable_dispositions_captured' => true,
            'corrected_amount' => '170', 'customer_delta' => '-30', 'prepared_by' => $f['admin']->id, 'prepared_at' => now(), 'proposal_hash' => str_repeat('a', 64)]));
        $c = $this->prepareAndRefund($f, $c);
        $d = $c->receivableDispositions()->sole();
        foreach (['original_entitlement', 'historical_paid', 'corrected_entitlement', 'original_unpaid', 'residual_payable', 'recovery_required', 'captain_id', 'branch_id', 'currency_id', 'payload_hash'] as $column) {
            $this->expectInvalidSQL(fn () => DB::table('invoice_correction_receivable_dispositions')->where('id', $d->id)->update([$column => $column === 'payload_hash' ? str_repeat('b', 64) : '999']));
        }
        $this->expectInvalidSQL(fn () => DB::table('invoice_correction_receivable_dispositions')->where('id', $d->id)->delete());
        $this->expectInvalidSQL(fn () => DB::table('invoice_correction_receivable_disposition_allocations')->where('disposition_id', $d->id)->update(['amount' => '41']));
        $this->expectInvalidSQL(fn () => DB::table('invoice_correction_receivable_disposition_allocations')->where('disposition_id', $d->id)->delete());
        $this->expectInvalidSQL(fn () => DB::table('invoice_correction_receivable_disposition_allocations')->insert([
            'disposition_id' => $d->id, 'captain_receivable_payout_allocation_id' => 999999, 'captain_receivable_payout_id' => 999999,
            'original_financial_entry_id' => $d->original_receivable_financial_entry_id, 'amount' => '1', 'covered_amount' => '1', 'excess_amount' => '0', 'payout_occurred_at' => now()]));
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->assertDatabaseHas('invoice_financial_corrections', ['id' => $c->id, 'state' => 'COMPLETED']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_sql_rejects_nonfinite_money_excess_precision_and_extra_source_facts(): void
    {
        $f = $this->fixture('USD');
        $this->payout($f, $this->productEntry($f), '40');
        $c = $this->prepareAndRefund($f, $this->correction($f, '70'));
        $row = (array) DB::table('invoice_correction_receivable_dispositions')->sole();
        unset($row['id']);
        $row['concept'] = 'CASH_SHORTFALL';
        foreach (['NaN', 'Infinity', '-Infinity', '-1', '1.2345'] as $invalid) {
            $candidate = array_replace($row, ['original_entitlement' => '0', 'historical_paid' => '0', 'original_unpaid' => '0',
                'original_receivable_financial_entry_id' => null, 'original_receivable_financial_transaction_id' => null,
                'corrected_entitlement' => $invalid, 'residual_payable' => $invalid, 'recovery_required' => '0']);
            $this->expectInvalidSQL(fn () => DB::table('invoice_correction_receivable_dispositions')->insert($candidate));
        }
        // This is structurally valid money and valid foreign keys, but an extra semantic source.
        $extra = array_replace($row, ['original_entitlement' => '0', 'historical_paid' => '0', 'original_unpaid' => '0',
            'original_receivable_financial_entry_id' => null, 'original_receivable_financial_transaction_id' => null,
            'corrected_entitlement' => '1', 'residual_payable' => '1', 'recovery_required' => '0']);
        $this->expectInvalidSQL(fn () => DB::table('invoice_correction_receivable_dispositions')->insert($extra));
        $this->assertDatabaseCount('invoice_correction_receivable_dispositions', 1);
        $this->assertSame('COMPLETED', app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c)->state->value);
        $residual = DB::table('financial_transactions')->where('event_type', 'RECEIVABLE_CORRECTED_RESIDUAL_DUE')->sole();
        $entry = (array) DB::table('financial_entries')->where('financial_transaction_id', $residual->id)->sole();
        unset($entry['id']);
        $entry['amount'] = '1';
        $this->expectInvalidSQL(fn () => DB::table('financial_entries')->insert($entry));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_multi_captain_safe_residuals_complete_in_historical_branch_after_transfer(): void
    {
        $f = $this->fixture('USD', null, '0', 2);
        $this->payout($f, $this->productEntry($f), '40');
        $this->payout($f, $this->productEntry($f, $f['captains'][1]), '60', $f['captains'][1]);
        $newBranch = Branch::factory()->create();
        // A transfer of the current home Branch cannot redirect historical financial facts.
        app(CaptainBranchService::class)->transfer($f['admin'], $f['captain'], [
            'branch_id' => $newBranch->id, 'starts_at' => now()->toIso8601String(), 'reason' => 'Transfer after historical Order']);
        $c = $this->prepareAndRefund($f, $this->correction($f, '140'));
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $rows = $c->receivableDispositions()->orderBy('captain_id')->get();
        $this->assertSame(['30.0000', '10.0000'], $rows->map(fn ($d) => $this->decimal($d->residual_payable))->all());
        $this->assertSame([$f['branch']->id], $rows->pluck('branch_id')->unique()->all());
        $this->assertSame([$f['currency']->id], $rows->pluck('currency_id')->unique()->all());
        $this->assertSame([$f['branch']->id], DB::table('financial_transactions')->where('source_type', 'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION')->distinct()->pluck('branch_id')->all());
        $this->assertCount(0, app(FinancialBalanceQueryService::class)->payableReceivableEntries($f['captain'], $newBranch, $f['currency']));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_unpaid_correction_before_delivery_keeps_v1_posting_and_corrected_source_payout(): void
    {
        $f = $this->fixture('USD', deliver: false);
        $c = $this->prepareAndRefund($f, $this->correction($f, '70'));
        $this->assertFalse($c->receivable_dispositions_captured);
        $this->assertDatabaseCount('invoice_correction_receivable_dispositions', 0);
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        app(OrderStatusService::class)->transition($f['employee'], $f['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $source = DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->where('t.event_type', 'INVOICE_CORRECTION_SHAM_PRODUCT_DUE')->select('e.*')->sole();
        $this->payout($f, $source, '70');
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function expectInvalidSQL(callable $write): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Invalid SQL committed.');
        } catch (QueryException|\PDOException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_legitimate_zero_original_cash_shortfall_has_no_fake_source_and_preserves_unpaid_v1_path(): void
    {
        $f = $this->fixture('USD', null, '100', 1, InvoiceCollectionMethod::Cash);
        $c = $this->prepareAndRefund($f, $this->correction($f, '100', '50'));
        $d = $c->receivableDispositions()->sole();
        $this->assertSame(1, $c->receivable_contract_version);
        $this->assertNull($d->original_receivable_financial_entry_id);
        $this->assertNull($d->original_receivable_financial_transaction_id);
        $this->assertSame('0.0000', $this->decimal($d->original_entitlement));
        $this->assertSame('50.0000', $this->decimal($d->residual_payable));
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->assertDatabaseHas('financial_transactions', ['source_type' => 'INVOICE_FINANCIAL_CORRECTION', 'event_type' => 'INVOICE_CORRECTION_CASH_SHORTFALL_DUE']);
        $this->assertSame(0, DB::table('financial_transactions')->where('source_type', 'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_collected_cancellation_and_abandonment_cannot_bypass_required_recovery_or_repeat_refund(): void
    {
        $f = $this->fixture('USD');
        $this->payout($f, $this->productEntry($f), '40');
        $service = app(InvoiceFinancialCorrectionService::class);
        $c = $service->request($f['employee'], $f['invoice'], InvoiceFinancialCorrectionKind::CancelAndRefund, [],
            'Cancellation requested', 'Paid-source cancellation recovery is deferred', (string) Str::uuid());
        $c = $this->prepareAndRefund($f, $c);
        $this->assertSame('40.0000', $this->decimal($c->receivableDispositions()->sole()->recovery_required));
        $refund = $c->refund;
        try {
            $service->finalize($f['admin'], $c);
            $this->fail('Unrecovered cancellation completed.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('CAPTAIN_RECOVERY_REQUIRED', $e->errors()['correction'][0]);
        }
        try {
            \app(CustomerRefundService::class)->record($f['admin'], $c, (string) Str::uuid(), now(), 'SECOND-REFUND', $this->proof());
            $this->fail('Refund repeated.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('request_key', $e->errors());
        }
        try {
            $service->abandon($f['admin'], $c);
            $this->fail('Real refund hidden by abandonment.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('correction', $e->errors());
        }
        $this->assertDatabaseCount('customer_refunds', 1);
        $this->assertSame($refund->id, $c->fresh()->refund->id);
        $this->assertSame('DELIVERED', $f['order']->fresh()->operational_status->value);
        $this->assertSame('READY_TO_FINALIZE', $c->fresh()->state->value);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function decimal(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(4);
    }

    private function moneyHistory(): array
    {
        return [DB::table('captain_receivable_payouts')->orderBy('id')->get()->all(),
            DB::table('captain_receivable_payout_allocations')->orderBy('id')->get()->all(),
            DB::table('financial_transactions')->where('event_type', 'CAPTAIN_RECEIVABLE_PAID')->orderBy('id')->get()->all(),
            DB::table('financial_entries')->whereIn('financial_transaction_id', DB::table('financial_transactions')->where('event_type', 'CAPTAIN_RECEIVABLE_PAID')->select('id'))->orderBy('id')->get()->all()];
    }
}
