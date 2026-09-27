<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Financial snapshot currency protection requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE order_financial_source_snapshots
    ADD CONSTRAINT financial_snapshot_hash_shape_valid CHECK (payload_hash ~ '^[0-9a-f]{64}$');

-- A snapshot freezes monetary values at one Currency precision even when no
-- financial Transaction has yet been posted for that Currency.
CREATE OR REPLACE FUNCTION freeze_financial_snapshot_currency_precision() RETURNS trigger AS $$
BEGIN
    IF NEW.decimal_places IS DISTINCT FROM OLD.decimal_places
       AND EXISTS (SELECT 1 FROM order_financial_source_snapshots WHERE currency_id = OLD.id) THEN
        RAISE EXCEPTION 'Currency % precision is frozen by financial source snapshots', OLD.id USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER financial_snapshot_currency_precision_frozen
    BEFORE UPDATE OF decimal_places ON currencies
    FOR EACH ROW EXECUTE FUNCTION freeze_financial_snapshot_currency_precision();

CREATE OR REPLACE FUNCTION lock_financial_snapshot_insert() RETURNS trigger AS $$
DECLARE target_order bigint;
DECLARE target_currency bigint;
BEGIN
    IF TG_TABLE_NAME = 'order_financial_source_snapshots' THEN
        target_order := NEW.order_id;
        target_currency := NEW.currency_id;
    ELSE
        SELECT order_id, currency_id INTO target_order, target_currency
          FROM order_financial_source_snapshots WHERE id = NEW.order_financial_source_snapshot_id;
    END IF;
    PERFORM lock_financial_snapshot_source(target_order);
    PERFORM 1 FROM currencies WHERE id = target_currency FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Financial snapshot Currency % does not exist', target_currency USING ERRCODE = '23514'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Financial snapshot currency protection is intentionally forward-only.');
    }
};
