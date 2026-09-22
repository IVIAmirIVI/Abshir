<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
LOCK TABLE financial_entries IN ACCESS EXCLUSIVE MODE;
DO $$
DECLARE bad_rows text;
BEGIN
    SELECT string_agg('entry=' || id || ', transaction=' || financial_transaction_id || ', amount=' || amount, '; ' ORDER BY id)
    INTO bad_rows FROM financial_entries
    WHERE amount::text IN ('NaN', 'Infinity', '-Infinity');
    IF bad_rows IS NOT NULL THEN
        RAISE EXCEPTION 'Non-finite financial history requires manual investigation: %', bad_rows USING ERRCODE = '23514';
    END IF;
END;
$$;

ALTER TABLE financial_entries ADD CONSTRAINT financial_entry_amount_finite
CHECK (amount::text NOT IN ('NaN', 'Infinity', '-Infinity'));

CREATE OR REPLACE FUNCTION enforce_financial_entry_precision() RETURNS trigger AS $$
DECLARE places integer;
BEGIN
    IF NEW.amount::text IN ('NaN', 'Infinity', '-Infinity') THEN
        RAISE EXCEPTION 'Financial amounts must be finite' USING ERRCODE = '23514';
    END IF;
    IF NEW.amount <= 0 THEN
        RAISE EXCEPTION 'Financial amounts must be positive' USING ERRCODE = '23514';
    END IF;
    SELECT decimal_places INTO places FROM currencies WHERE id = NEW.currency_id FOR UPDATE;
    IF places IS NULL OR places < 0 OR places > 4 OR NEW.amount <> ROUND(NEW.amount, places) THEN
        RAISE EXCEPTION 'Financial amount exceeds the Currency decimal precision' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION lock_financial_transaction_currency() RETURNS trigger AS $$
BEGIN
    PERFORM id FROM currencies WHERE id = NEW.currency_id FOR UPDATE;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER financial_transaction_currency_lock
BEFORE INSERT ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION lock_financial_transaction_currency();

CREATE OR REPLACE FUNCTION protect_used_currency_precision() RETURNS trigger AS $$
BEGIN
    IF NEW.decimal_places IS DISTINCT FROM OLD.decimal_places
       AND EXISTS (SELECT 1 FROM financial_transactions WHERE currency_id = OLD.id) THEN
        RAISE EXCEPTION 'Currency decimal precision cannot change after financial ledger use (currency %)', OLD.id USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER currency_ledger_precision_guard
BEFORE UPDATE OF decimal_places ON currencies
FOR EACH ROW EXECUTE FUNCTION protect_used_currency_precision();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Phase 9A financial integrity is intentionally forward-only.');
    }
};
