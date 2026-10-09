<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainDepositReceiptService;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CaptainFinancialOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function test_deposit_receipt_cannot_be_reversed_by_service_or_direct_sql(): void
    {
        Storage::fake('captain_financial_evidence');
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $receipt = app(CaptainDepositReceiptService::class)->record($actor, $captain, $branch, $currency,
            ['request_key' => (string) str()->uuid(), 'amount' => '20.00',
                'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')], $this->image());
        $original = FinancialTransaction::where('source_type', 'CAPTAIN_DEPOSIT_RECEIPT')->sole();
        $this->assertThrows(fn () => app(FinancialPostingService::class)->reverse($original,
            'TEST_REVERSAL', (string) str()->uuid(), 'REVERSE_DEPOSIT', 1, now(), 'Attempt'),
            FinancialIdempotencyConflictException::class);
        try {
            DB::transaction(fn () => DB::table('financial_transactions')->insert([
                'transaction_kind' => 'REVERSAL', 'status' => 'DRAFT', 'currency_id' => $currency->id,
                'branch_id' => $branch->id, 'source_type' => 'DIRECT_SQL', 'source_id' => (string) str()->uuid(),
                'event_type' => 'REVERSE_DEPOSIT', 'source_version' => 1,
                'payload_hash' => str_repeat('a', 64), 'occurred_at' => now(),
                'reversal_of_transaction_id' => $original->id, 'reason' => 'Attempt', 'created_at' => now(),
            ]));
            $this->fail('Direct SQL reversed the real Deposit receipt.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('FINANCIAL_SOURCE_REQUIRES_CONTROLLED_CORRECTION', $exception->getMessage());
        }
        $this->assertSame(1, FinancialTransaction::count());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
        $this->assertDatabaseHas('captain_deposit_receipts', ['id' => $receipt->id]);
        $this->assertSame('20.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance(
            $captain, $branch, $currency, FinancialAccountKind::CaptainDeposit));
    }

    public function test_direct_sql_extra_hidden_entry_cannot_join_a_deposit_receipt(): void
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $actor = User::factory()->create();
        $accounts = app(FinancialAccountService::class);
        $owner = $accounts->ownerForCaptain($captain);
        $deposit = $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit);
        $receivable = $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCompanyReceivable);
        try {
            DB::transaction(function () use ($currency, $branch, $captain, $actor, $deposit, $receivable): void {
                $occurredAt = now()->startOfSecond();
                $receiptId = DB::table('captain_deposit_receipts')->insertGetId([
                    'captain_id' => $captain->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                    'received_by' => $actor->id, 'request_key' => (string) str()->uuid(), 'amount' => '100',
                    'occurred_at' => $occurredAt, 'evidence_path' => 'private/proof.png',
                    'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png', 'evidence_size' => 100,
                    'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
                ]);
                $id = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'currency_id' => $currency->id,
                    'branch_id' => $branch->id, 'source_type' => 'CAPTAIN_DEPOSIT_RECEIPT',
                    'source_id' => (string) $receiptId, 'event_type' => 'CAPTAIN_DEPOSIT_RECEIVED',
                    'source_version' => 1, 'payload_hash' => str_repeat('b', 64),
                    'occurred_at' => $occurredAt, 'created_at' => now(),
                ]);
                foreach ([[$deposit->id, 'INCREASE', '100'], [$receivable->id, 'DECREASE', '100']] as [$accountId, $direction, $amount]) {
                    DB::table('financial_entries')->insert([
                        'financial_transaction_id' => $id, 'financial_account_id' => $accountId,
                        'currency_id' => $currency->id, 'direction' => $direction,
                        'amount' => $amount, 'created_at' => now(),
                    ]);
                }
                DB::table('financial_transactions')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now()]);
                DB::statement('SET CONSTRAINTS captain_deposit_receipt_complete, captain_financial_event_complete, captain_financial_entry_complete IMMEDIATE');
            });
            $this->fail('The hidden financial entry committed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('wrong account, direction, or exact amount', $exception->getMessage());
        }
        $this->assertDatabaseCount('captain_deposit_receipts', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_employee_balance_and_outstanding_queries_filter_branch_in_sql_while_admin_sees_both(): void
    {
        Storage::fake('captain_financial_evidence');
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branchA = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $branchB = Branch::factory()->create(['default_currency_id' => $currency->id]);
        // Deliberately collide independent IDs: the forbidden Branch ID is also this Captain ID.
        $captain = Captain::factory()->create(['id' => $branchB->id, 'home_branch_id' => $branchA->id]);
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branchA);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        foreach ([[$employee, $branchA, '20'], [$admin, $branchB, '30']] as [$actor, $branch, $amount]) {
            app(CaptainDepositReceiptService::class)->record($actor, $captain, $branch, $currency,
                ['request_key' => (string) str()->uuid(), 'amount' => $amount,
                    'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')], $this->image());
        }
        $captured = [];
        DB::listen(function ($query) use (&$captured): void {
            if (str_contains($query->sql, 'financial_entries') && str_contains($query->sql, 'financial_transactions')) {
                $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });
        $balances = app(FinancialBalanceQueryService::class);
        $employeeRows = $balances->captainBalancesByBranch($captain, $currency, [$branchA->id]);
        $this->assertSame([$branchA->id], array_map('intval', array_keys($employeeRows)));
        $this->assertStringContainsString('"t"."branch_id" in', $captured[array_key_last($captured)]['sql']);
        // Compare the binding belonging to the Branch predicate, not unrelated Captain/Currency IDs.
        // PostgreSQL sequences are independent and may legitimately produce the same numeric ID.
        $query = $captured[array_key_last($captured)];
        $branchBindingPosition = substr_count(explode('"t"."branch_id"', $query['sql'], 2)[0], '?');
        $this->assertSame($branchA->id, $query['bindings'][$branchBindingPosition]);
        $this->assertNotSame($branchB->id, $query['bindings'][$branchBindingPosition]);

        $this->assertCount(0, $balances->payableReceivableEntries($captain, $branchA, $currency));
        $this->assertStringContainsString('"t"."branch_id" =', $captured[array_key_last($captured)]['sql']);
        // Compare the binding belonging to the Branch predicate, not unrelated Captain/Currency IDs.
        // PostgreSQL sequences are independent and may legitimately produce the same numeric ID.
        $query = $captured[array_key_last($captured)];
        $branchBindingPosition = substr_count(explode('"t"."branch_id"', $query['sql'], 2)[0], '?');
        $this->assertSame($branchA->id, $query['bindings'][$branchBindingPosition]);
        $this->assertNotSame($branchB->id, $query['bindings'][$branchBindingPosition]);

        $this->actingAs($employee)->get(route('captains.financials.show', $captain))->assertOk()
            ->assertSee('20.0000')->assertDontSee('30.0000');
        $this->actingAs($admin)->get(route('captains.financials.show', $captain))->assertOk()
            ->assertSee('20.0000')->assertSee('30.0000');
        $adminRows = $balances->captainBalancesByBranch($captain, $currency, null);
        $this->assertEqualsCanonicalizing([$branchA->id, $branchB->id], array_map('intval', array_keys($adminRows)));
    }

    public function test_real_deposit_is_atomic_branch_scoped_exact_and_idempotent(): void
    {
        Storage::fake('captain_financial_evidence');
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branchA = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $branchB = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branchA->id]);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branchA);
        $accounts = app(FinancialAccountService::class);
        $deposit = $accounts->getOrCreate($accounts->ownerForCaptain($captain), $currency, FinancialAccountKind::CaptainDeposit);
        app(FinancialPostingService::class)->postEvent($currency, 'TEST', 'negative-opening', 'TEST', 1, now(), [
            ['account' => $deposit, 'direction' => FinancialEntryDirection::Decrease, 'amount' => '50'],
        ], $branchA);

        $data = ['request_key' => (string) str()->uuid(), 'amount' => '200.00',
            'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP'), 'reference' => 'Cash count'];
        $service = app(CaptainDepositReceiptService::class);
        $receipt = $service->record($actor, $captain, $branchA, $currency, $data, $this->image());
        $this->assertSame($receipt->id, $service->record($actor, $captain, $branchA, $currency, $data, $this->image())->id);
        $this->assertDatabaseCount('captain_deposit_receipts', 1);
        $this->assertSame(1, FinancialTransaction::where('event_type', 'CAPTAIN_DEPOSIT_RECEIVED')->count());
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('150.0000', $balances->captainBranchBalance($captain, $branchA, $currency, FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $balances->captainBranchBalance($captain, $branchB, $currency, FinancialAccountKind::CaptainDeposit));
        $this->assertThrows(fn () => $service->record($actor, $captain, $branchA, $currency,
            array_replace($data, ['amount' => '201.00']), $this->image()), FinancialIdempotencyConflictException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('captain_deposit_receipts')->where('id', $receipt->id)->update(['amount' => '250'])), QueryException::class);
        $this->actingAs($actor)->get(route('captains.financials.deposits.evidence', [$captain, $receipt]))->assertOk();
        $actor->branches()->detach($branchA);
        $actor->branches()->attach($branchB);
        $this->actingAs($actor)->get(route('captains.financials.deposits.evidence', [$captain, $receipt]))->assertForbidden();
    }

    public function test_old_branch_financial_history_remains_visible_after_transfer_without_leaking_other_branch(): void
    {
        Storage::fake('captain_financial_evidence');
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $old = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $new = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $old->id]);
        $oldEmployee = User::factory()->create();
        $newEmployee = User::factory()->create();
        foreach ([$oldEmployee, $newEmployee] as $user) {
            $user->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        }
        $oldEmployee->branches()->attach($old);
        $newEmployee->branches()->attach($new);
        app(CaptainDepositReceiptService::class)->record($oldEmployee, $captain, $old, $currency,
            ['request_key' => (string) str()->uuid(), 'amount' => '20', 'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP')], $this->image());
        $captain->forceFill(['home_branch_id' => $new->id])->save();

        $this->actingAs($oldEmployee)->get(route('captain-financials.index'))->assertOk()->assertSee($captain->user->name);
        $this->get(route('captains.financials.show', $captain))->assertOk()->assertSee($old->name)->assertSee('20.0000');
        $this->actingAs($newEmployee)->get(route('captains.financials.show', $captain))->assertOk()
            ->assertDontSee($old->name)->assertDontSee('20.0000');
        $this->post(route('captains.financials.deposits.store', $captain), [
            'branch_id' => $old->id, 'currency_id' => $currency->id,
            'request_key' => (string) str()->uuid(), 'amount' => '10',
            'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP'), 'evidence' => $this->image(),
        ])->assertForbidden();
    }

    public function test_postgresql_rejects_forged_deposit_event_and_nonfinite_source_amount(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $owner = app(FinancialAccountService::class)->ownerForCaptain($captain);
        $account = app(FinancialAccountService::class)->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit);
        $this->assertThrows(fn () => DB::transaction(fn () => app(FinancialPostingService::class)->postEvent(
            $currency, 'ARBITRARY', '123', 'CAPTAIN_DEPOSIT_RECEIVED', 1, now(),
            [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => '1']], $branch,
        )), QueryException::class);
        foreach (['NaN', 'Infinity', '-Infinity', '0', '-1', '1.001'] as $invalid) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('captain_deposit_receipts')->insert([
                'captain_id' => $captain->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                'received_by' => User::factory()->create()->id, 'request_key' => (string) str()->uuid(),
                'amount' => $invalid, 'occurred_at' => now(), 'evidence_path' => 'private/test',
                'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png', 'evidence_size' => 100,
                'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
            ])), QueryException::class, $invalid);
        }
    }

    public function test_postgresql_deposit_source_contract_checks_captain_branch_currency_kind_direction_and_amount(): void
    {
        $usd = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $usd->id]);
        $otherBranch = Branch::factory()->create(['default_currency_id' => $usd->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $otherCaptain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $actor = User::factory()->create();
        $accounts = app(FinancialAccountService::class);
        $owner = $accounts->ownerForCaptain($captain);
        $deposit = $accounts->getOrCreate($owner, $usd, FinancialAccountKind::CaptainDeposit);
        $custody = $accounts->getOrCreate($owner, $usd, FinancialAccountKind::CaptainCashCustody);
        $otherCurrency = $accounts->getOrCreate($owner, $eur, FinancialAccountKind::CaptainDeposit);
        $otherOwner = $accounts->ownerForCaptain($otherCaptain);
        $otherCaptainDeposit = $accounts->getOrCreate($otherOwner, $usd, FinancialAccountKind::CaptainDeposit);
        $post = function (int $accountId, int $branchId, string $direction, string $amount) use ($usd, $captain, $branch, $actor): void {
            DB::transaction(function () use ($usd, $captain, $branch, $actor, $accountId, $branchId, $direction, $amount): void {
                $occurredAt = now()->startOfSecond();
                $receiptId = DB::table('captain_deposit_receipts')->insertGetId([
                    'captain_id' => $captain->id, 'branch_id' => $branch->id,
                    'currency_id' => $usd->id, 'received_by' => $actor->id,
                    'request_key' => (string) str()->uuid(), 'amount' => '10',
                    'occurred_at' => $occurredAt, 'evidence_path' => 'private/proof.png',
                    'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png',
                    'evidence_size' => 100, 'evidence_sha256' => str_repeat('a', 64), 'created_at' => now(),
                ]);
                $transactionId = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT',
                    'currency_id' => $usd->id, 'branch_id' => $branchId,
                    'source_type' => 'CAPTAIN_DEPOSIT_RECEIPT', 'source_id' => (string) $receiptId,
                    'event_type' => 'CAPTAIN_DEPOSIT_RECEIVED', 'source_version' => 1,
                    'payload_hash' => str_repeat('b', 64), 'occurred_at' => $occurredAt, 'created_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $transactionId, 'financial_account_id' => $accountId,
                    'currency_id' => $usd->id, 'direction' => $direction, 'amount' => $amount, 'created_at' => now(),
                ]);
                DB::table('financial_transactions')->where('id', $transactionId)->update(['status' => 'POSTED', 'posted_at' => now()]);
                DB::statement('SET CONSTRAINTS captain_deposit_receipt_complete, captain_financial_event_complete, captain_financial_entry_complete IMMEDIATE');
            });
        };
        foreach ([
            [$otherCaptainDeposit->id, $branch->id, 'INCREASE', '10'],
            [$deposit->id, $otherBranch->id, 'INCREASE', '10'],
            [$otherCurrency->id, $branch->id, 'INCREASE', '10'],
            [$custody->id, $branch->id, 'INCREASE', '10'],
            [$deposit->id, $branch->id, 'DECREASE', '10'],
            [$deposit->id, $branch->id, 'INCREASE', '9'],
        ] as [$accountId, $branchId, $direction, $amount]) {
            $this->assertThrows(fn () => $post($accountId, $branchId, $direction, $amount), QueryException::class);
        }
        $post($deposit->id, $branch->id, 'INCREASE', '10');
        $this->assertDatabaseCount('captain_deposit_receipts', 1);
        $this->assertSame('10.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance(
            $captain, $branch, $usd, FinancialAccountKind::CaptainDeposit));
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG));
    }
}
