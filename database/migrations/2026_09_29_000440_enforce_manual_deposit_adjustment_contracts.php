<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION manual_deposit_branch_legitimate(p_captain bigint,p_branch bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (SELECT 1 FROM captains WHERE id=p_captain AND home_branch_id=p_branch)
       OR EXISTS (SELECT 1 FROM captain_branch_assignments WHERE captain_id=p_captain
           AND starts_at<=clock_timestamp() AND (branch_id=p_branch OR (assignment_type='PERMANENT_TRANSFER' AND from_branch_id=p_branch)))
       OR EXISTS (SELECT 1 FROM financial_entries e JOIN financial_transactions t ON t.id=e.financial_transaction_id
           JOIN financial_accounts a ON a.id=e.financial_account_id JOIN financial_owners o ON o.id=a.financial_owner_id
           WHERE o.owner_type='CAPTAIN' AND o.captain_id=p_captain AND t.branch_id=p_branch AND t.status='POSTED');
$$;
CREATE OR REPLACE FUNCTION manual_deposit_payload(p jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
    SELECT (p - ARRAY['id','created_at','payload_hash']) || jsonb_build_object(
        'amount',trim_scale((p->>'amount')::numeric)::text,
        'request_key',((p->>'request_key')::uuid)::text,
        'occurred_at',to_char((p->>'occurred_at')::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'));
$$;
CREATE OR REPLACE FUNCTION manual_deposit_hash(p jsonb) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT encode(sha256(convert_to(manual_deposit_payload(p)::text,'UTF8')),'hex');
$$;
CREATE OR REPLACE FUNCTION guard_manual_deposit_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE places integer;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Manual Deposit adjustment history is immutable' USING ERRCODE='55000';
    END IF;
    IF NOT captain_manual_deposits_active() THEN
        RAISE EXCEPTION 'Manual Deposit adjustments are not activated' USING ERRCODE='23514';
    END IF;
    SELECT decimal_places INTO places FROM currencies WHERE id=NEW.currency_id FOR UPDATE;
    IF places IS NULL OR NEW.amount::text IN ('NaN','Infinity','-Infinity') OR NEW.amount<=0
       OR NEW.amount<>round(NEW.amount,places) THEN
        RAISE EXCEPTION 'Manual Deposit amount violates Currency precision' USING ERRCODE='23514';
    END IF;
    -- Seal source authorization at insertion; later role changes do not rewrite history.
    PERFORM id FROM users WHERE id=NEW.adjusted_by_user_id AND is_active FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Manual Deposit actor must be active SUPER_ADMIN' USING ERRCODE='23514'; END IF;
    PERFORM ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id
        WHERE ur.user_id=NEW.adjusted_by_user_id AND r.code='SUPER_ADMIN' FOR SHARE OF ur,r;
    IF NOT FOUND THEN RAISE EXCEPTION 'Manual Deposit actor must be SUPER_ADMIN' USING ERRCODE='23514'; END IF;
    IF NOT manual_deposit_branch_legitimate(NEW.captain_id,NEW.branch_id) THEN
        RAISE EXCEPTION 'Manual Deposit Captain has no legitimate Branch relationship' USING ERRCODE='23514';
    END IF;
    IF NEW.occurred_at>clock_timestamp() OR NEW.payload_hash<>manual_deposit_hash(to_jsonb(NEW)) THEN
        RAISE EXCEPTION 'Manual Deposit source time or canonical hash is invalid' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION guard_manual_deposit_event() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s captain_deposit_manual_adjustments%ROWTYPE;
BEGIN
    IF EXISTS (SELECT 1 FROM financial_transactions t WHERE t.id=NEW.reversal_of_transaction_id
        AND t.source_type='CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT') THEN
        RAISE EXCEPTION 'Manual Deposit adjustments cannot be reversed generically' USING ERRCODE='23514';
    END IF;
    IF NEW.source_type<>'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT'
       AND NEW.event_type NOT IN ('CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_INCREASE','CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_DECREASE') THEN RETURN NEW; END IF;
    IF NOT captain_manual_deposits_active() OR NEW.source_type IS DISTINCT FROM 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT'
       OR NEW.source_id !~ '^[1-9][0-9]{0,18}$' THEN
        RAISE EXCEPTION 'Manual Deposit event requires active canonical source' USING ERRCODE='23514';
    END IF;
    IF NEW.source_id::numeric>9223372036854775807 THEN
        RAISE EXCEPTION 'Invalid manual Deposit source ID' USING ERRCODE='23514';
    END IF;
    SELECT * INTO s FROM captain_deposit_manual_adjustments WHERE id=NEW.source_id::bigint;
    IF NOT FOUND THEN RAISE EXCEPTION 'Manual Deposit source is missing' USING ERRCODE='23514'; END IF;
    PERFORM id FROM currencies WHERE id=s.currency_id FOR UPDATE;
    IF NEW.transaction_kind<>'EVENT' OR NEW.source_version<>1 OR NEW.reversal_of_transaction_id IS NOT NULL
       OR NEW.event_type IS DISTINCT FROM 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_'||s.direction
       OR NEW.currency_id IS DISTINCT FROM s.currency_id OR NEW.branch_id IS DISTINCT FROM s.branch_id
       OR NEW.recorded_by_user_id IS DISTINCT FROM s.adjusted_by_user_id
       OR NEW.occurred_at IS DISTINCT FROM s.occurred_at OR NEW.reason IS DISTINCT FROM s.reason
       OR NEW.notes IS DISTINCT FROM s.payload_hash THEN
        RAISE EXCEPTION 'Manual Deposit posting does not match source %',s.id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION validate_manual_deposit(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE s captain_deposit_manual_adjustments%ROWTYPE; t financial_transactions%ROWTYPE; entry_row record; places integer;
BEGIN
    SELECT * INTO s FROM captain_deposit_manual_adjustments WHERE id=p_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'Manual Deposit source % is missing',p_id USING ERRCODE='23514'; END IF;
    SELECT decimal_places INTO places FROM currencies WHERE id=s.currency_id;
    IF places IS NULL OR s.amount::text IN ('NaN','Infinity','-Infinity') OR s.amount<=0 OR s.amount<>round(s.amount,places)
       OR s.direction NOT IN ('INCREASE','DECREASE') OR s.reason !~ '[^[:space:]]'
       OR s.evidence_path !~ '^proof/[0-9a-f-]{36}\.(jpg|png|webp)$' OR s.evidence_name !~ '[^[:space:]]'
       OR s.evidence_mime NOT IN ('image/jpeg','image/png','image/webp') OR s.evidence_size NOT BETWEEN 1 AND 5242880
       OR s.evidence_sha256 !~ '^[0-9a-f]{64}$' OR s.occurred_at>clock_timestamp()
       OR s.payload_hash<>manual_deposit_hash(to_jsonb(s)) THEN
        RAISE EXCEPTION 'Manual Deposit source % amount/evidence/hash is invalid',p_id USING ERRCODE='23514';
    END IF;
    IF (SELECT count(*) FROM financial_transactions WHERE source_type='CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT' AND source_id=p_id::text)<>1 THEN
        RAISE EXCEPTION 'Manual Deposit source % requires exactly one transaction',p_id USING ERRCODE='23514';
    END IF;
    SELECT * INTO t FROM financial_transactions WHERE source_type='CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT' AND source_id=p_id::text;
    IF t.status<>'POSTED' OR t.transaction_kind<>'EVENT' OR t.source_version<>1 OR t.reversal_of_transaction_id IS NOT NULL
       OR t.event_type IS DISTINCT FROM 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_'||s.direction
       OR t.branch_id IS DISTINCT FROM s.branch_id OR t.currency_id IS DISTINCT FROM s.currency_id
       OR t.recorded_by_user_id IS DISTINCT FROM s.adjusted_by_user_id OR t.occurred_at IS DISTINCT FROM s.occurred_at
       OR t.reason IS DISTINCT FROM s.reason OR t.notes IS DISTINCT FROM s.payload_hash
       OR (SELECT count(*) FROM financial_entries WHERE financial_transaction_id=t.id)<>1
       OR EXISTS(SELECT 1 FROM financial_transactions WHERE reversal_of_transaction_id=t.id) THEN
        RAISE EXCEPTION 'Manual Deposit source % has an invalid complete posting',p_id USING ERRCODE='23514';
    END IF;
    SELECT e.*,a.account_kind,a.currency_id AS account_currency,o.owner_type,o.captain_id INTO entry_row
        FROM financial_entries e JOIN financial_accounts a ON a.id=e.financial_account_id
        JOIN financial_owners o ON o.id=a.financial_owner_id WHERE e.financial_transaction_id=t.id;
    IF entry_row.account_kind<>'CAPTAIN_DEPOSIT' OR entry_row.owner_type<>'CAPTAIN' OR entry_row.captain_id IS DISTINCT FROM s.captain_id
       OR entry_row.currency_id IS DISTINCT FROM s.currency_id OR entry_row.account_currency IS DISTINCT FROM s.currency_id
       OR entry_row.amount IS DISTINCT FROM s.amount OR entry_row.direction IS DISTINCT FROM s.direction THEN
        RAISE EXCEPTION 'Manual Deposit source % has wrong account, direction or amount',p_id USING ERRCODE='23514';
    END IF;
END;
$$;
CREATE OR REPLACE FUNCTION validate_manual_deposit_trigger() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME='captain_deposit_manual_adjustments' THEN
        PERFORM validate_manual_deposit(NEW.id);
    ELSE
        IF TG_TABLE_NAME='financial_entries' THEN SELECT * INTO t FROM financial_transactions WHERE id=NEW.financial_transaction_id;
        ELSE t:=NEW; END IF;
        IF t.source_type='CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT' THEN PERFORM validate_manual_deposit(t.source_id::bigint); END IF;
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER manual_deposit_source_complete AFTER INSERT ON captain_deposit_manual_adjustments
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_manual_deposit_trigger();
CREATE CONSTRAINT TRIGGER manual_deposit_transaction_complete AFTER INSERT OR UPDATE ON financial_transactions
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_manual_deposit_trigger();
CREATE CONSTRAINT TRIGGER manual_deposit_entry_complete AFTER INSERT ON financial_entries
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_manual_deposit_trigger();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Manual Deposit contracts are forward-only.');
    }
};
