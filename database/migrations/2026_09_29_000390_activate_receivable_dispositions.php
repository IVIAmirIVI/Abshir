<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Receivable disposition activation requires PostgreSQL.');
        }
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_financial_corrections, invoice_correction_receivable_dispositions, invoice_correction_receivable_disposition_allocations, financial_transactions, financial_entries, captain_receivable_payouts, captain_receivable_payout_allocations IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
DO $$ DECLARE bad record;
BEGIN
 SELECT a.id,a.financial_entry_id INTO bad FROM captain_receivable_payout_allocations a
 JOIN captain_receivable_payouts p ON p.id=a.captain_receivable_payout_id
 JOIN currencies c ON c.id=p.currency_id
 WHERE a.amount::text IN ('NaN','Infinity','-Infinity') OR a.amount<=0 OR a.amount<>round(a.amount,c.decimal_places);
 IF FOUND THEN RAISE EXCEPTION 'Invalid historical payout allocation: allocation_id=% source_entry_id=%',bad.id,bad.financial_entry_id USING ERRCODE='23514'; END IF;
 SELECT e.id INTO bad FROM financial_entries e JOIN captain_receivable_payout_allocations a ON a.financial_entry_id=e.id
 GROUP BY e.id HAVING sum(a.amount)>e.amount OR EXISTS(SELECT 1 FROM financial_transactions r WHERE r.reversal_of_transaction_id=e.financial_transaction_id);
 IF FOUND THEN RAISE EXCEPTION 'Invalid historical paid Receivable: source_entry_id=%',bad.id USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM invoice_correction_receivable_dispositions) THEN
   RAISE EXCEPTION 'Disposition activation found unexpected preactivation history' USING ERRCODE='23514';
 END IF;
END;
$$;
CREATE OR REPLACE FUNCTION receivable_dispositions_active() RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='captain_receivable_payout_allocations'::regclass
   AND tgname='aa_reserved_receivable_allocation' AND tgenabled IN ('O','A'))
 AND EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_financial_corrections'::regclass
   AND tgname='receivable_correction_complete' AND tgenabled IN ('O','A'))
 AND EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_correction_receivable_dispositions'::regclass
   AND tgname='receivable_disposition_complete' AND tgenabled IN ('O','A'))
 AND EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='financial_transactions'::regclass
   AND tgname='aa_disposition_order_lock' AND tgenabled IN ('O','A'))
 AND EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_correction_receivable_dispositions'::regclass
   AND tgname='disposition_immutable' AND tgenabled IN ('O','A'))
 AND EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_correction_receivable_disposition_allocations'::regclass
   AND tgname='disposition_allocation_immutable' AND tgenabled IN ('O','A'))
 AND EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_correction_receivable_disposition_allocations'::regclass
   AND tgname='receivable_disposition_allocation_complete' AND tgenabled IN ('O','A'))
 AND EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='invoice_financial_corrections'::regclass
   AND tgname='receivable_contract_version_guard' AND tgenabled IN ('O','A'))
 AND corrected_receivable_payouts_active();
$$;
SQL);
            if (! DB::scalar('SELECT receivable_dispositions_active()')) {
                throw new RuntimeException('Receivable disposition activation requires all source/reservation/completion guards.');
            }
            foreach (DB::table('captain_receivable_payouts')->orderBy('id')->pluck('id') as $id) {
                DB::select("SELECT validate_captain_financial_operation('PAYOUT',?)", [$id]);
                foreach (DB::table('captain_receivable_payout_allocations')->where('captain_receivable_payout_id', $id)->pluck('financial_entry_id') as $entryId) {
                    DB::select('SELECT validate_corrected_receivable_payout_source(?,?)', [$entryId, $id]);
                }
            }
            foreach (DB::table('invoice_financial_corrections')->where('state', 'COMPLETED')->pluck('id') as $id) {
                DB::select('SELECT validate_completed_invoice_correction(?)', [$id]);
                DB::select('SELECT validate_complete_correction_financial_set(?)', [$id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Receivable disposition activation is intentionally forward-only.');
    }
};
