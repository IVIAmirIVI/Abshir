<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Shared financial contracts require PostgreSQL.');
        }
        DB::statement('LOCK TABLE orders, invoices, invoice_collections, financial_transactions, financial_entries IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
ALTER FUNCTION order_financial_event_source_order_id(financial_transactions) RENAME TO mc4_previous_order_event_source_order_id;
CREATE FUNCTION order_financial_event_source_order_id(p_transaction financial_transactions)
RETURNS bigint LANGUAGE plpgsql STABLE AS $$
DECLARE o_id bigint;
BEGIN
    IF p_transaction.event_type IN ('SHARED_INVOICE_CASH_CUSTODY_RECEIVED','SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE','SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE') THEN
        IF p_transaction.transaction_kind<>'EVENT' OR p_transaction.source_type<>'INVOICE_COLLECTION'
           OR p_transaction.source_id !~ '^[1-9][0-9]{0,17}$' OR p_transaction.source_version<>1 THEN
            RAISE EXCEPTION 'Shared financial event requires canonical INVOICE_COLLECTION source/version' USING ERRCODE='23514';
        END IF;
        SELECT c.order_id INTO o_id FROM invoice_collections c JOIN invoices i ON i.id=c.invoice_id AND i.order_id=c.order_id WHERE c.id=p_transaction.source_id::bigint;
        IF o_id IS NULL OR NOT EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=o_id AND contract='SHARED_COMPANY_V1') THEN
            RAISE EXCEPTION 'Shared financial event has no authoritative shared Order' USING ERRCODE='23514';
        END IF;
        RETURN o_id;
    END IF;
    o_id:=mc4_previous_order_event_source_order_id(p_transaction);
    IF o_id IS NOT NULL AND EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=o_id AND contract='SHARED_COMPANY_V1') THEN
        RAISE EXCEPTION 'Legacy financial events cannot settle a shared company Order' USING ERRCODE='23514';
    END IF;
    RETURN o_id;
END;
$$;
CREATE FUNCTION validate_shared_order_financial_event(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE; c invoice_collections%ROWTYPE; i invoices%ROWTYPE;
    s order_financial_source_snapshots%ROWTYPE; o orders%ROWTYPE; expected_time timestamptz; expected_count integer; bad boolean;
BEGIN
    SELECT * INTO t FROM financial_transactions WHERE id=p_id;
    IF t.event_type NOT IN ('SHARED_INVOICE_CASH_CUSTODY_RECEIVED','SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE','SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE') THEN RETURN; END IF;
    PERFORM order_financial_event_source_order_id(t);
    SELECT * INTO c FROM invoice_collections WHERE id=t.source_id::bigint;
    SELECT * INTO i FROM invoices WHERE id=c.invoice_id;
    SELECT * INTO o FROM orders WHERE id=c.order_id FOR UPDATE;
    SELECT * INTO s FROM order_financial_source_snapshots WHERE invoice_id=i.id;
    IF s.id IS NULL THEN RAISE EXCEPTION 'Shared event requires a frozen issued Invoice source' USING ERRCODE='23514'; END IF;
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
        RAISE EXCEPTION 'Shared financial event differs from authoritative collection/Invoice facts' USING ERRCODE='23514';
    END IF;
    IF t.event_type='SHARED_INVOICE_CASH_CUSTODY_RECEIVED' THEN
        expected_time:=c.collected_at; expected_count:=1;
        IF c.collection_method<>'CASH' THEN RAISE EXCEPTION 'Direct company payment has no Captain cash custody' USING ERRCODE='23514'; END IF;
        SELECT EXISTS(SELECT 1 FROM financial_entries e JOIN financial_accounts a ON a.id=e.financial_account_id
           JOIN financial_owners f ON f.id=a.financial_owner_id WHERE e.financial_transaction_id=t.id AND
           (e.direction<>'INCREASE' OR e.amount IS DISTINCT FROM c.amount OR a.currency_id<>s.currency_id
            OR a.account_kind<>'CAPTAIN_CASH_CUSTODY' OR f.owner_type<>'CAPTAIN' OR f.captain_id IS DISTINCT FROM c.captain_id)) INTO bad;
    ELSE
        IF o.operational_status<>'DELIVERED' THEN RAISE EXCEPTION 'Shared earned obligations require delivery' USING ERRCODE='23514'; END IF;
        SELECT greatest(c.collected_at,i.issued_at,h.changed_at) INTO expected_time FROM order_status_history h
            WHERE h.order_id=o.id AND h.new_status='DELIVERED' ORDER BY h.changed_at DESC,h.id DESC LIMIT 1;
        SELECT count(*) INTO expected_count FROM order_financial_source_snapshot_lines l WHERE l.order_financial_source_snapshot_id=s.id
            AND (CASE t.event_type WHEN 'SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE' THEN l.product_funding_share ELSE l.captain_entitlement END)>0;
        SELECT EXISTS(SELECT 1 FROM financial_entries e JOIN financial_accounts a ON a.id=e.financial_account_id
           JOIN financial_owners f ON f.id=a.financial_owner_id
           LEFT JOIN order_financial_source_snapshot_lines l ON l.order_financial_source_snapshot_id=s.id AND l.captain_id=f.captain_id
           WHERE e.financial_transaction_id=t.id AND (l.id IS NULL OR e.direction<>'INCREASE' OR a.account_kind<>'CAPTAIN_COMPANY_RECEIVABLE'
            OR f.owner_type<>'CAPTAIN' OR a.currency_id<>s.currency_id
            OR e.amount IS DISTINCT FROM (CASE t.event_type WHEN 'SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE' THEN l.product_funding_share ELSE l.captain_entitlement END))) INTO bad;
    END IF;
    IF expected_time IS NULL OR t.occurred_at IS DISTINCT FROM expected_time OR expected_count=0 OR bad
       OR (SELECT count(*) FROM financial_entries WHERE financial_transaction_id=t.id)<>expected_count THEN
        RAISE EXCEPTION 'Shared financial event has incorrect time, claimant, account or exact amount' USING ERRCODE='23514';
    END IF;
END;
$$;
CREATE FUNCTION assert_shared_order_financial_set(p_order bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE c invoice_collections%ROWTYPE; i invoices%ROWTYPE; s_id bigint; ev text; required boolean; ids bigint[]; event_id bigint;
BEGIN
    IF NOT EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=p_order AND contract='SHARED_COMPANY_V1') THEN RETURN; END IF;
    SELECT * INTO i FROM invoices WHERE order_id=p_order AND superseded_at IS NULL AND status='ISSUED';
    SELECT * INTO c FROM invoice_collections WHERE invoice_id=i.id;
    IF c.id IS NULL THEN RETURN; END IF;
    SELECT id INTO s_id FROM order_financial_source_snapshots WHERE invoice_id=i.id;
    IF s_id IS NULL THEN RAISE EXCEPTION 'Shared collection requires an issued source snapshot' USING ERRCODE='23514'; END IF;
    FOREACH ev IN ARRAY ARRAY['SHARED_INVOICE_CASH_CUSTODY_RECEIVED','SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE','SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE'] LOOP
        IF ev='SHARED_INVOICE_CASH_CUSTODY_RECEIVED' THEN required:=c.collection_method='CASH';
        ELSE required:=(SELECT operational_status='DELIVERED' FROM orders WHERE id=p_order) AND EXISTS(
            SELECT 1 FROM order_financial_source_snapshot_lines WHERE order_financial_source_snapshot_id=s_id AND
                (CASE ev WHEN 'SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE' THEN product_funding_share ELSE captain_entitlement END)>0);
        END IF;
        SELECT array_agg(id) INTO ids FROM financial_transactions WHERE source_type='INVOICE_COLLECTION' AND source_id=c.id::text AND event_type=ev;
        IF coalesce(array_length(ids,1),0)<>(CASE WHEN required THEN 1 ELSE 0 END) THEN
            RAISE EXCEPTION 'Shared collection financial set is incomplete or premature for %',ev USING ERRCODE='23514';
        END IF;
        FOREACH event_id IN ARRAY coalesce(ids,ARRAY[]::bigint[]) LOOP PERFORM validate_shared_order_financial_event(event_id); END LOOP;
    END LOOP;
END;
$$;
CREATE FUNCTION shared_financial_constraint() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o_id bigint;
BEGIN
    IF TG_TABLE_NAME='financial_transactions' THEN
        PERFORM validate_shared_order_financial_event(NEW.id);
        o_id:=order_financial_event_source_order_id(NEW);
    ELSIF TG_TABLE_NAME='orders' THEN
        o_id:=NEW.id;
        IF EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=NEW.id AND contract='SHARED_COMPANY_V1') AND NEW.operational_status IS DISTINCT FROM OLD.operational_status AND NOT EXISTS(SELECT 1 FROM order_status_history WHERE order_id=NEW.id AND new_status=NEW.operational_status AND previous_status=OLD.operational_status) THEN
            RAISE EXCEPTION 'Shared status transition requires authoritative history' USING ERRCODE='23514';
        END IF;
        IF NEW.operational_status='DELIVERED' AND NEW.operational_status IS DISTINCT FROM OLD.operational_status
           AND EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=NEW.id AND contract='SHARED_COMPANY_V1') THEN
            PERFORM assert_shared_order_invoice_inputs(NEW.id,
                (SELECT currency_id FROM order_delivery_pricings WHERE order_id=NEW.id AND superseded_at IS NULL),
                (SELECT id FROM order_delivery_pricings WHERE order_id=NEW.id AND superseded_at IS NULL),
                (SELECT sum(amount) FROM order_shop_purchase_confirmations WHERE order_id=NEW.id));
        END IF;
    ELSE o_id:=NEW.order_id; END IF;
    IF o_id IS NOT NULL THEN PERFORM assert_shared_order_financial_set(o_id); END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER shared_order_status_history_valid AFTER UPDATE ON orders DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION shared_financial_constraint();
CREATE CONSTRAINT TRIGGER shared_collection_financial_set AFTER INSERT ON invoice_collections DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION shared_financial_constraint();
CREATE CONSTRAINT TRIGGER shared_delivery_financial_set AFTER INSERT ON order_status_history DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION shared_financial_constraint();
CREATE CONSTRAINT TRIGGER shared_financial_event_valid AFTER INSERT ON financial_transactions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION shared_financial_constraint();
CREATE FUNCTION shared_financial_reversal_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS(SELECT 1 FROM financial_transactions WHERE id=NEW.reversal_of_transaction_id AND event_type IN
        ('SHARED_INVOICE_CASH_CUSTODY_RECEIVED','SHARED_ORDER_PRODUCT_REIMBURSEMENT_DUE','SHARED_ORDER_DELIVERY_ENTITLEMENT_DUE')) THEN
        RAISE EXCEPTION 'Shared financial reversal requires attributed refund/recovery sources' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER aa_shared_financial_reversal_boundary BEFORE INSERT ON financial_transactions FOR EACH ROW EXECUTE FUNCTION shared_financial_reversal_boundary();
CREATE OR REPLACE FUNCTION shared_invoice_collection_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s order_financial_source_snapshots%ROWTYPE;
BEGIN
    PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
    IF EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=NEW.order_id AND contract='SHARED_COMPANY_V1') THEN
        SELECT * INTO s FROM order_financial_source_snapshots WHERE invoice_id=NEW.invoice_id;
        IF s.id IS NULL OR s.order_id<>NEW.order_id THEN RAISE EXCEPTION 'Shared collection requires its frozen issued Invoice' USING ERRCODE='23514'; END IF;
        PERFORM validate_financial_snapshot_fingerprint(s.id);
        IF NEW.collected_at < s.issued_at OR NEW.collected_at > clock_timestamp()
           OR NEW.collection_method NOT IN ('CASH','SHAM_CASH')
           OR (NEW.collection_method='CASH' AND (NEW.order_captain_id IS DISTINCT FROM s.primary_order_captain_id
               OR NEW.captain_id IS DISTINCT FROM (SELECT captain_id FROM order_captains WHERE id=s.primary_order_captain_id)))
           OR (NEW.collection_method='SHAM_CASH' AND (NEW.order_captain_id IS NOT NULL OR NEW.captain_id IS NOT NULL)) THEN
            RAISE EXCEPTION 'Shared collection must preserve the historical collector and payment method' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE FUNCTION shared_correction_source_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
    IF EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=NEW.order_id AND contract='SHARED_COMPANY_V1') THEN
        RAISE EXCEPTION 'Shared corrections require attributed refund and paid-receivable recovery sources' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER shared_correction_source_boundary BEFORE INSERT ON invoice_financial_corrections FOR EACH ROW EXECUTE FUNCTION shared_correction_source_boundary();
SQL);
        // Keep the existing boundary except for evidenced operational progress; business facts stay frozen.
        $definition = DB::scalar("SELECT pg_get_functiondef('shared_purchase_legacy_boundary()'::regprocedure)");
        $needle = "        RAISE EXCEPTION 'Shared purchase settlement and refund contract is not enabled'";
        if (! str_contains($definition, $needle)) {
            throw new RuntimeException('Unexpected installed shared purchase boundary; refusing activation.');
        }
        $allowed = <<<'SQL'
        IF TG_TABLE_NAME='orders' AND TG_OP='UPDATE' AND
           (to_jsonb(NEW)-ARRAY['operational_status','updated_at'])=(to_jsonb(OLD)-ARRAY['operational_status','updated_at'])
           AND (to_jsonb(NEW)->>'operational_status')=(CASE (to_jsonb(OLD)->>'operational_status')
               WHEN 'NEW' THEN 'CONFIRMED' WHEN 'CONFIRMED' THEN 'CAPTAIN_ASSIGNED'
               WHEN 'CAPTAIN_ASSIGNED' THEN 'HEADING_TO_SHOPS' WHEN 'HEADING_TO_SHOPS' THEN 'SHOPPING'
               WHEN 'SHOPPING' THEN 'HEADING_TO_CUSTOMER' WHEN 'HEADING_TO_CUSTOMER' THEN 'DELIVERED' END) THEN
            IF NOT EXISTS(SELECT 1 FROM order_captain_selections WHERE order_id=NEW.id AND contract='SHARED_COMPANY_V1') THEN
                RAISE EXCEPTION 'Shared execution requires explicit participant selection' USING ERRCODE='23514';
            END IF;
            IF (to_jsonb(NEW)->>'operational_status')='DELIVERED' AND (NOT EXISTS(SELECT 1 FROM order_shops WHERE order_id=NEW.id)
               OR EXISTS(SELECT 1 FROM order_shops s WHERE s.order_id=NEW.id AND (s.picked_up_at IS NULL OR NOT EXISTS(SELECT 1 FROM order_shop_receipts r WHERE r.order_shop_id=s.id)))) THEN
                RAISE EXCEPTION 'Shared delivery requires every pickup and receipt' USING ERRCODE='23514';
            END IF;

            RETURN NEW;
        END IF;
        IF TG_TABLE_NAME='order_shops' AND TG_OP='UPDATE' AND
           (to_jsonb(NEW)-ARRAY['contacted_at','confirmed_at','ready_at','picked_up_at','updated_at'])=(to_jsonb(OLD)-ARRAY['contacted_at','confirmed_at','ready_at','picked_up_at','updated_at'])
           AND ((to_jsonb(OLD)->>'contacted_at') IS NULL OR (to_jsonb(NEW)->>'contacted_at') IS NOT DISTINCT FROM (to_jsonb(OLD)->>'contacted_at'))
           AND ((to_jsonb(OLD)->>'confirmed_at') IS NULL OR (to_jsonb(NEW)->>'confirmed_at') IS NOT DISTINCT FROM (to_jsonb(OLD)->>'confirmed_at'))
           AND ((to_jsonb(OLD)->>'ready_at') IS NULL OR (to_jsonb(NEW)->>'ready_at') IS NOT DISTINCT FROM (to_jsonb(OLD)->>'ready_at'))
           AND ((to_jsonb(OLD)->>'picked_up_at') IS NULL OR (to_jsonb(NEW)->>'picked_up_at') IS NOT DISTINCT FROM (to_jsonb(OLD)->>'picked_up_at'))
           AND (SELECT operational_status NOT IN ('DELIVERED','CANCELLED') FROM orders WHERE id=(to_jsonb(NEW)->>'order_id')::bigint) THEN
            RETURN NEW;
        END IF;
SQL;
        DB::unprepared(str_replace($needle, $allowed."\n".$needle, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Shared financial history is forward-only.');
    }
};
