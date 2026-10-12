<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainBenefitReview;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBenefitRequestService;
use App\Services\CaptainBenefitReviewService;
use App\Services\CaptainReceivablePayoutService;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CaptainBenefitFixtures;
use Tests\TestCase;

class CaptainBenefitApprovalTest extends TestCase
{
    use CaptainBenefitFixtures, RefreshDatabase;

    public function test_permissions_self_approval_branch_and_nested_tampering_are_blocked(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $url = route('captains.requests.review', [$c['captain'], $c['proposal']]);
        $data = ['request_key' => (string) Str::uuid(), 'action' => 'APPROVE'];
        $this->actingAs($c['creator'])->post($url, $data)->assertSessionHasErrors('proposal');
        $this->actingAs($c['employee'])->post($url, $data)->assertForbidden();
        $this->actingAs($c['captain']->user)->post($url, $data)->assertForbidden();
        $this->assertFalse($c['employee']->can('captain_bonuses.approve'));
        $this->assertFalse($c['employee']->can('captain_expenses.approve'));
        $this->assertFalse($c['captain']->user->can('captain_bonuses.approve'));
        $delegate = User::factory()->create();
        $role = Role::create(['code' => 'BENEFIT_TEST_REVIEWER', 'name' => 'Bonus reviewer']);
        $role->permissions()->attach(Permission::whereIn('code', ['captains.view', 'captain_bonuses.approve'])->pluck('id'));
        $delegate->roles()->attach($role);
        $delegate->branches()->attach(Branch::factory()->create());
        $this->actingAs($delegate)->post($url, $data)->assertForbidden();
        $delegate->branches()->attach($c['branch']);
        $other = Captain::factory()->create(['home_branch_id' => $c['branch']->id]);
        $this->post(route('captains.requests.review', [$other, $c['proposal']]), $data)->assertNotFound();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $expense = $this->benefitContext('EXPENSE', true);
        $delegate->branches()->attach($expense['branch']);
        $this->benefitAction($expense, 'SUBMIT');
        $this->post(route('captains.requests.review', [$expense['captain'], $expense['proposal']]), array_replace($data, ['request_key' => (string) Str::uuid()]))->assertForbidden();
        $other = $this->benefitContext();
        $this->benefitAction($other, 'SUBMIT', $other['reviewer']);
        $this->actingAs($other['reviewer'])->post(route('captains.requests.review', [$other['captain'], $other['proposal']]),
            array_replace($data, ['request_key' => (string) Str::uuid()]))->assertSessionHasErrors('proposal');
        $this->assertSame(1, FinancialTransaction::count());
    }

    public function test_creator_cannot_bypass_self_approval_using_direct_sql(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $this->expectException(QueryException::class);
        DB::transaction(fn () => $c['proposal']->reviews()->create([
            'actor_id' => $c['creator']->id, 'action' => 'APPROVE', 'request_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'recorded_at' => now(),
        ]));
    }

    #[DataProvider('kinds')]
    public function test_approval_posts_one_exact_source_atomically_and_retry_never_duplicates(string $kind): void
    {
        $c = $this->benefitContext($kind, $kind === 'EXPENSE');
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->benefitAction($c, 'SUBMIT');
        $data = ['request_key' => (string) Str::uuid(), 'action' => 'APPROVE'];
        $service = app(CaptainBenefitReviewService::class);
        $approval = $service->act($c['reviewer'], $c['captain'], $c['proposal'], $data);
        $retry = $service->act($c['reviewer'], $c['captain'], $c['proposal'], $data);
        $this->assertSame($approval->id, $retry->id);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('financial_entries', 1);
        $transaction = FinancialTransaction::sole();
        $this->assertSame('CAPTAIN_BENEFIT_APPROVAL', $transaction->source_type);
        $this->assertSame((string) $approval->id, $transaction->source_id);
        $this->assertSame($kind === 'BONUS' ? 'CAPTAIN_BONUS_DUE' : 'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE', $transaction->event_type);
        $this->assertSame($c['branch']->id, $transaction->branch_id);
        $this->assertSame('20.0000', $transaction->entries()->sole()->amount);
        $balances = app(FinancialBalanceQueryService::class)->captainBalances($c['captain'], $c['currency']);
        $this->assertSame('20.0000', $balances['CAPTAIN_COMPANY_RECEIVABLE']);
        $this->assertSame('0.0000', $balances['CAPTAIN_DEPOSIT']);
        $this->assertSame('0.0000', $balances['CAPTAIN_CASH_CUSTODY']);
        $this->assertSame('APPROVED_UNPAID', $service->summary($c['proposal'])['state']);
        $this->assertThrows(fn () => app(CaptainBenefitRequestService::class)->save($c['creator'], $c['captain'], [
            'request_key' => (string) Str::uuid(), 'expected_version' => 1, 'amount' => '50', 'description' => 'Changed',
            'revision_note' => 'Change', ...($kind === 'EXPENSE' ? ['category' => 'TRANSPORT', 'expense_date' => today()->toDateString()] : []),
        ], null, $c['proposal']), ValidationException::class);
    }

    public static function kinds(): array
    {
        return [['BONUS'], ['EXPENSE']];
    }

    public function test_expense_requires_actual_intact_evidence_and_failures_leave_no_accrual(): void
    {
        $c = $this->benefitContext('EXPENSE');
        $this->benefitAction($c, 'SUBMIT');
        $url = route('captains.requests.review', [$c['captain'], $c['proposal']]);
        $this->actingAs($c['reviewer'])->post($url, ['request_key' => (string) Str::uuid(), 'action' => 'APPROVE'])->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('financial_transactions', 0);
        $withEvidence = $this->benefitContext('EXPENSE', true);
        $this->benefitAction($withEvidence, 'SUBMIT');
        Storage::disk('captain_financial_evidence')->put($withEvidence['proposal']->evidence_path, 'bad');
        $this->actingAs($withEvidence['reviewer'])->post(route('captains.requests.review', [$withEvidence['captain'], $withEvidence['proposal']]), [
            'request_key' => (string) Str::uuid(), 'action' => 'APPROVE',
        ])->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame(0, CaptainBenefitReview::where('action', 'APPROVE')->count());
    }

    public function test_rejection_requires_reason_and_cancel_never_posts_money(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $url = route('captains.requests.review', [$c['captain'], $c['proposal']]);
        $this->actingAs($c['reviewer'])->post($url, ['request_key' => (string) Str::uuid(), 'action' => 'REJECT'])->assertSessionHasErrors('reason');
        $this->benefitAction($c, 'REJECT', reason: 'Not a business expense');
        $this->assertSame('REJECTED', app(CaptainBenefitReviewService::class)->summary($c['proposal'])['state']);
        $this->assertThrows(fn () => $this->benefitAction($c, 'APPROVE'), ValidationException::class);
        $another = $this->benefitContext();
        $this->benefitAction($another, 'CANCEL', $another['creator']);
        $this->assertSame('CANCELLED', app(CaptainBenefitReviewService::class)->summary($another['proposal'])['state']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_partial_full_payouts_are_exact_evidenced_attributed_and_paid_source_cannot_reverse(): void
    {
        $this->travelTo(now()->subMinute()->microsecond(654321));
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');
        $approval = CaptainBenefitReview::where('action', 'APPROVE')->sole();
        $this->assertSame('654321', $c['proposal']->fresh()->recorded_at->format('u'));
        $this->assertSame('654321', $approval->recorded_at->format('u'));
        $this->assertTrue($approval->recorded_at->equalTo(FinancialTransaction::sole()->occurred_at));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $entry = FinancialTransaction::sole()->entries()->sole();
        $firstData = $this->payoutData($c, '7');
        $service = app(CaptainReceivablePayoutService::class);
        $file = $this->benefitImage();
        $first = $service->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'], $firstData, [['financial_entry_id' => $entry->id, 'amount' => '7']], $file);
        $again = $service->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'], $firstData, [['financial_entry_id' => $entry->id, 'amount' => '7']], $file);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('CASH', $first->payment_method);
        $this->assertSame($c['captain']->user_id, $first->received_by_user_id);
        $this->assertSame('PARTIALLY_PAID', app(CaptainBenefitReviewService::class)->summary($c['proposal'])['state']);
        $this->assertThrows(fn () => $this->benefitAction($c, 'REVERSE', reason: 'Undo'), ValidationException::class);
        $this->assertThrows(fn () => $service->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'], $this->payoutData($c, '14'), [['financial_entry_id' => $entry->id, 'amount' => '14']], $this->benefitImage()), ValidationException::class);
        $secondData = array_replace($this->payoutData($c, '13'), ['payment_method' => 'TRANSFER', 'reference' => 'BANK-TEST']);
        $second = $service->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'], $secondData, [['financial_entry_id' => $entry->id, 'amount' => '13']], $this->benefitImage());
        $this->assertSame('TRANSFER', $second->payment_method);
        $this->assertSame('FULLY_PAID', app(CaptainBenefitReviewService::class)->summary($c['proposal'])['state']);
        $this->assertSame('20.0000', (string) DB::table('captain_receivable_payout_allocations')->sum('amount'));
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance($c['captain'], $c['branch'], $c['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_payout_cannot_use_other_branch_currency_captain_missing_method_or_wrong_receiver(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');
        $entry = FinancialTransaction::sole()->entries()->sole();
        $service = app(CaptainReceivablePayoutService::class);
        $allocations = [['financial_entry_id' => $entry->id, 'amount' => '10']];
        foreach ([
            [$c['captain'], Branch::factory()->create(), $c['currency']],
            [$c['captain'], $c['branch'], Currency::create(['code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2])],
            [Captain::factory()->create(['home_branch_id' => $c['branch']->id]), $c['branch'], $c['currency']],
        ] as [$captain, $branch, $currency]) {
            $data = array_replace($this->payoutData($c, '10'), ['received_by_user_id' => $captain->user_id]);
            $this->assertThrows(fn () => $service->record($c['reviewer'], $captain, $branch, $currency, $data, $allocations, $this->benefitImage()), ValidationException::class);
        }
        $data = $this->payoutData($c, '10');
        unset($data['payment_method'], $data['received_by_user_id']);
        $this->assertThrows(fn () => $service->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'], $data, $allocations, $this->benefitImage()), ValidationException::class);
        $wrong = array_replace($this->payoutData($c, '10'), ['received_by_user_id' => $c['creator']->id]);
        $this->assertThrows(fn () => $service->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'], $wrong, $allocations, $this->benefitImage()), ValidationException::class);
        $transfer = array_replace($this->payoutData($c, '10'), ['payment_method' => 'TRANSFER']);
        $this->assertThrows(fn () => $service->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'], $transfer, $allocations, $this->benefitImage()), ValidationException::class);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
    }

    public function test_entirely_unpaid_reversal_is_exact_audited_and_keeps_original_branch_after_transfer(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');
        $original = FinancialTransaction::sole();
        $c['captain']->update(['home_branch_id' => Branch::factory()->create()->id]);
        $this->benefitAction($c, 'REVERSE', reason: 'Approved amount entered in error');
        $reversal = FinancialTransaction::whereNotNull('reversal_of_transaction_id')->sole();
        $this->assertSame($original->id, $reversal->reversal_of_transaction_id);
        $this->assertSame($c['branch']->id, $reversal->branch_id);
        $this->assertSame('20.0000', $reversal->entries()->sole()->amount);
        $this->assertSame('REVERSED', app(CaptainBenefitReviewService::class)->summary($c['proposal'])['state']);
        $this->assertCount(0, app(FinancialBalanceQueryService::class)->payableReceivableEntries($c['captain'], $c['branch'], $c['currency']));
        $this->assertDatabaseHas('financial_transactions', ['id' => $original->id, 'status' => 'POSTED']);
    }

    #[DataProvider('invalidPostings')]
    public function test_sql_blocks_forged_financial_source_and_rolls_back_approval(string $defect): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        try {
            DB::transaction(function () use ($c, $defect): void {
                $review = $c['proposal']->reviews()->create(['actor_id' => $c['reviewer']->id, 'action' => 'APPROVE', 'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'recorded_at' => now()]);
                $accounts = app(FinancialAccountService::class);
                $captain = $defect === 'Captain' ? Captain::factory()->create(['home_branch_id' => $c['branch']->id]) : $c['captain'];
                $account = $accounts->getOrCreate($accounts->ownerForCaptain($captain), $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
                if ($defect !== 'missing event') {
                    app(FinancialPostingService::class)->postEvent($c['currency'], $defect === 'source' ? 'ARBITRARY' : 'CAPTAIN_BENEFIT_APPROVAL', $review->id,
                        'CAPTAIN_BONUS_DUE', $defect === 'version' ? 2 : 1, $review->recorded_at,
                        [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $defect === 'amount' ? '19' : '20']],
                        $defect === 'Branch' ? Branch::factory()->create() : $c['branch'], $c['reviewer']);
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('SQL accepted a forged or incomplete benefit accrual');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('benefit', strtolower($exception->getMessage()));
        }
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame(0, CaptainBenefitReview::where('action', 'APPROVE')->count());
        $this->assertSame('PENDING', app(CaptainBenefitReviewService::class)->summary($c['proposal'])['state']);
    }

    public static function invalidPostings(): array
    {
        return array_map(fn (string $value): array => [$value], ['source', 'version', 'amount', 'Captain', 'Branch', 'missing event']);
    }

    public function test_ui_distinguishes_approval_from_payment_and_keeps_private_branch_history(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $url = route('captains.requests.show', [$c['captain'], $c['proposal']]);
        $this->actingAs($c['creator'])->get($url)->assertOk()->assertSee(__('captain_requests.self_approval'));
        $this->benefitAction($c, 'APPROVE');
        $this->actingAs($c['reviewer'])->get($url)->assertOk()->assertSee(__('captain_requests.states.APPROVED_UNPAID'))
            ->assertSee(__('captain_requests.open_payout'))->assertDontSee(__('captain_requests.states.FULLY_PAID'));
        $this->get(route('captains.financials.show', [$c['captain'], 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id]))
            ->assertOk()->assertSee(__('financial.events.CAPTAIN_BONUS_DUE'))->assertSee('name="payment_method"', false);
    }

    private function payoutData(array $c, string $amount): array
    {
        return ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
            'payment_method' => 'CASH', 'received_by_user_id' => $c['captain']->user_id];
    }
}
