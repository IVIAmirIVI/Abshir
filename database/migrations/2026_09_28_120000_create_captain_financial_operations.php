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
            throw new RuntimeException('Captain financial operations require PostgreSQL.');
        }

        foreach (['captain_deposit_receipts', 'captain_receivable_payouts'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                $table->id();
                $table->foreignId('captain_id')->constrained()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('currency_id')->constrained()->restrictOnDelete();
                $table->foreignId($tableName === 'captain_deposit_receipts' ? 'received_by' : 'paid_by')
                    ->constrained('users')->restrictOnDelete();
                $table->string('request_key', 64)->unique();
                $table->timestampTz('occurred_at', 6);
                $table->string('reference')->nullable();
                $table->text('note')->nullable();
                $table->string('evidence_path', 191);
                $table->string('evidence_name');
                $table->string('evidence_mime', 64);
                $table->unsignedInteger('evidence_size');
                $table->string('evidence_sha256', 64);
                $table->timestampTz('created_at', 6)->useCurrent();
                $table->index(['captain_id', 'branch_id', 'currency_id']);
            });
            DB::statement("ALTER TABLE {$tableName} ADD COLUMN amount numeric NOT NULL");
            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_amount_valid CHECK (amount::text NOT IN ('NaN', 'Infinity', '-Infinity') AND amount > 0)");
        }

        Schema::create('captain_receivable_payout_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('captain_receivable_payout_id')->constrained('captain_receivable_payouts')->restrictOnDelete();
            $table->foreignId('financial_entry_id')->constrained('financial_entries')->restrictOnDelete();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['captain_receivable_payout_id', 'financial_entry_id'], 'captain_payout_source_unique');
            $table->index('financial_entry_id');
        });
        DB::statement('ALTER TABLE captain_receivable_payout_allocations ADD COLUMN amount numeric NOT NULL');
        DB::statement("ALTER TABLE captain_receivable_payout_allocations ADD CONSTRAINT captain_payout_allocation_amount_valid CHECK (amount::text NOT IN ('NaN', 'Infinity', '-Infinity') AND amount > 0)");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_captain_financial_source_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Posted Captain financial source and evidence are immutable' USING ERRCODE = '55000';
END;
$$;
CREATE TRIGGER captain_deposit_receipt_immutable BEFORE UPDATE OR DELETE ON captain_deposit_receipts
FOR EACH ROW EXECUTE FUNCTION reject_captain_financial_source_mutation();
CREATE TRIGGER captain_receivable_payout_immutable BEFORE UPDATE OR DELETE ON captain_receivable_payouts
FOR EACH ROW EXECUTE FUNCTION reject_captain_financial_source_mutation();
CREATE TRIGGER captain_payout_allocation_immutable BEFORE UPDATE OR DELETE ON captain_receivable_payout_allocations
FOR EACH ROW EXECUTE FUNCTION reject_captain_financial_source_mutation();

CREATE OR REPLACE FUNCTION validate_captain_financial_source_amount() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE places integer;
BEGIN
    SELECT decimal_places INTO places FROM currencies WHERE id = NEW.currency_id FOR UPDATE;
    IF places IS NULL OR NEW.amount::text IN ('NaN', 'Infinity', '-Infinity')
       OR NEW.amount <= 0 OR NEW.amount <> round(NEW.amount, places) THEN
        RAISE EXCEPTION 'Captain financial source amount violates Currency precision' USING ERRCODE = '23514';
    END IF;
    IF NEW.evidence_mime NOT IN ('image/jpeg', 'image/png', 'image/webp')
       OR NEW.evidence_size < 1 OR NEW.evidence_size > 5242880
       OR NEW.evidence_sha256 !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'Captain financial evidence metadata is invalid' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER captain_deposit_receipt_amount BEFORE INSERT ON captain_deposit_receipts
FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_source_amount();
CREATE TRIGGER captain_receivable_payout_amount BEFORE INSERT ON captain_receivable_payouts
FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_source_amount();

CREATE OR REPLACE FUNCTION validate_captain_financial_operation(p_type text, p_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    source_row record;
    required_source text;
    required_event text;
    required_kind text;
    required_direction text;
    matching_count integer;
    matching_event_id bigint;
    event_row financial_transactions%ROWTYPE;
    entry_row record;
    owner_row record;
    allocated numeric;
    source_entry record;
    allocated_total numeric;
    branch_balance numeric;
BEGIN
    IF p_type = 'DEPOSIT' THEN
        SELECT * INTO source_row FROM captain_deposit_receipts WHERE id = p_id;
        required_source := 'CAPTAIN_DEPOSIT_RECEIPT';
        required_event := 'CAPTAIN_DEPOSIT_RECEIVED';
        required_kind := 'CAPTAIN_DEPOSIT';
        required_direction := 'INCREASE';
    ELSE
        SELECT * INTO source_row FROM captain_receivable_payouts WHERE id = p_id;
        required_source := 'CAPTAIN_RECEIVABLE_PAYOUT';
        required_event := 'CAPTAIN_RECEIVABLE_PAID';
        required_kind := 'CAPTAIN_COMPANY_RECEIVABLE';
        required_direction := 'DECREASE';
    END IF;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Captain financial % source % is missing', p_type, p_id USING ERRCODE = '23514';
    END IF;

    SELECT count(*), min(id) INTO matching_count, matching_event_id FROM financial_transactions
    WHERE source_type = required_source AND source_id = p_id::text AND event_type = required_event;
    IF matching_count <> 1 THEN
        RAISE EXCEPTION 'Captain financial % source % requires exactly one posted event', p_type, p_id USING ERRCODE = '23514';
    END IF;
    SELECT * INTO event_row FROM financial_transactions WHERE id = matching_event_id;
    IF event_row.transaction_kind <> 'EVENT' OR event_row.status <> 'POSTED' OR event_row.source_version <> 1
       OR event_row.branch_id IS DISTINCT FROM source_row.branch_id
       OR event_row.currency_id IS DISTINCT FROM source_row.currency_id
       OR event_row.occurred_at IS DISTINCT FROM source_row.occurred_at THEN
        RAISE EXCEPTION 'Captain financial % event % differs from immutable source %', p_type, event_row.id, p_id USING ERRCODE = '23514';
    END IF;

    SELECT e.*, a.account_kind, a.financial_owner_id, a.currency_id AS account_currency_id
      INTO entry_row FROM financial_entries e JOIN financial_accounts a ON a.id = e.financial_account_id
      WHERE e.financial_transaction_id = event_row.id;
    IF NOT FOUND OR (SELECT count(*) FROM financial_entries WHERE financial_transaction_id = event_row.id) <> 1
       OR entry_row.account_kind <> required_kind OR entry_row.direction <> required_direction
       OR entry_row.amount IS DISTINCT FROM source_row.amount
       OR entry_row.account_currency_id <> source_row.currency_id THEN
        RAISE EXCEPTION 'Captain financial % event % has wrong account, direction, or exact amount', p_type, event_row.id USING ERRCODE = '23514';
    END IF;
    SELECT * INTO owner_row FROM financial_owners WHERE id = entry_row.financial_owner_id;
    IF owner_row.owner_type <> 'CAPTAIN' OR owner_row.captain_id IS DISTINCT FROM source_row.captain_id THEN
        RAISE EXCEPTION 'Captain financial % event % targets another Captain', p_type, event_row.id USING ERRCODE = '23514';
    END IF;

    IF p_type <> 'PAYOUT' THEN RETURN; END IF;
    PERFORM id FROM financial_accounts WHERE id = entry_row.financial_account_id FOR UPDATE;
    allocated := 0;
    FOR source_entry IN
        SELECT a.*, e.amount AS source_amount, e.direction, e.currency_id AS entry_currency_id,
               t.id AS source_transaction_id, t.branch_id AS source_branch_id,
               t.event_type AS source_event_type, t.status AS source_status,
               t.transaction_kind AS source_kind, e.financial_account_id AS source_account_id
        FROM captain_receivable_payout_allocations a
        JOIN financial_entries e ON e.id = a.financial_entry_id
        JOIN financial_transactions t ON t.id = e.financial_transaction_id
        WHERE a.captain_receivable_payout_id = p_id ORDER BY a.financial_entry_id
    LOOP
        IF source_entry.source_account_id <> entry_row.financial_account_id
           OR source_entry.entry_currency_id <> source_row.currency_id
           OR source_entry.source_branch_id IS DISTINCT FROM source_row.branch_id
           OR source_entry.direction <> 'INCREASE' OR source_entry.source_status <> 'POSTED'
           OR source_entry.source_kind <> 'EVENT'
           OR source_entry.source_event_type NOT IN ('ORDER_CASH_DELIVERY_SHORTFALL_DUE',
               'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE', 'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE')
           OR EXISTS (SELECT 1 FROM financial_transactions r
               WHERE r.reversal_of_transaction_id = source_entry.source_transaction_id) THEN
            RAISE EXCEPTION 'Payout % allocation % has an ineligible Receivable source', p_id, source_entry.id USING ERRCODE = '23514';
        END IF;
        SELECT coalesce(sum(amount), 0) INTO allocated_total FROM captain_receivable_payout_allocations
        WHERE financial_entry_id = source_entry.financial_entry_id;
        IF allocated_total > source_entry.source_amount THEN
            RAISE EXCEPTION 'Receivable entry % is overallocated', source_entry.financial_entry_id USING ERRCODE = '23514';
        END IF;
        allocated := allocated + source_entry.amount;
    END LOOP;
    IF allocated <> source_row.amount THEN
        RAISE EXCEPTION 'Payout % allocation total % differs from amount %', p_id, allocated, source_row.amount USING ERRCODE = '23514';
    END IF;
    SELECT coalesce(sum(CASE WHEN e.direction = 'INCREASE' THEN e.amount ELSE -e.amount END), 0)
      INTO branch_balance FROM financial_entries e JOIN financial_transactions t ON t.id = e.financial_transaction_id
      WHERE e.financial_account_id = entry_row.financial_account_id AND t.branch_id = source_row.branch_id;
    IF branch_balance < 0 THEN
        RAISE EXCEPTION 'Payout % would make Branch Receivable negative', p_id USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION validate_captain_financial_operation_trigger() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE kind text;
DECLARE source_identity text;
DECLARE source_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'captain_deposit_receipts' THEN
        PERFORM validate_captain_financial_operation('DEPOSIT', NEW.id);
    ELSIF TG_TABLE_NAME = 'captain_receivable_payouts' THEN
        PERFORM validate_captain_financial_operation('PAYOUT', NEW.id);
    ELSIF TG_TABLE_NAME = 'captain_receivable_payout_allocations' THEN
        PERFORM validate_captain_financial_operation('PAYOUT', NEW.captain_receivable_payout_id);
    ELSE
        IF TG_TABLE_NAME = 'financial_entries' THEN
            SELECT source_type, source_id INTO kind, source_identity FROM financial_transactions WHERE id = NEW.financial_transaction_id;
        ELSE
            kind := NEW.source_type;
            source_identity := NEW.source_id;
        END IF;
        IF kind NOT IN ('CAPTAIN_DEPOSIT_RECEIPT', 'CAPTAIN_RECEIVABLE_PAYOUT') THEN
            RETURN NULL;
        END IF;
        IF source_identity !~ '^[1-9][0-9]{0,18}$' OR source_identity::numeric > 9223372036854775807 THEN
            RAISE EXCEPTION 'Captain financial source identity is invalid' USING ERRCODE = '23514';
        END IF;
        source_id := source_identity::bigint;
        IF kind = 'CAPTAIN_DEPOSIT_RECEIPT' THEN
            PERFORM validate_captain_financial_operation('DEPOSIT', source_id);
        ELSIF kind = 'CAPTAIN_RECEIVABLE_PAYOUT' THEN
            PERFORM validate_captain_financial_operation('PAYOUT', source_id);
        END IF;
    END IF;
    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION guard_captain_financial_event_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.event_type IN ('CAPTAIN_DEPOSIT_RECEIVED', 'CAPTAIN_RECEIVABLE_PAID')
       AND (NEW.transaction_kind <> 'EVENT' OR NEW.source_type IS DISTINCT FROM
           CASE NEW.event_type WHEN 'CAPTAIN_DEPOSIT_RECEIVED' THEN 'CAPTAIN_DEPOSIT_RECEIPT'
               ELSE 'CAPTAIN_RECEIVABLE_PAYOUT' END
           OR NEW.source_id !~ '^[1-9][0-9]{0,18}$'
           OR (NEW.source_id ~ '^[1-9][0-9]{0,18}$' AND NEW.source_id::numeric > 9223372036854775807)) THEN
        RAISE EXCEPTION 'Captain financial event % requires its canonical source', NEW.event_type USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER captain_financial_event_identity BEFORE INSERT ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION guard_captain_financial_event_identity();

CREATE CONSTRAINT TRIGGER captain_deposit_receipt_complete AFTER INSERT ON captain_deposit_receipts
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_operation_trigger();
CREATE CONSTRAINT TRIGGER captain_receivable_payout_complete AFTER INSERT ON captain_receivable_payouts
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_operation_trigger();
CREATE CONSTRAINT TRIGGER captain_payout_allocation_complete AFTER INSERT ON captain_receivable_payout_allocations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_operation_trigger();
CREATE CONSTRAINT TRIGGER captain_financial_event_complete AFTER INSERT OR UPDATE OF status ON financial_transactions
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_operation_trigger();
CREATE CONSTRAINT TRIGGER captain_financial_entry_complete AFTER INSERT ON financial_entries
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_operation_trigger();

CREATE OR REPLACE FUNCTION protect_paid_receivable_from_reversal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_account_id bigint;
BEGIN
    IF NEW.transaction_kind <> 'REVERSAL' THEN RETURN NEW; END IF;
    FOR source_account_id IN
        SELECT DISTINCT e.financial_account_id FROM financial_entries e
        JOIN financial_accounts a ON a.id = e.financial_account_id
        WHERE e.financial_transaction_id = NEW.reversal_of_transaction_id
          AND e.direction = 'INCREASE' AND a.account_kind = 'CAPTAIN_COMPANY_RECEIVABLE'
        ORDER BY e.financial_account_id
    LOOP
        PERFORM id FROM financial_accounts WHERE id = source_account_id FOR UPDATE;
    END LOOP;
    IF EXISTS (SELECT 1 FROM captain_receivable_payout_allocations a
        JOIN financial_entries e ON e.id = a.financial_entry_id
        WHERE e.financial_transaction_id = NEW.reversal_of_transaction_id) THEN
        RAISE EXCEPTION 'Paid Receivable source requires controlled future correction' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER captain_paid_source_reversal_guard BEFORE INSERT ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION protect_paid_receivable_from_reversal();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Captain financial source history is intentionally forward-only.');
    }
};
