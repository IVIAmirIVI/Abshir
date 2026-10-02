<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class TestDatabaseSafety
{
    public static function assertSafe(Application $app): void
    {
        if (! $app->environment('testing')) {
            throw new RuntimeException('Test bootstrap requires APP_ENV=testing; refusing to use runtime configuration.');
        }

        $connection = $app->make('db')->connection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        if ($driver !== 'pgsql' || $database !== 'abshir_testing') {
            throw new RuntimeException("Unsafe test database [{$driver}:{$database}]; PostgreSQL tests require abshir_testing (SQLite :memory: is also allowed).");
        }

        $actual = $connection->selectOne('SELECT current_database() AS name')->name;
        if ($actual !== 'abshir_testing') {
            throw new RuntimeException("Unsafe active PostgreSQL test database [{$actual}]; expected abshir_testing.");
        }
    }
}
