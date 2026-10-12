<?php

namespace Tests\Feature;

use App\Models\CaptainBenefitRequest;
use App\Models\CaptainBenefitReview;
use App\Models\FinancialTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CaptainBenefitFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class CaptainBenefitConcurrencyTest extends TestCase
{
    use CaptainBenefitFixtures;

    public function test_real_approval_and_payout_races_share_locks_without_duplicate_or_excessive_effects(): void
    {
        $this->isolated(function (): void {
            $c = $this->benefitContext();
            $this->benefitAction($c, 'SUBMIT');
            $approvalJobs = [];
            for ($index = 0; $index < 2; $index++) {
                $approvalJobs[] = ['operation' => 'review', 'actor' => $c['reviewer']->id, 'proposal' => $c['proposal']->id,
                    'data' => ['request_key' => (string) Str::uuid(), 'action' => 'APPROVE']];
            }
            $results = $this->race($c, $approvalJobs);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertSame(1, CaptainBenefitReview::where('action', 'APPROVE')->count());
            $this->assertSame(1, FinancialTransaction::count());
            $entry = FinancialTransaction::sole()->entries()->sole();
            $payoutJobs = [];
            for ($index = 0; $index < 2; $index++) {
                $payoutJobs[] = ['operation' => 'payout', 'actor' => $c['reviewer']->id, 'proposal' => $c['proposal']->id,
                    'data' => ['request_key' => (string) Str::uuid(), 'amount' => '15', 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
                        'payment_method' => 'CASH', 'received_by_user_id' => $c['captain']->user_id],
                    'allocations' => [['financial_entry_id' => $entry->id, 'amount' => '15']]];
            }
            $results = $this->race($c, $payoutJobs);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $this->assertSame('15.0000', (string) DB::table('captain_receivable_payout_allocations')->sum('amount'));
            $this->assertSame(1, DB::table('captain_receivable_payouts')->count());

            $another = $this->benefitContext();
            $this->benefitAction($another, 'SUBMIT');
            $this->benefitAction($another, 'APPROVE');
            $approved = FinancialTransaction::where('source_type', 'CAPTAIN_BENEFIT_APPROVAL')->latest('id')->firstOrFail();
            $entry = $approved->entries()->sole();
            $results = $this->race($another, [
                ['operation' => 'review', 'actor' => $another['reviewer']->id, 'proposal' => $another['proposal']->id,
                    'data' => ['request_key' => (string) Str::uuid(), 'action' => 'REVERSE', 'reason' => 'Race against payment']],
                ['operation' => 'payout', 'actor' => $another['reviewer']->id, 'proposal' => $another['proposal']->id,
                    'data' => ['request_key' => (string) Str::uuid(), 'amount' => '20', 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
                        'payment_method' => 'CASH', 'received_by_user_id' => $another['captain']->user_id],
                    'allocations' => [['financial_entry_id' => $entry->id, 'amount' => '20']]],
            ]);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['success', 'validation'], $statuses, json_encode($results));
            $reversed = FinancialTransaction::where('reversal_of_transaction_id', $approved->id)->exists();
            $paid = DB::table('captain_receivable_payout_allocations')->where('financial_entry_id', $entry->id)->exists();
            $this->assertNotSame($reversed, $paid);
        });
    }

    private function race(array $c, array $jobs): array
    {
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table('currencies')->where('id', $c['currency']->id)->lockForUpdate()->sole();
            foreach ($jobs as $job) {
                $job['tag'] = 'benefit_'.bin2hex(random_bytes(6));
                $job['storage_root'] = Storage::disk('captain_financial_evidence')->path('');
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'memory_limit=512M',
                    base_path('tests/Support/captain_benefit_worker.php'), base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], base_path(), [
                        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'abshir_testing', 'DB_URL' => '',
                        'DB_SCHEMA' => config('database.connections.pgsql.search_path'),
                    ]);
                $process->setTimeout(40);
                $process->start();
                $workers[] = ['process' => $process, 'tag' => $job['tag']];
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
            $this->assertSame(2, $blocked, 'Both real independent services must contend on the Currency row.');
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

    public function test_draft_creation_and_existing_source_payout_do_not_fail_on_opposing_currency_captain_locks(): void
    {
        $this->isolated(function (): void {
            $c = $this->benefitContext();
            $this->benefitAction($c, 'SUBMIT');
            $this->benefitAction($c, 'APPROVE');
            $entry = FinancialTransaction::sole()->entries()->sole();
            $results = $this->race($c, [
                ['operation' => 'payout', 'actor' => $c['reviewer']->id, 'proposal' => $c['proposal']->id,
                    'data' => ['request_key' => (string) Str::uuid(), 'amount' => '5', 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
                        'payment_method' => 'CASH', 'received_by_user_id' => $c['captain']->user_id],
                    'allocations' => [['financial_entry_id' => $entry->id, 'amount' => '5']]],
                ['operation' => 'draft', 'actor' => $c['creator']->id, 'proposal' => $c['proposal']->id,
                    'data' => ['request_key' => (string) Str::uuid(), 'kind' => 'BONUS', 'branch_id' => $c['branch']->id,
                        'currency_id' => $c['currency']->id, 'amount' => '2', 'description' => 'New proposed draft']],
            ]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame(2, CaptainBenefitRequest::count());
            $this->assertSame(2, FinancialTransaction::count());
            $this->assertSame('5.0000', (string) DB::table('captain_receivable_payout_allocations')->sum('amount'));
        });
    }

    private function isolated(callable $action): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        $key = 'database.connections.pgsql.search_path';
        $original = config($key);
        $schema = 'mc_benefit_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge('pgsql');
            Artisan::call('migrate', ['--force' => true]);
            $action();
        } finally {
            TestDatabaseSafety::assertSafe($this->app);
            DB::purge('pgsql');
            config([$key => $original]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
