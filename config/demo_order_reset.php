<?php

return [
    // Backups are deliberately outside every configured serving/public disk.
    'backup_directory' => storage_path('app/demo-order-reset-backups'),
    'postgres_bin' => env('ABSHIR_POSTGRES_BIN', PHP_OS_FAMILY === 'Windows'
        ? 'C:\\Program Files\\PostgreSQL\\18\\bin' : '/usr/bin'),
    'lock_timeout_seconds' => 15,
];
