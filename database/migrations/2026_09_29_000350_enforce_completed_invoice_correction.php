<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Completed financial corrections require PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_financial_corrections, customer_refunds, invoice_correction_financial_snapshots, invoices IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_completed_invoice_correction(p_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    c invoice_financial_corrections%ROWTYPE;
    original invoices%ROWTYPE;
    replacement invoices%ROWTYPE;
    refund_count integer;
    refund_total numeric;
    snapshot_count integer;
BEGIN
    SELECT * INTO c FROM invoice_financial_corrections WHERE id=p_id;
    IF c.id IS NULL OR c.state <> 'COMPLETED' THEN RETURN; END IF;
    SELECT * INTO original FROM invoices WHERE id=c.original_invoice_id;
    SELECT count(*), coalesce(sum(amount),0) INTO refund_count, refund_total
      FROM customer_refunds WHERE invoice_financial_correction_id=c.id;
    SELECT count(*) INTO snapshot_count FROM invoice_correction_financial_snapshots
      WHERE invoice_financial_correction_id=c.id;
    IF c.finalized_by IS NULL OR c.finalized_at IS NULL OR c.corrected_amount IS NULL
       OR c.customer_delta IS NULL OR c.corrected_amount-c.original_amount <> c.customer_delta
       OR snapshot_count <> 1 OR original.status <> 'REPLACED'
       OR (c.customer_delta < 0 AND (refund_count <> 1 OR refund_total <> -c.customer_delta))
       OR (c.customer_delta = 0 AND refund_count <> 0) THEN
        RAISE EXCEPTION 'Completed correction % lacks exact immutable financial disposition', c.id USING ERRCODE='23514';
    END IF;
    IF c.kind='REPRICE' THEN
        SELECT * INTO replacement FROM invoices WHERE id=c.replacement_invoice_id;
        IF replacement.id IS NULL OR replacement.status <> 'ISSUED'
           OR replacement.order_id IS DISTINCT FROM c.order_id
           OR replacement.currency_id IS DISTINCT FROM c.currency_id
           OR replacement.grand_total IS DISTINCT FROM c.corrected_amount
           OR replacement.version <= original.version
           OR original.replaced_by_invoice_id IS DISTINCT FROM replacement.id THEN
            RAISE EXCEPTION 'Completed correction % has no exact issued replacement Invoice', c.id USING ERRCODE='23514';
        END IF;
    ELSIF c.kind='CANCEL_AND_REFUND' THEN
        IF c.replacement_invoice_id IS NOT NULL OR c.corrected_amount <> 0
           OR original.replaced_by_invoice_id IS NOT NULL
           OR (SELECT operational_status FROM orders WHERE id=c.order_id) <> 'CANCELLED' THEN
            RAISE EXCEPTION 'Completed cancellation correction % lacks terminal zero obligation', c.id USING ERRCODE='23514';
        END IF;
    ELSE
        RAISE EXCEPTION 'Unsupported completed Invoice correction kind %', c.kind USING ERRCODE='23514';
    END IF;
END;
$$;
CREATE OR REPLACE FUNCTION completed_invoice_correction_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM validate_completed_invoice_correction(NEW.id);
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER completed_invoice_correction_contract
AFTER UPDATE ON invoice_financial_corrections DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION completed_invoice_correction_guard();
SQL);
            foreach (DB::table('invoice_financial_corrections')->where('state', 'COMPLETED')->pluck('id') as $id) {
                DB::select('SELECT validate_completed_invoice_correction(?)', [$id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Completed correction integrity is intentionally forward-only.');
    }
};
