<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerCreditNotice;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CustomerCreditCashPayoutService;
use App\Services\CustomerCreditService;
use App\Services\CustomerReturnService;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerReturnFixtures;
use Tests\TestCase;

class CustomerCreditCashPayoutTest extends TestCase
{
    use CustomerReturnFixtures, RefreshDatabase;

    private function grant(array $c): void
    {
        app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
            'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'reason' => 'ERROR', 'amount' => '30', 'reference' => (string) str()->uuid(), 'notes' => 'Documented prior error', 'occurred_at' => now()->toIso8601String(),
        ]);
    }

    private function payout(array $c, string $amount = '30'): array
    {
        return ['request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'captain_id' => $c['captains'][0]->id, 'amount' => $amount, 'reason' => 'Actual personal cash handed to Customer',
            'reference' => (string) str()->uuid(), 'occurred_at' => now()->toIso8601String(), 'personal_cash_confirmed' => true];
    }

    public function test_full_personal_funds_handover_debits_credit_and_has_one_separate_payable_source_with_private_evidence(): void
    {
        $c = $this->sold(deliveryFee: '100');
        $this->grant($c);
        $returnData = $this->data($c) + ['compensation_method' => 'CREDIT'];
        $returnData['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][0]->id, 'amount' => '50'], ['party' => 'COMPANY', 'amount' => '30']];
        $case = app(CustomerReturnService::class)->record($c['actor'], $c['order'], $returnData);
        $balances = app(FinancialBalanceQueryService::class);
        $before = $balances->captainBalances($c['captains'][0], $c['currency']);
        $data = $this->payout($c, '110');
        $proof = $this->proof('actual handover to Customer');
        $service = app(CustomerCreditCashPayoutService::class);

        $this->actingAs($c['actor'])->get(route('customers.show', $c['order']->customer))
            ->assertOk()->assertSee(route('customers.credits.payout', $c['order']->customer), false)->assertSee(__('credit.personal_cash_attestation'));
        $this->post(route('customers.credits.payout', $c['order']->customer), $data + ['evidence' => $proof])
            ->assertRedirect(route('customers.show', $c['order']->customer))->assertSessionHas('status', __('credit.cash_paid'));
        $source = DB::table('customer_credit_cash_payouts')->sole();

        $this->assertSame($source->id, $service->record($c['actor'], $c['order']->customer, $data, $proof)->id);
        $this->assertDatabaseCount('customer_credit_cash_payouts', 1);
        $this->assertDatabaseCount('customer_credit_cash_payout_grants', 2);
        $this->assertDatabaseCount('customer_credit_cash_payout_attributions', 2);
        $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        $after = $balances->captainBalances($c['captains'][0], $c['currency']);
        $this->assertSame($before['CAPTAIN_DEPOSIT'], $after['CAPTAIN_DEPOSIT']);
        $this->assertSame($before['CAPTAIN_CASH_CUSTODY'], $after['CAPTAIN_CASH_CUSTODY']);
        $this->assertSame((string) BigDecimal::of($before['CAPTAIN_COMPANY_RECEIVABLE'])->plus('110')->toScale(4), $after['CAPTAIN_COMPANY_RECEIVABLE']);
        $this->assertSame('50.0000', app(CustomerReturnService::class)->summary($c['actor'], $c['order'])->sole()->responsibilities->firstWhere('party', 'CAPTAIN')->funded_amount);
        $this->assertSame('50.0000', app(CustomerReturnService::class)->captainLiabilities($c['actor'], $c['captains'][0])->sole()->outstanding);
        $entry = $balances->payableReceivableEntries($c['captains'][0], $c['branch'], $c['currency'])->where('event_type', 'CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE')->sole();
        $this->assertSame('110.0000', $entry->remaining_amount);
        $this->assertStringContainsString(__('financial.events.CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE'),
            Blade::render('<x-financial.receivable-label event-type="CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE" />'));
        Storage::disk('customer_refunds')->assertExists($source->evidence_path);
        $this->actingAs($c['actor'])->get(route('customers.credits.payout-evidence', [$c['order']->customer, $source->id]))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('notifications', ['type' => CustomerCreditNotice::class, 'data->request_key' => $data['request_key']]);
        $this->assertThrows(fn () => $service->record($c['actor'], $c['order']->customer, array_replace($data, ['request_key' => (string) str()->uuid()]), $this->proof('duplicate full balance')), ValidationException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('customer_credit_cash_payouts')->where('id', $source->id)->update(['amount' => '1'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('customer_credit_cash_payout_attributions')->delete()), QueryException::class);

        $payoutData = ['request_key' => (string) str()->uuid(), 'amount' => '30', 'occurred_at' => now()->toIso8601String(), 'payment_method' => 'CASH', 'received_by_user_id' => $c['captains'][0]->user_id];
        app(CaptainReceivablePayoutService::class)->record($c['admin'], $c['captains'][0], $c['branch'], $c['currency'], $payoutData,
            [['financial_entry_id' => $entry->financial_entry_id, 'amount' => '30']], $this->proof('company reimburses thirty'));
        $this->assertSame('80.0000', $balances->payableReceivableEntries($c['captains'][0], $c['branch'], $c['currency'])->where('event_type', 'CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE')->sole()->remaining_amount);
        $finalPayout = ['request_key' => (string) str()->uuid(), 'amount' => '80', 'occurred_at' => now()->toIso8601String(),
            'payment_method' => 'TRANSFER', 'received_by_user_id' => $c['captains'][0]->user_id, 'reference' => 'ACTUAL-COMPANY-REIMBURSEMENT'];
        $finalProof = $this->proof('company reimburses remaining eighty');
        $payouts = app(CaptainReceivablePayoutService::class);
        $final = $payouts->record($c['admin'], $c['captains'][0], $c['branch'], $c['currency'], $finalPayout,
            [['financial_entry_id' => $entry->financial_entry_id, 'amount' => '80']], $finalProof);
        $this->assertSame($final->id, $payouts->record($c['admin'], $c['captains'][0], $c['branch'], $c['currency'], $finalPayout,
            [['financial_entry_id' => $entry->financial_entry_id, 'amount' => '80']], $finalProof)->id);
        $this->assertFalse($balances->payableReceivableEntries($c['captains'][0], $c['branch'], $c['currency'])->contains('event_type', 'CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE'));
        $this->assertThrows(fn () => $payouts->record($c['admin'], $c['captains'][0], $c['branch'], $c['currency'],
            array_replace($finalPayout, ['request_key' => (string) str()->uuid(), 'amount' => '1']),
            [['financial_entry_id' => $entry->financial_entry_id, 'amount' => '1']], $this->proof('duplicate reimbursement forbidden')), ValidationException::class);
        $this->assertSame($before, $balances->captainBalances($c['captains'][0], $c['currency']));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('invalidPayouts')]
    public function test_invalid_or_unattested_cash_payout_leaves_credit_and_financial_history_unchanged(string $defect): void
    {
        $c = $this->sold();
        $this->grant($c);
        $data = $this->payout($c);
        match ($defect) {
            'partial' => $data['amount'] = '20',
            'excess' => $data['amount'] = '31',
            'fraction' => $data['amount'] = '30.001',
            'unattested' => $data['personal_cash_confirmed'] = false,
            'before grant' => $data['occurred_at'] = now()->subMinute()->toIso8601String(),
        };
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();

        $this->assertThrows(fn () => app(CustomerCreditCashPayoutService::class)->record($c['actor'], $c['order']->customer, $data, $this->proof('invalid cash')), ValidationException::class);

        $this->assertDatabaseCount('customer_credit_cash_payouts', 0);
        $this->assertSame('30.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertCount(0, Storage::disk('customer_refunds')->allFiles());
    }

    public static function invalidPayouts(): array
    {
        return [['partial'], ['excess'], ['fraction'], ['unattested'], ['before grant']];
    }

    public function test_cash_payout_requires_specific_permission_and_branch_access(): void
    {
        $c = $this->sold();
        $this->grant($c);
        $unauthorized = User::factory()->create();
        $this->assertThrows(fn () => app(CustomerCreditCashPayoutService::class)->record($unauthorized, $c['order']->customer, $this->payout($c), $this->proof('forbidden')), AuthorizationException::class);
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $foreign->branches()->attach(Branch::factory()->create());
        $this->actingAs($foreign)->post(route('customers.credits.payout', $c['order']->customer),
            $this->payout($c) + ['evidence' => $this->proof('foreign branch cannot hand over credit')])->assertForbidden();
        $this->actingAs($foreign)->get(route('customers.credits.payout-evidence', [$c['order']->customer, 1]))->assertForbidden();
        $this->assertDatabaseCount('customer_credit_cash_payouts', 0);
    }

    public function test_notification_failure_rolls_back_cash_handover_credit_and_captain_entitlement(): void
    {
        $c = $this->sold();
        $this->grant($c);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        DB::unprepared("CREATE FUNCTION cash_fail_notice() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''cash notice failed''; END'; CREATE TRIGGER cash_fail_notice BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION cash_fail_notice()");
        try {
            $this->assertThrows(fn () => app(CustomerCreditCashPayoutService::class)->record($c['actor'], $c['order']->customer, $this->payout($c), $this->proof('notice rollback')), QueryException::class);
        } finally {
            DB::unprepared('DROP TRIGGER cash_fail_notice ON notifications; DROP FUNCTION cash_fail_notice()');
        }
        $this->assertDatabaseCount('customer_credit_cash_payouts', 0);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertSame('30.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        $this->assertCount(0, Storage::disk('customer_refunds')->allFiles());
    }

    #[DataProvider('forgedEntitlements')]
    public function test_database_requires_the_exact_canonical_personal_funds_entitlement(string $defect): void
    {
        $c = $this->sold();
        $this->grant($c);
        $grant = DB::table('customer_credit_entries')->sole();
        $accounts = app(FinancialAccountService::class);
        $account = $accounts->getOrCreate($accounts->ownerForCaptain($c['captains'][0]), $c['currency'],
            $defect === 'deposit account' ? FinancialAccountKind::CaptainDeposit : FinancialAccountKind::CaptainCompanyReceivable);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        try {
            DB::transaction(function () use ($c, $grant, $account, $defect): void {
                $data = $this->payout($c);
                $id = DB::table('customer_credit_cash_payouts')->insertGetId([
                    'request_key' => $data['request_key'], 'payload_hash' => hash('sha256', 'negative SQL fixture'),
                    'customer_id' => $c['order']->customer_id, 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                    'captain_id' => $c['captains'][0]->id, 'recorded_by' => $c['actor']->id, 'amount' => '30',
                    'reason' => $data['reason'], 'reference' => $data['reference'], 'occurred_at' => $data['occurred_at'], 'personal_cash_confirmed' => true,
                    'evidence_path' => 'negative-fixture.png', 'evidence_name' => 'negative-fixture.png', 'evidence_mime' => 'image/png',
                    'evidence_size' => 100, 'evidence_sha256' => hash('sha256', 'negative fixture evidence'),
                ]);
                DB::table('customer_credit_cash_payout_grants')->insert(['payout_id' => $id, 'grant_id' => $grant->id, 'amount' => '30']);
                if ($defect !== 'missing entitlement') {
                    app(FinancialPostingService::class)->postEvent($c['currency'],
                        $defect === 'forged source' ? 'UNRELATED_SOURCE' : 'CUSTOMER_CREDIT_CASH_PAYOUT', $id,
                        FinancialEventType::CustomerCreditPersonalFundsDue->value, 1, now(),
                        [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $defect === 'wrong amount' ? '29' : '30']],
                        $c['branch'], $c['actor'], 'Negative canonical source test');
                }
                DB::select('SELECT assert_credit_cash_payout(?)', [$id]);
            });
            $this->fail('A malformed entitlement bypassed PostgreSQL: '.$defect);
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertDatabaseCount('customer_credit_cash_payouts', 0);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertSame('30.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
    }

    public static function forgedEntitlements(): array
    {
        return [['missing entitlement'], ['wrong amount'], ['deposit account'], ['forged source']];
    }
}
