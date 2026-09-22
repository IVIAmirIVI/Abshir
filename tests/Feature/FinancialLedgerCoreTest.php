<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialOwnerType;
use App\Enums\FinancialTransactionKind;
use App\Enums\FinancialTransactionStatus;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialEntry;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class FinancialLedgerCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_owner_shapes_and_unique_identity_are_enforced(): void
    {
        $captain = Captain::factory()->create();
        $service = app(FinancialAccountService::class);
        $owner = $service->ownerForCaptain($captain);

        $this->assertSame(FinancialOwnerType::Captain, $owner->owner_type);
        $this->assertSame($captain->id, $owner->captain_id);
        $this->assertSame($owner->id, $service->ownerForCaptain($captain)->id);
        $this->assertSame($service->companyOwner()->id, $service->companyOwner()->id);

        $this->expectException(QueryException::class);
        DB::table('financial_owners')->insert([
            'owner_type' => FinancialOwnerType::Company->value,
            'captain_id' => $captain->id,
            'created_at' => now(),
        ]);
    }

    public function test_database_prevents_duplicate_company_and_duplicate_captain_owners(): void
    {
        $captain = Captain::factory()->create();
        $service = app(FinancialAccountService::class);
        $service->ownerForCaptain($captain);
        $service->companyOwner();

        $this->assertDatabaseCount('financial_owners', 2);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_owners')->insert([
            'owner_type' => FinancialOwnerType::Captain->value,
            'captain_id' => $captain->id,
            'created_at' => now(),
        ])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_owners')->insert([
            'owner_type' => FinancialOwnerType::Company->value,
            'created_at' => now(),
        ])), QueryException::class);
    }

    public function test_account_identity_and_owner_kind_are_enforced(): void
    {
        $currency = $this->currency('USD', 2);
        $service = app(FinancialAccountService::class);
        $captainOwner = $service->ownerForCaptain(Captain::factory()->create());
        $account = $service->getOrCreate($captainOwner, $currency, FinancialAccountKind::CaptainDeposit);

        $this->assertSame($account->id, $service->getOrCreate($captainOwner, $currency, FinancialAccountKind::CaptainDeposit)->id);
        $this->assertDatabaseCount('financial_accounts', 1);

        $branchOwner = $service->ownerForBranch(Branch::factory()->create());
        $this->assertThrows(
            fn () => $service->getOrCreate($branchOwner, $currency, FinancialAccountKind::CaptainDeposit),
            FinancialLedgerException::class,
        );
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_accounts')->insert([
            'financial_owner_id' => $branchOwner->id,
            'currency_id' => $currency->id,
            'account_kind' => FinancialAccountKind::CaptainDeposit->value,
            'created_at' => now(),
        ])), QueryException::class);
    }

    public function test_event_posting_balances_and_negative_balances_are_exact_and_separate(): void
    {
        [$currency, $deposit, $receivable, $custody] = $this->captainAccounts('USD', 2);
        $posting = app(FinancialPostingService::class);
        $occurredAt = now()->startOfSecond();
        $transaction = $posting->postEvent(
            $currency, 'TEST', 'event-1', 'OPENING', 1, $occurredAt,
            [
                ['account' => $deposit, 'direction' => FinancialEntryDirection::Decrease, 'amount' => '70.25'],
                ['account' => $receivable, 'direction' => FinancialEntryDirection::Increase, 'amount' => '300.10'],
                ['account' => $custody, 'direction' => FinancialEntryDirection::Increase, 'amount' => '1200.00'],
            ],
        );

        $this->assertSame(FinancialTransactionKind::Event, $transaction->transaction_kind);
        $this->assertSame(FinancialTransactionStatus::Posted, $transaction->status);
        $this->assertNotNull($transaction->posted_at);
        $this->assertCount(3, $transaction->entries);
        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('-70.2500', $balances->balance($deposit));
        $this->assertSame('300.1000', $balances->balance($receivable));
        $this->assertSame('1200.0000', $balances->balance($custody));
        $this->assertFalse(Schema::hasColumn('financial_accounts', 'balance'));

        $captain = $deposit->owner->captain;
        $this->assertSame([
            FinancialAccountKind::CaptainDeposit->value => '-70.2500',
            FinancialAccountKind::CaptainCompanyReceivable->value => '300.1000',
            FinancialAccountKind::CaptainCashCustody->value => '1200.0000',
        ], $balances->captainBalances($captain, $currency));
        $this->assertSame($transaction->id, $balances->recentMovements($deposit)->sole()->financial_transaction_id);
    }

    public function test_transfer_requires_one_currency_and_equal_opposite_totals(): void
    {
        [$currency, $deposit, $receivable] = $this->captainAccounts('USD', 2);
        $posting = app(FinancialPostingService::class);
        $transaction = $posting->postTransfer(
            $currency, 'TEST', 'transfer-1', 'EXPLICIT_TRANSFER', 1, now()->startOfSecond(),
            [
                ['account' => $deposit, 'direction' => 'DECREASE', 'amount' => '12.34'],
                ['account' => $receivable, 'direction' => 'INCREASE', 'amount' => '12.34'],
            ],
        );

        $this->assertSame(FinancialTransactionKind::Transfer, $transaction->transaction_kind);
        $this->assertThrows(fn () => $posting->postTransfer(
            $currency, 'TEST', 'transfer-2', 'EXPLICIT_TRANSFER', 1, now()->startOfSecond(),
            [
                ['account' => $deposit, 'direction' => 'DECREASE', 'amount' => '12.34'],
                ['account' => $receivable, 'direction' => 'INCREASE', 'amount' => '12.33'],
            ],
        ), FinancialLedgerException::class);
        $retry = $posting->postTransfer(
            $currency, 'TEST', 'transfer-2', 'EXPLICIT_TRANSFER', 1, now()->startOfSecond(),
            [
                ['account' => $deposit, 'direction' => 'DECREASE', 'amount' => '12.34'],
                ['account' => $receivable, 'direction' => 'INCREASE', 'amount' => '12.34'],
            ],
        );
        $this->assertSame(FinancialTransactionStatus::Posted, $retry->status);

        $other = $this->currency('EUR', 2);
        $otherAccount = app(FinancialAccountService::class)->getOrCreate($deposit->owner, $other, FinancialAccountKind::CaptainCashCustody);
        $this->assertThrows(fn () => $posting->postTransfer(
            $currency, 'TEST', 'transfer-3', 'EXPLICIT_TRANSFER', 1, now()->startOfSecond(),
            [
                ['account' => $deposit, 'direction' => 'DECREASE', 'amount' => '1.00'],
                ['account' => $otherAccount, 'direction' => 'INCREASE', 'amount' => '1.00'],
            ],
        ), FinancialLedgerException::class);
        $afterFailedHeader = $posting->postTransfer(
            $currency, 'TEST', 'transfer-3', 'EXPLICIT_TRANSFER', 1, now()->startOfSecond(),
            [
                ['account' => $deposit, 'direction' => 'DECREASE', 'amount' => '1.00'],
                ['account' => $receivable, 'direction' => 'INCREASE', 'amount' => '1.00'],
            ],
        );
        $this->assertSame(FinancialTransactionStatus::Posted, $afterFailedHeader->status);
    }

    public function test_money_precision_and_float_input_are_rejected(): void
    {
        [$currency, $deposit] = $this->captainAccounts('USD', 2);
        $posting = app(FinancialPostingService::class);

        $this->assertThrows(fn () => $posting->postEvent($currency, 'TEST', 'precision-1', 'TEST', 1, now(), [
            ['account' => $deposit, 'direction' => 'INCREASE', 'amount' => '1.001'],
        ]), FinancialLedgerException::class);
        $this->assertThrows(fn () => $posting->postEvent($currency, 'TEST', 'precision-2', 'TEST', 1, now(), [
            ['account' => $deposit, 'direction' => 'INCREASE', 'amount' => 1.25],
        ]), FinancialLedgerException::class);
        $this->assertThrows(function () use ($currency, $deposit): void {
            DB::transaction(function () use ($currency, $deposit): void {
                $transactionId = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'currency_id' => $currency->id,
                    'source_type' => 'DIRECT_SQL', 'source_id' => 'bad-precision', 'event_type' => 'TEST',
                    'source_version' => 1, 'payload_hash' => str_repeat('b', 64), 'occurred_at' => now(), 'created_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $transactionId, 'financial_account_id' => $deposit->id,
                    'currency_id' => $currency->id, 'direction' => 'INCREASE', 'amount' => '1.0010', 'created_at' => now(),
                ]);
            });
        }, QueryException::class);

        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('currencies')->where('id', $currency->id)->update(['decimal_places' => 5])), QueryException::class);
    }

    public function test_source_idempotency_returns_identical_transaction_and_rejects_changed_payload(): void
    {
        [$currency, $deposit, $receivable] = $this->captainAccounts('USD', 2);
        $posting = app(FinancialPostingService::class);
        $occurredAt = now()->startOfSecond();
        $arguments = [$currency, 'TEST', 'same-source', 'DEPOSIT', 1, $occurredAt, [
            ['account' => $deposit, 'direction' => 'INCREASE', 'amount' => '10.00'],
            ['account' => $receivable, 'direction' => 'INCREASE', 'amount' => '3.50'],
        ]];

        $first = $posting->postEvent(...$arguments);
        $second = $posting->postEvent(
            $currency, 'TEST', 'same-source', 'DEPOSIT', 1, $occurredAt,
            [
                ['account' => $receivable, 'direction' => 'INCREASE', 'amount' => '3.5'],
                ['account' => $deposit, 'direction' => 'INCREASE', 'amount' => '10'],
            ],
        );
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('financial_entries', 2);

        $this->assertThrows(fn () => $posting->postEvent(
            $currency, 'TEST', 'same-source', 'DEPOSIT', 1, $occurredAt,
            [['account' => $deposit, 'direction' => 'INCREASE', 'amount' => '11.00']],
        ), FinancialIdempotencyConflictException::class);
    }

    public function test_reversal_is_exact_immutable_and_can_happen_only_once(): void
    {
        [$currency, $deposit, $receivable] = $this->captainAccounts('USD', 2);
        $posting = app(FinancialPostingService::class);
        $original = $posting->postEvent(
            $currency, 'TEST', 'original', 'POST', 1, now()->startOfSecond(),
            [
                ['account' => $deposit, 'direction' => 'DECREASE', 'amount' => '7.25'],
                ['account' => $receivable, 'direction' => 'INCREASE', 'amount' => '19.50'],
            ],
        );
        $originalPayload = $original->fresh()->toArray();

        $this->assertThrows(fn () => $posting->reverse($original, 'TEST', 'reversal-empty', 'REVERSE', 1, now(), ''), FinancialLedgerException::class);
        $reversal = $posting->reverse($original, 'TEST', 'reversal-1', 'REVERSE', 1, now()->startOfSecond(), 'Approved correction');

        $this->assertSame(FinancialTransactionKind::Reversal, $reversal->transaction_kind);
        $this->assertSame($original->id, $reversal->reversal_of_transaction_id);
        $this->assertSame([
            [$deposit->id, 'INCREASE', '7.2500'],
            [$receivable->id, 'DECREASE', '19.5000'],
        ], $reversal->entries->sortBy('financial_account_id')->map(fn (FinancialEntry $entry): array => [$entry->financial_account_id, $entry->direction->value, $entry->amount])->values()->all());
        $this->assertSame($originalPayload, $original->fresh()->toArray());
        $this->assertThrows(fn () => $posting->reverse($original, 'TEST', 'reversal-2', 'REVERSE', 1, now(), 'Again'), FinancialLedgerException::class);
        $this->assertThrows(fn () => $posting->reverse($reversal, 'TEST', 'reversal-3', 'REVERSE', 1, now(), 'Chain'), FinancialLedgerException::class);
    }

    public function test_application_and_postgresql_prevent_history_mutation(): void
    {
        [$currency, $deposit] = $this->captainAccounts('USD', 2);
        $transaction = app(FinancialPostingService::class)->postEvent(
            $currency, 'TEST', 'immutable', 'POST', 1, now()->startOfSecond(),
            [['account' => $deposit, 'direction' => 'INCREASE', 'amount' => '2.00']],
        );
        $entry = $transaction->entries->sole();

        $this->assertThrows(fn () => $transaction->forceFill(['notes' => 'changed'])->save(), LogicException::class);
        $this->assertThrows(fn () => $entry->delete(), LogicException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_transactions')->where('id', $transaction->id)->update(['notes' => 'changed'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_transactions')->where('id', $transaction->id)->delete()), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_entries')->where('id', $entry->id)->update(['amount' => '3.0000'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_entries')->where('id', $entry->id)->delete()), QueryException::class);
        $this->assertDatabaseHas('financial_transactions', ['id' => $transaction->id, 'notes' => null]);
        $this->assertDatabaseHas('financial_entries', ['id' => $entry->id]);
    }

    public function test_database_makes_cross_currency_entries_impossible_and_balances_are_independent(): void
    {
        [$usd, $usdDeposit] = $this->captainAccounts('USD', 2);
        $eur = $this->currency('EUR', 2);
        $eurDeposit = app(FinancialAccountService::class)->getOrCreate($usdDeposit->owner, $eur, FinancialAccountKind::CaptainDeposit);
        $posting = app(FinancialPostingService::class);
        $posting->postEvent($usd, 'TEST', 'usd-event', 'POST', 1, now()->startOfSecond(), [['account' => $usdDeposit, 'direction' => 'INCREASE', 'amount' => '4.25']]);
        $posting->postEvent($eur, 'TEST', 'eur-event', 'POST', 1, now()->startOfSecond(), [['account' => $eurDeposit, 'direction' => 'INCREASE', 'amount' => '8.50']]);

        $balances = app(FinancialBalanceQueryService::class);
        $this->assertSame('4.2500', $balances->balance($usdDeposit));
        $this->assertSame('8.5000', $balances->balance($eurDeposit));

        $this->assertThrows(function () use ($usd, $eurDeposit): void {
            DB::transaction(function () use ($usd, $eurDeposit): void {
                $transactionId = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'currency_id' => $usd->id,
                    'source_type' => 'DIRECT_SQL', 'source_id' => 'cross-currency', 'event_type' => 'TEST',
                    'source_version' => 1, 'payload_hash' => str_repeat('a', 64), 'occurred_at' => now(), 'created_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $transactionId, 'financial_account_id' => $eurDeposit->id,
                    'currency_id' => $usd->id, 'direction' => 'INCREASE', 'amount' => '1.0000', 'created_at' => now(),
                ]);
            });
        }, QueryException::class);
    }

    private function captainAccounts(string $code, int $decimalPlaces): array
    {
        $currency = $this->currency($code, $decimalPlaces);
        $owner = app(FinancialAccountService::class)->ownerForCaptain(Captain::factory()->create());

        return [
            $currency,
            app(FinancialAccountService::class)->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit),
            app(FinancialAccountService::class)->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCompanyReceivable),
            app(FinancialAccountService::class)->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCashCustody),
        ];
    }

    private function currency(string $code, int $decimalPlaces): Currency
    {
        return Currency::create(['name' => $code, 'code' => $code, 'decimal_places' => $decimalPlaces]);
    }
}
