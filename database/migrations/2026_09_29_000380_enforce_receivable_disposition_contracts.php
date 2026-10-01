<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Receivable disposition contracts require PostgreSQL.');
        }
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_financial_corrections, financial_transactions, financial_entries, captain_receivable_payout_allocations IN SHARE ROW EXCLUSIVE MODE');
            $sourceGuard = DB::scalar("SELECT pg_get_functiondef('validate_invoice_correction_source()'::regprocedure)");
            foreach (['NEW', 'OLD'] as $row) {
                $sourceGuard = str_replace("to_jsonb({$row})-'state'", "to_jsonb({$row})-'receivable_contract_version'-'receivable_dispositions_captured'-'state'", $sourceGuard);
            }
            // The dedicated version guard controls these two new preparation-only fields.
            DB::unprepared($sourceGuard);
            foreach (['validate_complete_correction_financial_set', 'validate_invoice_correction_ledger_transaction', 'validate_captain_financial_operation'] as $name) {
                $signature = $name === 'validate_captain_financial_operation' ? '(text,bigint)' : '(bigint)';
                $definition = DB::scalar('SELECT pg_get_functiondef(?::regprocedure)', [$name.$signature]);
                $definition = str_replace('FUNCTION '.DB::getSchemaBuilder()->getCurrentSchemaName().'.'.$name.'(', 'FUNCTION '.DB::getSchemaBuilder()->getCurrentSchemaName().'.'.$name.'_9c3a(', $definition);
                // Retain the installed v1 validator verbatim, except the explicitly new residual whitelist.
                if ($name === 'validate_captain_financial_operation') {
                    $definition = str_replace("'INVOICE_CORRECTION_SHAM_DELIVERY_DUE')", "'INVOICE_CORRECTION_SHAM_DELIVERY_DUE', 'RECEIVABLE_CORRECTED_RESIDUAL_DUE')", $definition);
                }
                DB::unprepared($definition);
            }
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION expected_receivable_dispositions(p_id bigint)
RETURNS TABLE(concept text,captain_id bigint,order_captain_id bigint,original_entry_id bigint,
 original_transaction_id bigint,financial_account_id bigint,original_entitlement numeric,historical_paid numeric,corrected_entitlement numeric)
LANGUAGE sql STABLE AS $$
WITH c AS (SELECT * FROM invoice_financial_corrections WHERE id=p_id),
lines AS (
 SELECT l.*, row_number() OVER (ORDER BY l.order_captain_id)-1 AS ordinal, count(*) OVER () AS n,
   ic.collection_method, c.kind, c.currency_decimal_places AS places, c.currency_id, c.original_collection_id,
   (SELECT coalesce(sum(round(i.quantity*(j.value->>'unit_price')::numeric,c.currency_decimal_places)),0)
      FROM jsonb_array_elements(c.proposal_payload->'items') j JOIN invoice_items i
      ON i.id=(j.value->>'invoice_item_id')::bigint AND i.invoice_id=c.original_invoice_id) AS subtotal,
   (SELECT (j.value->>'customer_amount')::numeric FROM jsonb_array_elements(c.proposal_payload->'delivery') j
      WHERE (j.value->>'order_captain_id')::bigint=l.order_captain_id) AS customer
 FROM c JOIN order_financial_source_snapshot_lines l ON l.order_financial_source_snapshot_id=c.original_snapshot_id
 JOIN invoice_collections ic ON ic.id=c.original_collection_id JOIN orders o ON o.id=c.order_id
 WHERE o.operational_status='DELIVERED'
), concepts AS (
 SELECT l.*, 'CASH_SHORTFALL'::text AS concept,l.captain_shortfall AS original,
   CASE WHEN kind='CANCEL_AND_REFUND' THEN 0 ELSE greatest(l.captain_entitlement-l.customer,0) END AS corrected
 FROM lines l WHERE collection_method='CASH'
 UNION ALL
 SELECT l.*, 'SHAM_PRODUCT',l.product_funding_share,
   CASE WHEN kind='CANCEL_AND_REFUND' THEN 0 ELSE
     (floor(l.subtotal*power(10::numeric,l.places)/l.n)
      +CASE WHEN l.ordinal<mod(l.subtotal*power(10::numeric,l.places),l.n) THEN 1 ELSE 0 END)/power(10::numeric,l.places) END
 FROM lines l WHERE collection_method='SHAM_CASH'
 UNION ALL
 SELECT l.*, 'SHAM_DELIVERY',l.captain_entitlement,
   CASE WHEN kind='CANCEL_AND_REFUND' THEN 0 ELSE l.captain_entitlement END
 FROM lines l WHERE collection_method='SHAM_CASH'
), source_entries AS (
 SELECT e.*, t.source_id, t.id AS tx_id, o.captain_id,
 CASE t.event_type WHEN 'ORDER_CASH_DELIVERY_SHORTFALL_DUE' THEN 'CASH_SHORTFALL'
   WHEN 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE' THEN 'SHAM_PRODUCT'
   WHEN 'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE' THEN 'SHAM_DELIVERY' END AS concept
 FROM financial_entries e JOIN financial_transactions t ON t.id=e.financial_transaction_id
 JOIN financial_accounts a ON a.id=e.financial_account_id JOIN financial_owners o ON o.id=a.financial_owner_id
 WHERE t.source_type='INVOICE_COLLECTION' AND t.source_id=(SELECT original_collection_id::text FROM c)
 AND t.transaction_kind='EVENT' AND t.status='POSTED' AND e.direction='INCREASE'
 AND a.account_kind='CAPTAIN_COMPANY_RECEIVABLE' AND o.owner_type='CAPTAIN'
)
SELECT l.concept,l.captain_id,l.order_captain_id,e.id,e.tx_id,a.id,l.original,
 coalesce((SELECT sum(p.amount) FROM captain_receivable_payout_allocations p WHERE p.financial_entry_id=e.id),0),l.corrected
FROM concepts l LEFT JOIN source_entries e ON e.captain_id=l.captain_id AND e.concept=l.concept
LEFT JOIN financial_owners own ON own.owner_type='CAPTAIN' AND own.captain_id=l.captain_id
LEFT JOIN financial_accounts a ON a.financial_owner_id=own.id AND a.currency_id=l.currency_id AND a.account_kind='CAPTAIN_COMPANY_RECEIVABLE'
WHERE l.original>0 OR l.corrected>0;
$$;

CREATE OR REPLACE FUNCTION disposition_expected_allocations(p_entry bigint,p_corrected numeric)
RETURNS TABLE(allocation_id bigint,payout_id bigint,entry_id bigint,amount numeric,covered numeric,excess numeric,occurred_at timestamptz)
LANGUAGE sql STABLE AS $$
WITH allocations AS (
 SELECT a.*,p.occurred_at,
 coalesce(sum(a.amount) OVER (ORDER BY p.occurred_at,p.id,a.id ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING),0) AS paid_before
 FROM captain_receivable_payout_allocations a JOIN captain_receivable_payouts p ON p.id=a.captain_receivable_payout_id
 WHERE a.financial_entry_id=p_entry
)
SELECT id,captain_receivable_payout_id,financial_entry_id,amount,least(amount,greatest(p_corrected-paid_before,0)),
 amount-least(amount,greatest(p_corrected-paid_before,0)),occurred_at FROM allocations
ORDER BY occurred_at,captain_receivable_payout_id,id;
$$;

CREATE OR REPLACE FUNCTION receivable_disposition_payload(d invoice_correction_receivable_dispositions)
RETURNS jsonb LANGUAGE sql STABLE AS $$
SELECT (to_jsonb(d)-'id'-'payload_hash') || jsonb_build_object('allocations',coalesce((
 SELECT jsonb_agg(to_jsonb(a) ORDER BY a.occurred_at,a.payout_id,a.allocation_id)
 FROM disposition_expected_allocations(d.original_receivable_financial_entry_id,d.corrected_entitlement) a),'[]'::jsonb));
$$;

CREATE OR REPLACE FUNCTION validate_receivable_disposition_set(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c invoice_financial_corrections%ROWTYPE; d invoice_correction_receivable_dispositions%ROWTYPE;
    account_id bigint; paid numeric; desired_version integer;
BEGIN
 SELECT * INTO c FROM invoice_financial_corrections WHERE id=p_id;
 IF c.id IS NULL OR c.state='ABANDONED' THEN RETURN; END IF;
 IF NOT c.receivable_dispositions_captured THEN
   IF c.receivable_contract_version<>1 OR EXISTS(SELECT 1 FROM invoice_correction_receivable_dispositions WHERE invoice_financial_correction_id=p_id) THEN
     RAISE EXCEPTION 'Correction % lacks disposition capture',p_id USING ERRCODE='23514';
   END IF;
   RETURN;
 END IF;
 IF NOT receivable_dispositions_active() OR c.state NOT IN ('PREPARED','READY_TO_FINALIZE','COMPLETED') THEN
   RAISE EXCEPTION 'Correction % disposition contract is not active/prepared',p_id USING ERRCODE='23514';
 END IF;
 -- Currency/global Accounts serialize with payout; never acquire Order after these locks.
 PERFORM id FROM currencies WHERE id=c.currency_id FOR UPDATE;
 FOR account_id IN SELECT financial_account_id FROM invoice_correction_receivable_dispositions
   WHERE invoice_financial_correction_id=p_id GROUP BY financial_account_id ORDER BY financial_account_id LOOP
   PERFORM id FROM financial_accounts WHERE id=account_id FOR UPDATE;
 END LOOP;
 IF EXISTS (
   (SELECT concept,captain_id,order_captain_id,original_entry_id,original_transaction_id,financial_account_id,original_entitlement,historical_paid,corrected_entitlement
    FROM expected_receivable_dispositions(p_id)
    EXCEPT ALL
    SELECT concept,captain_id,order_captain_id,original_receivable_financial_entry_id,original_receivable_financial_transaction_id,financial_account_id,original_entitlement,historical_paid,corrected_entitlement
    FROM invoice_correction_receivable_dispositions WHERE invoice_financial_correction_id=p_id)
   UNION ALL
   (SELECT concept,captain_id,order_captain_id,original_receivable_financial_entry_id,original_receivable_financial_transaction_id,financial_account_id,original_entitlement,historical_paid,corrected_entitlement
    FROM invoice_correction_receivable_dispositions WHERE invoice_financial_correction_id=p_id
    EXCEPT ALL SELECT * FROM expected_receivable_dispositions(p_id))
 ) THEN RAISE EXCEPTION 'Correction % disposition exact source/amount set differs',p_id USING ERRCODE='23514'; END IF;
 SELECT CASE WHEN coalesce(max(historical_paid),0)>0 THEN 2 ELSE 1 END INTO desired_version
 FROM invoice_correction_receivable_dispositions WHERE invoice_financial_correction_id=p_id;
 IF c.receivable_contract_version<>desired_version THEN
    RAISE EXCEPTION 'Correction % has wrong Receivable contract version',p_id USING ERRCODE='23514';
 END IF;
 FOR d IN SELECT * FROM invoice_correction_receivable_dispositions WHERE invoice_financial_correction_id=p_id LOOP
   IF d.branch_id<>c.branch_id OR d.currency_id<>c.currency_id OR d.currency_decimal_places<>c.currency_decimal_places
     OR d.contract_version<>c.receivable_contract_version OR d.proposal_hash<>c.proposal_hash
     OR d.prepared_by IS DISTINCT FROM c.prepared_by OR d.captured_at IS DISTINCT FROM c.prepared_at
     OR d.payload_hash<>encode(sha256(convert_to(receivable_disposition_payload(d)::text,'UTF8')),'hex') THEN
     RAISE EXCEPTION 'Disposition % has invalid frozen header/hash',d.id USING ERRCODE='23514';
   END IF;
   IF d.original_entitlement>0 AND NOT EXISTS(SELECT 1 FROM financial_entries e JOIN financial_transactions t ON t.id=e.financial_transaction_id
     WHERE e.id=d.original_receivable_financial_entry_id AND t.id=d.original_receivable_financial_transaction_id
       AND e.financial_account_id=d.financial_account_id AND e.currency_id=d.currency_id AND e.amount=d.original_entitlement
       AND e.direction='INCREASE' AND t.branch_id=d.branch_id AND t.currency_id=d.currency_id) THEN
     RAISE EXCEPTION 'Disposition % original entry/Branch/Currency differs',d.id USING ERRCODE='23514';
   END IF;
   IF EXISTS (
     (SELECT * FROM disposition_expected_allocations(d.original_receivable_financial_entry_id,d.corrected_entitlement)
      EXCEPT ALL SELECT captain_receivable_payout_allocation_id,captain_receivable_payout_id,original_financial_entry_id,amount,covered_amount,excess_amount,payout_occurred_at
       FROM invoice_correction_receivable_disposition_allocations WHERE disposition_id=d.id)
     UNION ALL
     (SELECT captain_receivable_payout_allocation_id,captain_receivable_payout_id,original_financial_entry_id,amount,covered_amount,excess_amount,payout_occurred_at
       FROM invoice_correction_receivable_disposition_allocations WHERE disposition_id=d.id
      EXCEPT ALL SELECT * FROM disposition_expected_allocations(d.original_receivable_financial_entry_id,d.corrected_entitlement))
   ) THEN RAISE EXCEPTION 'Disposition % has stale/incomplete payout allocation snapshot',d.id USING ERRCODE='23514'; END IF;
   IF EXISTS(SELECT 1 FROM captain_receivable_payout_allocations a JOIN captain_receivable_payouts p ON p.id=a.captain_receivable_payout_id
     WHERE a.financial_entry_id=d.original_receivable_financial_entry_id AND
       (p.captain_id<>d.captain_id OR p.branch_id<>d.branch_id OR p.currency_id<>d.currency_id)) THEN
     RAISE EXCEPTION 'Disposition % contains another Captain/Branch/Currency payout',d.id USING ERRCODE='23514';
   END IF;
   IF EXISTS(SELECT 1 FROM invoice_correction_receivable_dispositions other JOIN invoice_financial_corrections oc ON oc.id=other.invoice_financial_correction_id
      WHERE other.original_receivable_financial_entry_id=d.original_receivable_financial_entry_id
        AND other.invoice_financial_correction_id<>p_id AND oc.state IN ('PREPARED','READY_TO_FINALIZE','COMPLETED')) THEN
     RAISE EXCEPTION 'Disposition % source is already reserved/disposed',d.id USING ERRCODE='23514';
   END IF;
   IF c.receivable_contract_version=2 AND EXISTS(SELECT 1 FROM financial_transactions WHERE reversal_of_transaction_id=d.original_receivable_financial_transaction_id) THEN
     RAISE EXCEPTION 'Disposition % original Receivable was reversed',d.id USING ERRCODE='23514';
   END IF;
   IF c.state='COMPLETED' AND d.recovery_required>0 THEN
     RAISE EXCEPTION 'CAPTAIN_RECOVERY_REQUIRED: disposition_id=% amount=%',d.id,d.recovery_required USING ERRCODE='23514';
   END IF;
 END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION validate_receivable_disposition_event(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE; d invoice_correction_receivable_dispositions%ROWTYPE; c invoice_financial_corrections%ROWTYPE;
 expected_amount numeric; expected_direction text;
BEGIN
 SELECT * INTO t FROM financial_transactions WHERE id=p_id;
 IF t.event_type NOT IN ('RECEIVABLE_UNPAID_REMAINDER_RETIRED','RECEIVABLE_CORRECTED_RESIDUAL_DUE')
    AND t.source_type<>'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION' THEN RETURN; END IF;
 IF t.source_type<>'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION' OR t.event_type NOT IN ('RECEIVABLE_UNPAID_REMAINDER_RETIRED','RECEIVABLE_CORRECTED_RESIDUAL_DUE')
   OR t.source_id !~ '^[1-9][0-9]{0,18}$' OR t.source_id::numeric>9223372036854775807
   OR t.transaction_kind<>'EVENT' OR t.status<>'POSTED' OR t.source_version<>2 OR t.reversal_of_transaction_id IS NOT NULL THEN
   RAISE EXCEPTION 'Invalid canonical Receivable disposition transaction %',p_id USING ERRCODE='23514';
 END IF;
 SELECT * INTO d FROM invoice_correction_receivable_dispositions WHERE id=t.source_id::bigint;
 SELECT * INTO c FROM invoice_financial_corrections WHERE id=d.invoice_financial_correction_id;
 IF d.id IS NULL OR d.contract_version<>2 OR c.state<>'COMPLETED' OR c.receivable_contract_version<>2
   OR t.branch_id IS DISTINCT FROM d.branch_id OR t.currency_id IS DISTINCT FROM d.currency_id
   OR t.occurred_at IS DISTINCT FROM c.finalized_at THEN
   RAISE EXCEPTION 'Disposition transaction % has invalid completed source',p_id USING ERRCODE='23514';
 END IF;
 PERFORM validate_receivable_disposition_set(c.id);
 expected_amount:=CASE t.event_type WHEN 'RECEIVABLE_UNPAID_REMAINDER_RETIRED' THEN d.original_unpaid ELSE d.residual_payable END;
 expected_direction:=CASE t.event_type WHEN 'RECEIVABLE_UNPAID_REMAINDER_RETIRED' THEN 'DECREASE' ELSE 'INCREASE' END;
 IF expected_amount<=0 OR (SELECT count(*) FROM financial_entries WHERE financial_transaction_id=p_id)<>1
  OR NOT EXISTS(SELECT 1 FROM financial_entries e WHERE e.financial_transaction_id=p_id AND e.financial_account_id=d.financial_account_id
      AND e.currency_id=d.currency_id AND e.direction=expected_direction AND e.amount=expected_amount) THEN
   RAISE EXCEPTION 'Disposition transaction % has wrong exact FinancialEntry set',p_id USING ERRCODE='23514';
 END IF;
END;
$$;

CREATE OR REPLACE FUNCTION validate_invoice_correction_ledger_transaction(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE; v integer;
BEGIN
 SELECT * INTO t FROM financial_transactions WHERE id=p_id;
 PERFORM validate_receivable_disposition_event(p_id);
 IF t.source_type='INVOICE_FINANCIAL_CORRECTION' THEN
   SELECT receivable_contract_version INTO v FROM invoice_financial_corrections WHERE id::text=t.source_id;
   IF v=2 AND t.event_type IN ('INVOICE_CORRECTION_CASH_SHORTFALL_DUE','INVOICE_CORRECTION_SHAM_PRODUCT_DUE','INVOICE_CORRECTION_SHAM_DELIVERY_DUE') THEN
     RAISE EXCEPTION 'Whole-C Receivable event cannot satisfy residual contract' USING ERRCODE='23514';
   END IF;
 END IF;
 PERFORM validate_invoice_correction_ledger_transaction_9c3a(p_id);
END;
$$;

CREATE OR REPLACE FUNCTION validate_complete_correction_financial_set(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c invoice_financial_corrections%ROWTYPE; d invoice_correction_receivable_dispositions%ROWTYPE; t_id bigint; company numeric; expected integer;
BEGIN
 SELECT * INTO c FROM invoice_financial_corrections WHERE id=p_id;
 IF c.id IS NULL OR c.state<>'COMPLETED' THEN RETURN; END IF;
 PERFORM validate_receivable_disposition_set(p_id);
 IF c.receivable_contract_version=1 THEN PERFORM validate_complete_correction_financial_set_9c3a(p_id); RETURN; END IF;
 -- Non-Receivable CASH movements retain the original v1 source/reversal contract.
 IF EXISTS (
   (SELECT id FROM financial_transactions WHERE source_type='INVOICE_COLLECTION' AND source_id=c.original_collection_id::text
      AND event_type='ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT'
    EXCEPT ALL SELECT original_transaction_id FROM invoice_correction_reversals WHERE invoice_financial_correction_id=p_id)
   UNION ALL
   (SELECT original_transaction_id FROM invoice_correction_reversals WHERE invoice_financial_correction_id=p_id
    EXCEPT ALL SELECT id FROM financial_transactions WHERE source_type='INVOICE_COLLECTION' AND source_id=c.original_collection_id::text
      AND event_type='ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT')
 ) THEN RAISE EXCEPTION 'Correction % has incorrect non-Receivable reversal set',p_id USING ERRCODE='23514'; END IF;
 SELECT coalesce(sum((a.value->>'company_collectible')::numeric),0) INTO company FROM invoice_correction_financial_snapshots s,
   jsonb_array_elements(s.payload->'allocations') a WHERE s.invoice_financial_correction_id=p_id;
 expected:=CASE WHEN (SELECT collection_method FROM invoice_collections WHERE id=c.original_collection_id)='CASH'
    AND c.kind='REPRICE' AND company>0 THEN 1 ELSE 0 END;
 IF (SELECT count(*) FROM financial_transactions WHERE source_type='INVOICE_FINANCIAL_CORRECTION' AND source_id=p_id::text)<>expected THEN
   RAISE EXCEPTION 'Correction % has extra/missing whole-C events',p_id USING ERRCODE='23514';
 END IF;
 FOR d IN SELECT * FROM invoice_correction_receivable_dispositions WHERE invoice_financial_correction_id=p_id LOOP
   IF (SELECT count(*) FROM financial_transactions WHERE source_type='INVOICE_CORRECTION_RECEIVABLE_DISPOSITION' AND source_id=d.id::text)
       <>(d.original_unpaid>0)::integer+(d.residual_payable>0)::integer
     OR (d.original_unpaid>0) IS DISTINCT FROM EXISTS(SELECT 1 FROM financial_transactions WHERE source_type='INVOICE_CORRECTION_RECEIVABLE_DISPOSITION'
          AND source_id=d.id::text AND event_type='RECEIVABLE_UNPAID_REMAINDER_RETIRED')
     OR (d.residual_payable>0) IS DISTINCT FROM EXISTS(SELECT 1 FROM financial_transactions WHERE source_type='INVOICE_CORRECTION_RECEIVABLE_DISPOSITION'
          AND source_id=d.id::text AND event_type='RECEIVABLE_CORRECTED_RESIDUAL_DUE') THEN
     RAISE EXCEPTION 'Correction % disposition % has extra/missing retirement/residual events',p_id,d.id USING ERRCODE='23514';
   END IF;
 END LOOP;
 FOR t_id IN SELECT id FROM financial_transactions WHERE source_type='INVOICE_FINANCIAL_CORRECTION' AND source_id=p_id::text
   UNION SELECT reversal_transaction_id FROM invoice_correction_reversals WHERE invoice_financial_correction_id=p_id
   UNION SELECT tx.id FROM financial_transactions tx JOIN invoice_correction_receivable_dispositions ds ON ds.id::text=tx.source_id
     WHERE tx.source_type='INVOICE_CORRECTION_RECEIVABLE_DISPOSITION' AND ds.invoice_financial_correction_id=p_id LOOP
   PERFORM validate_invoice_correction_ledger_transaction(t_id);
 END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION validate_captain_financial_operation(p_type text,p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE entry record;
BEGIN
 PERFORM validate_captain_financial_operation_9c3a(p_type,p_id);
 IF p_type='PAYOUT' THEN
   FOR entry IN SELECT a.financial_entry_id,t.id FROM captain_receivable_payout_allocations a JOIN financial_entries e ON e.id=a.financial_entry_id
      JOIN financial_transactions t ON t.id=e.financial_transaction_id WHERE a.captain_receivable_payout_id=p_id LOOP
      PERFORM validate_receivable_disposition_event(entry.id);
   END LOOP;
 END IF;
END;
$$;

CREATE OR REPLACE FUNCTION guard_reserved_receivable_allocation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e financial_entries%ROWTYPE;
BEGIN
 SELECT * INTO e FROM financial_entries WHERE id=NEW.financial_entry_id;
 PERFORM id FROM currencies WHERE id=e.currency_id FOR UPDATE;
 PERFORM id FROM financial_accounts WHERE id=e.financial_account_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM invoice_correction_receivable_dispositions d JOIN invoice_financial_corrections c ON c.id=d.invoice_financial_correction_id
   WHERE d.original_receivable_financial_entry_id=e.id AND c.state IN ('PREPARED','READY_TO_FINALIZE','COMPLETED')) THEN
   RAISE EXCEPTION 'Receivable source % is reserved or permanently disposed',e.id USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER aa_reserved_receivable_allocation BEFORE INSERT ON captain_receivable_payout_allocations
FOR EACH ROW EXECUTE FUNCTION guard_reserved_receivable_allocation();

CREATE OR REPLACE FUNCTION receivable_disposition_constraint() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c_id bigint;
BEGIN
 IF TG_TABLE_NAME='invoice_financial_corrections' THEN c_id:=NEW.id;
 ELSIF TG_TABLE_NAME='invoice_correction_receivable_dispositions' THEN c_id:=NEW.invoice_financial_correction_id;
 ELSE SELECT invoice_financial_correction_id INTO c_id FROM invoice_correction_receivable_dispositions WHERE id=NEW.disposition_id;
 END IF;
 PERFORM validate_receivable_disposition_set(c_id);
 IF (SELECT state FROM invoice_financial_corrections WHERE id=c_id)='COMPLETED' THEN PERFORM validate_complete_correction_financial_set(c_id); END IF;
 RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER receivable_disposition_complete AFTER INSERT ON invoice_correction_receivable_dispositions
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receivable_disposition_constraint();
CREATE CONSTRAINT TRIGGER receivable_disposition_allocation_complete AFTER INSERT ON invoice_correction_receivable_disposition_allocations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receivable_disposition_constraint();
CREATE CONSTRAINT TRIGGER receivable_correction_complete AFTER UPDATE ON invoice_financial_corrections
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receivable_disposition_constraint();

CREATE OR REPLACE FUNCTION lock_receivable_disposition_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_disposition invoice_correction_receivable_dispositions%ROWTYPE; o_id bigint;
BEGIN
 IF NEW.transaction_kind='REVERSAL' AND EXISTS(SELECT 1 FROM financial_transactions t WHERE t.id=NEW.reversal_of_transaction_id
      AND t.source_type='INVOICE_CORRECTION_RECEIVABLE_DISPOSITION') THEN
   RAISE EXCEPTION 'Disposition financial history cannot be generically reversed' USING ERRCODE='23514';
 END IF;
 IF NEW.transaction_kind='REVERSAL' AND NEW.source_type<>'INVOICE_FINANCIAL_CORRECTION_REVERSAL'
   AND EXISTS(SELECT 1 FROM invoice_correction_receivable_dispositions d JOIN invoice_financial_corrections c ON c.id=d.invoice_financial_correction_id
      WHERE d.original_receivable_financial_transaction_id=NEW.reversal_of_transaction_id AND c.state IN ('PREPARED','READY_TO_FINALIZE','COMPLETED')) THEN
   RAISE EXCEPTION 'Reserved Receivable requires its official correction contract' USING ERRCODE='23514';
 END IF;
 IF NEW.source_type='INVOICE_CORRECTION_RECEIVABLE_DISPOSITION' OR NEW.event_type IN ('RECEIVABLE_UNPAID_REMAINDER_RETIRED','RECEIVABLE_CORRECTED_RESIDUAL_DUE') THEN
   IF NEW.source_type<>'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION' OR NEW.source_id !~ '^[1-9][0-9]{0,18}$'
     OR NEW.source_id::numeric>9223372036854775807 THEN RAISE EXCEPTION 'Invalid disposition source identity' USING ERRCODE='23514'; END IF;
   SELECT * INTO source_disposition FROM invoice_correction_receivable_dispositions WHERE id=NEW.source_id::bigint;
   SELECT order_id INTO o_id FROM invoice_financial_corrections WHERE id=source_disposition.invoice_financial_correction_id;
   IF o_id IS NULL THEN RAISE EXCEPTION 'Missing disposition source' USING ERRCODE='23514'; END IF;
   PERFORM id FROM orders WHERE id=o_id FOR UPDATE;
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER aa_disposition_order_lock BEFORE INSERT ON financial_transactions FOR EACH ROW EXECUTE FUNCTION lock_receivable_disposition_source();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Receivable disposition contracts are intentionally forward-only.');
    }
};
