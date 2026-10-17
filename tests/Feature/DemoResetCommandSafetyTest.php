<?php

namespace Tests\Feature;

use App\Support\DemoOrderReset\OrderResetMaintenance;
use App\Support\DemoOrderReset\ResetInventory;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoResetCommandSafetyTest extends TestCase
{
    public static function fullOptions(): array
    {
        return ['ordinary' => [[]], 'unattended' => [['--no-interaction' => true]], 'preview' => [['--preview' => true]]];
    }

    #[DataProvider('fullOptions')]
    public function test_full_execution_is_refused_before_backup_or_connection(array $options): void
    {
        config(['demo_order_reset.postgres_bin' => '/missing-backup-binaries']);
        $this->assertSame(1, Artisan::call('abshir:reset-demo-orders', ['--full' => true, ...$options]));
        $this->assertStringContainsString(ResetInventory::FULL_BLOCKER, Artisan::output());
    }

    public function test_original_unattended_reset_is_still_refused(): void
    {
        config(['demo_order_reset.postgres_bin' => '/missing-backup-binaries']);
        $this->assertSame(1, Artisan::call('abshir:reset-demo-orders', ['--no-interaction' => true]));
        $this->assertStringContainsString('Interactive confirmation is required', Artisan::output());
        $this->assertNotContains('captain_deposit_receipts', OrderResetMaintenance::orderTables());
        $this->assertNotContains('customers', OrderResetMaintenance::orderTables());
    }

    public static function unsafeTargets(): array
    {
        return ['production' => ['production', 'abshir'], 'testing' => ['testing', 'abshir_testing'], 'unknown' => ['local', 'other_database']];
    }

    #[DataProvider('unsafeTargets')]
    public function test_dry_run_refuses_unsafe_environment_or_target(string $environment, string $database): void
    {
        $this->app->instance('env', $environment);
        config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => $database]);
        config(['demo_order_reset.postgres_bin' => '/missing-backup-binaries']);
        $this->assertSame(1, Artisan::call('abshir:reset-demo-orders', ['--full' => true, '--dry-run' => true, '--no-interaction' => true]));
        $this->assertStringContainsString('Reset requires', Artisan::output());
    }

    public function test_full_inventory_cannot_connect_to_preview(): void
    {
        $this->assertSame(1, Artisan::call('abshir:reset-demo-orders', ['--full' => true, '--dry-run' => true, '--preview' => true]));
        $this->assertStringContainsString('restricted to the local abshir database', Artisan::output());
    }
}
