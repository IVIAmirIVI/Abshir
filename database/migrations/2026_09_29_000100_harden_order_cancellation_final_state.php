<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Order cancellation final-state integrity requires PostgreSQL.');
        }

        // Keep the precheck and trigger replacement in one PostgreSQL migration transaction.
        DB::statement('LOCK TABLE orders, order_cancellations, order_cancellation_intents, order_cancellation_intent_shops, order_shop_purchase_confirmations, order_shop_returns, order_shops, order_shop_receipts IN SHARE ROW EXCLUSIVE MODE');

        $invalid = DB::selectOne(<<<'SQL'
SELECT * FROM (
    SELECT i.order_id, i.id AS intent_id, c.id AS cancellation_id,
        CASE
            WHEN (o.operational_status = 'CANCELLED') <> (c.id IS NOT NULL) THEN 'HISTORY_STATUS_SPLIT'
            WHEN i.frozen_at IS NULL THEN 'UNFROZEN_INTENT'
            WHEN c.id IS NOT NULL AND (c.classification <> 'AFTER_PURCHASE' OR c.reason <> i.reason) THEN 'WRONG_FINAL_HISTORY'
            WHEN NOT EXISTS (SELECT 1 FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id=i.id)
              OR EXISTS (
                  (SELECT p.id FROM order_shop_purchase_confirmations p WHERE p.order_id=i.order_id
                   EXCEPT SELECT s.purchase_confirmation_id FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id=i.id)
                  UNION ALL
                  (SELECT s.purchase_confirmation_id FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id=i.id
                   EXCEPT SELECT p.id FROM order_shop_purchase_confirmations p WHERE p.order_id=i.order_id)
              ) THEN 'STALE_REQUIRED_SET'
            WHEN c.id IS NOT NULL AND EXISTS (
                SELECT 1 FROM order_cancellation_intent_shops s
                WHERE s.order_cancellation_intent_id=i.id AND NOT EXISTS (
                    SELECT 1 FROM order_shop_returns r WHERE r.order_cancellation_intent_id=i.id
                      AND r.order_shop_id=s.order_shop_id AND r.purchase_confirmation_id=s.purchase_confirmation_id
                      AND r.sealed_at IS NOT NULL
                )
            ) THEN 'MISSING_COMPLETED_RETURN'
            ELSE NULL
        END AS issue
    FROM order_cancellation_intents i
    JOIN orders o ON o.id=i.order_id
    LEFT JOIN order_cancellations c ON c.order_id=i.order_id
) checks WHERE issue IS NOT NULL ORDER BY intent_id LIMIT 1
SQL);
        if ($invalid) {
            throw new RuntimeException("Phase 9C.2 final-state upgrade rejected order_id={$invalid->order_id}, intent_id={$invalid->intent_id}, cancellation_id=".($invalid->cancellation_id ?? 'NULL').", issue={$invalid->issue}.");
        }

        // picked_up_at is a business timestamp, not a trustworthy first-write timestamp.
        // Values after intent prove a conflict; backdated late writes cannot be inferred.
        $progress = DB::selectOne(<<<'SQL'
SELECT * FROM (
    SELECT i.order_id, i.id AS intent_id, s.id AS order_shop_id, NULL::bigint AS receipt_id,
        'PICKUP_AFTER_INTENT' AS issue
    FROM order_cancellation_intents i JOIN order_shops s ON s.order_id=i.order_id
    WHERE s.picked_up_at > i.intent_at
    UNION ALL
    SELECT i.order_id, i.id AS intent_id, s.id AS order_shop_id, r.id AS receipt_id,
        'RECEIPT_AFTER_INTENT' AS issue
    FROM order_cancellation_intents i JOIN order_shops s ON s.order_id=i.order_id
    JOIN order_shop_receipts r ON r.order_shop_id=s.id
    WHERE r.uploaded_at > i.intent_at
) conflicts ORDER BY intent_id, order_shop_id LIMIT 1
SQL);
        if ($progress) {
            throw new RuntimeException("Phase 9C.2 progress upgrade rejected order_id={$progress->order_id}, intent_id={$progress->intent_id}, order_shop_id={$progress->order_shop_id}, receipt_id=".($progress->receipt_id ?? 'NULL').", issue={$progress->issue}.");
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER order_cancel_final_complete ON orders;
DROP FUNCTION order_cancel_final_validate();

CREATE OR REPLACE FUNCTION phase9c2_validate_final_state(p_order_id bigint, p_legacy_status_change boolean)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE o orders%ROWTYPE; i order_cancellation_intents%ROWTYPE; c order_cancellations%ROWTYPE;
BEGIN
    SELECT * INTO o FROM orders WHERE id=p_order_id;
    IF o.id IS NULL THEN RETURN; END IF;
    SELECT * INTO i FROM order_cancellation_intents WHERE order_id=p_order_id;
    SELECT * INTO c FROM order_cancellations WHERE order_id=p_order_id;

    IF i.id IS NOT NULL THEN
        IF (o.operational_status='CANCELLED') <> (c.id IS NOT NULL) THEN
            RAISE EXCEPTION 'Phase 9C.2 final history/status mismatch, order_id=%, intent_id=%, cancellation_id=%',
                p_order_id, i.id, c.id USING ERRCODE='23514';
        END IF;
        IF c.id IS NULL THEN RETURN; END IF;
        IF i.frozen_at IS NULL OR c.classification <> 'AFTER_PURCHASE' OR c.reason <> i.reason
           OR NOT EXISTS (SELECT 1 FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id=i.id)
           OR EXISTS (
              (SELECT p.id FROM order_shop_purchase_confirmations p WHERE p.order_id=i.order_id
               EXCEPT SELECT s.purchase_confirmation_id FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id=i.id)
              UNION ALL
              (SELECT s.purchase_confirmation_id FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id=i.id
               EXCEPT SELECT p.id FROM order_shop_purchase_confirmations p WHERE p.order_id=i.order_id)
           ) OR EXISTS (
              SELECT 1 FROM order_cancellation_intent_shops s
              WHERE s.order_cancellation_intent_id=i.id AND NOT EXISTS (
                  SELECT 1 FROM order_shop_returns r WHERE r.order_cancellation_intent_id=i.id
                    AND r.purchase_confirmation_id=s.purchase_confirmation_id AND r.order_shop_id=s.order_shop_id
                    AND r.sealed_at IS NOT NULL
              )
           ) OR EXISTS (SELECT 1 FROM order_bill_payment_requests WHERE order_id=p_order_id AND status='REQUESTED')
           OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id=p_order_id)
           OR EXISTS (SELECT 1 FROM invoices WHERE order_id=p_order_id AND status='ISSUED' AND superseded_at IS NULL)
           OR EXISTS (SELECT 1 FROM invoice_collections WHERE order_id=p_order_id)
           OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoices inv
               ON t.source_type='CUSTOMER_INVOICE' AND t.source_id=inv.id::text
               WHERE inv.order_id=p_order_id AND t.transaction_kind='EVENT')
           OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoice_collections col
               ON t.source_type='INVOICE_COLLECTION' AND t.source_id=col.id::text
               WHERE col.order_id=p_order_id AND t.transaction_kind='EVENT')
           OR EXISTS (SELECT 1 FROM order_shops s WHERE s.order_id=p_order_id
               AND (s.picked_up_at IS NOT NULL OR EXISTS (SELECT 1 FROM order_shop_receipts r WHERE r.order_shop_id=s.id))
               AND NOT EXISTS (SELECT 1 FROM order_shop_purchase_confirmations p WHERE p.order_shop_id=s.id)) THEN
            RAISE EXCEPTION 'Phase 9C.2 final cancellation incomplete or financially unsafe, order_id=%, intent_id=%, cancellation_id=%',
                p_order_id, i.id, c.id USING ERRCODE='23514';
        END IF;
        RETURN;
    END IF;

    -- Preserve the original 000000 status-side guard for ordinary/legacy cancellations.
    -- Existing historical rows are not scanned or rewritten by this trigger replacement.
    IF p_legacy_status_change AND o.operational_status='CANCELLED' THEN
        IF c.id IS NULL THEN
            RAISE EXCEPTION 'Final CANCELLED Order requires cancellation source' USING ERRCODE='23514';
        END IF;
        IF EXISTS (SELECT 1 FROM invoices inv WHERE inv.order_id=p_order_id AND inv.status='ISSUED' AND inv.superseded_at IS NULL)
           OR EXISTS (SELECT 1 FROM invoice_collections WHERE order_id=p_order_id)
           OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id=p_order_id)
           OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoices inv
               ON t.source_type='CUSTOMER_INVOICE' AND t.source_id=inv.id::text
               WHERE inv.order_id=p_order_id AND t.transaction_kind='EVENT')
           OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoice_collections col
               ON t.source_type='INVOICE_COLLECTION' AND t.source_id=col.id::text
               WHERE col.order_id=p_order_id AND t.transaction_kind='EVENT') THEN
            RAISE EXCEPTION 'FINANCIAL_CORRECTION_REQUIRED for final cancellation order_id=%', p_order_id USING ERRCODE='23514';
        END IF;
        IF c.classification='BEFORE_PURCHASE' THEN
            IF EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=p_order_id)
               OR EXISTS (SELECT 1 FROM order_shops s WHERE s.order_id=p_order_id
                   AND (s.picked_up_at IS NOT NULL OR EXISTS (SELECT 1 FROM order_shop_receipts r WHERE r.order_shop_id=s.id))) THEN
                RAISE EXCEPTION 'BEFORE_PURCHASE cancellation conflicts with purchase evidence' USING ERRCODE='23514';
            END IF;
        ELSE
            RAISE EXCEPTION 'AFTER_PURCHASE final cancellation requires Phase 9C.2 intent, order_id=%', p_order_id USING ERRCODE='23514';
        END IF;
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION phase9c2_order_final_check() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM phase9c2_validate_final_state(NEW.id, true);
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER phase9c2_order_final_check
AFTER UPDATE OF operational_status ON orders DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION phase9c2_order_final_check();

CREATE OR REPLACE FUNCTION phase9c2_cancellation_final_check() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND OLD.order_id IS DISTINCT FROM NEW.order_id THEN
        PERFORM phase9c2_validate_final_state(OLD.order_id, false);
    END IF;
    PERFORM phase9c2_validate_final_state(CASE WHEN TG_OP='DELETE' THEN OLD.order_id ELSE NEW.order_id END, false);
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER phase9c2_cancellation_final_check
AFTER INSERT OR UPDATE OR DELETE ON order_cancellations DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION phase9c2_cancellation_final_check();

CREATE OR REPLACE FUNCTION phase9c2_intent_final_check() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM phase9c2_validate_final_state(NEW.order_id, false);
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER phase9c2_intent_final_check
AFTER INSERT OR UPDATE ON order_cancellation_intents DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION phase9c2_intent_final_check();

CREATE OR REPLACE FUNCTION phase9c2_cancellation_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=OLD.order_id)
       OR (TG_OP='UPDATE' AND EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.order_id)) THEN
        RAISE EXCEPTION 'Phase 9C.2 final OrderCancellation history is immutable, order_id=%', OLD.order_id USING ERRCODE='23514';
    END IF;
    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER phase9c2_cancellation_immutable BEFORE UPDATE OR DELETE ON order_cancellations
FOR EACH ROW EXECUTE FUNCTION phase9c2_cancellation_immutable();

CREATE OR REPLACE FUNCTION order_intent_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE order_state text;
BEGIN
    -- A pickup UPDATE already locks its Shop row. Holding all Shop rows here
    -- serializes the intent with that update without a Shop -> Order lock inversion.
    PERFORM id FROM order_shops WHERE order_id=NEW.order_id ORDER BY id FOR SHARE;
    SELECT operational_status INTO order_state FROM orders WHERE id=NEW.order_id;
    IF NEW.classification <> 'AFTER_PURCHASE' OR NEW.reason NOT IN
        ('CUSTOMER_CANCELLED','CUSTOMER_UNREACHABLE','CUSTOMER_REFUSED','SHOP_UNAVAILABLE','OPERATIONAL','OTHER')
       OR order_state IN ('DELIVERED','CANCELLED')
       OR NOT EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_shops s WHERE s.order_id=NEW.order_id
           AND (s.picked_up_at IS NOT NULL OR EXISTS (SELECT 1 FROM order_shop_receipts r WHERE r.order_shop_id=s.id))
           AND NOT EXISTS (SELECT 1 FROM order_shop_purchase_confirmations p WHERE p.order_shop_id=s.id)) THEN
        RAISE EXCEPTION 'Invalid AFTER_PURCHASE cancellation intent for order_id=%', NEW.order_id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION phase9c2_pickup_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (NEW.picked_up_at IS DISTINCT FROM OLD.picked_up_at
        OR NEW.order_id IS DISTINCT FROM OLD.order_id)
       AND (EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.order_id)
            OR EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=OLD.order_id)) THEN
        RAISE EXCEPTION 'Cancellation intent freezes OrderShop pickup and Order identity, order_shop_id=%', NEW.id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER phase9c2_pickup_guard BEFORE UPDATE OF picked_up_at, order_id ON order_shops
FOR EACH ROW EXECUTE FUNCTION phase9c2_pickup_guard();

CREATE OR REPLACE FUNCTION phase9c2_shop_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.picked_up_at IS NOT NULL THEN
        PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
        IF EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.order_id) THEN
            RAISE EXCEPTION 'Cancellation intent freezes new OrderShop pickup, order_id=%', NEW.order_id USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER phase9c2_shop_insert_guard BEFORE INSERT ON order_shops
FOR EACH ROW EXECUTE FUNCTION phase9c2_shop_insert_guard();

CREATE OR REPLACE FUNCTION phase9c2_receipt_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_order_id bigint;
BEGIN
    SELECT order_id INTO parent_order_id FROM order_shops WHERE id=NEW.order_shop_id;
    PERFORM id FROM orders WHERE id=parent_order_id FOR UPDATE;
    IF EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=parent_order_id) THEN
        RAISE EXCEPTION 'Cancellation intent freezes normal OrderShop receipts, order_shop_id=%', NEW.order_shop_id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER phase9c2_receipt_insert_guard BEFORE INSERT ON order_shop_receipts
FOR EACH ROW EXECUTE FUNCTION phase9c2_receipt_insert_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Phase 9C.2 final cancellation history is forward-only.');
    }
};
