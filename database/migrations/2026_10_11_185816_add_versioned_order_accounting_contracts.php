<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Versioned Order accounting requires PostgreSQL.');
        }
        DB::statement('LOCK TABLE orders, invoices, invoice_collections, financial_transactions, financial_entries IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
-- All pre-existing Orders retain V1, including uncollected historical invoices.
-- The default and insert guard bind every subsequent Order to V2, independently of dates/config.
ALTER TABLE orders ADD COLUMN accounting_contract_version smallint NOT NULL DEFAULT 1
    CONSTRAINT orders_accounting_contract_version_check CHECK(accounting_contract_version IN (1,2));
ALTER TABLE orders ALTER COLUMN accounting_contract_version SET DEFAULT 2;
CREATE FUNCTION guard_order_accounting_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (TG_OP='INSERT' AND NEW.accounting_contract_version IS DISTINCT FROM 2)
       OR (TG_OP='UPDATE' AND NEW.accounting_contract_version IS DISTINCT FROM OLD.accounting_contract_version) THEN
        RAISE EXCEPTION 'Order accounting contract is bound at creation and cannot be changed' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER aa_order_accounting_version BEFORE INSERT OR UPDATE ON orders
    FOR EACH ROW EXECUTE FUNCTION guard_order_accounting_version();

ALTER FUNCTION order_financial_event_source_order_id(financial_transactions) RENAME TO accounting_v1_order_source_order_id;
CREATE FUNCTION order_financial_event_source_order_id(p_transaction financial_transactions)
RETURNS bigint LANGUAGE plpgsql STABLE AS $$
DECLARE o_id bigint;
BEGIN
    IF p_transaction.event_type IN ('ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED','ORDER_V2_PRODUCT_REIMBURSEMENT_DUE','ORDER_V2_DELIVERY_ENTITLEMENT_DUE') THEN
        IF p_transaction.transaction_kind<>'EVENT' OR p_transaction.source_type<>'INVOICE_COLLECTION'
           OR p_transaction.source_id !~ '^[1-9][0-9]{0,17}$' OR p_transaction.source_version<>2 THEN
            RAISE EXCEPTION 'V2 event requires canonical INVOICE_COLLECTION source/version' USING ERRCODE='23514';
        END IF;
        SELECT c.order_id INTO o_id FROM invoice_collections c JOIN invoices i ON i.id=c.invoice_id AND i.order_id=c.order_id
            JOIN orders o ON o.id=c.order_id AND o.accounting_contract_version=2 WHERE c.id=p_transaction.source_id::bigint;
        IF o_id IS NULL THEN RAISE EXCEPTION 'V2 event requires an authoritative V2 Order' USING ERRCODE='23514'; END IF;
        RETURN o_id;
    END IF;
    o_id:=accounting_v1_order_source_order_id(p_transaction);
    IF o_id IS NOT NULL AND (SELECT accounting_contract_version FROM orders WHERE id=o_id)=2
       AND p_transaction.event_type IN ('ORDER_PRODUCT_REIMBURSEMENT','ORDER_DELIVERY_ENTITLEMENT','ORDER_COMPANY_DELIVERY_SHARE',
           'INVOICE_CASH_CUSTODY_RECEIVED','ORDER_CASH_COMPANY_DELIVERY_SHARE_HELD','ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT',
           'ORDER_CASH_DELIVERY_SHORTFALL_DUE','ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE','ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE',
           'SHARED_INVOICE_CASH_CUSTODY_RECEIVED','SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE','SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE') THEN
        RAISE EXCEPTION 'V1 Invoice events cannot be posted against a V2 Order' USING ERRCODE='23514';
    END IF;
    RETURN o_id;
END;
$$;

CREATE FUNCTION validate_order_accounting_v2_event(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE; c invoice_collections%ROWTYPE; i invoices%ROWTYPE;
    s order_financial_source_snapshots%ROWTYPE; o orders%ROWTYPE; expected_time timestamptz; expected_count integer; bad boolean;
BEGIN
    SELECT * INTO t FROM financial_transactions WHERE id=p_id;
    IF t.event_type NOT IN ('ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED','ORDER_V2_PRODUCT_REIMBURSEMENT_DUE','ORDER_V2_DELIVERY_ENTITLEMENT_DUE') THEN RETURN; END IF;
    PERFORM order_financial_event_source_order_id(t);
    SELECT * INTO c FROM invoice_collections WHERE id=t.source_id::bigint;
    SELECT * INTO i FROM invoices WHERE id=c.invoice_id;
    SELECT * INTO o FROM orders WHERE id=c.order_id FOR UPDATE;
    SELECT * INTO s FROM order_financial_source_snapshots WHERE invoice_id=i.id;
    IF s.id IS NULL THEN RAISE EXCEPTION 'V2 event requires a frozen issued Invoice source' USING ERRCODE='23514'; END IF;
    PERFORM validate_financial_snapshot_fingerprint(s.id);
    IF t.status<>'POSTED' OR t.branch_id IS DISTINCT FROM s.branch_id OR t.currency_id IS DISTINCT FROM s.currency_id
       OR t.recorded_by_user_id IS DISTINCT FROM c.recorded_by OR t.notes IS DISTINCT FROM s.payload_hash
       OR t.reason IS NOT NULL OR t.reversal_of_transaction_id IS NOT NULL
       OR i.status<>'ISSUED' OR i.superseded_at IS NOT NULL OR c.amount IS DISTINCT FROM i.grand_total
       OR c.currency_id<>i.currency_id OR c.collected_at<i.issued_at OR c.collected_at>clock_timestamp()
       OR o.operational_status='CANCELLED' OR EXISTS(SELECT 1 FROM order_cancellation_intents WHERE order_id=o.id)
       OR (c.collection_method='CASH' AND (c.order_captain_id IS DISTINCT FROM s.primary_order_captain_id
          OR c.captain_id IS DISTINCT FROM (SELECT captain_id FROM order_captains WHERE id=s.primary_order_captain_id)))
       OR (c.collection_method='SHAM_CASH' AND (c.order_captain_id IS NOT NULL OR c.captain_id IS NOT NULL))
       OR c.collection_method NOT IN ('CASH','SHAM_CASH') THEN
        RAISE EXCEPTION 'V2 event differs from authoritative collection/Invoice facts' USING ERRCODE='23514';
    END IF;
    IF t.event_type='ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED' THEN
        expected_time:=c.collected_at; expected_count:=1;
        IF c.collection_method<>'CASH' OR i.delivery_total<=0 THEN
            RAISE EXCEPTION 'Only positive collected CASH delivery creates custody' USING ERRCODE='23514';
        END IF;
        SELECT EXISTS(SELECT 1 FROM financial_entries e JOIN financial_accounts a ON a.id=e.financial_account_id
            JOIN financial_owners f ON f.id=a.financial_owner_id WHERE e.financial_transaction_id=t.id AND
            (e.direction<>'INCREASE' OR e.amount IS DISTINCT FROM i.delivery_total OR a.currency_id<>s.currency_id
             OR a.account_kind<>'CAPTAIN_CASH_CUSTODY' OR f.owner_type<>'CAPTAIN' OR f.captain_id IS DISTINCT FROM c.captain_id)) INTO bad;
    ELSE
        IF o.operational_status<>'DELIVERED' THEN RAISE EXCEPTION 'V2 earned obligations require delivery' USING ERRCODE='23514'; END IF;
        IF t.event_type='ORDER_V2_PRODUCT_REIMBURSEMENT_DUE' AND c.collection_method<>'SHAM_CASH' THEN
            RAISE EXCEPTION 'CASH products are not company reimbursement obligations' USING ERRCODE='23514';
        END IF;
        SELECT greatest(c.collected_at,i.issued_at,h.changed_at) INTO expected_time FROM order_status_history h
            WHERE h.order_id=o.id AND h.new_status='DELIVERED' ORDER BY h.changed_at DESC,h.id DESC LIMIT 1;
        SELECT count(*) INTO expected_count FROM order_financial_source_snapshot_lines l WHERE l.order_financial_source_snapshot_id=s.id
            AND (CASE t.event_type WHEN 'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE' THEN l.product_funding_share ELSE l.captain_entitlement END)>0;
        SELECT EXISTS(SELECT 1 FROM financial_entries e JOIN financial_accounts a ON a.id=e.financial_account_id
            JOIN financial_owners f ON f.id=a.financial_owner_id
            LEFT JOIN order_financial_source_snapshot_lines l ON l.order_financial_source_snapshot_id=s.id AND l.captain_id=f.captain_id
            WHERE e.financial_transaction_id=t.id AND (l.id IS NULL OR e.direction<>'INCREASE' OR a.account_kind<>'CAPTAIN_COMPANY_RECEIVABLE'
             OR f.owner_type<>'CAPTAIN' OR a.currency_id<>s.currency_id
             OR e.amount IS DISTINCT FROM (CASE t.event_type WHEN 'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE' THEN l.product_funding_share ELSE l.captain_entitlement END))) INTO bad;
    END IF;
    IF expected_time IS NULL OR t.occurred_at IS DISTINCT FROM expected_time OR expected_count=0 OR bad
       OR (SELECT count(*) FROM financial_entries WHERE financial_transaction_id=t.id)<>expected_count THEN
        RAISE EXCEPTION 'V2 event has incorrect time, claimant, account or exact amount' USING ERRCODE='23514';
    END IF;
END;
$$;

CREATE FUNCTION assert_order_accounting_v2_set(p_order bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c invoice_collections%ROWTYPE; i invoices%ROWTYPE; s order_financial_source_snapshots%ROWTYPE;
    ev text; required boolean; ids bigint[]; event_id bigint;
BEGIN
    PERFORM id FROM orders WHERE id=p_order AND accounting_contract_version=2 FOR UPDATE;
    IF NOT FOUND THEN RETURN; END IF;
    SELECT * INTO i FROM invoices WHERE order_id=p_order AND superseded_at IS NULL AND status='ISSUED';
    SELECT * INTO c FROM invoice_collections WHERE invoice_id=i.id;
    IF c.id IS NULL THEN RETURN; END IF;
    SELECT * INTO s FROM order_financial_source_snapshots WHERE invoice_id=i.id;
    IF s.id IS NULL THEN RAISE EXCEPTION 'V2 collection requires an issued source snapshot' USING ERRCODE='23514'; END IF;
    PERFORM validate_financial_snapshot_fingerprint(s.id);
    IF c.order_id<>p_order OR c.currency_id<>i.currency_id OR c.amount IS DISTINCT FROM i.grand_total
       OR c.collected_at<i.issued_at OR c.collected_at>clock_timestamp()
       OR (c.collection_method='CASH' AND (c.order_captain_id IS DISTINCT FROM s.primary_order_captain_id
          OR c.captain_id IS DISTINCT FROM (SELECT captain_id FROM order_captains WHERE id=s.primary_order_captain_id)))
       OR (c.collection_method='SHAM_CASH' AND (c.order_captain_id IS NOT NULL OR c.captain_id IS NOT NULL))
       OR c.collection_method NOT IN ('CASH','SHAM_CASH')
       OR (SELECT operational_status='CANCELLED' FROM orders WHERE id=p_order)
       OR EXISTS(SELECT 1 FROM order_cancellation_intents WHERE order_id=p_order) THEN
        RAISE EXCEPTION 'V2 collection has invalid authoritative financial facts' USING ERRCODE='23514';
    END IF;
    FOREACH ev IN ARRAY ARRAY['ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED','ORDER_V2_PRODUCT_REIMBURSEMENT_DUE','ORDER_V2_DELIVERY_ENTITLEMENT_DUE'] LOOP
        IF ev='ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED' THEN required:=c.collection_method='CASH' AND i.delivery_total>0;
        ELSE required:=(SELECT operational_status='DELIVERED' FROM orders WHERE id=p_order)
            AND (ev<>'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE' OR c.collection_method='SHAM_CASH') AND EXISTS(
                SELECT 1 FROM order_financial_source_snapshot_lines WHERE order_financial_source_snapshot_id=s.id
                  AND (CASE ev WHEN 'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE' THEN product_funding_share ELSE captain_entitlement END)>0);
        END IF;
        SELECT array_agg(id) INTO ids FROM financial_transactions WHERE source_type='INVOICE_COLLECTION' AND source_id=c.id::text AND event_type=ev;
        IF coalesce(array_length(ids,1),0)<>(CASE WHEN required THEN 1 ELSE 0 END) THEN
            RAISE EXCEPTION 'V2 financial set is incomplete or premature for %',ev USING ERRCODE='23514';
        END IF;
        FOREACH event_id IN ARRAY coalesce(ids,ARRAY[]::bigint[]) LOOP PERFORM validate_order_accounting_v2_event(event_id); END LOOP;
    END LOOP;
END;
$$;

-- Keep the original V1 validators executable, rather than weakening historical contracts.
ALTER FUNCTION validate_shared_order_financial_event(bigint) RENAME TO accounting_v1_validate_shared_event;
CREATE FUNCTION validate_shared_order_financial_event(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    PERFORM accounting_v1_validate_shared_event(p_id);
    PERFORM validate_order_accounting_v2_event(p_id);
END;
$$;
ALTER FUNCTION assert_shared_order_financial_set(bigint) RENAME TO accounting_v1_assert_shared_set;
CREATE FUNCTION assert_shared_order_financial_set(p_order bigint) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    IF (SELECT accounting_contract_version FROM orders WHERE id=p_order)=2 THEN
        PERFORM assert_order_accounting_v2_set(p_order);
    ELSE PERFORM accounting_v1_assert_shared_set(p_order);
    END IF;
END;
$$;
CREATE FUNCTION order_accounting_v2_constraint() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o_id bigint;
BEGIN
    IF TG_TABLE_NAME='financial_transactions' THEN
        PERFORM validate_order_accounting_v2_event(NEW.id);
        o_id:=order_financial_event_source_order_id(NEW);
    ELSIF TG_TABLE_NAME='orders' THEN
        o_id:=NEW.id;
        IF NEW.accounting_contract_version=2 AND NEW.operational_status IS DISTINCT FROM OLD.operational_status THEN
            IF OLD.operational_status='DELIVERED' OR (NEW.operational_status='DELIVERED' AND NOT EXISTS(
                SELECT 1 FROM order_status_history WHERE order_id=NEW.id AND new_status='DELIVERED' AND previous_status=OLD.operational_status)) THEN
                RAISE EXCEPTION 'V2 delivery must retain its authoritative status history' USING ERRCODE='23514';
            END IF;
        END IF;
    ELSE o_id:=NEW.order_id; END IF;
    IF o_id IS NOT NULL THEN PERFORM assert_order_accounting_v2_set(o_id); END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER order_accounting_v2_collection AFTER INSERT ON invoice_collections DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION order_accounting_v2_constraint();
CREATE CONSTRAINT TRIGGER order_accounting_v2_delivery AFTER UPDATE ON orders DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION order_accounting_v2_constraint();
CREATE CONSTRAINT TRIGGER order_accounting_v2_history AFTER INSERT ON order_status_history DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION order_accounting_v2_constraint();
CREATE CONSTRAINT TRIGGER order_accounting_v2_event AFTER INSERT OR UPDATE ON financial_transactions DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION order_accounting_v2_constraint();

CREATE FUNCTION order_accounting_v2_correction_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME='invoice_financial_corrections' THEN
        PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
        IF (SELECT accounting_contract_version FROM orders WHERE id=NEW.order_id)=2 THEN
            RAISE EXCEPTION 'V2 collected correction requires attributed custody/refund and receivable contracts' USING ERRCODE='23514';
        END IF;
    ELSE
        IF EXISTS(SELECT 1 FROM financial_transactions WHERE id=NEW.reversal_of_transaction_id AND event_type IN
           ('ORDER_V2_CASH_DELIVERY_CUSTODY_RECEIVED','ORDER_V2_PRODUCT_REIMBURSEMENT_DUE','ORDER_V2_DELIVERY_ENTITLEMENT_DUE')) THEN
            RAISE EXCEPTION 'V2 reversal requires an authoritative refund/recovery source' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER aa_order_accounting_v2_correction BEFORE INSERT ON invoice_financial_corrections
    FOR EACH ROW EXECUTE FUNCTION order_accounting_v2_correction_boundary();
CREATE TRIGGER aa_order_accounting_v2_reversal BEFORE INSERT ON financial_transactions
    FOR EACH ROW EXECUTE FUNCTION order_accounting_v2_correction_boundary();
SQL);
        // Reuse the existing evidenced single-Order payout contract; shared settlement stays disabled.
        $definition = DB::scalar("SELECT pg_get_functiondef('validate_captain_financial_operation_9c3a(text,bigint)'::regprocedure)");
        $needle = "'RECEIVABLE_CORRECTED_RESIDUAL_DUE')";
        if (substr_count($definition, $needle) !== 1) {
            throw new RuntimeException('Unexpected installed payout source whitelist; refusing V2 activation.');
        }
        DB::unprepared(str_replace($needle, "'RECEIVABLE_CORRECTED_RESIDUAL_DUE', 'ORDER_V2_PRODUCT_REIMBURSEMENT_DUE', 'ORDER_V2_DELIVERY_ENTITLEMENT_DUE')", $definition));
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_v2_receivable_payout_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE; o_id bigint;
BEGIN
    SELECT tx.* INTO t FROM financial_entries e JOIN financial_transactions tx ON tx.id=e.financial_transaction_id WHERE e.id=NEW.financial_entry_id;
    IF t.event_type IN ('ORDER_V2_PRODUCT_REIMBURSEMENT_DUE','ORDER_V2_DELIVERY_ENTITLEMENT_DUE') THEN
        -- Sources are already POSTED and immutable. Do not reverse Currency -> Account lock ordering by locking Order here.
        o_id:=order_financial_event_source_order_id(t);
        IF EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=o_id AND contract='SHARED_COMPANY_V1') THEN
            RAISE EXCEPTION 'Shared V2 payout requires attributed settlement sources' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER ab_v2_receivable_payout_source BEFORE INSERT ON captain_receivable_payout_allocations
    FOR EACH ROW EXECUTE FUNCTION guard_v2_receivable_payout_source();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Order accounting versions and financial history are forward-only.');
    }
};
