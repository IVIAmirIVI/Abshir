<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;

trait AccountingV1Fixtures
{
    /** Real pre-activation schema: never forge V1 on a new V2 Order or disable a guard. */
    protected function accountingV1MigrationPaths(): array
    {
        TestDatabaseSafety::assertSafe($this->app);
        if (DB::scalar('SELECT current_schema()') === 'public') {
            throw new LogicException('Historical accounting fixtures require an isolated test schema.');
        }

        return array_values(array_filter(glob(database_path('migrations/*.php')),
            fn ($path) => basename($path) < '2026_10_11_185816_add_versioned_order_accounting_contracts.php'));
    }

    protected function migrateAccountingV1Schema(): void
    {
        Artisan::call('migrate', ['--path' => $this->accountingV1MigrationPaths(), '--realpath' => true, '--force' => true]);
    }
}
