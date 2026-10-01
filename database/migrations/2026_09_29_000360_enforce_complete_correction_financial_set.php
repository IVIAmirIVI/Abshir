<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Complete correction financial sets require PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_financial_corrections, invoice_correction_reversals, financial_transactions, orders IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
ALTER TABLE financial_transactions ADD CONSTRAINT correction_source_event_whitelist CHECK (
    (source_type <> 'INVOICE_FINANCIAL_CORRECTION' OR event_type IN (
        'INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT', 'INVOICE_CORRECTION_CASH_SHORTFALL_DUE',
        'INVOICE_CORRECTION_SHAM_PRODUCT_DUE', 'INVOICE_CORRECTION_SHAM_DELIVERY_DUE'))
    AND (source_type <> 'INVOICE_FINANCIAL_CORRECTION_REVERSAL'
        OR event_type = 'INVOICE_CORRECTION_REVERSE_ORIGINAL')
);

CREATE OR REPLACE FUNCTION validate_complete_correction_financial_set(p_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    c invoice_financial_corrections%ROWTYPE;
    payload jsonb;
    method text;
    order_status text;
    original_count integer;
    reversal_count integer;
    corrected_count integer;
    expected_count integer := 0;
    company_amount numeric;
    shortfall_amount numeric;
    product_amount numeric;
    entitlement_amount numeric;
BEGIN
    SELECT * INTO c FROM invoice_financial_corrections WHERE id=p_id;
    IF c.id IS NULL OR c.state <> 'COMPLETED' THEN RETURN; END IF;
    SELECT operational_status INTO order_status FROM orders WHERE id=c.order_id;
    SELECT collection_method INTO method FROM invoice_collections WHERE id=c.original_collection_id;
    SELECT s.payload INTO payload FROM invoice_correction_financial_snapshots s
        WHERE s.invoice_financial_correction_id=c.id;

    SELECT count(*) INTO original_count FROM financial_transactions t
      WHERE t.source_type='INVOICE_COLLECTION' AND t.source_id=c.original_collection_id::text
        AND t.event_type IN ('ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT',
            'ORDER_CASH_DELIVERY_SHORTFALL_DUE', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
            'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE');
    SELECT count(*) INTO reversal_count FROM invoice_correction_reversals r
      WHERE r.invoice_financial_correction_id=c.id;
    IF original_count <> reversal_count OR EXISTS (
        SELECT 1 FROM financial_transactions t LEFT JOIN invoice_correction_reversals r
          ON r.original_transaction_id=t.id AND r.invoice_financial_correction_id=c.id
        WHERE t.source_type='INVOICE_COLLECTION' AND t.source_id=c.original_collection_id::text
          AND t.event_type IN ('ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT',
            'ORDER_CASH_DELIVERY_SHORTFALL_DUE', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
            'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE') AND r.id IS NULL
    ) OR EXISTS (
        SELECT 1 FROM invoice_correction_reversals r
        JOIN financial_transactions t ON t.id=r.original_transaction_id
        WHERE r.invoice_financial_correction_id=c.id
          AND (t.source_type<>'INVOICE_COLLECTION' OR t.source_id<>c.original_collection_id::text
            OR t.event_type NOT IN ('ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT',
              'ORDER_CASH_DELIVERY_SHORTFALL_DUE', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
              'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE'))
    ) THEN
        RAISE EXCEPTION 'Completed correction % lacks the complete original reversal set', c.id USING ERRCODE='23514';
    END IF;

    SELECT count(*) INTO corrected_count FROM financial_transactions t
      WHERE t.source_type='INVOICE_FINANCIAL_CORRECTION' AND t.source_id=c.id::text;
    IF c.kind='REPRICE' AND order_status='DELIVERED' THEN
        SELECT coalesce(sum((a.value->>'company_collectible')::numeric),0),
               coalesce(sum((a.value->>'captain_shortfall')::numeric),0),
               coalesce(sum((a.value->>'product_funding_share')::numeric),0),
               coalesce(sum((a.value->>'captain_entitlement')::numeric),0)
          INTO company_amount, shortfall_amount, product_amount, entitlement_amount
          FROM jsonb_array_elements(payload->'allocations') a;
        IF method='CASH' THEN
            expected_count := (company_amount>0)::integer + (shortfall_amount>0)::integer;
            IF (company_amount>0) IS DISTINCT FROM EXISTS (
                SELECT 1 FROM financial_transactions t WHERE t.source_type='INVOICE_FINANCIAL_CORRECTION'
                  AND t.source_id=c.id::text AND t.event_type='INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT')
              OR (shortfall_amount>0) IS DISTINCT FROM EXISTS (
                SELECT 1 FROM financial_transactions t WHERE t.source_type='INVOICE_FINANCIAL_CORRECTION'
                  AND t.source_id=c.id::text AND t.event_type='INVOICE_CORRECTION_CASH_SHORTFALL_DUE') THEN
                RAISE EXCEPTION 'Completed correction % lacks a required CASH event', c.id USING ERRCODE='23514';
            END IF;
        ELSIF method='SHAM_CASH' THEN
            expected_count := (product_amount>0)::integer + (entitlement_amount>0)::integer;
            IF (product_amount>0) IS DISTINCT FROM EXISTS (
                SELECT 1 FROM financial_transactions t WHERE t.source_type='INVOICE_FINANCIAL_CORRECTION'
                  AND t.source_id=c.id::text AND t.event_type='INVOICE_CORRECTION_SHAM_PRODUCT_DUE')
              OR (entitlement_amount>0) IS DISTINCT FROM EXISTS (
                SELECT 1 FROM financial_transactions t WHERE t.source_type='INVOICE_FINANCIAL_CORRECTION'
                  AND t.source_id=c.id::text AND t.event_type='INVOICE_CORRECTION_SHAM_DELIVERY_DUE') THEN
                RAISE EXCEPTION 'Completed correction % lacks a required SHAM_CASH event', c.id USING ERRCODE='23514';
            END IF;
        ELSE
            RAISE EXCEPTION 'Completed correction % has unsupported Collection method', c.id USING ERRCODE='23514';
        END IF;
    ELSIF original_count <> 0 THEN
        RAISE EXCEPTION 'Non-delivered correction % unexpectedly has original Order events', c.id USING ERRCODE='23514';
    END IF;
    IF corrected_count <> expected_count THEN
        RAISE EXCEPTION 'Completed correction % has incomplete or extra corrected financial events', c.id USING ERRCODE='23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION completed_correction_financial_set_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM validate_complete_correction_financial_set(NEW.id);
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER completed_correction_financial_set
AFTER UPDATE ON invoice_financial_corrections DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION completed_correction_financial_set_guard();

CREATE OR REPLACE FUNCTION delivered_correction_financial_set_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE correction_id bigint;
BEGIN
    IF NEW.operational_status='DELIVERED' THEN
        FOR correction_id IN SELECT id FROM invoice_financial_corrections
            WHERE order_id=NEW.id AND state='COMPLETED' LOOP
            PERFORM validate_complete_correction_financial_set(correction_id);
        END LOOP;
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER delivered_correction_financial_set
AFTER UPDATE ON orders DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION delivered_correction_financial_set_guard();
SQL);
            foreach (DB::table('invoice_financial_corrections')->where('state', 'COMPLETED')->pluck('id') as $id) {
                DB::select('SELECT validate_complete_correction_financial_set(?)', [$id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Complete correction financial sets are intentionally forward-only.');
    }
};
