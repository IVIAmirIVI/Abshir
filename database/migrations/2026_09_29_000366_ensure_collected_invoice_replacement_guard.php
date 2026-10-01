<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Collected Invoice replacement protection requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoices, invoice_collections, invoice_financial_corrections IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_collected_invoice_replacement(p_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE i invoices%ROWTYPE; col invoice_collections%ROWTYPE; correction_id bigint; matches integer; related_ids text;
BEGIN
    SELECT * INTO i FROM invoices WHERE id=p_id;
    IF i.id IS NULL OR i.status<>'REPLACED' THEN RETURN; END IF;
    SELECT * INTO col FROM invoice_collections WHERE invoice_id=i.id;
    IF col.id IS NULL THEN
        IF i.replaced_by_invoice_id IS NULL THEN
            RAISE EXCEPTION 'Uncollected replaced Invoice requires replacement link: invoice_id=%', i.id USING ERRCODE='23514';
        END IF;
        RETURN;
    END IF;
    SELECT string_agg(id::text,',' ORDER BY id) INTO related_ids
      FROM invoice_financial_corrections WHERE original_invoice_id=i.id;
    SELECT count(*),min(c.id) INTO matches,correction_id FROM invoice_financial_corrections c
    WHERE c.original_invoice_id=i.id AND c.original_collection_id=col.id AND c.state='COMPLETED'
      AND c.order_id=i.order_id AND c.currency_id=i.currency_id
      AND c.branch_id=(SELECT branch_id FROM orders WHERE id=i.order_id)
      AND ((c.kind='REPRICE' AND c.replacement_invoice_id=i.replaced_by_invoice_id)
        OR (c.kind='CANCEL_AND_REFUND' AND c.replacement_invoice_id IS NULL AND i.replaced_by_invoice_id IS NULL));
    IF matches<>1 OR col.order_id IS DISTINCT FROM i.order_id OR col.currency_id IS DISTINCT FROM i.currency_id
       OR col.amount IS DISTINCT FROM i.grand_total OR i.superseded_at IS NULL
       OR i.replaced_at IS NULL OR i.replaced_by IS NULL THEN
        RAISE EXCEPTION 'Collected Invoice replacement requires completed financial correction: invoice_id=%, correction_ids=%',
            i.id,coalesce(related_ids,'none') USING ERRCODE='23514';
    END IF;
    BEGIN
        PERFORM validate_completed_invoice_correction(correction_id);
        PERFORM validate_complete_correction_financial_set(correction_id);
        PERFORM validate_invoice_correction_snapshot_facts(id) FROM invoice_correction_financial_snapshots
            WHERE invoice_financial_correction_id=correction_id;
    EXCEPTION WHEN OTHERS THEN
        RAISE EXCEPTION 'Invalid collected replacement history: invoice_id=%, correction_id=%: %',
            i.id,correction_id,SQLERRM USING ERRCODE='23514';
    END;
END;
$$;
CREATE OR REPLACE FUNCTION validate_replaced_invoice_at_commit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM validate_collected_invoice_replacement(NEW.id);
    RETURN NULL;
END;
$$;
SQL);
            foreach (DB::table('invoices')->where('status', 'REPLACED')
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('invoice_collections')->whereColumn('invoice_id', 'invoices.id'))
                ->orderBy('id')->pluck('id') as $id) {
                DB::select('SELECT validate_collected_invoice_replacement(?)', [$id]);
            }

            $trigger = DB::selectOne(<<<'SQL'
SELECT tgfoid='validate_replaced_invoice_at_commit()'::regprocedure AS correct_function,
       tgtype=17 AS correct_events, tgdeferrable, tginitdeferred, tgconstraint<>0 AS constraint_trigger
FROM pg_trigger WHERE tgrelid='invoices'::regclass AND tgname='replaced_invoice_complete'
SQL);
            if (! $trigger || ! $trigger->correct_function || ! $trigger->correct_events
                || ! $trigger->tgdeferrable || ! $trigger->tginitdeferred || ! $trigger->constraint_trigger) {
                DB::statement('DROP TRIGGER IF EXISTS replaced_invoice_complete ON invoices');
                DB::unprepared(<<<'SQL'
CREATE CONSTRAINT TRIGGER replaced_invoice_complete AFTER UPDATE ON invoices
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_replaced_invoice_at_commit();
SQL);
            }
            DB::statement('ALTER TABLE invoices ENABLE TRIGGER replaced_invoice_complete');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Collected Invoice replacement protection is intentionally forward-only.');
    }
};
