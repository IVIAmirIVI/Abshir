<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Captain financial Branch integrity requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            // Serialize the historical scan with writers. Source tables are locked first,
            // preserving the source -> ledger order of production transactions.
            DB::statement('LOCK TABLE orders, invoices, invoice_collections, order_bill_payments, order_bill_payment_principal_handovers IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('LOCK TABLE financial_owners, financial_accounts, financial_transactions, financial_entries IN ACCESS EXCLUSIVE MODE');

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION captain_financial_source_branch(p_transaction financial_transactions)
RETURNS bigint LANGUAGE plpgsql STABLE AS $$
DECLARE expected_branch bigint;
BEGIN
    IF p_transaction.transaction_kind = 'REVERSAL' THEN
        SELECT branch_id INTO expected_branch FROM financial_transactions
        WHERE id = p_transaction.reversal_of_transaction_id;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Financial transaction % reversal source % is missing',
                p_transaction.id, p_transaction.reversal_of_transaction_id USING ERRCODE = '23514';
        END IF;
        RETURN expected_branch;
    END IF;

    IF p_transaction.source_type NOT IN ('CUSTOMER_INVOICE', 'INVOICE_COLLECTION',
        'ORDER_BILL_PAYMENT', 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER') THEN
        RETURN NULL;
    END IF;
    IF p_transaction.source_id !~ '^[0-9]+$' OR length(p_transaction.source_id) > 18 THEN
        RAISE EXCEPTION 'Financial transaction % source %/% has invalid numeric identity',
            p_transaction.id, p_transaction.source_type, p_transaction.source_id USING ERRCODE = '23514';
    END IF;

    IF p_transaction.source_type = 'CUSTOMER_INVOICE' THEN
        SELECT o.branch_id INTO expected_branch FROM invoices i
        JOIN orders o ON o.id = i.order_id WHERE i.id = p_transaction.source_id::bigint;
    ELSIF p_transaction.source_type = 'INVOICE_COLLECTION' THEN
        SELECT o.branch_id INTO expected_branch FROM invoice_collections c
        JOIN invoices i ON i.id = c.invoice_id AND i.order_id = c.order_id
        JOIN orders o ON o.id = c.order_id WHERE c.id = p_transaction.source_id::bigint;
    ELSIF p_transaction.source_type = 'ORDER_BILL_PAYMENT' THEN
        SELECT o.branch_id INTO expected_branch FROM order_bill_payments p
        JOIN orders o ON o.id = p.order_id WHERE p.id = p_transaction.source_id::bigint;
    ELSE
        SELECT o.branch_id INTO expected_branch FROM order_bill_payment_principal_handovers h
        JOIN orders o ON o.id = h.order_id WHERE h.id = p_transaction.source_id::bigint;
    END IF;
    IF expected_branch IS NULL THEN
        RAISE EXCEPTION 'Financial transaction % source %/% has no authoritative Order Branch',
            p_transaction.id, p_transaction.source_type, p_transaction.source_id USING ERRCODE = '23514';
    END IF;
    RETURN expected_branch;
END;
$$;

CREATE OR REPLACE FUNCTION validate_captain_financial_transaction_branch(p_transaction_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE;
    expected_branch bigint;
    captain_details text;
BEGIN
    SELECT * INTO t FROM financial_transactions WHERE id = p_transaction_id;
    IF NOT FOUND THEN RETURN; END IF;

    SELECT string_agg(DISTINCT 'captain=' || o.captain_id::text ||
        '/owner=' || o.id::text || '/currency=' || a.currency_id::text, ', ')
    INTO captain_details
    FROM financial_entries e
    JOIN financial_accounts a ON a.id = e.financial_account_id
    JOIN financial_owners o ON o.id = a.financial_owner_id
    WHERE e.financial_transaction_id = t.id AND o.owner_type = 'CAPTAIN';

    IF captain_details IS NOT NULL AND t.branch_id IS NULL THEN
        RAISE EXCEPTION 'Captain financial transaction_id=% source=%/% event=% branch=NULL %: authoritative Branch required',
            t.id, t.source_type, t.source_id, t.event_type, captain_details USING ERRCODE = '23514';
    END IF;

    expected_branch := captain_financial_source_branch(t);
    IF (t.transaction_kind = 'REVERSAL' AND captain_details IS NOT NULL AND expected_branch IS NULL)
        OR (expected_branch IS NOT NULL AND t.branch_id IS DISTINCT FROM expected_branch) THEN
        RAISE EXCEPTION 'Captain financial transaction_id=% source=%/% event=% branch=% expected=% %: source/reversal Branch mismatch',
            t.id, t.source_type, t.source_id, t.event_type, t.branch_id, expected_branch,
            coalesce(captain_details, 'non-Captain') USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION validate_captain_financial_transaction_branch_trigger()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'financial_entries' THEN
        PERFORM validate_captain_financial_transaction_branch(NEW.financial_transaction_id);
    ELSE
        PERFORM validate_captain_financial_transaction_branch(NEW.id);
    END IF;
    RETURN NULL;
END;
$$;
SQL);

            $ids = DB::table('financial_transactions as t')
                ->join('financial_entries as e', 'e.financial_transaction_id', '=', 't.id')
                ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')
                ->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
                ->where(function ($query): void {
                    $query->where('o.owner_type', 'CAPTAIN')
                        ->orWhereIn('t.source_type', [
                            'CUSTOMER_INVOICE', 'INVOICE_COLLECTION',
                            'ORDER_BILL_PAYMENT', 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER',
                        ])
                        ->orWhere('t.transaction_kind', 'REVERSAL');
                })
                ->distinct()->orderBy('t.id')->pluck('t.id');
            foreach ($ids as $id) {
                DB::select('SELECT validate_captain_financial_transaction_branch(?)', [$id]);
            }

            DB::unprepared(<<<'SQL'
CREATE CONSTRAINT TRIGGER captain_financial_transaction_branch_guard
AFTER INSERT ON financial_transactions DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_transaction_branch_trigger();

CREATE CONSTRAINT TRIGGER captain_financial_entry_branch_guard
AFTER INSERT ON financial_entries DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validate_captain_financial_transaction_branch_trigger();

-- The source Order Branch is historical identity, not a mutable routing preference.
CREATE OR REPLACE FUNCTION prevent_order_financial_branch_change()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.branch_id IS DISTINCT FROM OLD.branch_id THEN
        RAISE EXCEPTION 'Order % source Branch is immutable', OLD.id USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER order_financial_branch_immutable
BEFORE UPDATE OF branch_id ON orders FOR EACH ROW
EXECUTE FUNCTION prevent_order_financial_branch_change();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Captain financial Branch integrity is intentionally forward-only.');
    }
};
