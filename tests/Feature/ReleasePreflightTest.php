<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReleasePreflightTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $options = []): array
    {
        $exit = Artisan::call('abshir:release-preflight', ['--json' => true] + $options);

        return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_verified_disposable_schema_inventory_is_read_only_and_all_migrations_are_present(): void
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        [$exit, $report] = $this->report();
        $this->assertSame(0, $exit);
        $this->assertSame('abshir_testing', $report['database']);
        $this->assertSame([], $report['pending_migrations']);
        $this->assertTrue($report['ready_for_next_review']);
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|alter|truncate|create|drop)\b/i', $sql))));
    }

    public function test_forbidden_preview_and_arbitrary_database_targets_are_rejected_before_any_query(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach (['abshir_cleanup_preview', 'unapproved_database'] as $database) {
            $this->artisan('abshir:release-preflight', ['--database' => $database, '--json' => true])
                ->expectsOutputToContain('Database target is not an approved PostgreSQL identity')->assertFailed();
        }
        $this->assertSame([], $queries);
    }

    public function test_unapplied_migration_inventory_blocks_readiness_without_repair_or_schema_activation(): void
    {
        DB::table('migrations')->where('migration', '2026_10_12_210249_create_captain_benefit_notification_events')->delete();
        [$exit, $report] = $this->report();
        $this->assertSame(1, $exit);
        $this->assertContains('2026_10_12_210249_create_captain_benefit_notification_events', $report['pending_migrations']);
        $this->assertFalse($report['ready_for_next_review']);
        $this->assertDatabaseMissing('migrations', ['migration' => '2026_10_12_210249_create_captain_benefit_notification_events']);
    }

    public function test_production_settings_are_checks_only_and_unsafe_defaults_cannot_certify_a_release(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://localhost', 'session.secure' => false, 'queue.default' => 'sync']);
        [$exit, $report] = $this->report(['--production' => true]);
        $this->assertSame(1, $exit);
        foreach (['production_environment', 'debug_disabled', 'https_url', 'secure_session_cookie'] as $check) {
            $this->assertFalse($report['checks'][$check]);
        }
        $this->assertFalse($report['ready_for_next_review']);
        $this->assertTrue(config('app.debug'));
        $this->assertFalse(config('session.secure'));
    }

    public function test_private_evidence_root_inside_web_root_is_never_marked_safe(): void
    {
        config(['filesystems.disks.order_receipts.root' => public_path(),
            'filesystems.disks.order_receipts.serve' => false, 'filesystems.disks.order_receipts.visibility' => 'private']);
        [$exit, $report] = $this->report(['--production' => true]);
        $this->assertSame(1, $exit);
        $this->assertFalse($report['checks']['private_evidence_disk:order_receipts']);
        $this->assertFalse($report['checks']['private_evidence_disks']);
        // A URL-serving ancestor must also be rejected, even outside public/.
        config(['filesystems.disks.order_receipts.root' => storage_path('app'),
            'filesystems.disks.local.root' => storage_path(), 'filesystems.disks.local.serve' => true]);
        [$exit, $report] = $this->report(['--production' => true]);
        $this->assertFalse($report['checks']['private_evidence_disk:order_receipts']);
    }
}
