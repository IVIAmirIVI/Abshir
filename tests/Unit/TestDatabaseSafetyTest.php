<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseSafety;

class TestDatabaseSafetyTest extends TestCase
{
    public function test_current_dedicated_or_in_memory_connection_is_supported(): void
    {
        TestDatabaseSafety::assertSafe(Application::getInstance());
        $this->addToAssertionCount(1);
    }

    public function test_development_postgresql_is_rejected_before_connecting(): void
    {
        $this->assertRejected(['driver' => 'pgsql', 'database' => 'abshir'], 'pgsql:abshir');
    }

    public function test_database_url_cannot_bypass_the_guard(): void
    {
        $this->assertRejected([
            'driver' => 'pgsql', 'database' => 'abshir_testing',
            'url' => 'postgresql://127.0.0.1/abshir',
        ], 'pgsql:abshir');
    }

    private function assertRejected(array $connection, string $message): void
    {
        $app = Application::getInstance();
        $config = $app->make('config');
        $original = $config->get('database.default');
        $config->set('database.connections.safety_probe', $connection);
        $config->set('database.default', 'safety_probe');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($message);
            TestDatabaseSafety::assertSafe($app);
        } finally {
            $app->make('db')->purge('safety_probe');
            $config->set('database.default', $original);
            $config->set('database.connections.safety_probe', null);
        }
    }
}
