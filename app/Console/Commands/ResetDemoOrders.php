<?php

namespace App\Console\Commands;

use App\Support\DemoOrderReset\OrderResetMaintenance;
use App\Support\DemoOrderReset\PostgresBackup;
use Illuminate\Console\Command;
use PDO;
use Throwable;

final class ResetDemoOrders extends Command
{
    protected $signature = 'abshir:reset-demo-orders
        {--preview : Use ONLY abshir_cleanup_preview to validate the reset; never the normal runtime connection}';

    protected $description = 'Interactively back up and reset all DEVELOPMENT demo Orders, preserving masters and independent finances';

    public function handle(PostgresBackup $backup): int
    {
        $pdo = null;
        try {
            // There is deliberately no --force, --yes, configurable target, or unattended mode.
            if (! $this->input->isInteractive()) {
                $this->error('Interactive confirmation is required. --no-interaction is not supported.');

                return self::FAILURE;
            }
            if (config('database.default') !== 'pgsql') {
                $this->error('The normal development connection must be pgsql/abshir.');

                return self::FAILURE;
            }
            $preview = (bool) $this->option('preview');
            $database = $preview ? 'abshir_cleanup_preview' : 'abshir';
            $connection = config('database.connections.pgsql');
            $pdo = OrderResetMaintenance::connect($connection, app()->environment(), $preview);
            $maintenance = new OrderResetMaintenance;
            $counts = $maintenance->prepare($pdo, $database);
            $this->warn('Target: '.$database.' / public. ALL Orders here must be experimental.');
            $this->table(['Table', 'Rows to delete'], array_map(fn ($table, $count) => [$table, $count], array_keys($counts), $counts));
            $verified = $backup->create($pdo, $connection, $database);
            $this->info('Verified PostgreSQL backup: '.$verified['path']);
            $this->line('SHA256: '.$verified['sha256']);
            $phrase = 'RESET DEMO ORDERS '.$database;
            if ($this->ask('Type exactly "'.$phrase.'" to delete these records (anything else cancels)') !== $phrase) {
                $pdo->rollBack();
                $this->info('Cancelled; no database records changed. The verified backup is retained.');

                return self::SUCCESS;
            }
            $report = $maintenance->execute();
            $report['backup'] = $verified;
            // Write the verification before commit: an unwritable report must also roll back.
            $reportPath = $verified['path'].'.reset-report.json';
            if (file_put_contents($reportPath, json_encode($report + ['commit_status' => 'VERIFIED_NOT_YET_COMMITTED'],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException('Cannot save the reset verification report.');
            }
            $pdo->commit();
            // Never suggest rollback after COMMIT; the durable pre-commit report still exists
            // if a filesystem problem prevents recording the final completion marker.
            try {
                $written = file_put_contents($reportPath, json_encode($report + ['commit_status' => 'COMMITTED'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                $written = false;
            }
            if ($written === false) {
                $this->warn('Database COMMITTED, but the completion marker could not be written. Keep the backup and verification report.');
            }
            $this->info('Reset complete: 0 Orders; preserved rows and balances verified; all guards restored.');
            $this->line('Attachments retained. Report: '.$reportPath);

            return self::SUCCESS;
        } catch (Throwable $error) {
            $rolledBack = $pdo instanceof PDO && $pdo->inTransaction();
            if ($rolledBack) {
                $pdo->rollBack();
            }
            $this->error(($rolledBack ? 'Reset rolled back. ' : 'Reset refused/failed. ').$error->getMessage());

            return self::FAILURE;
        }
    }
}
