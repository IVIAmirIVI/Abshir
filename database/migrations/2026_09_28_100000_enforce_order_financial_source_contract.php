<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Order financial source integrity requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE orders, invoices, invoice_collections, order_bill_payments, order_bill_payment_principal_handovers IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('LOCK TABLE financial_transactions, financial_entries IN ACCESS EXCLUSIVE MODE');

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION order_financial_event_source_order_id(p_transaction financial_transactions)
RETURNS bigint LANGUAGE plpgsql STABLE AS $$
DECLARE
    required_source text;
    source_number bigint;
    source_order_id bigint;
    original_event_type text;
BEGIN
    IF p_transaction.event_type IN ('REVERSE_LEGACY_ORDER_EVENT', 'REVERSE_OBSOLETE_CASH_CUSTODY') THEN
        IF p_transaction.transaction_kind <> 'REVERSAL'
           OR p_transaction.source_type <> 'ORDER_FINANCIAL_SEMANTICS_CORRECTION'
           OR p_transaction.reversal_of_transaction_id IS NULL
           OR p_transaction.source_id IS DISTINCT FROM p_transaction.reversal_of_transaction_id::text THEN
            RAISE EXCEPTION 'Financial transaction % correction reversal has an invalid source link',
                p_transaction.id USING ERRCODE = '23514';
        END IF;
        SELECT event_type INTO original_event_type FROM financial_transactions
        WHERE id = p_transaction.reversal_of_transaction_id;
        IF (p_transaction.event_type = 'REVERSE_OBSOLETE_CASH_CUSTODY'
            AND original_event_type IS DISTINCT FROM 'ORDER_CASH_COMPANY_DELIVERY_SHARE_HELD')
           OR (p_transaction.event_type = 'REVERSE_LEGACY_ORDER_EVENT'
            AND original_event_type NOT IN ('ORDER_PRODUCT_REIMBURSEMENT', 'ORDER_DELIVERY_ENTITLEMENT',
                'ORDER_COMPANY_DELIVERY_SHARE', 'INVOICE_CASH_CUSTODY_RECEIVED')) THEN
            RAISE EXCEPTION 'Financial transaction % reverses an unrelated Order event',
                p_transaction.id USING ERRCODE = '23514';
        END IF;
        RETURN NULL;
    END IF;

    required_source := CASE
        WHEN p_transaction.event_type IN ('ORDER_PRODUCT_REIMBURSEMENT', 'ORDER_DELIVERY_ENTITLEMENT',
            'ORDER_COMPANY_DELIVERY_SHARE') THEN 'CUSTOMER_INVOICE'
        WHEN p_transaction.event_type IN ('INVOICE_CASH_CUSTODY_RECEIVED',
            'ORDER_CASH_COMPANY_DELIVERY_SHARE_HELD', 'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT',
            'ORDER_CASH_DELIVERY_SHORTFALL_DUE', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
            'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE') THEN 'INVOICE_COLLECTION'
        WHEN p_transaction.event_type IN ('BILL_PAYMENT_CASH_COLLECTED',
            'BILL_PAYMENT_SERVICE_FEE_RETAINED') THEN 'ORDER_BILL_PAYMENT'
        WHEN p_transaction.event_type = 'BILL_PAYMENT_PRINCIPAL_HANDED_OVER'
            THEN 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER'
        ELSE NULL
    END;
    IF required_source IS NULL THEN RETURN NULL; END IF;

    IF p_transaction.transaction_kind <> 'EVENT'
       OR p_transaction.source_type IS DISTINCT FROM required_source THEN
        RAISE EXCEPTION 'Financial transaction % event % requires canonical source %',
            p_transaction.id, p_transaction.event_type, required_source USING ERRCODE = '23514';
    END IF;
    IF p_transaction.source_id !~ '^[1-9][0-9]{0,18}$'
       OR (length(p_transaction.source_id) = 19 AND p_transaction.source_id > '9223372036854775807') THEN
        RAISE EXCEPTION 'Financial transaction % event % has an invalid canonical source ID %',
            p_transaction.id, p_transaction.event_type, p_transaction.source_id USING ERRCODE = '23514';
    END IF;
    source_number := p_transaction.source_id::bigint;

    IF required_source = 'CUSTOMER_INVOICE' THEN
        SELECT i.order_id INTO source_order_id FROM invoices i WHERE i.id = source_number;
    ELSIF required_source = 'INVOICE_COLLECTION' THEN
        SELECT c.order_id INTO source_order_id FROM invoice_collections c
        JOIN invoices i ON i.id = c.invoice_id AND i.order_id = c.order_id
        WHERE c.id = source_number;
    ELSIF required_source = 'ORDER_BILL_PAYMENT' THEN
        SELECT p.order_id INTO source_order_id FROM order_bill_payments p WHERE p.id = source_number;
    ELSE
        SELECT h.order_id INTO source_order_id FROM order_bill_payment_principal_handovers h
        JOIN order_bill_payments p ON p.id = h.bill_payment_id AND p.order_id = h.order_id
        WHERE h.id = source_number;
    END IF;
    IF source_order_id IS NULL THEN
        RAISE EXCEPTION 'Financial transaction % event % source %/% has no authoritative Order',
            p_transaction.id, p_transaction.event_type, required_source, p_transaction.source_id
            USING ERRCODE = '23514';
    END IF;
    RETURN source_order_id;
END;
$$;

CREATE OR REPLACE FUNCTION validate_order_financial_event_source(p_transaction_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    transaction_row financial_transactions%ROWTYPE;
    source_order_id bigint;
    source_branch_id bigint;
BEGIN
    SELECT * INTO transaction_row FROM financial_transactions WHERE id = p_transaction_id;
    IF NOT FOUND THEN RETURN; END IF;
    source_order_id := order_financial_event_source_order_id(transaction_row);
    IF source_order_id IS NULL THEN RETURN; END IF;
    SELECT branch_id INTO source_branch_id FROM orders WHERE id = source_order_id FOR UPDATE;
    IF source_branch_id IS NULL OR transaction_row.branch_id IS DISTINCT FROM source_branch_id THEN
        RAISE EXCEPTION 'Financial transaction % event % source Order % Branch mismatch: actual %, expected %',
            transaction_row.id, transaction_row.event_type, source_order_id,
            transaction_row.branch_id, source_branch_id USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION lock_order_financial_event_source_before_insert()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_order_id bigint;
BEGIN
    -- Existing source rows are locked before the Currency trigger. An invalid or
    -- not-yet-created source is rejected by the deferred contract at COMMIT.
    BEGIN
        source_order_id := order_financial_event_source_order_id(NEW);
    EXCEPTION WHEN SQLSTATE '23514' THEN
        RETURN NEW;
    END;
    IF source_order_id IS NOT NULL THEN
        PERFORM id FROM orders WHERE id = source_order_id FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Financial event % has no source Order %', NEW.event_type,
                source_order_id USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION validate_order_financial_event_source_at_commit()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM validate_order_financial_event_source(NEW.id);
    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION prevent_order_financial_branch_change()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.branch_id IS NOT DISTINCT FROM OLD.branch_id THEN RETURN NEW; END IF;
    IF EXISTS (SELECT 1 FROM invoices WHERE order_id = OLD.id AND status = 'ISSUED')
       OR EXISTS (SELECT 1 FROM order_financial_source_snapshots WHERE order_id = OLD.id)
       OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id = OLD.id)
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoices i
           ON t.source_type = 'CUSTOMER_INVOICE' AND t.source_id = i.id::text WHERE i.order_id = OLD.id)
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoice_collections c
           ON t.source_type = 'INVOICE_COLLECTION' AND t.source_id = c.id::text WHERE c.order_id = OLD.id)
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN order_bill_payments p
           ON t.source_type = 'ORDER_BILL_PAYMENT' AND t.source_id = p.id::text WHERE p.order_id = OLD.id)
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN order_bill_payment_principal_handovers h
           ON t.source_type = 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER' AND t.source_id = h.id::text
           WHERE h.order_id = OLD.id) THEN
        RAISE EXCEPTION 'Order % financial source Branch is immutable', OLD.id USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$;
SQL);

            $ids = DB::table('financial_transactions')
                ->whereIn('event_type', [
                    'ORDER_PRODUCT_REIMBURSEMENT', 'ORDER_DELIVERY_ENTITLEMENT',
                    'ORDER_COMPANY_DELIVERY_SHARE', 'INVOICE_CASH_CUSTODY_RECEIVED',
                    'ORDER_CASH_COMPANY_DELIVERY_SHARE_HELD',
                    'ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT',
                    'ORDER_CASH_DELIVERY_SHORTFALL_DUE',
                    'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
                    'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE',
                    'BILL_PAYMENT_CASH_COLLECTED', 'BILL_PAYMENT_PRINCIPAL_HANDED_OVER',
                    'BILL_PAYMENT_SERVICE_FEE_RETAINED',
                    'REVERSE_LEGACY_ORDER_EVENT', 'REVERSE_OBSOLETE_CASH_CUSTODY',
                ])->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                DB::select('SELECT validate_order_financial_event_source(?)', [$id]);
            }

            DB::unprepared(<<<'SQL'
CREATE TRIGGER aa_order_financial_event_source_lock
BEFORE INSERT ON financial_transactions FOR EACH ROW
EXECUTE FUNCTION lock_order_financial_event_source_before_insert();

CREATE CONSTRAINT TRIGGER order_financial_event_source_contract
AFTER INSERT ON financial_transactions DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validate_order_financial_event_source_at_commit();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Order financial source integrity is intentionally forward-only.');
    }
};
