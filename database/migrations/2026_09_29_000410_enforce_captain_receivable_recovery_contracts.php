<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Recovery contracts require PostgreSQL.');
        }
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_financial_corrections, captain_receivable_recoveries, captain_receivable_recovery_allocations IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION captain_recovery_payload(r captain_receivable_recoveries) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT (to_jsonb(r)-'id'-'payload_hash'-'created_at') || jsonb_build_object(
 'amount',trim_scale(r.amount)::text,'received_at',to_char(r.received_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
 'allocations',coalesce((
 SELECT jsonb_agg(jsonb_build_object('disposition_id',d.id,'amount',trim_scale(d.recovery_required)::text,'disposition_hash',d.payload_hash) ORDER BY d.id)
 FROM invoice_correction_receivable_dispositions d WHERE d.invoice_financial_correction_id=r.invoice_financial_correction_id
 AND d.captain_id=r.captain_id AND d.branch_id=r.branch_id AND d.currency_id=r.currency_id AND d.recovery_required>0),'[]'::jsonb));
$$;
CREATE OR REPLACE FUNCTION validate_captain_recovery(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r captain_receivable_recoveries%ROWTYPE; c invoice_financial_corrections%ROWTYPE; required numeric;
BEGIN
 SELECT * INTO r FROM captain_receivable_recoveries WHERE id=p_id;
 IF r.id IS NULL THEN RETURN; END IF;
 SELECT * INTO c FROM invoice_financial_corrections WHERE id=r.invoice_financial_correction_id;
 IF NOT captain_receivable_recoveries_active() OR NOT receivable_dispositions_active()
 OR c.id IS NULL OR c.receivable_contract_version<>2 OR NOT c.receivable_dispositions_captured
 OR c.state NOT IN ('READY_TO_FINALIZE','COMPLETED')
 OR r.branch_id<>c.branch_id OR r.currency_id<>c.currency_id OR r.method<>'CASH'
 OR r.received_at<c.prepared_at OR r.received_at>clock_timestamp()
 OR r.amount<>round(r.amount,c.currency_decimal_places)
 OR r.payload_hash<>encode(sha256(convert_to(captain_recovery_payload(r)::text,'UTF8')),'hex') THEN
 RAISE EXCEPTION 'Invalid Captain recovery header/hash: recovery_id=% correction_id=%',p_id,c.id USING ERRCODE='23514'; END IF;
 SELECT coalesce(sum(recovery_required),0) INTO required FROM invoice_correction_receivable_dispositions
 WHERE invoice_financial_correction_id=c.id AND captain_id=r.captain_id AND branch_id=r.branch_id AND currency_id=r.currency_id AND recovery_required>0;
 IF required<=0 OR r.amount<>required THEN RAISE EXCEPTION 'Recovery % must equal exact full requirement %',p_id,required USING ERRCODE='23514'; END IF;
 IF EXISTS(
 (SELECT id,recovery_required FROM invoice_correction_receivable_dispositions WHERE invoice_financial_correction_id=c.id
 AND captain_id=r.captain_id AND branch_id=r.branch_id AND currency_id=r.currency_id AND recovery_required>0
 EXCEPT ALL SELECT disposition_id,amount FROM captain_receivable_recovery_allocations WHERE captain_receivable_recovery_id=p_id)
 UNION ALL
 (SELECT disposition_id,amount FROM captain_receivable_recovery_allocations WHERE captain_receivable_recovery_id=p_id
 EXCEPT ALL SELECT id,recovery_required FROM invoice_correction_receivable_dispositions WHERE invoice_financial_correction_id=c.id
 AND captain_id=r.captain_id AND branch_id=r.branch_id AND currency_id=r.currency_id AND recovery_required>0)
 ) THEN RAISE EXCEPTION 'Recovery % allocation exact set differs',p_id USING ERRCODE='23514'; END IF;
END;
$$;
CREATE OR REPLACE FUNCTION validate_correction_recoveries(p_id bigint,p_complete boolean DEFAULT false) RETURNS void LANGUAGE plpgsql AS $$
DECLARE receipt_id bigint;
BEGIN
 FOR receipt_id IN SELECT id FROM captain_receivable_recoveries WHERE invoice_financial_correction_id=p_id LOOP
 PERFORM validate_captain_recovery(receipt_id);
 END LOOP;
 IF p_complete AND EXISTS(SELECT 1 FROM invoice_correction_receivable_dispositions d
 WHERE d.invoice_financial_correction_id=p_id AND d.recovery_required>0 AND NOT EXISTS(
 SELECT 1 FROM captain_receivable_recovery_allocations a JOIN captain_receivable_recoveries r ON r.id=a.captain_receivable_recovery_id
 WHERE a.disposition_id=d.id AND a.amount=d.recovery_required AND r.invoice_financial_correction_id=p_id
 AND r.captain_id=d.captain_id AND r.branch_id=d.branch_id AND r.currency_id=d.currency_id)) THEN
 RAISE EXCEPTION 'CAPTAIN_RECOVERY_REQUIRED: correction_id=%',p_id USING ERRCODE='23514'; END IF;
END;
$$;
CREATE OR REPLACE FUNCTION guard_captain_recovery_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c invoice_financial_corrections%ROWTYPE; parent_order_id bigint;
BEGIN
 SELECT order_id INTO parent_order_id FROM invoice_financial_corrections WHERE id=NEW.invoice_financial_correction_id;
 PERFORM id FROM orders WHERE id=parent_order_id FOR UPDATE;
 SELECT * INTO c FROM invoice_financial_corrections WHERE id=NEW.invoice_financial_correction_id FOR UPDATE;
 IF c.state<>'READY_TO_FINALIZE' OR c.receivable_contract_version<>2 OR NOT c.receivable_dispositions_captured
 OR NEW.branch_id<>c.branch_id OR NEW.currency_id<>c.currency_id THEN
 RAISE EXCEPTION 'Recovery needs authoritative active ready correction %',c.id USING ERRCODE='23514'; END IF;
 PERFORM id FROM currencies WHERE id=c.currency_id FOR UPDATE;
 PERFORM id FROM users WHERE id=NEW.received_by_user_id AND is_active FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Recovery receiver is inactive/unknown' USING ERRCODE='23514'; END IF;
 PERFORM ur.user_id FROM user_roles ur JOIN roles role ON role.id=ur.role_id
 WHERE ur.user_id=NEW.received_by_user_id AND role.code='ORDER_EMPLOYEE' FOR SHARE OF ur,role;
 IF NOT FOUND THEN RAISE EXCEPTION 'Physical receiver must be an ORDER_EMPLOYEE' USING ERRCODE='23514'; END IF;
 PERFORM user_id FROM user_branches WHERE user_id=NEW.received_by_user_id AND branch_id=NEW.branch_id FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Physical receiver must belong to historical Branch' USING ERRCODE='23514'; END IF;
 -- Authorization is sealed at INSERT. Later role/Branch changes do not rewrite history.
 RETURN NEW;
END;
$$;
CREATE TRIGGER aa_recovery_source BEFORE INSERT ON captain_receivable_recoveries FOR EACH ROW EXECUTE FUNCTION guard_captain_recovery_source();
CREATE OR REPLACE FUNCTION guard_captain_recovery_allocation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r captain_receivable_recoveries%ROWTYPE; d invoice_correction_receivable_dispositions%ROWTYPE;
BEGIN
 SELECT * INTO r FROM captain_receivable_recoveries WHERE id=NEW.captain_receivable_recovery_id;
 SELECT * INTO d FROM invoice_correction_receivable_dispositions WHERE id=NEW.disposition_id;
 IF r.id IS NULL OR d.id IS NULL OR r.invoice_financial_correction_id<>d.invoice_financial_correction_id
 OR r.captain_id<>d.captain_id OR r.branch_id<>d.branch_id OR r.currency_id<>d.currency_id
 OR d.recovery_required<=0 OR NEW.amount<>d.recovery_required
 OR (SELECT state FROM invoice_financial_corrections WHERE id=r.invoice_financial_correction_id)<>'READY_TO_FINALIZE' THEN
 RAISE EXCEPTION 'Invalid recovery allocation source/amount' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER recovery_allocation_source BEFORE INSERT ON captain_receivable_recovery_allocations FOR EACH ROW EXECUTE FUNCTION guard_captain_recovery_allocation();
CREATE OR REPLACE FUNCTION captain_recovery_constraint() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r_id bigint; c_id bigint;
BEGIN
 IF TG_TABLE_NAME='captain_receivable_recoveries' THEN r_id:=NEW.id; ELSE r_id:=NEW.captain_receivable_recovery_id; END IF;
 SELECT invoice_financial_correction_id INTO c_id FROM captain_receivable_recoveries WHERE id=r_id;
 PERFORM validate_captain_recovery(r_id);
 PERFORM validate_recovery_disposition_source(c_id);
 RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER recovery_complete AFTER INSERT ON captain_receivable_recoveries DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW EXECUTE FUNCTION captain_recovery_constraint();
CREATE CONSTRAINT TRIGGER recovery_allocation_complete AFTER INSERT ON captain_receivable_recovery_allocations DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW EXECUTE FUNCTION captain_recovery_constraint();
SQL);
            $definition = DB::scalar("SELECT pg_get_functiondef('validate_receivable_disposition_set(bigint)'::regprocedure)");
            if (! str_contains($definition, "IF c.state='COMPLETED' AND d.recovery_required>0 THEN")
                || ! str_contains($definition, " END LOOP;\nEND;")) {
                throw new RuntimeException('Unexpected installed disposition contract; recovery deployment remains closed.');
            }
            $definition = str_replace("IF c.state='COMPLETED' AND d.recovery_required>0 THEN", "IF c.state='COMPLETED' AND d.recovery_required>0 AND NOT captain_receivable_recoveries_active() THEN", $definition);
            $definition = str_replace(" END LOOP;\nEND;", " END LOOP;\n IF captain_receivable_recoveries_active() THEN PERFORM validate_correction_recoveries(p_id,c.state='COMPLETED'); END IF;\nEND;", $definition);
            DB::unprepared($definition);
            // Reservation prevents new original allocations; receipt validation needs no Account lock.
            $receiptSource = str_replace('FUNCTION '.DB::getSchemaBuilder()->getCurrentSchemaName().'.validate_receivable_disposition_set(',
                'FUNCTION '.DB::getSchemaBuilder()->getCurrentSchemaName().'.validate_recovery_disposition_source(', $definition);
            $start = strpos($receiptSource, ' PERFORM id FROM currencies');
            $end = strpos($receiptSource, ' END LOOP;', $start);
            if ($start === false || $end === false) {
                throw new RuntimeException('Cannot install immutable recovery source validator.');
            }
            $receiptSource = substr($receiptSource, 0, $start).substr($receiptSource, $end + strlen(' END LOOP;'));
            DB::unprepared($receiptSource);
            $expected = DB::scalar("SELECT pg_get_functiondef('expected_receivable_dispositions(bigint)'::regprocedure)");
            if (! str_contains($expected, "WHERE o.operational_status='DELIVERED'")) {
                throw new RuntimeException('Unexpected installed historical source resolver; recovery deployment remains closed.');
            }
            $expected = str_replace("WHERE o.operational_status='DELIVERED'", "WHERE (o.operational_status='DELIVERED' OR (o.operational_status='CANCELLED' AND c.kind='CANCEL_AND_REFUND' AND c.receivable_contract_version=2))", $expected);
            DB::unprepared($expected);
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION captain_recovery_cancellation_allowed(p_order bigint,p_intent boolean) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE c invoice_financial_corrections%ROWTYPE;
BEGIN
 IF NOT captain_receivable_recoveries_active() OR EXISTS(SELECT 1 FROM order_bill_payments WHERE order_id=p_order) THEN RETURN false; END IF;
 SELECT * INTO c FROM invoice_financial_corrections WHERE order_id=p_order AND kind='CANCEL_AND_REFUND'
 AND receivable_contract_version=2 AND receivable_dispositions_captured
 AND state=CASE WHEN p_intent THEN 'READY_TO_FINALIZE' ELSE 'COMPLETED' END ORDER BY id DESC LIMIT 1;
 IF c.id IS NULL OR c.corrected_amount<>0 OR NOT EXISTS(SELECT 1 FROM customer_refunds WHERE invoice_financial_correction_id=c.id AND amount=c.original_amount) THEN RETURN false; END IF;
 PERFORM validate_receivable_disposition_set(c.id);
 IF NOT p_intent THEN
 PERFORM validate_correction_recoveries(c.id,true);
 IF EXISTS(SELECT 1 FROM invoices WHERE order_id=p_order AND superseded_at IS NULL) THEN RETURN false; END IF;
 END IF;
 RETURN true;
END;
$$;
SQL);
            $cancel = DB::scalar("SELECT pg_get_functiondef('phase9c3a_can_cancel(bigint,boolean)'::regprocedure)");
            $cancel = str_replace("BEGIN\n", "BEGIN\n    IF captain_recovery_cancellation_allowed(p_order_id,p_for_intent) THEN RETURN true; END IF;\n", $cancel);
            DB::unprepared($cancel);
            $intent = DB::scalar("SELECT pg_get_functiondef('order_intent_validate()'::regprocedure)");
            if (! str_contains($intent, "OR order_state IN ('DELIVERED','CANCELLED')")) {
                throw new RuntimeException('Unexpected installed cancellation guard; recovery deployment remains closed.');
            }
            $intent = str_replace("OR order_state IN ('DELIVERED','CANCELLED')", "OR (order_state IN ('DELIVERED','CANCELLED') AND NOT (order_state='DELIVERED' AND captain_recovery_cancellation_allowed(NEW.order_id,true)))", $intent);
            DB::unprepared($intent);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Recovery source contracts are forward-only.');
    }
};
