<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Corrected Receivable activation requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            // Prevent new sources/allocations between retrospective validation and activation.
            DB::statement('LOCK TABLE invoice_financial_corrections, invoice_correction_financial_snapshots, invoice_correction_reversals, financial_transactions, financial_entries, captain_receivable_payouts, captain_receivable_payout_allocations IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_financial_corrections'::regclass
        AND tgname='completed_invoice_correction_contract' AND tgenabled IN ('O','A'))
       OR NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_financial_corrections'::regclass
        AND tgname='completed_correction_financial_set' AND tgenabled IN ('O','A'))
       OR NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='orders'::regclass
        AND tgname='delivered_correction_financial_set' AND tgenabled IN ('O','A')) THEN
        RAISE EXCEPTION 'Corrected payout activation requires installed 000350/000360 completion contracts' USING ERRCODE='23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION corrected_receivable_payouts_active() RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_financial_corrections'::regclass
        AND tgname='completed_invoice_correction_contract' AND tgenabled IN ('O','A'))
      AND EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_financial_corrections'::regclass
        AND tgname='completed_correction_financial_set' AND tgenabled IN ('O','A'))
      AND EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid='orders'::regclass
        AND tgname='delivered_correction_financial_set' AND tgenabled IN ('O','A'));
$$;

CREATE OR REPLACE FUNCTION validate_corrected_receivable_payout_source(p_entry bigint, p_payout bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE; c invoice_financial_corrections%ROWTYPE; snapshot_id bigint; event_id bigint;
BEGIN
    SELECT tx.* INTO t FROM financial_entries e JOIN financial_transactions tx ON tx.id=e.financial_transaction_id
      WHERE e.id=p_entry;
    IF t.id IS NULL OR (t.source_type<>'INVOICE_FINANCIAL_CORRECTION' AND t.event_type NOT IN (
        'INVOICE_CORRECTION_CASH_SHORTFALL_DUE','INVOICE_CORRECTION_SHAM_PRODUCT_DUE','INVOICE_CORRECTION_SHAM_DELIVERY_DUE')) THEN
        RETURN;
    END IF;
    BEGIN
        IF NOT corrected_receivable_payouts_active() THEN
            RAISE EXCEPTION 'Corrected payout completion contracts are not active' USING ERRCODE='23514';
        END IF;
        IF t.source_type<>'INVOICE_FINANCIAL_CORRECTION' OR t.source_id !~ '^[1-9][0-9]{0,18}$'
           OR (length(t.source_id)=19 AND t.source_id>'9223372036854775807') THEN
            RAISE EXCEPTION 'Invalid corrected Receivable source identity' USING ERRCODE='23514';
        END IF;
        SELECT * INTO c FROM invoice_financial_corrections WHERE id=t.source_id::bigint;
        IF c.id IS NULL OR c.state<>'COMPLETED' THEN
            RAISE EXCEPTION 'Corrected Receivable requires a COMPLETED correction' USING ERRCODE='23514';
        END IF;
        PERFORM validate_completed_invoice_correction(c.id);
        PERFORM validate_complete_correction_financial_set(c.id);
        SELECT id INTO snapshot_id FROM invoice_correction_financial_snapshots WHERE invoice_financial_correction_id=c.id;
        PERFORM validate_invoice_correction_snapshot_facts(snapshot_id);
        FOR event_id IN
            SELECT id FROM financial_transactions WHERE source_type='INVOICE_FINANCIAL_CORRECTION' AND source_id=c.id::text
            UNION SELECT reversal_transaction_id FROM invoice_correction_reversals WHERE invoice_financial_correction_id=c.id
        LOOP
            PERFORM validate_invoice_correction_ledger_transaction(event_id);
        END LOOP;
        PERFORM validate_invoice_correction_ledger_transaction(t.id);
    EXCEPTION WHEN OTHERS THEN
        RAISE EXCEPTION 'Corrected payout integrity failure: correction_id=%, source_entry_id=%, payout_id=%: %',
            c.id, p_entry, p_payout, SQLERRM USING ERRCODE='23514';
    END;
END;
$$;
SQL);
            foreach (DB::table('invoice_financial_corrections')->where('state', 'COMPLETED')->pluck('id') as $id) {
                try {
                    DB::select('SELECT validate_completed_invoice_correction(?)', [$id]);
                    DB::select('SELECT validate_complete_correction_financial_set(?)', [$id]);
                    $snapshotId = DB::table('invoice_correction_financial_snapshots')->where('invoice_financial_correction_id', $id)->value('id');
                    DB::select('SELECT validate_invoice_correction_snapshot_facts(?)', [$snapshotId]);
                } catch (Throwable $exception) {
                    throw new RuntimeException("Corrected payout activation found invalid completed history: correction_id={$id}", previous: $exception);
                }
            }
            foreach (DB::select(<<<'SQL'
SELECT e.id FROM financial_entries e JOIN financial_transactions t ON t.id=e.financial_transaction_id
WHERE t.source_type='INVOICE_FINANCIAL_CORRECTION' OR t.event_type IN (
    'INVOICE_CORRECTION_CASH_SHORTFALL_DUE','INVOICE_CORRECTION_SHAM_PRODUCT_DUE','INVOICE_CORRECTION_SHAM_DELIVERY_DUE')
ORDER BY e.id
SQL) as $entry) {
                DB::select('SELECT validate_corrected_receivable_payout_source(?,NULL)', [$entry->id]);
            }
            foreach (DB::select(<<<'SQL'
SELECT a.financial_entry_id, a.captain_receivable_payout_id, t.source_id AS correction_id
FROM captain_receivable_payout_allocations a
JOIN financial_entries e ON e.id=a.financial_entry_id
JOIN financial_transactions t ON t.id=e.financial_transaction_id
WHERE t.source_type='INVOICE_FINANCIAL_CORRECTION' OR t.event_type IN (
    'INVOICE_CORRECTION_CASH_SHORTFALL_DUE','INVOICE_CORRECTION_SHAM_PRODUCT_DUE','INVOICE_CORRECTION_SHAM_DELIVERY_DUE')
ORDER BY a.id
SQL) as $allocation) {
                DB::select('SELECT validate_corrected_receivable_payout_source(?,?)', [$allocation->financial_entry_id, $allocation->captain_receivable_payout_id]);
                try {
                    DB::select("SELECT validate_captain_financial_operation('PAYOUT',?)", [$allocation->captain_receivable_payout_id]);
                } catch (Throwable $exception) {
                    throw new RuntimeException("Corrected payout allocation is invalid: correction_id={$allocation->correction_id}, source_entry_id={$allocation->financial_entry_id}, payout_id={$allocation->captain_receivable_payout_id}", previous: $exception);
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Corrected Receivable activation is intentionally forward-only.');
    }
};
