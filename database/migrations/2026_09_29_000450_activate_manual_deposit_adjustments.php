<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // No row locks acquired under these table locks; validators only observe immutable facts.
            DB::statement('LOCK TABLE captain_deposit_manual_adjustments, financial_transactions, financial_entries IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
DO $$ DECLARE s record; t record;
BEGIN
    FOR s IN SELECT id FROM captain_deposit_manual_adjustments ORDER BY id LOOP
        PERFORM validate_manual_deposit(s.id);
    END LOOP;
    FOR t IN SELECT * FROM financial_transactions WHERE source_type='CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT'
        OR event_type IN ('CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_INCREASE','CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_DECREASE') LOOP
        IF t.source_type IS DISTINCT FROM 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT' OR t.source_id !~ '^[1-9][0-9]{0,18}$' THEN
            RAISE EXCEPTION 'Malformed manual Deposit transaction %',t.id USING ERRCODE='23514';
        END IF;
        PERFORM validate_manual_deposit(t.source_id::bigint);
    END LOOP;
END; $$;
CREATE OR REPLACE FUNCTION captain_manual_deposits_active() RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT count(*)=5 FROM pg_trigger WHERE NOT tgisinternal AND tgenabled='O'
      AND (tgrelid='captain_deposit_manual_adjustments'::regclass AND tgname IN ('manual_deposit_source_guard','manual_deposit_source_complete')
        OR tgrelid='financial_transactions'::regclass AND tgname IN ('manual_deposit_event_guard','manual_deposit_transaction_complete')
        OR tgrelid='financial_entries'::regclass AND tgname='manual_deposit_entry_complete');
$$;
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Manual Deposit activation is forward-only.');
    }
};
