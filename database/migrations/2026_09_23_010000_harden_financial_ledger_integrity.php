<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Phase 9A financial integrity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
DO $$
DECLARE
    invalid_codes text;
BEGIN
    SELECT string_agg(code || '=' || decimal_places::text, ', ' ORDER BY code)
    INTO invalid_codes
    FROM currencies
    WHERE decimal_places < 0 OR decimal_places > 4;

    IF invalid_codes IS NOT NULL THEN
        RAISE EXCEPTION 'Cannot enforce Currency precision 0..4. Correct these Currency rows first: %', invalid_codes;
    END IF;
END;
$$;
SQL);

        DB::statement('ALTER TABLE currencies ADD CONSTRAINT currencies_decimal_places_valid CHECK (decimal_places BETWEEN 0 AND 4)');
        DB::statement('ALTER TABLE financial_entries ALTER COLUMN amount TYPE numeric USING amount::numeric');

        DB::statement('DROP TRIGGER financial_transaction_entries_guard ON financial_transactions');
        DB::statement('DROP FUNCTION validate_financial_transaction_entries()');
        DB::statement('DROP TRIGGER financial_transactions_immutable ON financial_transactions');

        Schema::table('financial_transactions', function (Blueprint $table): void {
            $table->string('status', 16)->default('DRAFT')->after('transaction_kind');
            $table->timestampTz('posted_at')->nullable()->after('occurred_at');
        });

        DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transaction_status_valid CHECK (status IN ('DRAFT', 'POSTED'))");
        DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transaction_posted_shape_valid CHECK ((status = 'DRAFT' AND posted_at IS NULL) OR (status = 'POSTED' AND posted_at IS NOT NULL))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_financial_identity_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Financial owner and account identities are immutable' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER financial_owners_immutable
BEFORE UPDATE OR DELETE ON financial_owners
FOR EACH ROW EXECUTE FUNCTION prevent_financial_identity_mutation();

CREATE TRIGGER financial_accounts_immutable
BEFORE UPDATE OR DELETE ON financial_accounts
FOR EACH ROW EXECUTE FUNCTION prevent_financial_identity_mutation();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_financial_transaction_finalization(transaction_id bigint) RETURNS void AS $$
DECLARE
    transaction_record financial_transactions%ROWTYPE;
    entry_count bigint;
    signed_total numeric;
    has_increase boolean;
    has_decrease boolean;
BEGIN
    SELECT * INTO STRICT transaction_record
    FROM financial_transactions
    WHERE id = transaction_id;

    SELECT COUNT(*),
           COALESCE(SUM(CASE WHEN direction = 'INCREASE' THEN amount ELSE -amount END), 0),
           COALESCE(BOOL_OR(direction = 'INCREASE'), false),
           COALESCE(BOOL_OR(direction = 'DECREASE'), false)
    INTO entry_count, signed_total, has_increase, has_decrease
    FROM financial_entries
    WHERE financial_transaction_id = transaction_id;

    IF entry_count = 0 THEN
        RAISE EXCEPTION 'A financial transaction must contain at least one entry' USING ERRCODE = '23514';
    END IF;

    IF transaction_record.transaction_kind = 'TRANSFER'
       AND (signed_total <> 0 OR NOT has_increase OR NOT has_decrease) THEN
        RAISE EXCEPTION 'A financial transfer must be zero-sum with increases and decreases' USING ERRCODE = '23514';
    END IF;

    IF transaction_record.transaction_kind = 'REVERSAL' THEN
        IF BTRIM(COALESCE(transaction_record.reason, '')) = '' THEN
            RAISE EXCEPTION 'A financial reversal requires a non-empty reason' USING ERRCODE = '23514';
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM financial_transactions original
            WHERE original.id = transaction_record.reversal_of_transaction_id
              AND original.status = 'POSTED'
              AND original.transaction_kind <> 'REVERSAL'
              AND original.currency_id = transaction_record.currency_id
        ) THEN
            RAISE EXCEPTION 'A financial reversal requires a POSTED non-reversal original in the same Currency' USING ERRCODE = '23514';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM (
                SELECT financial_account_id, direction, amount
                FROM financial_entries
                WHERE financial_transaction_id = transaction_record.reversal_of_transaction_id
            ) original
            FULL JOIN (
                SELECT financial_account_id, direction, amount
                FROM financial_entries
                WHERE financial_transaction_id = transaction_record.id
            ) reversal
              ON reversal.financial_account_id = original.financial_account_id
             AND reversal.amount = original.amount
             AND reversal.direction = CASE original.direction WHEN 'INCREASE' THEN 'DECREASE' ELSE 'INCREASE' END
            WHERE original.financial_account_id IS NULL OR reversal.financial_account_id IS NULL
        ) THEN
            RAISE EXCEPTION 'A financial reversal must exactly oppose every original entry' USING ERRCODE = '23514';
        END IF;
    END IF;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION enforce_financial_transaction_insert() RETURNS trigger AS $$
BEGIN
    IF NEW.status <> 'DRAFT' OR NEW.posted_at IS NOT NULL THEN
        RAISE EXCEPTION 'Financial transactions must be created as DRAFT' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION finalize_financial_transaction() RETURNS trigger AS $$
BEGIN
    IF OLD.status <> 'DRAFT' OR NEW.status <> 'POSTED' OR OLD.posted_at IS NOT NULL OR NEW.posted_at IS NULL
       OR NEW.transaction_kind IS DISTINCT FROM OLD.transaction_kind
       OR NEW.currency_id IS DISTINCT FROM OLD.currency_id
       OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
       OR NEW.source_type IS DISTINCT FROM OLD.source_type
       OR NEW.source_id IS DISTINCT FROM OLD.source_id
       OR NEW.event_type IS DISTINCT FROM OLD.event_type
       OR NEW.source_version IS DISTINCT FROM OLD.source_version
       OR NEW.payload_hash IS DISTINCT FROM OLD.payload_hash
       OR NEW.occurred_at IS DISTINCT FROM OLD.occurred_at
       OR NEW.recorded_by_user_id IS DISTINCT FROM OLD.recorded_by_user_id
       OR NEW.reversal_of_transaction_id IS DISTINCT FROM OLD.reversal_of_transaction_id
       OR NEW.reason IS DISTINCT FROM OLD.reason
       OR NEW.notes IS DISTINCT FROM OLD.notes
       OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Only the controlled DRAFT to POSTED transition is allowed' USING ERRCODE = '55000';
    END IF;

    PERFORM validate_financial_transaction_finalization(NEW.id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION prevent_financial_transaction_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Financial transactions cannot be deleted' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION enforce_financial_entry_insert() RETURNS trigger AS $$
DECLARE
    parent_status varchar;
BEGIN
    SELECT status INTO parent_status
    FROM financial_transactions
    WHERE id = NEW.financial_transaction_id
    FOR UPDATE;

    IF parent_status IS DISTINCT FROM 'DRAFT' THEN
        RAISE EXCEPTION 'Entries may only be added to a DRAFT financial transaction' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION require_posted_financial_transaction() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM financial_transactions
        WHERE id = NEW.id AND status = 'POSTED'
    ) THEN
        RAISE EXCEPTION 'A DRAFT financial transaction cannot be committed' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER financial_transaction_insert_guard
BEFORE INSERT ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION enforce_financial_transaction_insert();

CREATE TRIGGER financial_transaction_finalize_guard
BEFORE UPDATE ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION finalize_financial_transaction();

CREATE TRIGGER financial_transaction_delete_guard
BEFORE DELETE ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION prevent_financial_transaction_delete();

CREATE TRIGGER financial_entry_insert_guard
BEFORE INSERT ON financial_entries
FOR EACH ROW EXECUTE FUNCTION enforce_financial_entry_insert();

CREATE CONSTRAINT TRIGGER financial_transaction_must_be_posted
AFTER INSERT OR UPDATE OF status ON financial_transactions
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION require_posted_financial_transaction();
SQL);

        DB::table('financial_transactions')
            ->where('transaction_kind', '<>', 'REVERSAL')
            ->where('status', 'DRAFT')
            ->orderBy('id')
            ->eachById(fn ($transaction) => DB::table('financial_transactions')->where('id', $transaction->id)->update([
                'status' => 'POSTED',
                'posted_at' => $transaction->created_at,
            ]));

        DB::table('financial_transactions')
            ->where('transaction_kind', 'REVERSAL')
            ->where('status', 'DRAFT')
            ->orderBy('id')
            ->eachById(fn ($transaction) => DB::table('financial_transactions')->where('id', $transaction->id)->update([
                'status' => 'POSTED',
                'posted_at' => $transaction->created_at,
            ]));
    }

    public function down(): void
    {
        throw new RuntimeException('Phase 9A financial integrity is intentionally forward-only.');
    }
};
