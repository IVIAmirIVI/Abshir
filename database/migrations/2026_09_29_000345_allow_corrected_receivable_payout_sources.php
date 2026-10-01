<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Corrected Receivable payout sources require PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE financial_transactions, financial_entries, captain_receivable_payout_allocations IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_captain_financial_operation(p_type text, p_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    source_row record;
    required_source text;
    required_event text;
    required_kind text;
    required_direction text;
    matching_count integer;
    matching_event_id bigint;
    event_row financial_transactions%ROWTYPE;
    entry_row record;
    owner_row record;
    allocated numeric;
    source_entry record;
    allocated_total numeric;
    branch_balance numeric;
BEGIN
    IF p_type = 'DEPOSIT' THEN
        SELECT * INTO source_row FROM captain_deposit_receipts WHERE id = p_id;
        required_source := 'CAPTAIN_DEPOSIT_RECEIPT';
        required_event := 'CAPTAIN_DEPOSIT_RECEIVED';
        required_kind := 'CAPTAIN_DEPOSIT';
        required_direction := 'INCREASE';
    ELSE
        SELECT * INTO source_row FROM captain_receivable_payouts WHERE id = p_id;
        required_source := 'CAPTAIN_RECEIVABLE_PAYOUT';
        required_event := 'CAPTAIN_RECEIVABLE_PAID';
        required_kind := 'CAPTAIN_COMPANY_RECEIVABLE';
        required_direction := 'DECREASE';
    END IF;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Captain financial % source % is missing', p_type, p_id USING ERRCODE = '23514';
    END IF;

    SELECT count(*), min(id) INTO matching_count, matching_event_id FROM financial_transactions
    WHERE source_type = required_source AND source_id = p_id::text AND event_type = required_event;
    IF matching_count <> 1 THEN
        RAISE EXCEPTION 'Captain financial % source % requires exactly one posted event', p_type, p_id USING ERRCODE = '23514';
    END IF;
    SELECT * INTO event_row FROM financial_transactions WHERE id = matching_event_id;
    IF event_row.transaction_kind <> 'EVENT' OR event_row.status <> 'POSTED' OR event_row.source_version <> 1
       OR event_row.branch_id IS DISTINCT FROM source_row.branch_id
       OR event_row.currency_id IS DISTINCT FROM source_row.currency_id
       OR event_row.occurred_at IS DISTINCT FROM source_row.occurred_at THEN
        RAISE EXCEPTION 'Captain financial % event % differs from immutable source %', p_type, event_row.id, p_id USING ERRCODE = '23514';
    END IF;

    SELECT e.*, a.account_kind, a.financial_owner_id, a.currency_id AS account_currency_id
      INTO entry_row FROM financial_entries e JOIN financial_accounts a ON a.id = e.financial_account_id
      WHERE e.financial_transaction_id = event_row.id;
    IF NOT FOUND OR (SELECT count(*) FROM financial_entries WHERE financial_transaction_id = event_row.id) <> 1
       OR entry_row.account_kind <> required_kind OR entry_row.direction <> required_direction
       OR entry_row.amount IS DISTINCT FROM source_row.amount
       OR entry_row.account_currency_id <> source_row.currency_id THEN
        RAISE EXCEPTION 'Captain financial % event % has wrong account, direction, or exact amount', p_type, event_row.id USING ERRCODE = '23514';
    END IF;
    SELECT * INTO owner_row FROM financial_owners WHERE id = entry_row.financial_owner_id;
    IF owner_row.owner_type <> 'CAPTAIN' OR owner_row.captain_id IS DISTINCT FROM source_row.captain_id THEN
        RAISE EXCEPTION 'Captain financial % event % targets another Captain', p_type, event_row.id USING ERRCODE = '23514';
    END IF;

    IF p_type <> 'PAYOUT' THEN RETURN; END IF;
    PERFORM id FROM financial_accounts WHERE id = entry_row.financial_account_id FOR UPDATE;
    allocated := 0;
    FOR source_entry IN
        SELECT a.*, e.amount AS source_amount, e.direction, e.currency_id AS entry_currency_id,
               t.id AS source_transaction_id, t.branch_id AS source_branch_id,
               t.event_type AS source_event_type, t.status AS source_status,
               t.transaction_kind AS source_kind, e.financial_account_id AS source_account_id
        FROM captain_receivable_payout_allocations a
        JOIN financial_entries e ON e.id = a.financial_entry_id
        JOIN financial_transactions t ON t.id = e.financial_transaction_id
        WHERE a.captain_receivable_payout_id = p_id ORDER BY a.financial_entry_id
    LOOP
        IF source_entry.source_account_id <> entry_row.financial_account_id
           OR source_entry.entry_currency_id <> source_row.currency_id
           OR source_entry.source_branch_id IS DISTINCT FROM source_row.branch_id
           OR source_entry.direction <> 'INCREASE' OR source_entry.source_status <> 'POSTED'
           OR source_entry.source_kind <> 'EVENT'
           OR source_entry.source_event_type NOT IN ('ORDER_CASH_DELIVERY_SHORTFALL_DUE',
               'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE', 'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE',
               'INVOICE_CORRECTION_CASH_SHORTFALL_DUE', 'INVOICE_CORRECTION_SHAM_PRODUCT_DUE',
               'INVOICE_CORRECTION_SHAM_DELIVERY_DUE')
           OR EXISTS (SELECT 1 FROM financial_transactions r
               WHERE r.reversal_of_transaction_id = source_entry.source_transaction_id) THEN
            RAISE EXCEPTION 'Payout % allocation % has an ineligible Receivable source', p_id, source_entry.id USING ERRCODE = '23514';
        END IF;
        SELECT coalesce(sum(amount), 0) INTO allocated_total FROM captain_receivable_payout_allocations
        WHERE financial_entry_id = source_entry.financial_entry_id;
        IF allocated_total > source_entry.source_amount THEN
            RAISE EXCEPTION 'Receivable entry % is overallocated', source_entry.financial_entry_id USING ERRCODE = '23514';
        END IF;
        allocated := allocated + source_entry.amount;
    END LOOP;
    IF allocated <> source_row.amount THEN
        RAISE EXCEPTION 'Payout % allocation total % differs from amount %', p_id, allocated, source_row.amount USING ERRCODE = '23514';
    END IF;
    SELECT coalesce(sum(CASE WHEN e.direction = 'INCREASE' THEN e.amount ELSE -e.amount END), 0)
      INTO branch_balance FROM financial_entries e JOIN financial_transactions t ON t.id = e.financial_transaction_id
      WHERE e.financial_account_id = entry_row.financial_account_id AND t.branch_id = source_row.branch_id;
    IF branch_balance < 0 THEN
        RAISE EXCEPTION 'Payout % would make Branch Receivable negative', p_id USING ERRCODE = '23514';
    END IF;
END;
$$;
SQL);
            foreach (DB::table('captain_receivable_payouts')->pluck('id') as $id) {
                DB::select("SELECT validate_captain_financial_operation('PAYOUT', ?)", [$id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Corrected Receivable payout source eligibility is intentionally forward-only.');
    }
};
