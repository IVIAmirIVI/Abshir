<?php

namespace App\Support\DemoOrderReset;

use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PostgresBackup
{
    /** The exporting transaction holds write-blocking table locks until cleanup commits. */
    public function create(PDO $pdo, array $connection, string $database): array
    {
        $directory = config('demo_order_reset.backup_directory');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create the private reset backup directory.');
        }
        $root = realpath($directory);
        foreach (config('filesystems.disks') as $disk) {
            if (! empty($disk['root']) && (! empty($disk['serve']) || ($disk['visibility'] ?? '') === 'public')) {
                $served = realpath($disk['root']);
                if ($served && $this->within($root, $served)) {
                    throw new RuntimeException('Reset backups must not be inside a serving/public disk.');
                }
            }
        }
        if ($this->within($root, public_path())) {
            throw new RuntimeException('Reset backups must be outside public/.');
        }
        $bin = rtrim(config('demo_order_reset.postgres_bin'), '/\\');
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $dump = $bin.DIRECTORY_SEPARATOR.'pg_dump'.$suffix;
        $restore = $bin.DIRECTORY_SEPARATOR.'pg_restore'.$suffix;
        if (! is_file($dump) || ! is_file($restore)) {
            throw new RuntimeException('pg_dump/pg_restore are missing; configure ABSHIR_POSTGRES_BIN.');
        }
        $path = $root.DIRECTORY_SEPARATOR.$database.'_before_demo_reset_'.date('Ymd_His').'_'.bin2hex(random_bytes(6)).'.dump';
        $snapshot = $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();
        // An argv array and process-only environment avoid shell quoting and password disclosure.
        $environment = ['PGPASSWORD' => $connection['password'], 'PGPASSFILE' => false,
            'PGSERVICE' => false, 'PGSERVICEFILE' => false, 'PGOPTIONS' => false];
        $process = new Process([$dump, '--host='.$connection['host'], '--port='.$connection['port'],
            '--username='.$connection['username'], '--dbname='.$database, '--no-password',
            '--format=custom', '--snapshot='.$snapshot, '--file='.$path], null, $environment, null, 600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('PostgreSQL backup failed (exit '.$process->getExitCode().'); cleanup was not executed.');
        }
        $verify = new Process([$restore, '--list', $path], null, $environment, null, 120);
        $verify->run();
        if (! $verify->isSuccessful() || ! str_contains($verify->getOutput(), 'TABLE DATA public orders')
            || filesize($path) < 1024 || file_get_contents($path, false, null, 0, 5) !== 'PGDMP') {
            throw new RuntimeException('PostgreSQL archive verification failed; cleanup was not executed.');
        }
        chmod($path, 0600);
        $result = ['path' => $path, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path),
            'verification' => 'pg_dump exit 0; PGDMP header; pg_restore --list exit 0; public.orders present',
            'database' => $database, 'exported_snapshot' => $snapshot];
        if (file_put_contents($path.'.verification.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot save backup verification; cleanup was not executed.');
        }

        return $result;
    }

    private function within(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
