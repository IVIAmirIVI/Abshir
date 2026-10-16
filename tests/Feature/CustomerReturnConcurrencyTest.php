<?php

namespace Tests\Feature;

use App\Services\CustomerCreditService;
use App\Services\CustomerReturnService;
use App\Services\OrderCancellationSafetyService;
use App\Services\OrderWorkflowData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\AccountingV1Fixtures;
use Tests\Support\CustomerReturnFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class CustomerReturnConcurrencyTest extends TestCase
{
    use AccountingV1Fixtures, CustomerReturnFixtures;

    public function test_concurrent_partial_redemptions_cannot_overfund_one_responsible_party(): void
    {
        $this->isolated(function (): void {
            $original = $this->sold(deliveryFee: '100');
            $time = now()->toImmutable();
            $data = $this->data($original) + ['compensation_method' => 'CREDIT'];
            $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $original['assignments'][0]->id, 'amount' => '50'], ['party' => 'COMPANY', 'amount' => '30']];
            $case = app(CustomerReturnService::class)->record($original['actor'], $original['order'], $data);
            $grant = DB::table('customer_credit_entries')->where('source_return_case_id', $case->id)->sole();
            $party = $case->responsibilities()->where('party', 'CAPTAIN')->sole();
            $first = $this->sold(collect: false, deliveryFee: '100', master: $original);
            $second = $this->sold(collect: false, deliveryFee: '100', master: $original);
            $this->travelTo($time->addSecond());
            $jobs = [];
            foreach ([$first, $second] as $c) {
                $jobs[] = ['operation' => 'mixed', 'actor' => $c['actor']->id, 'invoice' => $c['invoice']->id, 'method' => 'SHAM_CASH',
                    'data' => ['request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '30'], 'attributions' => [$grant->id => [$party->id => '30']], 'actual_amount' => '150', 'collected_at' => now()->toIso8601String()]];
            }
            $results = $this->race($first['order']->id, $jobs, $first['order']->customer_id);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertSame('30.0000', DB::scalar('SELECT ret_funded_responsibility(?)', [$party->id]));
            $this->assertSame('50.0000', app(CustomerCreditService::class)->balances($original['actor'], $original['order']->customer)->sole()->available);
        });
    }

    public function test_concurrent_credit_cash_payout_retries_create_one_handover_and_one_entitlement(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold();
            app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
                'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                'reason' => 'ERROR', 'notes' => 'One actual full handover', 'reference' => 'CASH-CREDIT-ONE', 'amount' => '30', 'occurred_at' => now()->toIso8601String(),
            ]);
            $job = ['operation' => 'cash-credit', 'actor' => $c['actor']->id, 'customer' => $c['order']->customer_id, 'proof' => 'one actual receipt',
                'data' => ['request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                    'captain_id' => $c['captains'][0]->id, 'amount' => '30', 'reference' => 'ACTUAL-ONE', 'reason' => 'Full handover from personal funds',
                    'occurred_at' => now()->toIso8601String(), 'personal_cash_confirmed' => true]];
            $results = $this->race($c['order']->id, [$job, $job], $c['order']->customer_id);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertDatabaseCount('customer_credit_cash_payouts', 1);
            $this->assertSame(1, DB::table('financial_transactions')->where('event_type', 'CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE')->count());
            $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        });
    }

    public function test_credit_cash_payout_and_invoice_redemption_cannot_spend_the_same_balance(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold(collect: false, deliveryFee: '100');
            $grant = app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
                'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                'reason' => 'ERROR', 'notes' => 'Exclusive credit', 'reference' => 'EXCLUSIVE-CREDIT', 'amount' => '30', 'occurred_at' => now()->toIso8601String(),
            ]);
            $cash = ['operation' => 'cash-credit', 'actor' => $c['actor']->id, 'customer' => $c['order']->customer_id, 'proof' => 'actual payment',
                'data' => ['request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                    'captain_id' => $c['captains'][0]->id, 'amount' => '30', 'reference' => 'ACTUAL-CASH', 'reason' => 'Full actual handover',
                    'occurred_at' => now()->toIso8601String(), 'personal_cash_confirmed' => true]];
            $mixed = ['operation' => 'mixed', 'actor' => $c['actor']->id, 'invoice' => $c['invoice']->id, 'method' => 'CASH',
                'data' => ['request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '30'], 'actual_amount' => '150', 'collected_at' => now()->toIso8601String()]];
            $results = $this->race($c['order']->id, [$cash, $mixed], $c['order']->customer_id);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertSame('30.0000', DB::scalar('SELECT customer_credit_grant_spent(?)', [$grant->id]));
            $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        });
    }

    public function test_concurrent_mixed_collections_on_different_orders_cannot_spend_the_same_credit(): void
    {
        $this->isolated(function (): void {
            $first = $this->sold(collect: false, deliveryFee: '100');
            $second = $this->sold(collect: false, master: $first);
            $this->travelTo($first['invoice']->issued_at->addSecond());
            $grant = app(CustomerCreditService::class)->grant($first['actor'], $first['order']->customer, [
                'request_key' => (string) str()->uuid(), 'branch_id' => $first['branch']->id, 'currency_id' => $first['currency']->id,
                'reason' => 'ERROR', 'notes' => 'Concurrent mixed credit', 'reference' => 'CREDIT-RACE', 'amount' => '30',
                'occurred_at' => now()->subSecond()->format('Y-m-d H:i:s.uP'),
            ]);
            $jobs = [];
            foreach ([$first, $second] as $c) {
                $jobs[] = ['operation' => 'mixed', 'actor' => $c['actor']->id, 'invoice' => $c['invoice']->id, 'method' => 'CASH',
                    'data' => ['request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '30'],
                        'actual_amount' => '150', 'collected_at' => now()->format('Y-m-d H:i:s.uP'), 'notes' => 'UAT race']];
            }
            $results = $this->race($first['order']->id, $jobs, $first['order']->customer_id);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertDatabaseCount('invoice_collections', 1);
            $this->assertDatabaseCount('customer_credit_settlements', 1);
            $this->assertDatabaseCount('customer_credit_redemptions', 1);
            $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($first['actor'], $first['order']->customer)->sole()->available);
        });
    }

    public function test_concurrent_identical_mixed_collection_is_posted_once(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold(2, collect: false, deliveryFee: '100');
            $grant = app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
                'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                'reason' => 'ERROR', 'notes' => 'Concurrent exact replay', 'reference' => 'CREDIT-REPLAY', 'amount' => '30',
                'occurred_at' => now()->subSecond()->format('Y-m-d H:i:s.uP'),
            ]);
            $job = ['operation' => 'mixed', 'actor' => $c['actor']->id, 'invoice' => $c['invoice']->id, 'method' => 'CASH',
                'data' => ['request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '30'],
                    'actual_amount' => '250', 'collected_at' => now()->format('Y-m-d H:i:s.uP'), 'notes' => 'UAT replay']];
            $results = $this->race($c['order']->id, [$job, $job]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertDatabaseCount('invoice_collections', 1);
            $this->assertDatabaseCount('customer_credit_redemptions', 1);
            $this->assertDatabaseCount('financial_transactions', 1);
            $this->assertDatabaseHas('financial_entries', ['amount' => '170']);
        });
    }

    public function test_concurrent_identical_customer_credit_grants_are_recorded_once(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold();
            $data = ['request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                'source_order_id' => $c['order']->id, 'reason' => 'ERROR', 'notes' => 'One documented incident', 'reference' => 'CREDIT-ONE',
                'amount' => '30', 'occurred_at' => now()->format('Y-m-d H:i:s.uP')];
            $job = ['operation' => 'credit', 'actor' => $c['admin']->id, 'customer' => $c['order']->customer_id, 'data' => $data];
            $results = $this->race($c['order']->id, [$job, $job]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertDatabaseCount('customer_credit_entries', 1);
            $this->assertSame('30.0000', DB::table('customer_credit_entries')->sum('amount'));
        });
    }

    public function test_concurrent_captain_repayments_cannot_exceed_actual_funded_debt(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold(productAmount: '200');
            $data = $this->data($c, '1', '200');
            $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][0]->id, 'amount' => '200']];
            $s = app(CustomerReturnService::class);
            $case = $s->record($c['actor'], $c['order'], $data);
            $s->move($c['actor'], $case, $this->movement($c, '200'), $this->proof('full compensation funded'));
            $jobs = [];
            foreach (['first', 'second'] as $tag) {
                $jobs[] = ['operation' => 'move', 'actor' => $c['actor']->id, 'case' => $case->id,
                    'data' => $this->movement($c, '150', 'RECOVERY') + ['responsibility_id' => $case->responsibilities()->sole()->id], 'proof' => $tag];
            }
            $results = $this->race($c['order']->id, $jobs);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertSame('150.0000', DB::table('customer_return_movements')->where('kind', 'RECOVERY')->sum('amount'));
            $this->assertSame('50.0000', $s->summary($c['actor'], $c['order'])->sole()->responsibilities->sole()->outstanding);
        });
    }

    public function test_new_return_and_legacy_correction_cannot_both_register_concurrently(): void
    {
        $this->isolated(function (array $c): void {
            $results = $this->race($c['order']->id, [
                ['operation' => 'record', 'actor' => $c['actor']->id, 'order' => $c['order']->id, 'data' => $this->data($c)],
                ['operation' => 'correction', 'actor' => $c['actor']->id, 'invoice' => $c['invoice']->id, 'key' => (string) str()->uuid(),
                    'proposal' => ['items' => [['invoice_item_id' => $c['invoice']->items()->sole()->id, 'unit_price' => '40']],
                        'delivery' => [['order_captain_id' => $c['assignments'][0]->id, 'customer_amount' => '10']]]],
            ]);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertSame(1, DB::table('customer_return_cases')->count() + DB::table('invoice_financial_corrections')->count());
            $this->assertDatabaseCount('customer_return_movements', 0);
        }, historical: true);
    }

    public function test_concurrent_refunds_cannot_both_spend_the_same_remaining_amount(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold();
            $case = app(CustomerReturnService::class)->record($c['actor'], $c['order'], $this->data($c));
            $jobs = [];
            foreach (['first', 'second'] as $tag) {
                $jobs[] = ['operation' => 'move', 'actor' => $c['actor']->id, 'case' => $case->id, 'data' => $this->movement($c, '80'), 'proof' => $tag];
            }
            $results = $this->race($c['order']->id, $jobs);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertSame('80.0000', DB::table('customer_return_movements')->sum('amount'));
            $this->assertCount(1, Storage::disk('customer_refunds')->allFiles());
        });
    }

    public function test_concurrent_identical_receipt_reuses_one_immutable_payment(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold();
            $case = app(CustomerReturnService::class)->record($c['actor'], $c['order'], $this->data($c));
            $job = ['operation' => 'move', 'actor' => $c['actor']->id, 'case' => $case->id, 'data' => $this->movement($c, '80'), 'proof' => 'same receipt'];
            $results = $this->race($c['order']->id, [$job, $job]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertDatabaseCount('customer_return_movements', 1);
            $this->assertCount(1, Storage::disk('customer_refunds')->allFiles());
        });
    }

    public function test_concurrent_returns_cannot_claim_the_same_product_quantity_twice(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold();
            $results = $this->race($c['order']->id, [
                ['operation' => 'record', 'actor' => $c['actor']->id, 'order' => $c['order']->id, 'data' => $this->data($c, '0.75', '60')],
                ['operation' => 'record', 'actor' => $c['actor']->id, 'order' => $c['order']->id, 'data' => $this->data($c, '0.75', '60')],
            ]);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertDatabaseCount('customer_return_cases', 1);
        });
    }

    public function test_return_credit_and_personal_cash_handover_use_a_consistent_lock_order(): void
    {
        $this->isolated(function (): void {
            $c = $this->sold();
            app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
                'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                'reason' => 'ERROR', 'notes' => 'Prior independent credit', 'reference' => 'LOCK-ORDER-CREDIT', 'amount' => '30', 'occurred_at' => now()->toIso8601String(),
            ]);
            $cash = ['operation' => 'cash-credit', 'actor' => $c['actor']->id, 'customer' => $c['order']->customer_id, 'proof' => 'personal handover before new grant',
                'data' => ['request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
                    'captain_id' => $c['captains'][0]->id, 'amount' => '30', 'reference' => 'LOCK-ORDER-HANDOVER', 'reason' => 'Entire prior balance physically paid',
                    'occurred_at' => now()->toIso8601String(), 'personal_cash_confirmed' => true]];
            $return = ['operation' => 'record', 'actor' => $c['actor']->id, 'order' => $c['order']->id,
                'data' => $this->data($c) + ['compensation_method' => 'CREDIT']];

            $results = $this->race($c['order']->id, [$cash, $return], $c['order']->customer_id, ordered: true);

            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertDatabaseCount('customer_credit_cash_payouts', 1);
            $this->assertDatabaseCount('customer_return_cases', 1);
            $this->assertSame('80.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        });
    }

    public function test_delivered_workflow_read_does_not_wait_for_order_row_locks(): void
    {
        $this->assertWorkflowEligibilityIsReadOnly(true);
    }

    public function test_issued_open_workflow_read_does_not_wait_for_invoice_row_locks(): void
    {
        $this->assertWorkflowEligibilityIsReadOnly(false);
    }

    private function assertWorkflowEligibilityIsReadOnly(bool $delivered): void
    {
        $this->isolated(function () use ($delivered): void {
            $c = $this->sold(collect: $delivered);
            $history = DB::table('financial_entries')->orderBy('id')->get()->toJson();
            config(['database.connections.workflow_blocker' => config('database.connections.pgsql')]);
            $blocker = DB::connection('workflow_blocker');
            $this->assertSame('abshir_testing', $blocker->scalar('SELECT current_database()'));
            $blocker->beginTransaction();
            try {
                $blocker->table($delivered ? 'orders' : 'invoices')->where('id', $delivered ? $c['order']->id : $c['invoice']->id)->lockForUpdate()->sole();
                // Test connection only: fail promptly if a read attempts to acquire a conflicting lock.
                DB::statement("SET lock_timeout = '200ms'");
                $data = app(OrderWorkflowData::class)->load($c['actor'], $c['order']->fresh());
                $this->assertFalse($data['cancellationUi']['allowed']);
                $this->assertNotEmpty($data['cancellationUi']['problem']);
                $this->assertSame($history, DB::table('financial_entries')->orderBy('id')->get()->toJson());
                if (! $delivered) {
                    try {
                        app(OrderCancellationSafetyService::class)->assertNoFinancialCorrectionRequiredLocked($c['order']->fresh());
                        $this->fail('The mutation guard must retain its default Invoice lock.');
                    } catch (QueryException $exception) {
                        $this->assertSame('55P03', $exception->errorInfo[0]);
                    }
                }
            } finally {
                DB::statement('SET lock_timeout = DEFAULT');
                $blocker->rollBack();
                DB::purge('workflow_blocker');
                config(['database.connections.workflow_blocker' => null]);
            }
        });
    }

    private function race(int $orderId, array $jobs, ?int $customerId = null, bool $ordered = false): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            if ($customerId !== null) {
                DB::table('customers')->where('id', $customerId)->lockForUpdate()->sole();
            } else {
                DB::table('orders')->where('id', $orderId)->lockForUpdate()->sole();
            }
            foreach ($jobs as $job) {
                $job['tag'] = 'ret_'.bin2hex(random_bytes(6));
                $job['storage_root'] = Storage::disk('customer_refunds')->path('');
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'memory_limit=512M', base_path('tests/Support/customer_return_worker.php'), base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'abshir_testing', 'DB_URL' => '', 'DB_SCHEMA' => config('database.connections.pgsql.search_path'),
                ]);
                $process->setTimeout(40);
                $process->start();
                $workers[] = ['process' => $process, 'tag' => $job['tag']];
                if ($ordered) {
                    $deadline = microtime(true) + 15;
                    do {
                        DB::select('SELECT pg_stat_clear_snapshot()');
                        $waiting = DB::table('pg_stat_activity')->where('application_name', $job['tag'])->where('wait_event_type', 'Lock')->exists();
                        if ($waiting) {
                            break;
                        }
                        usleep(20_000);
                    } while (microtime(true) < $deadline);
                    $this->assertTrue($waiting, 'Ordered worker must be waiting on the database lock before the next worker starts.');
                }
            }
            $deadline = microtime(true) + 15;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = DB::table('pg_stat_activity')->whereIn('application_name', array_column($workers, 'tag'))->where('wait_event_type', 'Lock')->count();
                if ($blocked === 2) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both real service processes must contend on the locked Order or Customer before release.');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput());
                $results[] = json_decode($worker['process']->getOutput(), true, flags: JSON_THROW_ON_ERROR);
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
        }
    }

    private function isolated(callable $action, bool $historical = false): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        $key = 'database.connections.pgsql.search_path';
        $original = config($key);
        $schema = 'mc_ret_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge('pgsql');
            if ($historical) {
                $this->migrateAccountingV1Schema();
                $context = $this->sold(enableV3: false);
            }
            Artisan::call('migrate', ['--force' => true]);
            if ($historical) {
                $action($context);
            } else {
                $action();
            }
        } finally {
            TestDatabaseSafety::assertSafe($this->app);
            DB::purge('pgsql');
            config([$key => $original]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
