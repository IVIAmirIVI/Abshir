<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Models\OrderShopPurchaseConfirmation;
use App\Services\BillPaymentFeeRuleService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderReturnEvidenceService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderShopPurchaseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\SharedPurchaseFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class SharedOrderShopPurchaseConcurrencyTest extends TestCase
{
    use SharedPurchaseFixtures;

    public function test_real_shared_purchase_retries_contend_and_create_one_complete_source(): void
    {
        $this->isolated(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $c = $this->sharedContext();
            $data = $this->sharedData($c, '80.01');
            $results = $this->race($c, ['shared', 'shared'], [$data, $data]);
            $this->assertSame(['success', 'success'], array_column($results, 'status'), json_encode($results));
            $this->assertSame($results[0]['id'], $results[1]['id']);
            $this->assertSame(1, DB::table('order_shop_purchase_confirmations')->count());
            $this->assertSame(2, DB::table('order_shop_purchase_allocations')->count());
            $this->assertSame(['40.01', '40.00'], DB::table('order_shop_purchase_allocations')->orderBy('order_captain_id')->pluck('amount')->all());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_real_shared_and_legacy_confirmations_cannot_both_win(): void
    {
        $this->isolated(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $c = $this->sharedContext();
            $results = $this->race($c, ['shared', 'single'], [$this->sharedData($c),
                $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id]]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $loser = collect($results)->firstWhere('status', 'error');
            $this->assertSame(ValidationException::class, $loser['class'], json_encode($results));
            $purchase = OrderShopPurchaseConfirmation::sole();
            $this->assertSame($purchase->purchase_mode === 'SINGLE_PAYER' ? 0 : 2, DB::table('order_shop_purchase_allocations')->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_additive_upgrade_preserves_existing_single_payer_source_and_refuses_rollback(): void
    {
        $this->isolated(function (): void {
            $path = 'database/migrations/2026_10_09_000000_add_shared_shop_purchase_foundation.php';
            $paths = collect(glob(database_path('migrations/*.php')))->filter(fn ($file) => basename($file) !== basename($path) && basename($file) < '2026_10_09_030000')
                ->map(fn ($file) => 'database/migrations/'.basename($file))->all();
            Artisan::call('migrate', ['--path' => $paths, '--force' => true]);
            Storage::fake('order_returns');
            $c = $this->sharedContext(2, 1);
            $purchase = app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'],
                $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
            $before = $purchase->getAttributes();
            Artisan::call('migrate', ['--path' => $path, '--force' => true]);
            $after = $purchase->fresh()->getAttributes();
            $this->assertSame('SINGLE_PAYER', $after['purchase_mode']);
            $this->assertNull($after['collecting_order_captain_id']);
            unset($after['purchase_mode'], $after['collecting_order_captain_id']);
            $this->assertSame($before, $after);
            $this->assertSame(0, DB::table('order_shop_purchase_allocations')->count());
            $this->assertThrows(fn () => (require base_path($path))->down(), \RuntimeException::class);
            $this->assertSame($before['amount'], $purchase->fresh()->amount);
        });
    }

    public function test_global_purchase_key_contends_across_orders_with_a_controlled_conflict(): void
    {
        $this->isolated(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $first = $this->sharedContext();
            $second = $this->sharedContext();
            $data = $this->sharedData($first);
            $other = array_replace($this->sharedData($second), ['request_key' => strtoupper($data['request_key'])]);
            $results = $this->race($first, ['shared', 'shared'], [$data, $other], $second);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $this->assertSame(1, DB::table('order_shop_purchase_confirmations')->count());
            $this->assertSame(2, DB::table('order_shop_purchase_allocations')->count());
            $this->assertSame(['40.00', '40.00'], DB::table('order_shop_purchase_allocations')->orderBy('order_captain_id')->pluck('amount')->all());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_shared_and_single_payer_global_key_race_is_a_controlled_conflict(): void
    {
        $this->isolated(function (): void {
            Artisan::call('migrate', ['--force' => true]);
            $first = $this->sharedContext();
            $second = $this->sharedContext(2, 1);
            $data = $this->sharedData($first);
            $other = array_replace($this->sharedData($second), ['request_key' => strtoupper($data['request_key']),
                'order_captain_id' => $second['assignments'][0]->id]);
            $results = $this->race($first, ['shared', 'single'], [$data, $other], $second);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $purchase = OrderShopPurchaseConfirmation::sole();
            $this->assertSame($purchase->purchase_mode === 'SINGLE_PAYER' ? 0 : 2, DB::table('order_shop_purchase_allocations')->count());
            $this->assertSame(0, DB::table('financial_transactions')->count());
        });
    }

    public function test_real_shared_collections_contend_and_only_one_full_custody_source_wins(): void
    {
        $this->isolated(function (): void {
            [$c, $invoice] = $this->sharedInvoice();
            $data = ['invoice_id' => $invoice->id, 'method' => 'CASH', 'amount' => '100', 'currency_id' => $c['currency']->id, 'collected_at' => now()->toIso8601String()];
            $results = $this->race($c, ['collection', 'collection'], [$data, $data]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $this->assertSame(1, DB::table('invoice_collections')->count());
            $this->assertSame(1, DB::table('financial_transactions')->count());
            $this->assertSame(1, DB::table('financial_entries')->count());
            DB::select('SELECT assert_shared_order_financial_set(?)', [$c['order']->id]);
        });
    }

    public function test_real_deliveries_contend_and_each_shared_claim_is_posted_once(): void
    {
        $this->isolated(function (): void {
            [$c, $invoice] = $this->sharedInvoice();
            app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
            app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
            app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash, '100', $c['currency']->id, now());
            foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER'] as $target) {
                app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($target), null);
            }
            $results = $this->race($c, ['delivery', 'delivery'], [[], []]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $this->assertSame(3, DB::table('financial_transactions')->count());
            $this->assertSame(5, DB::table('financial_entries')->count());
            $this->assertSame(1, $c['order']->statusHistory()->where('new_status', 'DELIVERED')->count());
            DB::select('SELECT assert_shared_order_financial_set(?)', [$c['order']->id]);
        });
    }

    private function sharedInvoice(bool $migrate = true): array
    {
        if ($migrate) {
            Artisan::call('migrate', ['--force' => true]);
        }
        $c = $this->sharedContext();
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        $service = app(InvoiceService::class);
        $invoice = $service->issue($c['actor'], $c['order'], $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order'])));

        return [$c, $invoice];
    }

    public function test_exception_upgrade_preserves_existing_shared_cash_and_single_bill_financial_history(): void
    {
        $this->isolated(function (): void {
            $prior = array_values(array_filter(glob(database_path('migrations/*.php')),
                fn ($path) => basename($path) < '2026_10_09_060000'));
            Artisan::call('migrate', ['--path' => $prior, '--realpath' => true, '--force' => true]);
            [$shared, $invoice] = $this->sharedInvoice(false);
            app(InvoiceCollectionService::class)->record($shared['actor'], $invoice, InvoiceCollectionMethod::Cash, '100', $shared['currency']->id, now());
            $single = $this->sharedContext(4, 1);
            app(OrderShopPurchaseConfirmationService::class)->record($single['actor'], $single['order'], $single['shop'],
                $this->sharedData($single) + ['order_captain_id' => $single['assignments'][0]->id], $this->sharedImage());
            app(OrderShopReceiptService::class)->upload($single['actor'], $single['order'], $single['shop'], $this->sharedImage(), null);
            app(OrderShopProgressService::class)->record($single['actor'], $single['order'], $single['shop'], 'picked_up_at');
            app(BillPaymentFeeRuleService::class)->create($single['admin'], $single['currency']->id, '2', now()->subDay());
            $request = app(OrderBillPaymentService::class)->request($single['actor'], $single['order'], $single['currency']->id);
            foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
                app(OrderStatusService::class)->transition($single['actor'], $single['order'], OrderOperationalStatus::from($status), null);
            }
            $payment = app(OrderBillPaymentService::class)->complete($single['actor'], $single['order'], $request, [
                'order_captain_id' => $single['assignments'][0]->id, 'currency_id' => $single['currency']->id,
                'bill_number' => 'LEGACY', 'bill_date' => today()->toDateString(), 'principal_amount' => '100',
                'payment_reference' => 'SHAM-LEGACY',
            ], [$this->sharedImage()]);
            $tables = ['orders', 'order_captains', 'order_captain_selections', 'order_shop_purchase_confirmations',
                'order_shop_purchase_allocations', 'invoices', 'invoice_collections', 'order_financial_source_snapshots',
                'order_financial_source_snapshot_lines', 'order_bill_payment_requests', 'order_bill_payments',
                'order_bill_payment_evidence', 'order_bill_payment_principal_handovers', 'financial_accounts', 'financial_transactions', 'financial_entries'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
            foreach (['060000_enable_shared_collector_bill_payments', '070000_allow_uncollected_shared_invoice_versions'] as $name) {
                $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_09_'.$name.'.php', '--force' => true]));
            }
            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
            }
            DB::select('SELECT assert_shared_order_financial_set(?)', [$shared['order']->id]);
            DB::select('SELECT validate_completed_bill_payment_at_commit(?)', [$payment->id]);
            DB::select('SELECT validate_financial_snapshot_fingerprint(?)', [DB::table('order_financial_source_snapshots')->value('id')]);
            $this->assertSame('O', DB::scalar("SELECT t.tgenabled FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND t.tgname='completed_bill_payment_commit_guard'"));
        });
    }

    public function test_real_shared_bill_completions_contend_without_duplicate_financial_sources(): void
    {
        $this->isolated(function (): void {
            [$c, $data] = $this->sharedBill();
            $results = $this->race($c, ['bill_complete', 'bill_complete'], [$data, $data]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $this->assertSame(1, DB::table('order_bill_payments')->count());
            $this->assertSame(1, DB::table('order_bill_payment_principal_handovers')->count());
            $this->assertSame(1, DB::table('order_bill_payment_evidence')->count());
            $this->assertSame(3, DB::table('financial_transactions')->count());
            $this->assertSame(3, DB::table('financial_entries')->count());
            DB::select('SELECT validate_completed_bill_payment_at_commit(?)', [DB::table('order_bill_payments')->value('id')]);
        });
    }

    public function test_real_shared_bill_cancellation_and_completion_cannot_both_commit(): void
    {
        $this->isolated(function (): void {
            [$c, $data] = $this->sharedBill();
            $results = $this->race($c, ['bill_complete', 'bill_cancel'], [$data, $data]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $completed = DB::table('order_bill_payment_requests')->where('id', $data['request_id'])->value('status') === 'COMPLETED';
            $this->assertSame($completed ? 1 : 0, DB::table('order_bill_payments')->count());
            $this->assertSame($completed ? 1 : 0, DB::table('order_bill_payment_evidence')->count());
            $this->assertSame($completed ? 3 : 0, DB::table('financial_transactions')->count());
        });
    }

    public function test_real_shared_invoice_replacement_and_collection_cannot_both_commit_on_original(): void
    {
        $this->isolated(function (): void {
            [$c, $invoice] = $this->sharedInvoice();
            // Replacement is an existing admin permission; both workers must be authorized before competing.
            $c['actor'] = $c['admin'];
            $data = ['invoice_id' => $invoice->id, 'method' => 'CASH', 'amount' => '100', 'currency_id' => $c['currency']->id, 'collected_at' => now()->toIso8601String()];
            $results = $this->race($c, ['invoice_replace', 'collection'], [$data, $data]);
            $this->assertSame(1, collect($results)->where('status', 'success')->count(), json_encode($results));
            $this->assertSame(ValidationException::class, collect($results)->firstWhere('status', 'error')['class']);
            $collected = DB::table('invoice_collections')->where('invoice_id', $invoice->id)->exists();
            $this->assertSame($collected ? 'ISSUED' : 'REPLACED', $invoice->fresh()->status->value);
            $this->assertSame($collected ? 1 : 2, DB::table('invoices')->count());
            $this->assertSame($collected ? 1 : 0, DB::table('financial_transactions')->count());
            $this->assertSame(1, $c['order']->currentInvoice()->count());
            DB::select('SELECT validate_financial_snapshot_fingerprint(?)', [DB::table('order_financial_source_snapshots')->value('id')]);
        });
    }

    private function sharedBill(): array
    {
        [$c] = $this->sharedInvoice();
        app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '2', now()->subDay());
        $request = app(OrderBillPaymentService::class)->request($c['actor'], $c['order'], $c['currency']->id);
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }

        return [$c, ['request_id' => $request->id, 'order_captain_id' => $c['assignments'][0]->id,
            'currency_id' => $c['currency']->id, 'principal_amount' => '100', 'bill_number' => 'SHARED',
            'bill_date' => today()->toDateString(), 'payment_reference' => 'SHAM-SHARED']];
    }

    private function race(array $c, array $operations, array $data, ?array $secondContext = null): array
    {
        $directory = sys_get_temp_dir().'/abshir-mc1-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $tag = 'mc1-'.bin2hex(random_bytes(6));
        $workers = [];
        $database = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_DATABASE' => 'abshir_testing',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'DB_SCHEMA' => $database['search_path']];
        DB::beginTransaction();
        if ($secondContext) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['order-shop-purchase:'.$data[0]['request_key']]);
        } else {
            DB::table('orders')->where('id', $c['order']->id)->lockForUpdate()->sole();
        }
        $parentPid = (int) DB::scalar('SELECT pg_backend_pid()');
        try {
            foreach ([0, 1] as $index) {
                $context = $index === 1 && $secondContext ? $secondContext : $c;
                $payloadPath = $directory.'/payload-'.$index.'.json';
                $readyPath = $directory.'/ready-'.$index;
                $resultPath = $directory.'/result-'.$index.'.json';
                file_put_contents($payloadPath, json_encode(['tag' => $tag, 'operation' => $operations[$index],
                    'actor_id' => $context['actor']->id, 'order_id' => $context['order']->id, 'shop_id' => $context['shop']->id, 'data' => $data[$index]], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/shared_purchase_worker.php'),
                    $payloadPath, $readyPath, $directory.'/go', $resultPath], base_path(), $environment);
                $process->setTimeout(30);
                $process->start();
                $workers[] = compact('process', 'readyPath', 'resultPath');
            }
            $deadline = microtime(true) + 15;
            while (! collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])) && microtime(true) < $deadline) {
                usleep(25_000);
            }
            $this->assertTrue(collect($workers)->every(fn ($worker) => file_exists($worker['readyPath'])), 'Both independent Laravel processes must bootstrap.');
            touch($directory.'/go');
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                // PostgreSQL may queue the second tuple-lock waiter behind the first worker,
                // rather than report the parent as its immediate blocker. Follow the real lock graph.
                $blocked = (int) DB::scalar('WITH RECURSIVE waiting(pid) AS (
                    SELECT CAST(? AS integer) UNION
                    SELECT a.pid FROM pg_stat_activity a JOIN waiting w ON w.pid=ANY(pg_blocking_pids(a.pid))
                ) SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND pid IN (SELECT pid FROM waiting)', [$parentPid, $tag]);
                if ($blocked === 2) {
                    break;
                }
                usleep(25_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both real services must contend on the parent Order lock before either can commit.');
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
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function isolated(callable $action): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        $key = 'database.connections.pgsql.search_path';
        $original = config($key);
        $schema = 'mc1_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
            $action();
        } finally {
            if (Schema::hasTable('order_bill_payment_evidence')) {
                foreach (DB::table('order_bill_payment_evidence')->pluck('storage_path') as $path) {
                    Storage::disk(OrderBillPaymentService::DISK)->delete($path);
                }
            }
            if (Schema::hasTable('order_shop_purchase_confirmations')) {
                foreach (DB::table('order_shop_purchase_confirmations')->pluck('evidence_path') as $path) {
                    app(OrderReturnEvidenceService::class)->delete($path);
                }
            }
            if (Schema::hasTable('order_shop_receipts')) {
                foreach (DB::table('order_shop_receipts')->pluck('storage_path') as $path) {
                    Storage::disk('order_receipts')->delete($path);
                }
            }
            DB::purge('pgsql');
            config([$key => $original]);
            DB::reconnect('pgsql');
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
