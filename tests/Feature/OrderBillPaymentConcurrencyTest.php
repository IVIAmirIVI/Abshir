<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\OrderCaptain;
use App\Models\Role;
use App\Models\User;
use App\Services\BillPaymentFeeRuleService;
use App\Services\FinancialAccountService;
use App\Services\FinancialPostingService;
use App\Services\OrderBillPaymentService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OrderBillPaymentConcurrencyTest extends TestCase
{
    public function test_pending_tier_migration_preserves_existing_fixed_financial_history_and_trigger_dependencies(): void
    {
        $this->assertSame('abshir_testing', DB::selectOne('SELECT current_database() AS name')->name);
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'bill_legacy_migration_'.str()->lower(str()->random(12));
        $tierPath = database_path('migrations/2026_10_08_010000_add_tiered_bill_payment_fees.php');
        $beforeFiles = Storage::disk(OrderBillPaymentService::DISK)->allFiles();
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            $priorPaths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => $path !== $tierPath));
            Artisan::call('migrate', ['--path' => $priorPaths, '--realpath' => true, '--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->fixture();
            $service = app(OrderBillPaymentService::class);
            $request = $service->request($c['employee'], $c['order'], $c['currency']->id);
            $pending = $service->request($c['employee'], $c['order'], $c['currency']->id);
            $cancelled = $service->request($c['employee'], $c['order'], $c['currency']->id);
            $service->cancel($c['employee'], $c['order'], $cancelled, 'Legacy cancellation');
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer,
                'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            $payment = $service->complete($c['employee'], $c['order'], $request, [
                'order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id,
                'bill_number' => 'LEGACY', 'bill_date' => today()->toDateString(),
                'principal_amount' => '1125.12', 'payment_reference' => 'SHAM-LEGACY',
            ], [UploadedFile::fake()->createWithContent('legacy.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))]);
            $tables = ['bill_payment_fee_rules', 'order_bill_payment_requests', 'order_bill_payments',
                'order_bill_payment_principal_handovers', 'order_bill_payment_evidence', 'financial_transactions', 'financial_entries', 'currencies'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }
            $triggerSql = "SELECT t.tgname, t.tgfoid, t.tgenabled, t.tgdeferrable, t.tginitdeferred FROM pg_trigger t
                JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname=current_schema() AND NOT t.tgisinternal
                AND (c.relname IN ('bill_payment_fee_rules','order_bill_payment_requests','order_bill_payments')
                     OR t.tgname='bill_payment_event_commit_guard')
                AND t.tgname <> 'bill_payment_fee_configuration_guard' ORDER BY t.tgname";
            $triggers = DB::select($triggerSql);
            Artisan::call('migrate', ['--path' => [$tierPath], '--realpath' => true, '--force' => true]);
            foreach ($tables as $table) {
                $after = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table) {
                    $row = (array) $row;
                    if ($table === 'bill_payment_fee_rules') {
                        $this->assertSame('FIXED', $row['fee_mode']);
                        $this->assertNull($row['fee_tiers']);
                        unset($row['fee_mode'], $row['fee_tiers']);
                    }

                    return $row;
                })->all();
                $this->assertSame($before[$table], $after, "Historical {$table} data changed.");
            }
            $this->assertEquals($triggers, DB::select($triggerSql));
            foreach ($triggers as $trigger) {
                $this->assertSame('O', $trigger->tgenabled);
                if (in_array($trigger->tgname, ['completed_bill_payment_commit_guard', 'bill_payment_event_commit_guard'], true)) {
                    $this->assertTrue($trigger->tgdeferrable);
                    $this->assertTrue($trigger->tginitdeferred);
                }
            }
            foreach (['verify_bill_payment_request_fee_source()' => 'trigger', 'verify_completed_bill_payment_source()' => 'trigger',
                'validate_completed_bill_payment_at_commit(bigint)' => 'void', 'guard_bill_payment_fee_history()' => 'trigger',
                'calculated_bill_payment_fee(bigint,numeric)' => 'numeric'] as $signature => $result) {
                $this->assertSame($result, DB::selectOne('SELECT pg_get_function_result(to_regprocedure(?)) AS result', [$signature])->result);
            }
            $this->assertSame('2.0000', $payment->fresh()->service_fee);
            $this->assertSame('1125.1200', $payment->fresh()->principal_amount);
            DB::select('SELECT validate_completed_bill_payment_at_commit(?)', [$payment->id]);
            $this->assertSame('2.0000', $service->preview($c['employee'], $c['order']->fresh(), $pending, '1125.12')['fee']);
            $this->assertTrue(DB::table('migrations')->where('migration', '2026_10_08_010000_add_tiered_bill_payment_fees')->exists());
        } finally {
            Storage::disk(OrderBillPaymentService::DISK)->delete(array_values(array_diff(Storage::disk(OrderBillPaymentService::DISK)->allFiles(), $beforeFiles)));
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    public function test_deferred_commit_failure_removes_evidence_and_rolls_back_all_financial_sources(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Deferred failure cleanup requires PostgreSQL.');
        }
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'bill_commit_failure_'.str()->lower(str()->random(12));
        $beforeFiles = Storage::disk(OrderBillPaymentService::DISK)->allFiles();
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->fixture();
            $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_test_bill_evidence_at_commit() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Injected deferred completion failure' USING ERRCODE = '23514';
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER zz_reject_test_bill_evidence_at_commit
AFTER INSERT ON order_bill_payment_evidence DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION reject_test_bill_evidence_at_commit();
SQL);
            try {
                app(OrderBillPaymentService::class)->complete($c['employee'], $c['order'], $request, [
                    'order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id,
                    'bill_number' => 'COMMIT-FAIL', 'bill_date' => today()->toDateString(),
                    'principal_amount' => '100', 'payment_reference' => 'SHAM-COMMIT-FAIL',
                ], [UploadedFile::fake()->createWithContent('deferred-proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))]);
                $this->fail('Deferred commit failure must abort completion.');
            } catch (QueryException|\PDOException $exception) {
                $this->assertStringContainsString('Injected deferred completion failure', $exception->getMessage());
            }
            $this->assertSame('REQUESTED', $request->fresh()->status->value);
            foreach (['order_bill_payments', 'order_bill_payment_principal_handovers', 'order_bill_payment_evidence', 'financial_transactions', 'financial_entries'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
            }
            $this->assertSame([], array_values(array_diff(Storage::disk(OrderBillPaymentService::DISK)->allFiles(), $beforeFiles)));
        } finally {
            Storage::disk(OrderBillPaymentService::DISK)->delete(array_values(array_diff(Storage::disk(OrderBillPaymentService::DISK)->allFiles(), $beforeFiles)));
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    public function test_postgresql_rejects_incomplete_committed_payment_sets_and_invalid_source_links(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Deferred source-set validation requires PostgreSQL.');
        }
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'bill_source_guard_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->fixture();
            $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $history = $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            $at = now()->startOfSecond();
            $attributes = [
                'request_id' => $request->id, 'order_id' => $c['order']->id,
                'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['assignment']->captain_id,
                'currency_id' => $c['currency']->id, 'fee_rule_id' => $request->fee_rule_id,
                'bill_number' => 'SQL-1', 'bill_date' => today()->toDateString(),
                'principal_amount' => '100', 'service_fee' => $request->service_fee,
                'total_customer_cash' => '102', 'delivered_at' => $history->fresh()->changed_at,
                'paid_at' => $at, 'paid_by' => $c['employee']->id, 'payment_reference' => 'SQL-REF',
                'created_at' => $at, 'updated_at' => $at,
            ];
            foreach (['payment_only', 'without_evidence', 'without_ledger'] as $case) {
                try {
                    DB::transaction(function () use ($case, $attributes, $request, $c, $at): void {
                        $paymentId = DB::table('order_bill_payments')->insertGetId($attributes);
                        if ($case !== 'payment_only') {
                            DB::table('order_bill_payment_principal_handovers')->insert([
                                'bill_payment_id' => $paymentId, 'order_id' => $c['order']->id,
                                'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['assignment']->captain_id,
                                'currency_id' => $c['currency']->id, 'principal_amount' => '100',
                                'received_by' => $c['employee']->id, 'occurred_at' => $at,
                                'created_at' => $at, 'updated_at' => $at,
                            ]);
                        }
                        if ($case === 'without_ledger') {
                            DB::table('order_bill_payment_evidence')->insert([
                                'bill_payment_id' => $paymentId,
                                'storage_path' => $c['order']->id.'/'.$request->id.'/'.$paymentId.'/proof.png',
                                'original_filename' => 'proof.png', 'mime_type' => 'image/png', 'file_size' => 1,
                                'sha256' => str_repeat('a', 64), 'uploaded_by' => $c['employee']->id,
                                'uploaded_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                            ]);
                            DB::table('order_bill_payment_requests')->where('id', $request->id)->update(['status' => 'COMPLETED', 'completed_at' => $at]);
                        }
                    });
                    $this->fail("The database committed {$case}.");
                } catch (QueryException|\PDOException $exception) {
                    $this->assertStringContainsString('bill payment', strtolower($exception->getMessage()));
                }
                $this->assertSame(0, DB::table('order_bill_payments')->count());
                $this->assertSame(0, DB::table('order_bill_payment_principal_handovers')->count());
                $this->assertSame(0, DB::table('order_bill_payment_evidence')->count());
                $this->assertSame('REQUESTED', $request->fresh()->status->value);
            }

            foreach ([['order_id' => $c['order']->id + 9999], ['currency_id' => $c['currency']->id + 9999], ['captain_id' => $c['assignment']->captain_id + 9999], ['delivered_at' => now()->subDay()]] as $change) {
                try {
                    DB::transaction(fn () => DB::table('order_bill_payments')->insert([...$attributes, ...$change]));
                    $this->fail('The database accepted a mismatched successful source.');
                } catch (QueryException|\PDOException) {
                    $this->assertSame(0, DB::table('order_bill_payments')->count());
                }
            }
            foreach ([['order_id' => $c['order']->id + 9999], ['currency_id' => $c['currency']->id + 9999], ['captain_id' => $c['assignment']->captain_id + 9999], ['principal_amount' => '101']] as $change) {
                try {
                    DB::transaction(function () use ($attributes, $change, $c, $at): void {
                        $paymentId = DB::table('order_bill_payments')->insertGetId($attributes);
                        DB::table('order_bill_payment_principal_handovers')->insert([
                            'bill_payment_id' => $paymentId, 'order_id' => $c['order']->id,
                            'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['assignment']->captain_id,
                            'currency_id' => $c['currency']->id, 'principal_amount' => '100',
                            'received_by' => $c['employee']->id, 'occurred_at' => $at,
                            'created_at' => $at, 'updated_at' => $at,
                            ...$change,
                        ]);
                    });
                    $this->fail('The database accepted a mismatched handover source.');
                } catch (QueryException|\PDOException) {
                    $this->assertSame(0, DB::table('order_bill_payments')->count());
                }
            }
            try {
                DB::transaction(fn () => DB::table('order_bill_payment_requests')->insert([
                    'order_id' => $c['order']->id, 'currency_id' => $c['currency']->id,
                    'fee_rule_id' => $request->fee_rule_id, 'service_fee' => '3',
                    'status' => 'REQUESTED', 'requested_by' => $c['employee']->id,
                    'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('The database accepted a fee snapshot different from its referenced rule.');
            } catch (QueryException|\PDOException) {
                $this->assertSame(1, DB::table('order_bill_payment_requests')->count());
            }
            try {
                DB::transaction(fn () => DB::table('order_bill_payment_requests')->where('id', $request->id)->update([
                    'status' => 'CANCELLED', 'cancelled_at' => now(), 'cancellation_reason' => 'Missing actor',
                ]));
                $this->fail('Cancellation without an actor committed.');
            } catch (QueryException|\PDOException) {
                $this->assertSame('REQUESTED', $request->fresh()->status->value);
            }
            try {
                DB::transaction(fn () => DB::table('bill_payment_fee_rules')->where('id', $request->fee_rule_id)->update(['effective_until' => $request->requested_at]));
                $this->fail('The fee interval excluded its historical request.');
            } catch (QueryException|\PDOException) {
                $this->assertNull($request->feeRule->fresh()->effective_until);
            }
        } finally {
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    public function test_postgresql_commit_rejects_each_malformed_bill_payment_ledger_companion(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Deferred financial companion validation requires PostgreSQL.');
        }
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'bill_ledger_guard_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->fixture();
            $cases = [
                'wrong_source', 'wrong_account_kind', 'wrong_direction', 'wrong_amount',
                'wrong_currency', 'wrong_captain', 'missing_cash_increase',
                'missing_cash_decrease', 'missing_fee_event', 'missing_evidence', 'missing_handover',
            ];
            $requests = [];
            foreach ($cases as $case) {
                $requests[$case] = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
            }
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $history = $c['order']->statusHistory()->create([
                'previous_status' => OrderOperationalStatus::HeadingToCustomer,
                'new_status' => OrderOperationalStatus::Delivered,
                'changed_by' => $c['employee']->id,
                'changed_at' => now(),
            ]);
            $deliveredAt = $history->fresh()->changed_at;
            $accounts = app(FinancialAccountService::class);
            $owner = $accounts->ownerForCaptain($c['assignment']->captain);
            $custody = $accounts->getOrCreate($owner, $c['currency'], FinancialAccountKind::CaptainCashCustody);
            $deposit = $accounts->getOrCreate($owner, $c['currency'], FinancialAccountKind::CaptainDeposit);
            $otherCurrency = Currency::create(['name' => 'Other Currency', 'code' => 'EUR', 'decimal_places' => 2]);
            $otherCurrencyCustody = $accounts->getOrCreate($owner, $otherCurrency, FinancialAccountKind::CaptainCashCustody);
            $otherCaptain = Captain::create([
                'user_id' => User::factory()->create()->id,
                'home_branch_id' => $c['order']->branch_id,
                'operational_status' => CaptainOperationalStatus::Available,
            ]);
            $otherCaptainCustody = $accounts->getOrCreate(
                $accounts->ownerForCaptain($otherCaptain), $c['currency'], FinancialAccountKind::CaptainCashCustody,
            );

            foreach ($cases as $case) {
                $request = $requests[$case];
                $at = now()->startOfSecond();
                $reachedCommit = false;
                $paymentId = null;
                try {
                    DB::transaction(function () use ($case, $request, $c, $at, $deliveredAt, $custody, $deposit, $otherCurrency, $otherCurrencyCustody, $otherCaptainCustody, &$paymentId, &$reachedCommit): void {
                        $paymentId = DB::table('order_bill_payments')->insertGetId([
                            'request_id' => $request->id, 'order_id' => $c['order']->id,
                            'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['assignment']->captain_id,
                            'currency_id' => $c['currency']->id, 'fee_rule_id' => $request->fee_rule_id,
                            'bill_number' => 'COMMIT-'.$case, 'bill_date' => today()->toDateString(),
                            'principal_amount' => '100', 'service_fee' => $request->service_fee,
                            'total_customer_cash' => '102', 'delivered_at' => $deliveredAt,
                            'paid_at' => $at, 'paid_by' => $c['employee']->id,
                            'payment_reference' => 'COMMIT-'.$case, 'created_at' => $at, 'updated_at' => $at,
                        ]);
                        $handoverId = DB::table('order_bill_payment_principal_handovers')->insertGetId([
                            'bill_payment_id' => $paymentId, 'order_id' => $c['order']->id,
                            'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['assignment']->captain_id,
                            'currency_id' => $c['currency']->id, 'principal_amount' => '100',
                            'received_by' => $c['employee']->id, 'occurred_at' => $at,
                            'created_at' => $at, 'updated_at' => $at,
                        ]);
                        DB::table('order_bill_payment_evidence')->insert([
                            'bill_payment_id' => $paymentId,
                            'storage_path' => $c['order']->id.'/'.$request->id.'/'.$paymentId.'/proof.png',
                            'original_filename' => 'proof.png', 'mime_type' => 'image/png', 'file_size' => 1,
                            'sha256' => str_repeat('a', 64), 'uploaded_by' => $c['employee']->id,
                            'uploaded_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                        ]);
                        $posting = app(FinancialPostingService::class);
                        if ($case !== 'missing_cash_increase') {
                            $posting->postEvent(
                                $case === 'wrong_currency' ? $otherCurrency : $c['currency'],
                                'ORDER_BILL_PAYMENT', $case === 'wrong_source' ? $paymentId + 999999 : $paymentId,
                                FinancialEventType::BillPaymentCashCollected->value, 1, $deliveredAt,
                                [[
                                    'account' => match ($case) {
                                        'wrong_account_kind' => $deposit,
                                        'wrong_currency' => $otherCurrencyCustody,
                                        'wrong_captain' => $otherCaptainCustody,
                                        default => $custody,
                                    },
                                    'direction' => $case === 'wrong_direction' ? FinancialEntryDirection::Decrease : FinancialEntryDirection::Increase,
                                    'amount' => $case === 'wrong_amount' ? '99' : '100',
                                ]], $c['order']->branch, $c['employee'],
                            );
                        }
                        if ($case !== 'missing_cash_decrease') {
                            $posting->postEvent($c['currency'], 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER', $handoverId,
                                FinancialEventType::BillPaymentPrincipalHandedOver->value, 1, $at,
                                [['account' => $custody, 'direction' => FinancialEntryDirection::Decrease, 'amount' => '100']],
                                $c['order']->branch, $c['employee']);
                        }
                        if ($case !== 'missing_fee_event') {
                            $posting->postEvent($c['currency'], 'ORDER_BILL_PAYMENT', $paymentId,
                                FinancialEventType::BillPaymentServiceFeeRetained->value, 1, $at,
                                [['account' => $deposit, 'direction' => FinancialEntryDirection::Decrease, 'amount' => '2']],
                                $c['order']->branch, $c['employee']);
                        }
                        DB::table('order_bill_payment_requests')->where('id', $request->id)->update(['status' => 'COMPLETED', 'completed_at' => $at]);
                        if (in_array($case, ['missing_evidence', 'missing_handover'], true)) {
                            // Remove exactly one companion after a complete set passes the immediate
                            // request guard, then restore the immutable trigger before COMMIT.
                            $table = $case === 'missing_evidence' ? 'order_bill_payment_evidence' : 'order_bill_payment_principal_handovers';
                            $trigger = $case === 'missing_evidence' ? 'immutable_bill_payment_evidence' : 'immutable_bill_payment_handover';
                            $this->assertSame(1, DB::table($table)->where('bill_payment_id', $paymentId)->count());
                            $this->assertSame(3, DB::table('financial_transactions')->count());
                            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
                            DB::table($table)->where('bill_payment_id', $paymentId)->delete();
                            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
                            $this->assertSame(0, DB::table($table)->where('bill_payment_id', $paymentId)->count());
                            $otherTable = $case === 'missing_evidence' ? 'order_bill_payment_principal_handovers' : 'order_bill_payment_evidence';
                            $this->assertSame(1, DB::table($otherTable)->where('bill_payment_id', $paymentId)->count());
                            $this->assertSame('COMPLETED', DB::table('order_bill_payment_requests')->where('id', $request->id)->value('status'));
                            $this->assertSame(3, DB::table('financial_transactions')->count());
                        }
                        $reachedCommit = true;
                    });
                    $this->fail("PostgreSQL committed malformed bill-payment companion: {$case}.");
                } catch (QueryException|\PDOException $exception) {
                    $this->assertTrue($reachedCommit, "{$case} failed before COMMIT: {$exception->getMessage()}");
                    if (in_array($case, ['missing_evidence', 'missing_handover'], true)) {
                        $this->assertSame('23514', (string) $exception->getCode());
                        $this->assertStringContainsString("Successful bill payment {$paymentId} lacks its exact request, handover, evidence or financial event set", $exception->getMessage());
                    }
                    $this->assertSame('REQUESTED', $request->fresh()->status->value);
                    $this->assertSame(0, DB::table('order_bill_payments')->where('request_id', $request->id)->count());
                    $this->assertSame(0, DB::table('financial_transactions')->count());
                }
            }
        } finally {
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    public static function completionFeeModes(): array
    {
        return ['fixed' => [false], 'tiered SYP' => [true]];
    }

    #[DataProvider('completionFeeModes')]
    public function test_two_real_completion_services_contend_and_only_one_posts_money(bool $tiered): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent-service concurrency requires PostgreSQL.');
        }
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'bill_payment_race_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->fixture($tiered);
            $principal = $tiered ? '1500' : '100';
            $fee = $tiered ? '30.0000' : '2.0000';
            $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
            $filesBefore = Storage::disk(OrderBillPaymentService::DISK)->allFiles();
            $results = $this->race([
                ['operation' => 'complete', 'evidence_name' => 'proof-worker-a.png', 'actor_id' => $c['employee']->id, 'order_id' => $c['order']->id, 'request_id' => $request->id, 'data' => ['order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id, 'bill_number' => 'B-1', 'bill_date' => today()->toDateString(), 'principal_amount' => $principal, 'payment_reference' => 'SHAM-1']],
                ['operation' => 'complete', 'evidence_name' => 'proof-worker-b.png', 'actor_id' => $c['employee']->id, 'order_id' => $c['order']->id, 'request_id' => $request->id, 'data' => ['order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id, 'bill_number' => 'B-1', 'bill_date' => today()->toDateString(), 'principal_amount' => $principal, 'payment_reference' => 'SHAM-1']],
            ], 'orders', $c['order']->id);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $loser = collect($results)->firstWhere('status', 'error');
            $this->assertSame(ValidationException::class, $loser['class'] ?? null, json_encode($results));
            $this->assertSame(1, $c['order']->billPayments()->count());
            $this->assertSame(1, $c['order']->billPaymentRequests()->where('status', 'COMPLETED')->count());
            $payment = $c['order']->billPayments()->sole();
            $this->assertSame($fee, $payment->service_fee);
            $this->assertSame($tiered ? null : $fee, $request->fresh()->service_fee);
            $this->assertSame(1, $payment->handover()->count());
            $evidence = $payment->evidence()->sole();
            $this->assertContains($evidence->original_filename, ['proof-worker-a.png', 'proof-worker-b.png']);
            $this->assertTrue(Storage::disk(OrderBillPaymentService::DISK)->exists($evidence->storage_path));
            $this->assertSame(hash('sha256', Storage::disk(OrderBillPaymentService::DISK)->get($evidence->storage_path)), $evidence->sha256);
            $this->assertSame([$evidence->storage_path], array_values(array_diff(Storage::disk(OrderBillPaymentService::DISK)->allFiles(), $filesBefore)));
            $events = FinancialTransaction::with('entries.account.owner')->whereIn('event_type', [
                FinancialEventType::BillPaymentCashCollected->value,
                FinancialEventType::BillPaymentPrincipalHandedOver->value,
                FinancialEventType::BillPaymentServiceFeeRetained->value,
            ])->get()->keyBy('event_type');
            $this->assertCount(3, $events);
            foreach ([
                FinancialEventType::BillPaymentCashCollected->value => [FinancialAccountKind::CaptainCashCustody, FinancialEntryDirection::Increase, $principal.'.0000'],
                FinancialEventType::BillPaymentPrincipalHandedOver->value => [FinancialAccountKind::CaptainCashCustody, FinancialEntryDirection::Decrease, $principal.'.0000'],
                FinancialEventType::BillPaymentServiceFeeRetained->value => [FinancialAccountKind::CaptainDeposit, FinancialEntryDirection::Decrease, $fee],
            ] as $eventType => [$kind, $direction, $amount]) {
                $this->assertSame(1, FinancialTransaction::where('event_type', $eventType)->count());
                $entry = $events[$eventType]->entries->sole();
                $this->assertSame($kind, $entry->account->account_kind);
                $this->assertSame($direction, $entry->direction);
                $this->assertSame($amount, $entry->amount);
                $this->assertSame($c['assignment']->captain_id, $entry->account->owner->captain_id);
            }
            $cashAccount = $events[FinancialEventType::BillPaymentCashCollected->value]->entries->sole()->account;
            foreach ([['0'.$payment->id, 1], [(string) $payment->id, 2]] as [$sourceId, $version]) {
                try {
                    DB::transaction(fn () => app(FinancialPostingService::class)->postEvent(
                        $c['currency'], 'ORDER_BILL_PAYMENT', $sourceId,
                        FinancialEventType::BillPaymentCashCollected->value, $version, $payment->delivered_at,
                        [['account' => $cashAccount, 'direction' => FinancialEntryDirection::Increase, 'amount' => $principal]],
                        $c['order']->branch, $c['employee'],
                    ));
                    $this->fail('An extra or noncanonical bill-payment event committed.');
                } catch (QueryException|\PDOException) {
                    $this->assertSame(3, FinancialTransaction::whereIn('event_type', [
                        FinancialEventType::BillPaymentCashCollected->value,
                        FinancialEventType::BillPaymentPrincipalHandedOver->value,
                        FinancialEventType::BillPaymentServiceFeeRetained->value,
                    ])->count());
                }
            }
        } finally {
            Storage::disk(OrderBillPaymentService::DISK)->delete(DB::table('order_bill_payment_evidence')->pluck('storage_path')->all());
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    public function test_payment_wins_order_lock_and_concurrent_delivery_history_rewrite_is_rejected(): void
    {
        $this->assertSerializedDeliverySourceRace(true);
    }

    public function test_delivery_history_wins_order_lock_and_payment_uses_new_committed_timestamp(): void
    {
        $this->assertSerializedDeliverySourceRace(false);
    }

    public function test_direct_sql_payment_insert_waits_for_history_order_lock_and_rejects_stale_delivery(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Direct SQL payment serialization requires PostgreSQL.');
        }
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'bill_direct_lock_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->fixture();
            $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $history = $c['order']->statusHistory()->create([
                'previous_status' => OrderOperationalStatus::HeadingToCustomer,
                'new_status' => OrderOperationalStatus::Delivered,
                'changed_by' => $c['employee']->id,
                'changed_at' => now(),
            ]);
            $at = now()->startOfSecond();
            [$mutation, $payment] = $this->raceDeliverySource(
                ['operation' => 'mutate_delivery_hold', 'history_id' => $history->id],
                ['operation' => 'insert_payment', 'payment_attributes' => [
                    'request_id' => $request->id, 'order_id' => $c['order']->id,
                    'order_captain_id' => $c['assignment']->id, 'captain_id' => $c['assignment']->captain_id,
                    'currency_id' => $c['currency']->id, 'fee_rule_id' => $request->fee_rule_id,
                    'bill_number' => 'DIRECT-SQL', 'bill_date' => today()->toDateString(),
                    'principal_amount' => '100', 'service_fee' => $request->service_fee,
                    'total_customer_cash' => '102', 'delivered_at' => $history->fresh()->changed_at,
                    'paid_at' => $at, 'paid_by' => $c['employee']->id,
                    'payment_reference' => 'DIRECT-SQL', 'created_at' => $at, 'updated_at' => $at,
                ]],
            );
            $this->assertSame('success', $mutation['status']);
            $this->assertSame('error', $payment['status']);
            $this->assertSame(QueryException::class, $payment['class']);
            $this->assertStringContainsString('delivery history', $payment['message']);
            $this->assertSame(0, $c['order']->billPayments()->count());
            $this->assertSame('REQUESTED', $request->fresh()->status->value);
            $this->assertSame(0, FinancialTransaction::count());
        } finally {
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    private function assertSerializedDeliverySourceRace(bool $paymentFirst): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Delivery-source serialization requires PostgreSQL.');
        }
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'bill_delivery_race_'.str()->lower(str()->random(12));
        $filesBefore = Storage::disk(OrderBillPaymentService::DISK)->allFiles();
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->fixture();
            $request = app(OrderBillPaymentService::class)->request($c['employee'], $c['order'], $c['currency']->id);
            $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $history = $c['order']->statusHistory()->create([
                'previous_status' => OrderOperationalStatus::HeadingToCustomer,
                'new_status' => OrderOperationalStatus::Delivered,
                'changed_by' => $c['employee']->id,
                'changed_at' => now(),
            ]);
            $originalDeliveryAt = $history->fresh()->changed_at;
            $completion = [
                'actor_id' => $c['employee']->id, 'order_id' => $c['order']->id,
                'request_id' => $request->id, 'evidence_name' => 'serialized-proof.png',
                'data' => [
                    'order_captain_id' => $c['assignment']->id, 'currency_id' => $c['currency']->id,
                    'bill_number' => 'SERIALIZED', 'bill_date' => today()->toDateString(),
                    'principal_amount' => '100', 'payment_reference' => 'SHAM-SERIALIZED',
                ],
            ];
            $mutation = ['history_id' => $history->id];
            [$first, $second] = $paymentFirst
                ? $this->raceDeliverySource(['operation' => 'complete_hold', ...$completion], ['operation' => 'mutate_delivery', ...$mutation])
                : $this->raceDeliverySource(['operation' => 'mutate_delivery_hold', ...$mutation], ['operation' => 'complete', ...$completion]);

            $this->assertSame('success', $first['status'], json_encode([$first, $second]));
            if ($paymentFirst) {
                $this->assertSame('error', $second['status'], json_encode([$first, $second]));
                $this->assertSame(QueryException::class, $second['class']);
                $this->assertSame($originalDeliveryAt->toISOString(), $history->fresh()->changed_at->toISOString());
            } else {
                $this->assertSame('success', $second['status'], json_encode([$first, $second]));
                $this->assertNotSame($originalDeliveryAt->toISOString(), $history->fresh()->changed_at->toISOString());
            }

            $payment = $c['order']->billPayments()->sole();
            $this->assertSame('COMPLETED', $request->fresh()->status->value);
            $this->assertSame(1, $payment->handover()->count());
            $evidence = $payment->evidence()->sole();
            $this->assertTrue(Storage::disk(OrderBillPaymentService::DISK)->exists($evidence->storage_path));
            $this->assertSame($evidence->sha256, hash('sha256', Storage::disk(OrderBillPaymentService::DISK)->get($evidence->storage_path)));
            $this->assertSame([$evidence->storage_path], array_values(array_diff(Storage::disk(OrderBillPaymentService::DISK)->allFiles(), $filesBefore)));
            $this->assertSame($history->fresh()->changed_at->toISOString(), $payment->delivered_at->toISOString());
            $cash = FinancialTransaction::where('event_type', FinancialEventType::BillPaymentCashCollected->value)->sole();
            $this->assertSame($history->fresh()->changed_at->toISOString(), $cash->occurred_at->toISOString());
            $this->assertSame(3, FinancialTransaction::whereIn('event_type', [
                FinancialEventType::BillPaymentCashCollected->value,
                FinancialEventType::BillPaymentPrincipalHandedOver->value,
                FinancialEventType::BillPaymentServiceFeeRetained->value,
            ])->count());
            $this->assertSame(3, DB::table('financial_entries')->count());
        } finally {
            Storage::disk(OrderBillPaymentService::DISK)->delete(array_values(array_diff(Storage::disk(OrderBillPaymentService::DISK)->allFiles(), $filesBefore)));
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function raceDeliverySource(array $firstPayload, array $secondPayload): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-delivery-source-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $holdPath = $directory.DIRECTORY_SEPARATOR.'holding';
        $releasePath = $directory.DIRECTORY_SEPARATOR.'release';
        $tag = 'delivery-source-'.bin2hex(random_bytes(6));
        $workers = [];
        try {
            foreach ([['operation' => $firstPayload['operation'], ...$firstPayload, 'hold_path' => $holdPath, 'release_path' => $releasePath], $secondPayload] as $index => $payload) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$index}.json";
                $readyPath = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
                $goPath = $directory.DIRECTORY_SEPARATOR."go-{$index}";
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                file_put_contents($payloadPath, json_encode(['tag' => $tag, ...$payload], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/order_bill_payment_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'goPath', 'resultPath');
                $deadline = microtime(true) + 10;
                while (! file_exists($readyPath) && microtime(true) < $deadline) {
                    usleep(25_000);
                }
                $this->assertFileExists($readyPath, "Worker {$index} did not boot.");
                touch($goPath);
                if ($index === 0) {
                    while (! file_exists($holdPath) && microtime(true) < $deadline) {
                        usleep(25_000);
                    }
                    $this->assertFileExists($holdPath, 'The first worker did not hold the Order transaction.');
                }
            }

            $deadline = microtime(true) + 10;
            $blocked = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) total FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$tag])->total;
                if ($blocked === 1) {
                    break;
                }
                usleep(25_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(1, $blocked, 'The second worker must wait for the first worker\'s Order row lock.');
            touch($releasePath);
            $results = [];
            foreach ($workers as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());
                $results[] = json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            touch($releasePath);
            foreach ($workers as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop();
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_fee_version_and_request_snapshot_contend_on_the_same_currency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent-service concurrency requires PostgreSQL.');
        }
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'bill_fee_race_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true]);
            $c = $this->fixture();
            $results = $this->race([
                ['operation' => 'request', 'actor_id' => $c['employee']->id, 'order_id' => $c['order']->id, 'currency_id' => $c['currency']->id],
                ['operation' => 'version', 'actor_id' => $c['admin']->id, 'rule_id' => $c['rule']->id, 'fee' => '3', 'effective_from' => 'NOW'],
            ], 'currencies', $c['currency']->id);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $request = $c['order']->billPaymentRequests()->sole();
            $this->assertContains($request->service_fee, ['2.0000', '3.0000']);
            $this->assertSame($request->feeRule->fixed_fee, $request->service_fee);
            $this->assertSame(2, DB::table('bill_payment_fee_rules')->count());
        } finally {
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }

    private function fixture(bool $tiered = false): array
    {
        $currency = Currency::create(['name' => 'Dollar', 'code' => $tiered ? 'SYP' : 'USD', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $customer = Customer::create(['name' => 'Race customer']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $order = Order::create(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'order_number' => 'BILL-RACE', 'ordered_at' => now(), 'customer_name_snapshot' => 'Race customer', 'customer_phone_snapshot' => '123', 'area_name_snapshot' => 'Area', 'address_line_1_snapshot' => 'Street', 'created_by' => $employee->id]);
        $captain = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $assignment = OrderCaptain::create(['order_id' => $order->id, 'captain_id' => $captain->id, 'assignment_role' => 'SUPPORT', 'assigned_at' => now()->subHour(), 'assigned_by' => $employee->id]);
        $rule = app(BillPaymentFeeRuleService::class)->create($admin, $currency->id, $tiered ? null : '2', now()->subDay(), $tiered ? BillPaymentFeeRuleService::sypTiers() : null);

        return compact('currency', 'admin', 'employee', 'order', 'assignment', 'rule');
    }

    private function race(array $payloads, string $lockedTable, int $lockedId): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-bill-payment-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $goPath = $directory.DIRECTORY_SEPARATOR.'go';
        $tag = 'bill-payment-'.bin2hex(random_bytes(6));
        $workers = [];
        DB::beginTransaction();
        DB::table($lockedTable)->where('id', $lockedId)->lockForUpdate()->firstOrFail();
        try {
            foreach ($payloads as $index => $payload) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$index}.json";
                $readyPath = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                file_put_contents($payloadPath, json_encode(['tag' => $tag, ...$payload], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/order_bill_payment_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'resultPath');
            }
            $deadline = microtime(true) + 10;
            while (! collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])) && microtime(true) < $deadline) {
                usleep(25_000);
            }
            touch($goPath);
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) total FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$tag])->total;
                if ($blocked === 2) {
                    break;
                }
                usleep(25_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both production workers must contend on the same business lock.');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());
                $results[] = json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop();
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function workerEnvironment(): array
    {
        $database = config('database.connections.'.config('database.default'));

        return ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SCHEMA' => (string) $database['search_path']];
    }
}
