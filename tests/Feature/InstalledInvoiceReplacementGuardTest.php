<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstalledInvoiceReplacementGuardTest extends TestCase
{
    public function test_phase_9c3a_actual_public_invoice_replacement_constraint_is_attached_after_upgrade(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Installed-schema verification requires PostgreSQL.');
        }
        if (! DB::scalar("SELECT to_regclass('public.migrations') IS NOT NULL")
            || ! DB::table('public.migrations')->where('migration', '2026_09_29_000366_ensure_collected_invoice_replacement_guard')->exists()) {
            $this->markTestSkipped('Run the complete migration chain on the installed public schema first.');
        }
        // Read the actual installed schema, irrespective of the disposable test search_path.
        $triggers = DB::select("SELECT tgdeferrable,tginitdeferred,tgconstraint<>0 AS is_constraint,tgenabled,tgtype,tgfoid='public.validate_replaced_invoice_at_commit()'::regprocedure AS correct_function FROM pg_trigger WHERE tgrelid='public.invoices'::regclass AND tgname='replaced_invoice_complete'");
        $this->assertCount(1, $triggers);
        $this->assertTrue($triggers[0]->tgdeferrable && $triggers[0]->tginitdeferred && $triggers[0]->is_constraint && $triggers[0]->correct_function);
        $this->assertSame('O', $triggers[0]->tgenabled);
        $this->assertSame(17, $triggers[0]->tgtype);
    }
}
