<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Read-only inventory; never activates migrations, repairs data or certifies go-live. */
class ReleasePreflight extends Command
{
    protected $signature = 'abshir:release-preflight {--database= : Approved database identity to inspect} {--production : Also check production settings} {--json : Emit a redacted JSON report}';

    protected $description = 'Inspect release prerequisites and pending migrations without changing database or configuration.';

    public function handle(): int
    {
        $report = ['preparation_only' => true, 'checks' => [], 'pending_migrations' => [], 'counts' => []];
        try {
            $configured = DB::connection();
            $config = $configured->getConfig();
            $target = $this->option('database') ?: $config['database'];
            $allowed = ['abshir', 'abshir_uat', 'abshir_uat_v2', 'abshir_testing'];
            if ($this->option('production') && app()->environment('production')) {
                $allowed[] = $config['database'];
            }
            if (($config['driver'] ?? null) !== 'pgsql' || ! in_array($target, $allowed, true)
                || str_contains($target, 'cleanup_preview')) {
                throw new \RuntimeException('Database target is not an approved PostgreSQL identity.');
            }
            if ($target === 'abshir_testing' && ! app()->environment('testing')) {
                throw new \RuntimeException('Testing inspection requires the isolated testing environment.');
            }
            $connection = $target === $config['database'] ? $configured : DB::build(array_replace($config, ['url' => null, 'database' => $target]));
            if ($connection->transactionLevel() > 0 && ! app()->environment('testing')) {
                throw new \RuntimeException('Use a dedicated read-only preflight process outside an application transaction.');
            }
            $report += $connection->transaction(function () use ($connection, $target): array {
                if ($connection->transactionLevel() === 1) {
                    $connection->statement('SET TRANSACTION READ ONLY');
                }
                $identity = $connection->selectOne('SELECT current_database() AS database, current_schema() AS schema, version() AS version');
                if ($identity->database !== $target || ($target !== 'abshir_testing' && $identity->schema !== 'public')
                    || ($target === 'abshir_testing' && ! preg_match('/^mc_testing_[a-f0-9]+$/D', $identity->schema))) {
                    throw new \RuntimeException('Database/schema identity mismatch; inspection stopped.');
                }

                return ['database' => $identity->database, 'schema' => $identity->schema, 'server_version' => $identity->version,
                    'inventory' => $this->inventory($connection)];
            });
            $report['pending_migrations'] = $report['inventory']['pending_migrations'];
            $report['counts'] = $report['inventory']['counts'];
            $report['checks'] = $report['inventory']['checks'];
            unset($report['inventory']);
            if ($this->option('production')) {
                $report['checks'] += $this->productionChecks();
            }
            $report['ready_for_next_review'] = ! in_array(false, $report['checks'], true) && $report['pending_migrations'] === [];
        } catch (Throwable $exception) {
            // No PDO exception text: it can contain database addresses or credentials.
            $report['ready_for_next_review'] = false;
            $report['error'] = $exception instanceof \RuntimeException && ! $exception instanceof \PDOException
                && ! $exception instanceof QueryException ? $exception->getMessage() : 'Read-only inspection failed; inspect protected server logs.';
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('Read-only release preparation — not deployment approval');
            $this->line('Database: '.($report['database'] ?? 'not verified').' / '.($report['schema'] ?? 'not verified'));
            foreach ($report['checks'] as $key => $value) {
                $this->line(($value ? 'PASS ' : 'PENDING ').$key);
            }
            foreach ($report['pending_migrations'] as $migration) {
                $this->line('PENDING migration: '.$migration);
            }
            if (isset($report['error'])) {
                $this->error($report['error']);
            }
        }

        return $report['ready_for_next_review'] ? self::SUCCESS : self::FAILURE;
    }

    private function inventory(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $installed = $schema->hasTable('migrations') ? $connection->table('migrations')->pluck('migration')->all() : [];
        $files = array_map(fn ($file) => pathinfo($file, PATHINFO_FILENAME), glob(database_path('migrations/*.php')));
        sort($files);
        $counts = [];
        foreach (['users', 'roles', 'permissions', 'branches', 'captains', 'customers', 'shops', 'products', 'currencies',
            'orders', 'invoices', 'invoice_collections', 'financial_transactions', 'financial_entries', 'shop_commission_transactions', 'captain_benefit_requests', 'notifications'] as $table) {
            $counts[$table] = $schema->hasTable($table) ? $connection->table($table)->count() : null;
        }
        $namespace = $connection->selectOne('SELECT current_schema() AS name')->name;
        $invalidFks = $connection->selectOne("SELECT COUNT(*) AS total FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname=? AND c.contype='f' AND NOT c.convalidated", [$namespace])->total;
        $disabledTriggers = $connection->selectOne("SELECT COUNT(*) AS total FROM pg_trigger t JOIN pg_class r ON r.oid=t.tgrelid JOIN pg_namespace n ON n.oid=r.relnamespace WHERE n.nspname=? AND t.tgenabled='D'", [$namespace])->total;
        $checks = ['migration_repository_present' => $schema->hasTable('migrations'), 'foreign_keys_validated' => (int) $invalidFks === 0,
            'triggers_enabled' => (int) $disabledTriggers === 0, 'accounting_v2_column' => $schema->hasColumn('orders', 'accounting_contract_version')];
        foreach (['validate_financial_transaction_finalization(bigint)', 'calculated_bill_payment_fee(bigint,numeric)'] as $function) {
            $checks['function:'.$function] = $connection->selectOne('SELECT to_regprocedure(?) IS NOT NULL AS present', [$function])->present;
        }

        return ['pending_migrations' => array_values(array_diff($files, $installed)), 'counts' => $counts, 'checks' => $checks];
    }

    private function productionChecks(): array
    {
        $privateRoots = [];
        $disks = config('filesystems.disks');
        foreach ($disks as $name => $disk) {
            if (in_array($name, ['order_receipts', 'order_bill_payments', 'captain_financial_evidence', 'customer_refunds', 'captain_receivable_recoveries', 'captain_deposit_manual_adjustments', 'order_returns'], true)) {
                $root = isset($disk['root']) ? realpath($disk['root']) : false;
                $safe = ($disk['driver'] ?? null) === 'local' && $root !== false
                    && ($disk['serve'] ?? false) === false && ($disk['visibility'] ?? 'private') !== 'public';
                $webRoot = realpath(public_path());
                if ($root && $webRoot && str_starts_with(strtolower(str_replace('\\', '/', $root)).'/', strtolower(str_replace('\\', '/', $webRoot)).'/')) {
                    $safe = false;
                }
                foreach ($disks as $other) {
                    if (($other['serve'] ?? false) || ($other['visibility'] ?? null) === 'public') {
                        $served = isset($other['root']) ? realpath($other['root']) : false;
                        if ($root && $served && str_starts_with(strtolower(str_replace('\\', '/', $root)).'/', strtolower(str_replace('\\', '/', $served)).'/')) {
                            $safe = false;
                        }
                    }
                }
                $privateRoots[$name] = $safe;
            }
        }

        return collect($privateRoots)->mapWithKeys(fn ($safe, $name) => ['private_evidence_disk:'.$name => $safe])->all()
            + ['production_environment' => app()->environment('production'), 'debug_disabled' => config('app.debug') === false,
                'https_url' => str_starts_with((string) config('app.url'), 'https://'), 'application_key_present' => filled(config('app.key')),
                'secure_session_cookie' => config('session.secure') === true, 'http_only_cookie' => config('session.http_only') === true,
                'same_site_cookie' => in_array(config('session.same_site'), ['lax', 'strict'], true),
                'durable_session' => ! in_array(config('session.driver'), ['array', 'cookie'], true),
                'durable_queue' => ! in_array(config('queue.default'), ['sync', 'null'], true),
                'retained_logs' => in_array(config('logging.default'), ['daily', 'stderr', 'syslog', 'errorlog'], true),
                'private_evidence_disks' => count($privateRoots) === 7 && ! in_array(false, $privateRoots, true),
                'frontend_manifest' => is_file(public_path('build/manifest.json'))];
    }
}
