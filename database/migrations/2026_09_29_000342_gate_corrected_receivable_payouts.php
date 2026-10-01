<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Corrected Receivable deployment gating requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE captain_receivable_payout_allocations IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION corrected_receivable_payouts_active() RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT false;
$$;
CREATE OR REPLACE FUNCTION validate_corrected_receivable_payout_source(p_entry bigint, p_payout bigint)
RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM financial_entries e JOIN financial_transactions t ON t.id=e.financial_transaction_id
        WHERE e.id=p_entry AND (t.source_type='INVOICE_FINANCIAL_CORRECTION'
          OR t.event_type IN ('INVOICE_CORRECTION_CASH_SHORTFALL_DUE',
            'INVOICE_CORRECTION_SHAM_PRODUCT_DUE','INVOICE_CORRECTION_SHAM_DELIVERY_DUE'))) THEN
        RAISE EXCEPTION 'Corrected Receivable payouts are gated until complete financial-contract activation: source_entry_id=%, payout_id=%',
            p_entry, p_payout USING ERRCODE='23514';
    END IF;
END;
$$;
CREATE OR REPLACE FUNCTION corrected_receivable_payout_gate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM validate_corrected_receivable_payout_source(NEW.financial_entry_id,NEW.captain_receivable_payout_id);
    RETURN NEW;
END;
$$;
CREATE TRIGGER corrected_receivable_payout_activation_guard BEFORE INSERT ON captain_receivable_payout_allocations
FOR EACH ROW EXECUTE FUNCTION corrected_receivable_payout_gate();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Corrected Receivable deployment gating is intentionally forward-only.');
    }
};
