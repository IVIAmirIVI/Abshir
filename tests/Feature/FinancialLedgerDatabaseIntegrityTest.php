<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\FinancialAccountService;
use App\Services\FinancialPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class FinancialLedgerDatabaseIntegrityTest extends TestCase
{
    public function test_used_currency_precision_is_frozen_and_exact_service_reversal_survives(): void
    {
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $actor = User::factory()->create();
            $role = Role::create(['code' => 'SUPER_ADMIN', 'name' => 'Super Admin']);
            $actor->roles()->attach($role);
            $service = app(CurrencyService::class);
            $currency = $fixture['currency'];
            $data = ['code' => $currency->code, 'name' => $currency->name, 'decimal_places' => 2];
            $this->assertSame(2, (int) $service->save($actor, $currency, $data)->decimal_places);
            $service->save($actor, $currency, [...$data, 'decimal_places' => 4]);
            $original = app(FinancialPostingService::class)->postEvent($currency, 'AUDIT', 'precision', 'EVENT', 1, now(), [
                ['account' => $fixture['deposit'], 'direction' => 'INCREASE', 'amount' => '1.2345'],
            ]);
            $this->assertThrows(fn () => $service->save($actor, $currency, $data), ValidationException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('currencies')->where('id', $currency->id)->update(['decimal_places' => 2])), QueryException::class);
            $this->assertSame('Renamed', $service->save($actor, $currency, [...$data, 'decimal_places' => 4, 'name' => 'Renamed'])->name);
            $reversal = app(FinancialPostingService::class)->reverse($original, 'AUDIT', 'precision-reversal', 'REVERSE', 1, now(), 'Exact reversal');
            $this->assertSame('1.2345', $reversal->entries->sole()->amount);
            $this->assertSame('DECREASE', $reversal->entries->sole()->direction->value);
            $this->assertSame(4, (int) $currency->fresh()->decimal_places);
        });
    }

    public function test_non_finite_and_non_positive_sql_amounts_are_rejected_without_rounding(): void
    {
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            foreach (['NaN', 'Infinity', '-Infinity', '0', '-1', '0.10001'] as $amount) {
                $this->assertThrows(fn () => DB::transaction(function () use ($fixture, $amount): void {
                    $id = $this->draft($fixture['currency'], 'EVENT');
                    $this->entry($id, $fixture['deposit'], 'INCREASE', $amount);
                    $this->finalize($id);
                }), QueryException::class);
            }
            DB::transaction(function () use ($fixture): void {
                $id = $this->draft($fixture['currency'], 'EVENT');
                $this->entry($id, $fixture['deposit'], 'INCREASE', '0.1000');
                $this->finalize($id);
            });
            $this->assertSame('0.1000', DB::table('financial_entries')->sole()->amount);
        });
    }

    public function test_finite_migration_aborts_on_existing_bad_history_without_rewriting_it(): void
    {
        $this->withIsolatedSchema(function (): void {
            Schema::create('financial_entries', function ($table): void {
                $table->id();
                $table->bigInteger('financial_transaction_id');
            });
            DB::statement('ALTER TABLE financial_entries ADD amount numeric');
            foreach (['NaN', 'Infinity', '-Infinity'] as $amount) {
                DB::table('financial_entries')->insert(['financial_transaction_id' => 42, 'amount' => $amount]);
            }
            $migration = require base_path('database/migrations/2026_09_23_020000_finalize_financial_ledger_integrity.php');
            try {
                DB::transaction(fn () => $migration->up());
                $this->fail('Bad history was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Non-finite financial history requires manual investigation', $exception->getMessage());
                $this->assertStringContainsString('transaction=42', $exception->getMessage());
            }
            $this->assertSame(['NaN', 'Infinity', '-Infinity'], DB::table('financial_entries')->orderBy('id')->pluck('amount')->all());
            $this->assertFalse($this->constraintExists('financial_entry_amount_finite'));
        }, migrate: false);
    }

    public function test_currency_precision_contract_and_unbounded_numeric_input_are_enforced_by_postgresql(): void
    {
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();

            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('currencies')->where('id', $fixture['currency']->id)->update(['decimal_places' => 5])), QueryException::class);

            $this->assertThrows(function () use ($fixture): void {
                DB::transaction(function () use ($fixture): void {
                    $transactionId = $this->draft($fixture['currency'], 'EVENT');
                    $this->entry($transactionId, $fixture['deposit'], 'INCREASE', '0.10001');
                });
            }, QueryException::class);

            $transaction = app(FinancialPostingService::class)->postEvent(
                $fixture['currency'], 'DB_AUDIT', str()->uuid()->toString(), 'EXACT_PRECISION', 1, now(),
                [['account' => $fixture['deposit'], 'direction' => 'INCREASE', 'amount' => '0.1000']],
            );
            $this->assertSame('0.1000', $transaction->entries->sole()->amount);
        });
    }

    public function test_migration_aborts_before_constraint_when_existing_currency_precision_is_invalid(): void
    {
        $this->withIsolatedSchema(function (): void {
            Schema::create('currencies', function ($table): void {
                $table->id();
                $table->string('code');
                $table->unsignedTinyInteger('decimal_places');
            });
            DB::table('currencies')->insert(['code' => 'BAD', 'decimal_places' => 5]);

            $migration = require base_path('database/migrations/2026_09_23_010000_harden_financial_ledger_integrity.php');
            try {
                $migration->up();
                $this->fail('The integrity migration accepted an invalid existing Currency.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Correct these Currency rows first: BAD=5', $exception->getMessage());
                $this->assertFalse($this->constraintExists('currencies_decimal_places_valid'));
            }
        }, migrate: false);
    }

    public function test_posted_history_rejects_late_entries_and_owner_or_account_identity_changes(): void
    {
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $posting = app(FinancialPostingService::class);
            $event = $posting->postEvent(
                $fixture['currency'], 'DB_AUDIT', str()->uuid()->toString(), 'EVENT', 1, now(),
                [['account' => $fixture['deposit'], 'direction' => 'INCREASE', 'amount' => '10.0000']],
            );
            $transfer = $posting->postTransfer(
                $fixture['currency'], 'DB_AUDIT', str()->uuid()->toString(), 'TRANSFER', 1, now(),
                [
                    ['account' => $fixture['deposit'], 'direction' => 'DECREASE', 'amount' => '2.0000'],
                    ['account' => $fixture['receivable'], 'direction' => 'INCREASE', 'amount' => '2.0000'],
                ],
            );

            foreach ([$event, $transfer] as $transaction) {
                $this->assertThrows(fn () => DB::transaction(fn () => $this->entry($transaction->id, $fixture['custody'], 'INCREASE', '1.0000')), QueryException::class);
            }

            $otherCaptain = Captain::factory()->create();
            $otherOwner = app(FinancialAccountService::class)->ownerForCaptain($otherCaptain);
            $otherCurrency = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2]);

            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_owners')->where('id', $fixture['owner']->id)->update(['captain_id' => $otherCaptain->id])), QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_owners')->where('id', $fixture['owner']->id)->update(['owner_type' => 'COMPANY', 'captain_id' => null])), QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_owners')->where('id', $fixture['owner']->id)->delete()), QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_accounts')->where('id', $fixture['deposit']->id)->update(['financial_owner_id' => $otherOwner->id])), QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_accounts')->where('id', $fixture['deposit']->id)->update(['account_kind' => FinancialAccountKind::CaptainCashCustody->value])), QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_accounts')->where('id', $fixture['deposit']->id)->update(['currency_id' => $otherCurrency->id])), QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_accounts')->where('id', $fixture['deposit']->id)->delete()), QueryException::class);
            $this->assertThrows(fn () => $fixture['owner']->forceFill(['captain_id' => $otherCaptain->id])->save(), LogicException::class);
            $this->assertThrows(fn () => $fixture['deposit']->forceFill(['account_kind' => FinancialAccountKind::CaptainCashCustody])->save(), LogicException::class);
        });
    }

    public function test_only_complete_transactions_can_finalize_and_drafts_cannot_commit(): void
    {
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();

            DB::transaction(function () use ($fixture): void {
                $transactionId = $this->draft($fixture['currency'], 'TRANSFER');
                $this->entry($transactionId, $fixture['deposit'], 'DECREASE', '4.0000');
                $this->entry($transactionId, $fixture['receivable'], 'INCREASE', '4.0000');
                $this->finalize($transactionId);
            });
            $this->assertDatabaseHas('financial_transactions', ['status' => 'POSTED']);

            $otherOwner = app(FinancialAccountService::class)->ownerForCaptain(Captain::factory()->create());
            $fourthAccount = app(FinancialAccountService::class)->getOrCreate($otherOwner, $fixture['currency'], FinancialAccountKind::CaptainDeposit);
            $largeTransferId = DB::transaction(function () use ($fixture, $fourthAccount): int {
                $transactionId = $this->draft($fixture['currency'], 'TRANSFER');
                $this->entry($transactionId, $fixture['deposit'], 'DECREASE', '60000000000000.0000');
                $this->entry($transactionId, $fixture['receivable'], 'DECREASE', '60000000000000.0000');
                $this->entry($transactionId, $fixture['custody'], 'INCREASE', '60000000000000.0000');
                $this->entry($transactionId, $fourthAccount, 'INCREASE', '60000000000000.0000');
                $this->finalize($transactionId);

                return $transactionId;
            });
            $this->assertSame('POSTED', DB::table('financial_transactions')->where('id', $largeTransferId)->value('status'));

            $this->assertThrows(function () use ($fixture): void {
                DB::transaction(function () use ($fixture): void {
                    $transactionId = $this->draft($fixture['currency'], 'TRANSFER');
                    $this->entry($transactionId, $fixture['deposit'], 'DECREASE', '4.0000');
                    $this->entry($transactionId, $fixture['receivable'], 'INCREASE', '3.9999');
                    $this->finalize($transactionId);
                });
            }, QueryException::class);

            try {
                DB::beginTransaction();
                $this->draft($fixture['currency'], 'EVENT');
                DB::commit();
                $this->fail('A DRAFT financial transaction was committed.');
            } catch (\Throwable $exception) {
                $this->assertStringContainsString('cannot be committed', $exception->getMessage());
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }

            $this->assertSame(0, DB::table('financial_transactions')->where('status', 'DRAFT')->count());
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('financial_transactions')->insert([
                ...$this->transactionAttributes($fixture['currency'], 'EVENT'),
                'status' => 'POSTED',
                'posted_at' => now(),
            ])), QueryException::class);
        });
    }

    public function test_postgresql_enforces_exact_reversal_semantics_at_finalization(): void
    {
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $original = $this->original($fixture, true);
            $reversalId = DB::transaction(function () use ($fixture, $original): int {
                $transactionId = $this->draft($fixture['currency'], 'REVERSAL', $original->id, 'Direct exact reversal');
                foreach ($original->entries as $entry) {
                    $this->entry($transactionId, $entry->account, $entry->direction->opposite()->value, $entry->amount);
                }
                $this->finalize($transactionId);

                return $transactionId;
            });
            $this->assertDatabaseHas('financial_transactions', ['id' => $reversalId, 'status' => 'POSTED']);
            $this->assertThrows(fn () => DB::transaction(fn () => $this->entry($reversalId, $fixture['custody'], 'INCREASE', '1.0000')), QueryException::class);

            $this->assertInvalidReversal($fixture, 'missing_reason');
            $this->assertInvalidReversal($fixture, 'partial');
            $this->assertInvalidReversal($fixture, 'altered_amount');
            $this->assertInvalidReversal($fixture, 'wrong_account');
            $this->assertInvalidReversal($fixture, 'extra_entry');

            $this->assertThrows(function () use ($fixture, $reversalId): void {
                DB::transaction(function () use ($fixture, $reversalId): void {
                    $reversal = FinancialTransaction::with('entries.account')->findOrFail($reversalId);
                    $transactionId = $this->draft($fixture['currency'], 'REVERSAL', $reversal->id, 'Reversal chain');
                    foreach ($reversal->entries as $entry) {
                        $this->entry($transactionId, $entry->account, $entry->direction->opposite()->value, $entry->amount);
                    }
                    $this->finalize($transactionId);
                });
            }, QueryException::class);
        });
    }

    private function assertInvalidReversal(array $fixture, string $mode): void
    {
        $this->assertThrows(function () use ($fixture, $mode): void {
            DB::transaction(function () use ($fixture, $mode): void {
                $original = $this->original($fixture, true);
                $reason = $mode === 'missing_reason' ? null : 'Invalid direct reversal';
                $transactionId = $this->draft($fixture['currency'], 'REVERSAL', $original->id, $reason);
                $entries = $original->entries->values();

                if ($mode === 'partial') {
                    $entries = $entries->take(1);
                }
                foreach ($entries as $index => $entry) {
                    $account = $mode === 'wrong_account' && $index === 0 ? $fixture['custody'] : $entry->account;
                    $amount = $mode === 'altered_amount' && $index === 0 ? '99.0000' : $entry->amount;
                    $this->entry($transactionId, $account, $entry->direction->opposite()->value, $amount);
                }
                if ($mode === 'extra_entry') {
                    $this->entry($transactionId, $fixture['custody'], 'INCREASE', '1.0000');
                }
                $this->finalize($transactionId);
            });
        }, QueryException::class);
    }

    private function original(array $fixture, bool $multiple): FinancialTransaction
    {
        $movements = [['account' => $fixture['deposit'], 'direction' => 'INCREASE', 'amount' => '7.2500']];
        if ($multiple) {
            $movements[] = ['account' => $fixture['receivable'], 'direction' => 'DECREASE', 'amount' => '3.5000'];
        }

        return app(FinancialPostingService::class)->postEvent(
            $fixture['currency'], 'DB_AUDIT', str()->uuid()->toString(), 'ORIGINAL', 1, now(), $movements,
        );
    }

    private function fixture(): array
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 4]);
        $captain = Captain::factory()->create();
        $accounts = app(FinancialAccountService::class);
        $owner = $accounts->ownerForCaptain($captain);

        return [
            'currency' => $currency,
            'captain' => $captain,
            'owner' => $owner,
            'deposit' => $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit),
            'receivable' => $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCompanyReceivable),
            'custody' => $accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCashCustody),
        ];
    }

    private function draft(Currency $currency, string $kind, ?int $reversalOf = null, ?string $reason = null): int
    {
        return DB::table('financial_transactions')->insertGetId([
            ...$this->transactionAttributes($currency, $kind),
            'status' => 'DRAFT',
            'reversal_of_transaction_id' => $reversalOf,
            'reason' => $reason,
        ]);
    }

    private function transactionAttributes(Currency $currency, string $kind): array
    {
        return [
            'transaction_kind' => $kind,
            'currency_id' => $currency->id,
            'source_type' => 'DIRECT_SQL_AUDIT',
            'source_id' => str()->uuid()->toString(),
            'event_type' => $kind,
            'source_version' => 1,
            'payload_hash' => hash('sha256', random_bytes(32)),
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }

    private function entry(int $transactionId, FinancialAccount $account, string $direction, string $amount): void
    {
        DB::table('financial_entries')->insert([
            'financial_transaction_id' => $transactionId,
            'financial_account_id' => $account->id,
            'currency_id' => $account->currency_id,
            'direction' => $direction,
            'amount' => $amount,
            'created_at' => now(),
        ]);
    }

    private function finalize(int $transactionId): void
    {
        DB::table('financial_transactions')->where('id', $transactionId)->update([
            'status' => 'POSTED',
            'posted_at' => now(),
        ]);
    }

    private function constraintExists(string $name): bool
    {
        return (bool) DB::selectOne(
            'SELECT EXISTS (SELECT 1 FROM pg_constraint constraint_record JOIN pg_class relation ON relation.oid = constraint_record.conrelid JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace WHERE namespace.nspname = current_schema() AND constraint_record.conname = ?) AS present',
            [$name],
        )->present;
    }

    private function withIsolatedSchema(callable $callback, bool $migrate = true): void
    {
        $connectionName = (string) config('database.default');
        $configurationKey = "database.connections.{$connectionName}.search_path";
        $originalSearchPath = (string) config($configurationKey);
        $schema = 'financial_integrity_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');

        try {
            config([$configurationKey => $schema]);
            DB::purge($connectionName);
            DB::reconnect($connectionName);
            if ($migrate) {
                Artisan::call('migrate', ['--force' => true]);
            }
            $callback();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge($connectionName);
            config([$configurationKey => $originalSearchPath]);
            DB::reconnect($connectionName);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }
}
