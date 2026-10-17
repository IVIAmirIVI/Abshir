<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('guard_benefit_review()'::regprocedure)");
        $previous = "permission:=CASE WHEN NEW.action IN ('SUBMIT','CANCEL') THEN 'captains.update'";
        if (substr_count($definition, $previous) !== 1) {
            throw new RuntimeException('Unexpected benefit review permission guard; refusing to change it.');
        }
        $replacement = "permission:=CASE WHEN NEW.action='SUBMIT' THEN CASE WHEN p.kind='BONUS' THEN 'captain_bonuses.submit' ELSE 'captain_expenses.submit' END\n        WHEN NEW.action='CANCEL' THEN 'captains.update'";
        DB::unprepared(str_replace($previous, $replacement, $definition));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Benefit submission authorization is forward-only; use a reviewed forward migration.');
    }
};
