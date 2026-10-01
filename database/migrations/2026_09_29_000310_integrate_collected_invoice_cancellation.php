<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Collected-Invoice cancellation integration requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION phase9c3a_can_cancel(p_order_id bigint, p_for_intent boolean)
RETURNS boolean LANGUAGE plpgsql STABLE AS $$
DECLARE correction invoice_financial_corrections%ROWTYPE; original invoice_collections%ROWTYPE;
BEGIN
    SELECT * INTO correction FROM invoice_financial_corrections
    WHERE order_id=p_order_id AND kind='CANCEL_AND_REFUND'
      AND (state='COMPLETED' OR (p_for_intent AND state IN ('REQUESTED','PREPARED','READY_TO_FINALIZE')))
    ORDER BY id DESC LIMIT 1;
    IF correction.id IS NULL OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id=p_order_id) THEN RETURN false; END IF;
    SELECT * INTO original FROM invoice_collections WHERE id=correction.original_collection_id;
    IF original.id IS NULL OR original.order_id IS DISTINCT FROM p_order_id
       OR original.invoice_id IS DISTINCT FROM correction.original_invoice_id THEN RETURN false; END IF;
    IF p_for_intent THEN
        RETURN (SELECT operational_status NOT IN ('DELIVERED','CANCELLED') FROM orders WHERE id=p_order_id);
    END IF;
    RETURN correction.corrected_amount=0
       AND correction.customer_delta=-original.amount
       AND EXISTS (SELECT 1 FROM customer_refunds r WHERE r.invoice_financial_correction_id=correction.id
           AND r.original_collection_id=original.id AND r.amount=original.amount
           AND r.method=original.collection_method)
       AND EXISTS (SELECT 1 FROM invoices i WHERE i.id=correction.original_invoice_id
           AND i.status='REPLACED' AND i.superseded_at IS NOT NULL)
       AND NOT EXISTS (SELECT 1 FROM invoices WHERE order_id=p_order_id AND superseded_at IS NULL)
       AND NOT EXISTS (SELECT 1 FROM financial_transactions t
           JOIN invoice_collections c ON t.source_type='INVOICE_COLLECTION' AND t.source_id=c.id::text
           WHERE c.order_id=p_order_id AND t.transaction_kind='EVENT'
             AND NOT EXISTS (SELECT 1 FROM financial_transactions r WHERE r.reversal_of_transaction_id=t.id));
END;
$$;

CREATE OR REPLACE FUNCTION order_intent_financial_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF phase9c3a_can_cancel(NEW.order_id,true) THEN RETURN NEW; END IF;
    IF EXISTS (SELECT 1 FROM invoices WHERE order_id=NEW.order_id AND status='ISSUED' AND superseded_at IS NULL)
       OR EXISTS (SELECT 1 FROM invoice_collections WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoices i
           ON t.source_type='CUSTOMER_INVOICE' AND t.source_id=i.id::text
           WHERE i.order_id=NEW.order_id AND t.transaction_kind='EVENT')
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoice_collections c
           ON t.source_type='INVOICE_COLLECTION' AND t.source_id=c.id::text
           WHERE c.order_id=NEW.order_id AND t.transaction_kind='EVENT') THEN
        RAISE EXCEPTION 'FINANCIAL_CORRECTION_REQUIRED for cancellation intent order_id=%', NEW.order_id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;

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
            RAISE EXCEPTION 'Phase 9C.2 final history/status mismatch, order_id=%', p_order_id USING ERRCODE='23514';
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
           OR (NOT phase9c3a_can_cancel(p_order_id,false) AND (
               EXISTS (SELECT 1 FROM invoice_collections WHERE order_id=p_order_id)
               OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoices inv
                   ON t.source_type='CUSTOMER_INVOICE' AND t.source_id=inv.id::text
                   WHERE inv.order_id=p_order_id AND t.transaction_kind='EVENT')
               OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoice_collections col
                   ON t.source_type='INVOICE_COLLECTION' AND t.source_id=col.id::text
                   WHERE col.order_id=p_order_id AND t.transaction_kind='EVENT')))
           OR EXISTS (SELECT 1 FROM order_shops s WHERE s.order_id=p_order_id
               AND (s.picked_up_at IS NOT NULL OR EXISTS (SELECT 1 FROM order_shop_receipts r WHERE r.order_shop_id=s.id))
               AND NOT EXISTS (SELECT 1 FROM order_shop_purchase_confirmations p WHERE p.order_shop_id=s.id)) THEN
            RAISE EXCEPTION 'Phase 9C.2 final cancellation incomplete or financially unsafe, order_id=%', p_order_id USING ERRCODE='23514';
        END IF;
        RETURN;
    END IF;
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
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Collected-Invoice cancellation integration is intentionally forward-only.');
    }
};
