<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

trait RefreshAccountingV1Database
{
    use AccountingV1Fixtures, RefreshDatabase {
        refreshDatabase as private refreshCurrentDatabase;
    }

    protected function usesAccountingV1Fixture(): bool
    {
        return true;
    }

    public function refreshDatabase(): void
    {
        if (! $this->usesAccountingV1Fixture()) {
            $this->refreshCurrentDatabase();

            return;
        }

        TestDatabaseSafety::assertSafe($this->app);
        $originalSchema = DB::scalar('SELECT current_schema()');
        if (! preg_match('/^mc_testing_[a-f0-9]{16}$/', $originalSchema)) {
            throw new LogicException('V1 fixtures must run through the isolated PostgreSQL runner.');
        }

        // Table-only refresh cannot remove historical SQL functions. Use a separate
        // disposable schema so V1 and V2 never rebuild over one another's functions.
        $schema = 'mc_v1_'.bin2hex(random_bytes(8));
        $originalConfiguration = config('database.connections.pgsql.search_path');
        $originalMigrationState = RefreshDatabaseState::$migrated;
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        config(['database.connections.pgsql.search_path' => $schema]);
        DB::purge('pgsql');

        $cleanup = function () use ($schema, $originalConfiguration, $originalMigrationState): void {
            try {
                TestDatabaseSafety::assertSafe($this->app);
                DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            } finally {
                config(['database.connections.pgsql.search_path' => $originalConfiguration]);
                DB::purge('pgsql');
                RefreshDatabaseState::$migrated = $originalMigrationState;
            }
        };

        try {
            $this->migrateAccountingV1Schema();
            $this->beginDatabaseTransaction();
        } catch (Throwable $exception) {
            $cleanup();

            throw $exception;
        }

        // RefreshDatabase registers transaction rollback first; cleanup runs after it.
        $this->beforeApplicationDestroyed($cleanup);
    }
}
