<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Captain financial deferred validation requires PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_captain_financial_operation_trigger() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    source_family text;
    source_identity text;
    financial_source_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'captain_deposit_receipts' THEN
        PERFORM validate_captain_financial_operation('DEPOSIT', NEW.id);
    ELSIF TG_TABLE_NAME = 'captain_receivable_payouts' THEN
        PERFORM validate_captain_financial_operation('PAYOUT', NEW.id);
    ELSIF TG_TABLE_NAME = 'captain_receivable_payout_allocations' THEN
        PERFORM validate_captain_financial_operation('PAYOUT', NEW.captain_receivable_payout_id);
    ELSE
        IF TG_TABLE_NAME = 'financial_entries' THEN
            SELECT t.source_type, t.source_id INTO source_family, source_identity
            FROM financial_transactions AS t WHERE t.id = NEW.financial_transaction_id;
        ELSE
            source_family := NEW.source_type;
            source_identity := NEW.source_id;
        END IF;
        IF source_family NOT IN ('CAPTAIN_DEPOSIT_RECEIPT', 'CAPTAIN_RECEIVABLE_PAYOUT') THEN
            RETURN NULL;
        END IF;
        IF source_identity !~ '^[1-9][0-9]{0,18}$' OR source_identity::numeric > 9223372036854775807 THEN
            RAISE EXCEPTION 'Captain financial source identity is invalid' USING ERRCODE = '23514';
        END IF;
        financial_source_id := source_identity::bigint;
        IF source_family = 'CAPTAIN_DEPOSIT_RECEIPT' THEN
            PERFORM validate_captain_financial_operation('DEPOSIT', financial_source_id);
        ELSE
            PERFORM validate_captain_financial_operation('PAYOUT', financial_source_id);
        END IF;
    END IF;
    RETURN NULL;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Captain financial deferred validation is intentionally forward-only.');
    }
};
