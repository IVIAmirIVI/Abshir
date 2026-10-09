<?php

namespace Tests\Preview;

use App\Console\Commands\ResetDemoOrders;
use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\CustomerAddress;
use App\Models\User;
use App\Services\CaptainDepositManualAdjustmentService;
use App\Services\CaptainDepositReceiptService;
use App\Services\FinancialAccountService;
use App\Services\FinancialPostingService;
use App\Services\OrderService;
use App\Support\DemoOrderReset\OrderResetMaintenance;
use Illuminate\Database\PostgresConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Opt-in isolated preview tests, intentionally outside the normal feature suites. */
final class DemoOrderResetTest extends TestCase
{
    private ?PDO $pdo = null;

    private function connection(): PDO
    {
        return $this->pdo = OrderResetMaintenance::connect(config('database.connections.pgsql'), app()->environment(), true);
    }

    protected function tearDown(): void
    {
        if ($this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
        }
        DB::purge('reset_fixture');
        DB::setDefaultConnection('pgsql');
    }

    public function test_production_test_database_remote_host_and_other_schema_are_refused_before_connecting(): void
    {
        $valid = config('database.connections.pgsql');
        foreach ([['production', $valid], ['testing', $valid],
            ['local', array_replace($valid, ['database' => 'abshir_testing'])],
            ['local', array_replace($valid, ['host' => 'example.com'])],
            ['local', array_replace($valid, ['search_path' => 'abshir_testing'])],
            ['local', array_replace($valid, ['url' => 'pgsql://unsupported'])]] as [$env, $config]) {
            try {
                OrderResetMaintenance::connect($config, $env, true);
                self::fail('Unsafe connection was accepted.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('Reset requires', $e->getMessage());
            }
        }
    }

    public function test_noninteractive_command_is_refused_without_database_work(): void
    {
        $tester = $this->command();
        self::assertSame(1, $tester->execute(['--preview' => true], ['interactive' => false]));
        self::assertStringContainsString('Interactive confirmation is required', $tester->getDisplay());
    }

    public function test_actual_database_identity_is_checked(): void
    {
        $pdo = $this->connection();
        self::assertSame('abshir_cleanup_preview', $pdo->query('SELECT current_database()')->fetchColumn());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        OrderResetMaintenance::assertIdentity($pdo, 'abshir');
    }

    public function test_complete_cleanup_and_forced_error_roll_back_all_rows_and_guard_definitions(): void
    {
        $pdo = $this->connection();
        $before = $this->state($pdo);
        $maintenance = new OrderResetMaintenance;
        $maintenance->begin($pdo, 'abshir_cleanup_preview');
        $this->ensureDemoOrder($pdo);
        $counts = $maintenance->plan();
        self::assertGreaterThan(0, $counts['orders'] ?? 0);
        $report = $maintenance->execute();
        self::assertSame(0, $report['orders_after']);
        self::assertTrue($report['guards_restored']);
        self::assertTrue($report['preserved_rows_verified']);
        self::assertSame($counts, $report['deleted']);
        try {
            throw new RuntimeException('Simulated failure before COMMIT');
        } catch (RuntimeException) {
            $pdo->rollBack();
        }
        self::assertSame($before, $this->state($pdo));
    }

    public function test_independent_deposit_and_withdrawal_are_preserved_with_exact_balance(): void
    {
        $pdo = $this->connection();
        $before = $this->state($pdo);
        $maintenance = new OrderResetMaintenance;
        $maintenance->begin($pdo, 'abshir_cleanup_preview');
        $this->ensureDemoOrder($pdo);
        $db = $this->fixtures($pdo);
        Storage::fake('captain_financial_evidence');
        Storage::fake('captain_deposit_manual_adjustments');
        $captain = Captain::query()->firstOrFail();
        $currency = Currency::query()->where('code', 'SYP')->firstOrFail();
        $branch = Branch::query()->firstOrFail();
        $actor = User::query()->whereHas('roles', fn ($q) => $q->where('code', 'SUPER_ADMIN'))->firstOrFail();
        $deposit = app(CaptainDepositReceiptService::class)->record($actor, $captain, $branch, $currency,
            ['request_key' => (string) Str::uuid(), 'amount' => '1000', 'occurred_at' => now()->subMinute()->toIso8601String()],
            $this->evidence());
        // A second genuine independent receipt proves two different preserved sources.
        $second = app(CaptainDepositReceiptService::class)->record($actor, $captain, $branch, $currency,
            ['request_key' => (string) Str::uuid(), 'amount' => '200', 'occurred_at' => now()->subMinute()->toIso8601String()],
            $this->evidence());
        app(CaptainDepositManualAdjustmentService::class)->record($actor, $captain, $branch, $currency,
            ['request_key' => (string) Str::uuid(), 'amount' => '150', 'direction' => 'DECREASE',
                'reason' => 'Preview-only independent withdrawal fixture', 'occurred_at' => now()->subMinute()->toIso8601String()],
            $this->evidence());
        $pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
        $counts = $maintenance->plan();
        self::assertArrayNotHasKey('captain_deposit_receipts', $counts);
        $report = $maintenance->execute();
        self::assertSame(2, (int) $pdo->query('SELECT count(*) FROM captain_deposit_receipts')->fetchColumn());
        self::assertSame(3, (int) $pdo->query('SELECT count(*) FROM financial_transactions')->fetchColumn());
        self::assertSame('1200.0000', $pdo->query("SELECT SUM(amount)::text FROM financial_entries WHERE direction='INCREASE'")->fetchColumn());
        self::assertContains('1050.0000', array_column($report['balances_after'], 'balance'));
        self::assertTrue($report['preserved_rows_verified']);
        $db->rollBack();
        self::assertSame($before, $this->state($pdo));
    }

    public function test_maintenance_cannot_delete_an_independent_master_or_unapproved_row(): void
    {
        $pdo = $this->connection();
        $before = $this->state($pdo);
        $maintenance = new OrderResetMaintenance;
        $maintenance->begin($pdo, 'abshir_cleanup_preview');
        $this->fixtures($pdo);
        Storage::fake('captain_financial_evidence');
        app(CaptainDepositReceiptService::class)->record(
            User::query()->whereHas('roles', fn ($q) => $q->where('code', 'SUPER_ADMIN'))->firstOrFail(),
            Captain::query()->firstOrFail(), Branch::query()->firstOrFail(), Currency::query()->where('code', 'SYP')->firstOrFail(),
            ['request_key' => (string) Str::uuid(), 'amount' => '100', 'occurred_at' => now()->subMinute()->toIso8601String()],
            $this->evidence());
        $maintenance->plan();
        $install = new ReflectionMethod($maintenance, 'installDeleteMaintenance');
        $install->invoke($maintenance);
        $observer = OrderResetMaintenance::connect(config('database.connections.pgsql'), app()->environment(), true);
        self::assertSame($before, $this->state($observer), 'Another connection must see the original guards, not the maintenance exception.');
        // Same function as the ledger delete guard is used for other immutable facts.
        $pdo->exec('SAVEPOINT forbidden_delete');
        try {
            $pdo->exec('DELETE FROM public.financial_accounts');
            self::fail('Master financial Accounts were deletable.');
        } catch (\PDOException $e) {
            self::assertSame('55000', $e->getCode());
            $pdo->exec('ROLLBACK TO SAVEPOINT forbidden_delete');
        }
        $pdo->exec('SAVEPOINT forbidden_update');
        try {
            $pdo->exec('UPDATE financial_entries SET amount=amount+1');
            self::fail('Maintenance allowed financial UPDATE.');
        } catch (\PDOException $e) {
            self::assertSame('55000', $e->getCode());
            $pdo->exec('ROLLBACK TO SAVEPOINT forbidden_update');
        }
    }

    public function test_foreign_keys_remain_enabled_and_an_unreviewed_dependency_rolls_back(): void
    {
        $pdo = $this->connection();
        $before = $this->state($pdo);
        $maintenance = new OrderResetMaintenance;
        $maintenance->begin($pdo, 'abshir_cleanup_preview');
        $this->ensureDemoOrder($pdo);
        // Simulate a dependency introduced after the reviewed catalog. PostgreSQL itself
        // must refuse the parent deletion; the reset may never cascade through it.
        $pdo->exec('CREATE TABLE public.reset_unknown_dependency (id bigint PRIMARY KEY, order_id bigint NOT NULL REFERENCES public.orders(id) ON DELETE RESTRICT)');
        $pdo->exec('INSERT INTO public.reset_unknown_dependency SELECT 1,id FROM orders ORDER BY id LIMIT 1');
        $maintenance->plan();
        try {
            $maintenance->execute();
            self::fail('The unknown FK was disabled or ignored.');
        } catch (\PDOException $e) {
            self::assertContains($e->getCode(), ['23503', '23001']);
        }
        $pdo->rollBack();
        self::assertSame($before, $this->state($pdo));
        self::assertNull($pdo->query("SELECT to_regclass('public.reset_unknown_dependency')")->fetchColumn());
    }

    public function test_public_writers_really_contend_and_snapshot_does_not_predate_locks(): void
    {
        $pdo = $this->connection();
        $maintenance = new OrderResetMaintenance;
        $maintenance->begin($pdo, 'abshir_cleanup_preview');
        self::assertSame('read committed', $pdo->query('SHOW transaction_isolation')->fetchColumn());
        $writer = OrderResetMaintenance::connect(config('database.connections.pgsql'), app()->environment(), true);
        self::assertNotSame($pdo->query('SELECT pg_backend_pid()')->fetchColumn(), $writer->query('SELECT pg_backend_pid()')->fetchColumn());
        $writer->exec("SET lock_timeout='250ms'");
        try {
            $writer->exec('UPDATE public.orders SET notes=notes');
            self::fail('Concurrent writers were not blocked while the backup/reset transaction is active.');
        } catch (\PDOException $e) {
            self::assertSame('55P03', $e->getCode());
        }
        $pdo->rollBack();
        self::assertSame(0, $writer->exec('UPDATE public.orders SET notes=notes'), 'The preview remains empty after rollback.');
    }

    public function test_backup_failure_refuses_cleanup_and_rolls_back(): void
    {
        $pdo = $this->connection();
        $before = $this->state($pdo);
        $bin = config('demo_order_reset.postgres_bin');
        config(['demo_order_reset.postgres_bin' => '/missing-abshir-postgres-binaries']);
        try {
            $tester = $this->command();
            self::assertSame(1, $tester->execute(['--preview' => true], ['interactive' => true]));
            self::assertStringContainsString('Reset rolled back', $tester->getDisplay());
            self::assertSame($before, $this->state($pdo));
        } finally {
            config(['demo_order_reset.postgres_bin' => $bin]);
        }
    }

    public function test_wrong_confirmation_retains_backup_without_deleting_records(): void
    {
        $pdo = $this->connection();
        $before = $this->state($pdo);
        $tester = $this->command();
        $tester->setInputs(['cancel']);
        self::assertSame(0, $tester->execute(['--preview' => true], ['interactive' => true]));
        self::assertStringContainsString('Verified PostgreSQL backup', $tester->getDisplay());
        self::assertStringContainsString('Cancelled; no database records changed', $tester->getDisplay());
        self::assertSame($before, $this->state($pdo));
    }

    public function test_unknown_financial_source_causes_atomic_refusal(): void
    {
        $pdo = $this->connection();
        $before = $this->state($pdo);
        $maintenance = new OrderResetMaintenance;
        $maintenance->begin($pdo, 'abshir_cleanup_preview');
        $db = $this->fixtures($pdo);
        $currency = Currency::query()->where('code', 'SYP')->firstOrFail();
        $account = app(FinancialAccountService::class)->getOrCreate(
            app(FinancialAccountService::class)->ownerForCaptain(Captain::query()->firstOrFail()),
            $currency, FinancialAccountKind::CaptainDeposit);
        app(FinancialPostingService::class)->postEvent($currency, 'UNKNOWN_INDEPENDENT_FIXTURE', 'unclassified',
            'PREVIEW_FIXTURE', 1, now()->subMinute(),
            [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => '100']],
            Branch::query()->firstOrFail());
        try {
            $maintenance->plan();
            self::fail('Unknown source was guessed.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Unsupported financial source', $e->getMessage());
        }
        $db->rollBack();
        self::assertSame($before, $this->state($pdo));
    }

    private function fixtures(PDO $pdo): PostgresConnection
    {
        // Use the REAL production services, inside the maintenance transaction. The
        // counter merely lets Laravel nest savepoints inside the raw PDO transaction.
        if (DB::getDefaultConnection() === 'reset_fixture') {
            return DB::connection('reset_fixture');
        }
        config(['database.connections.reset_fixture' => array_replace(config('database.connections.pgsql'),
            ['database' => 'abshir_cleanup_preview'])]);
        DB::setDefaultConnection('reset_fixture');
        $db = DB::connection('reset_fixture');
        $db->setPdo($pdo)->setReadPdo($pdo);
        (new ReflectionProperty($db, 'transactions'))->setValue($db, 1);

        return $db;
    }

    private function ensureDemoOrder(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT count(*) FROM public.orders')->fetchColumn() > 0) {
            return;
        }
        $this->fixtures($pdo);
        $address = CustomerAddress::query()->with('customer.phones', 'area')->where('is_active', true)
            ->whereHas('customer', fn ($q) => $q->where('is_active', true)->whereHas('phones'))
            ->whereHas('area', fn ($q) => $q->where('is_active', true))->firstOrFail();
        app(OrderService::class)->create(
            User::query()->whereHas('roles', fn ($q) => $q->where('code', 'SUPER_ADMIN'))->firstOrFail(),
            ['source' => 'MANUAL', 'entry_mode' => 'LIVE', 'branch_id' => $address->area->branch_id,
                'customer_id' => $address->customer_id, 'customer_phone_id' => $address->customer->phones->first()->id,
                'customer_address_id' => $address->id]);
    }

    private function evidence(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('reset-fixture.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9XkAAAAASUVORK5CYII='));
    }

    private function command(): CommandTester
    {
        $command = app(ResetDemoOrders::class);
        $application = new Application;
        $application->addCommand($command);
        $command->setLaravel(app());

        return new CommandTester($command);
    }

    private function state(PDO $pdo): array
    {
        return $pdo->query("SELECT 'orders' AS name,count(*)::text AS content FROM public.orders
            UNION ALL SELECT 'ledger',md5(COALESCE(string_agg(to_jsonb(t)::text,'' ORDER BY id),'')) FROM financial_transactions t
            UNION ALL SELECT 'entries',md5(COALESCE(string_agg(to_jsonb(e)::text,'' ORDER BY id),'')) FROM financial_entries e
            UNION ALL SELECT 'guards',md5(string_agg(pg_get_functiondef(t.tgfoid),'| ' ORDER BY t.oid))
            FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
}
