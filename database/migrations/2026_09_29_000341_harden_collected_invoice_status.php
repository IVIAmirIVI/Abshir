<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Collected Invoice status protection requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoices, invoice_collections, invoice_financial_corrections IN SHARE ROW EXCLUSIVE MODE');
            $bad = DB::selectOne(<<<'SQL'
SELECT col.id AS collection_id, i.id AS invoice_id, i.status
FROM invoice_collections col JOIN invoices i ON i.id=col.invoice_id
WHERE col.order_id IS DISTINCT FROM i.order_id OR col.currency_id IS DISTINCT FROM i.currency_id
   OR col.amount IS DISTINCT FROM i.grand_total
   OR NOT ((i.status='ISSUED' AND i.superseded_at IS NULL)
       OR (i.status='REPLACED' AND i.superseded_at IS NOT NULL AND EXISTS (
           SELECT 1 FROM invoice_financial_corrections c WHERE c.original_invoice_id=i.id
             AND c.original_collection_id=col.id AND c.state='COMPLETED'
             AND ((c.kind='REPRICE' AND c.replacement_invoice_id=i.replaced_by_invoice_id)
               OR (c.kind='CANCEL_AND_REFUND' AND c.replacement_invoice_id IS NULL
                   AND i.replaced_by_invoice_id IS NULL)))))
LIMIT 1
SQL);
            if ($bad) {
                throw new RuntimeException("Collected Invoice status/history is invalid: invoice_id={$bad->invoice_id}, collection_id={$bad->collection_id}, status={$bad->status}");
            }
            $bad = DB::selectOne(<<<'SQL'
SELECT c.id AS correction_id, c.original_invoice_id AS invoice_id FROM invoice_financial_corrections c
JOIN invoices i ON i.id=c.original_invoice_id
WHERE c.state IN ('REQUESTED','PREPARED','READY_TO_FINALIZE')
  AND (i.status<>'ISSUED' OR i.superseded_at IS NOT NULL)
LIMIT 1
SQL);
            if ($bad) {
                throw new RuntimeException("Active correction has invalid original Invoice status: correction_id={$bad->correction_id}, invoice_id={$bad->invoice_id}");
            }
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_collected_invoice_status() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM invoice_collections WHERE invoice_id=OLD.id)
       AND NEW.status NOT IN ('ISSUED','REPLACED') THEN
        RAISE EXCEPTION 'Collected Invoice % cannot transition to %', OLD.id, NEW.status USING ERRCODE='23514';
    END IF;
    -- REPLACED remains subject to the existing deferred completed-correction contract.
    RETURN NEW;
END;
$$;
CREATE TRIGGER collected_invoice_status_guard BEFORE UPDATE OF status ON invoices
FOR EACH ROW EXECUTE FUNCTION protect_collected_invoice_status();

CREATE OR REPLACE FUNCTION validate_new_invoice_correction_status() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE i invoices%ROWTYPE;
BEGIN
    PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
    SELECT * INTO i FROM invoices WHERE id=NEW.original_invoice_id FOR UPDATE;
    IF i.id IS NULL OR i.status<>'ISSUED' OR i.superseded_at IS NOT NULL
       OR i.order_id IS DISTINCT FROM NEW.order_id
       OR NOT EXISTS (SELECT 1 FROM invoice_collections col WHERE col.id=NEW.original_collection_id
           AND col.invoice_id=i.id AND col.order_id=NEW.order_id AND col.currency_id=i.currency_id
           AND col.amount=i.grand_total) THEN
        RAISE EXCEPTION 'New correction requires the authoritative collected ISSUED Invoice: invoice_id=%, collection_id=%',
            NEW.original_invoice_id, NEW.original_collection_id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER invoice_correction_creation_status_guard BEFORE INSERT ON invoice_financial_corrections
FOR EACH ROW EXECUTE FUNCTION validate_new_invoice_correction_status();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Collected Invoice history protection is intentionally forward-only.');
    }
};
