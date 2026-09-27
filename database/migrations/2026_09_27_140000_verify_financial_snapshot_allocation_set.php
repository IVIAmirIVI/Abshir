<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Financial snapshot allocation validation requires PostgreSQL.');
        }

        // Forward upgrade for databases where 120000 ran before the allocation-set
        // check was added. Existing snapshots were required to be absent at 120000.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION verify_financial_snapshot_allocation_set(p_snapshot_id bigint) RETURNS void AS $$
DECLARE snapshot_row order_financial_source_snapshots%ROWTYPE;
    snapshot_lines integer;
    source_allocations integer;
BEGIN
    SELECT * INTO snapshot_row FROM order_financial_source_snapshots WHERE id = p_snapshot_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'Missing financial snapshot %', p_snapshot_id USING ERRCODE = '23514'; END IF;
    SELECT count(*) INTO snapshot_lines FROM order_financial_source_snapshot_lines
        WHERE order_financial_source_snapshot_id = p_snapshot_id;
    SELECT count(*) INTO source_allocations FROM order_delivery_allocations
        WHERE order_delivery_pricing_id = snapshot_row.order_delivery_pricing_id;
    IF snapshot_lines <> source_allocations OR EXISTS (
        SELECT 1 FROM order_delivery_allocations a
        WHERE a.order_delivery_pricing_id = snapshot_row.order_delivery_pricing_id
          AND NOT EXISTS (SELECT 1 FROM order_financial_source_snapshot_lines l
              WHERE l.order_financial_source_snapshot_id = p_snapshot_id
                AND l.order_captain_id = a.order_captain_id)
    ) THEN
        RAISE EXCEPTION 'Financial snapshot % Order % Invoice % has an unmatched allocation set',
            p_snapshot_id, snapshot_row.order_id, snapshot_row.invoice_id USING ERRCODE = '23514';
    END IF;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION verify_financial_snapshot_allocation_insert() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'order_financial_source_snapshots' THEN
        PERFORM verify_financial_snapshot_allocation_set(NEW.id);
    ELSE
        PERFORM verify_financial_snapshot_allocation_set(NEW.order_financial_source_snapshot_id);
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER financial_snapshot_allocation_set_valid
    AFTER INSERT ON order_financial_source_snapshots DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION verify_financial_snapshot_allocation_insert();
CREATE CONSTRAINT TRIGGER financial_snapshot_line_allocation_set_valid
    AFTER INSERT ON order_financial_source_snapshot_lines DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION verify_financial_snapshot_allocation_insert();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Financial snapshot allocation validation is intentionally forward-only.');
    }
};
