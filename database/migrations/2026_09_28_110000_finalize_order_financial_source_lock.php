<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Order financial source integrity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION lock_order_financial_event_source_before_insert()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_order_id bigint;
BEGIN
    -- Existing source rows are locked before the Currency trigger. An invalid or
    -- not-yet-created source is rejected by the deferred contract at COMMIT.
    BEGIN
        source_order_id := order_financial_event_source_order_id(NEW);
    EXCEPTION WHEN SQLSTATE '23514' THEN
        RETURN NEW;
    END;
    IF source_order_id IS NOT NULL THEN
        PERFORM id FROM orders WHERE id = source_order_id FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Financial event % has no source Order %', NEW.event_type,
                source_order_id USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Order financial source integrity is intentionally forward-only.');
    }
};
