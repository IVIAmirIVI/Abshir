<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Captain financial event identity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_captain_financial_event_identity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected_source text;
DECLARE expected_event text;
BEGIN
    expected_source := CASE NEW.event_type
        WHEN 'CAPTAIN_DEPOSIT_RECEIVED' THEN 'CAPTAIN_DEPOSIT_RECEIPT'
        WHEN 'CAPTAIN_RECEIVABLE_PAID' THEN 'CAPTAIN_RECEIVABLE_PAYOUT'
        ELSE NULL END;
    expected_event := CASE NEW.source_type
        WHEN 'CAPTAIN_DEPOSIT_RECEIPT' THEN 'CAPTAIN_DEPOSIT_RECEIVED'
        WHEN 'CAPTAIN_RECEIVABLE_PAYOUT' THEN 'CAPTAIN_RECEIVABLE_PAID'
        ELSE NULL END;
    IF expected_source IS NOT NULL OR expected_event IS NOT NULL THEN
        IF NEW.transaction_kind <> 'EVENT' OR NEW.status NOT IN ('DRAFT', 'POSTED')
           OR NEW.source_type IS DISTINCT FROM expected_source
           OR NEW.event_type IS DISTINCT FROM expected_event
           OR NEW.source_version <> 1
           OR NEW.source_id !~ '^[1-9][0-9]{0,18}$'
           OR (NEW.source_id ~ '^[1-9][0-9]{0,18}$' AND NEW.source_id::numeric > 9223372036854775807) THEN
            RAISE EXCEPTION 'Captain financial event and source identity must be canonical' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Captain financial event identity is intentionally forward-only.');
    }
};
