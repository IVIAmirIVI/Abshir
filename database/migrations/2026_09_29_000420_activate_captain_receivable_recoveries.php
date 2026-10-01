<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Recovery activation requires PostgreSQL.');
        }
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_financial_corrections, invoice_correction_receivable_dispositions, invoice_correction_receivable_disposition_allocations, captain_receivable_recoveries, captain_receivable_recovery_allocations, captain_receivable_payout_allocations IN SHARE ROW EXCLUSIVE MODE');
            // The table locks freeze the source/allocation set. Use the identical read-only
            // source validator without Currency/Account row locks, avoiding an activation
            // table-lock -> Account inversion against an in-flight payout.
            foreach (DB::table('invoice_financial_corrections')->where('state', '<>', 'ABANDONED')->pluck('id') as $id) {
                DB::select('SELECT validate_recovery_disposition_source(?)', [$id]);
            }
            if (DB::table('captain_receivable_recoveries')->exists()) {
                throw new RuntimeException('Unexpected preactivation Captain recovery_id='.DB::table('captain_receivable_recoveries')->value('id').'; no automatic repair.');
            }
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION captain_receivable_recoveries_active() RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT receivable_dispositions_active() AND NOT EXISTS(
 SELECT 1 FROM (VALUES
 ('captain_receivable_recoveries','recovery_immutable'),('captain_receivable_recoveries','aa_recovery_source'),
 ('captain_receivable_recoveries','recovery_complete'),('captain_receivable_recovery_allocations','recovery_allocation_immutable'),
 ('captain_receivable_recovery_allocations','recovery_allocation_source'),('captain_receivable_recovery_allocations','recovery_allocation_complete')) required(table_name,trigger_name)
 WHERE NOT EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid=required.table_name::regclass AND tgname=required.trigger_name AND tgenabled IN ('O','A')));
$$;
SQL);
            if (! DB::scalar('SELECT captain_receivable_recoveries_active()')) {
                throw new RuntimeException('Recovery activation requires all immutable source/allocation/completion guards.');
            }
            foreach (DB::table('invoice_financial_corrections')->where('receivable_dispositions_captured', true)->pluck('id') as $id) {
                DB::select('SELECT validate_recovery_disposition_source(?)', [$id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Recovery activation is forward-only.');
    }
};
