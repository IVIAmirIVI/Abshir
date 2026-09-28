<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\FinancialAccountService;
use App\Services\FinancialPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaptainFinancialBranchMigrationTest extends TestCase
{
    public function test_fresh_and_valid_historical_upgrade_preserve_rows(): void
    {
        $this->withOldSchema(function (): void {
            $this->newMigration()->up();
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });

        $this->withOldSchema(function (): void {
            $fixture = $this->fixture();
            $event = $this->event($fixture, $fixture['branch']);
            $before = DB::table('financial_transactions')->where('id', $event->id)->first();
            $this->newMigration()->up();
            $this->assertEquals($before, DB::table('financial_transactions')->where('id', $event->id)->first());
        });
    }

    public function test_branchless_captain_history_aborts_with_identifiers_and_no_repair(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture();
            $event = $this->event($fixture, null);
            try {
                $this->newMigration()->up();
                $this->fail('Branchless Captain history was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString("transaction_id={$event->id}", $exception->getMessage());
                $this->assertStringContainsString('branch=NULL', $exception->getMessage());
                $this->assertStringContainsString("captain={$fixture['captain']->id}", $exception->getMessage());
                $this->assertStringContainsString('currency=', $exception->getMessage());
                $this->assertNull(DB::table('financial_transactions')->where('id', $event->id)->value('branch_id'));
            }
        });
    }

    public function test_wrong_order_source_branch_aborts_without_rewriting_history(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture();
            $wrongBranch = Branch::factory()->create(['default_currency_id' => $fixture['currency']->id]);
            $event = $this->event($fixture, $wrongBranch);
            try {
                $this->newMigration()->up();
                $this->fail('Misbranched Order history was accepted.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString("transaction_id={$event->id}", $exception->getMessage());
                $this->assertStringContainsString('source/reversal Branch mismatch', $exception->getMessage());
                $this->assertSame($wrongBranch->id, (int) DB::table('financial_transactions')->where('id', $event->id)->value('branch_id'));
            }
        });
    }

    public function test_final_postgresql_guard_rejects_direct_sql_branchless_captain_commit(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture();
            $this->newMigration()->up();
            $this->assertThrows(fn () => DB::transaction(function () use ($fixture): void {
                $id = DB::table('financial_transactions')->insertGetId([
                    'transaction_kind' => 'EVENT', 'status' => 'DRAFT', 'currency_id' => $fixture['currency']->id,
                    'branch_id' => null, 'source_type' => 'DIRECT_SQL', 'source_id' => 'branchless',
                    'event_type' => 'DEPOSIT', 'source_version' => 1, 'payload_hash' => str_repeat('a', 64),
                    'occurred_at' => now(), 'created_at' => now(),
                ]);
                DB::table('financial_entries')->insert([
                    'financial_transaction_id' => $id, 'financial_account_id' => $fixture['deposit']->id,
                    'currency_id' => $fixture['currency']->id, 'direction' => 'INCREASE',
                    'amount' => '1.00', 'created_at' => now(),
                ]);
                DB::table('financial_transactions')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now()]);
                $this->assertSame(1, DB::table('financial_entries as e')->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
                    ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
                    ->where('e.financial_transaction_id', $id)->where('o.owner_type', 'CAPTAIN')->count());
            }), \PDOException::class);
            $this->assertSame(0, DB::table('financial_transactions')->count());
            $wrongBranch = Branch::factory()->create(['default_currency_id' => $fixture['currency']->id]);
            $this->assertThrows(fn () => $this->event($fixture, $wrongBranch), \PDOException::class);
            $this->assertSame(0, DB::table('financial_transactions')->count());
            $this->event($fixture, $fixture['branch']);
            $this->assertSame(1, DB::table('financial_transactions')->count());
            $this->assertThrows(fn () => DB::table('orders')->where('id', $fixture['invoice']->order_id)
                ->update(['branch_id' => $wrongBranch->id]), QueryException::class);
        });
    }

    public function test_source_contract_upgrade_rejects_forged_published_event_without_rewriting_history(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture();
            $this->newMigration()->up();
            $event = app(FinancialPostingService::class)->postEvent(
                $fixture['currency'], 'DIRECT_SQL', 'unrelated',
                'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT', 1, now(),
                [['account' => $fixture['deposit'], 'direction' => 'DECREASE', 'amount' => '1.00']],
                $fixture['branch'],
            );
            $before = DB::table('financial_transactions')->where('id', $event->id)->first();
            try {
                $this->sourceContractMigration()->up();
                $this->fail('A forged Order-derived event survived the upgrade precheck.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString("transaction {$event->id}", $exception->getMessage());
                $this->assertStringContainsString('canonical source', $exception->getMessage());
                $this->assertEquals($before, DB::table('financial_transactions')->where('id', $event->id)->first());
            }
        });
    }

    public function test_source_contract_upgrade_preserves_valid_history_and_unfreezes_pre_financial_orders(): void
    {
        $this->withOldSchema(function (): void {
            $fixture = $this->fixture();
            $event = $this->event($fixture, $fixture['branch']);
            $this->newMigration()->up();
            $this->sourceContractMigration()->up();
            $this->assertSame($fixture['branch']->id, FinancialTransaction::findOrFail($event->id)->branch_id);
            $otherBranch = Branch::factory()->create(['default_currency_id' => $fixture['currency']->id]);
            $unbound = Order::create([
                'branch_id' => $fixture['branch']->id,
                'customer_id' => DB::table('orders')->where('id', $fixture['invoice']->order_id)->value('customer_id'),
                'order_number' => 'UNBOUND-'.str()->random(8), 'ordered_at' => now(),
                'source' => 'MANUAL', 'entry_mode' => 'LIVE',
                'customer_name_snapshot' => 'Unbound', 'customer_phone_snapshot' => '000',
                'area_name_snapshot' => 'Area', 'area_path_snapshot' => 'Area', 'address_line_1_snapshot' => 'Street',
            ]);
            $unbound->update(['branch_id' => $otherBranch->id]);
            $this->assertSame($otherBranch->id, $unbound->fresh()->branch_id);
        });
    }

    private function event(array $fixture, ?Branch $branch): FinancialTransaction
    {
        return app(FinancialPostingService::class)->postEvent(
            $fixture['currency'], 'CUSTOMER_INVOICE', $fixture['invoice']->id, 'ORDER_COMPANY_DELIVERY_SHARE', 1,
            now(), [['account' => $fixture['deposit'], 'direction' => 'DECREASE', 'amount' => '1.00']], $branch,
        );
    }

    private function fixture(): array
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $owner = app(FinancialAccountService::class)->ownerForCaptain($captain);
        $deposit = app(FinancialAccountService::class)->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit);
        $customer = Customer::factory()->create();
        $order = Order::create([
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'order_number' => 'UPGRADE-'.str()->random(8),
            'ordered_at' => now(), 'source' => 'MANUAL', 'entry_mode' => 'LIVE',
            'customer_name_snapshot' => $customer->name, 'customer_phone_snapshot' => '000',
            'area_name_snapshot' => 'Area', 'area_path_snapshot' => 'Area', 'address_line_1_snapshot' => 'Street',
        ]);
        $invoice = Invoice::create([
            'order_id' => $order->id, 'invoice_number' => 'UPGRADE-'.str()->random(8), 'version' => 1,
            'status' => 'DRAFT', 'customer_name_snapshot' => $customer->name, 'customer_phone_snapshot' => '000',
            'address_snapshot' => 'Street', 'order_number_snapshot' => $order->order_number,
            'ordered_at_snapshot' => $order->ordered_at,
        ]);

        return compact('currency', 'branch', 'captain', 'deposit', 'invoice');
    }

    private function newMigration(): object
    {
        return require database_path('migrations/2026_09_28_090000_harden_captain_financial_transaction_branches.php');
    }

    private function sourceContractMigration(): object
    {
        return require database_path('migrations/2026_09_28_100000_enforce_order_financial_source_contract.php');
    }

    private function withOldSchema(callable $callback): void
    {
        $name = (string) config('database.default');
        $key = "database.connections.{$name}.search_path";
        $original = (string) config($key);
        $schema = 'captain_branch_upgrade_'.str()->lower(str()->random(10));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($name);
            DB::reconnect($name);
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (basename($path) < '2026_09_28_090000') {
                    (require $path)->up();
                }
            }
            $callback();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge($name);
            config([$key => $original]);
            DB::reconnect($name);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }
}
