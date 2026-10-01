<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Invoice correction snapshots require PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_financial_corrections, invoices, invoice_collections IN ACCESS EXCLUSIVE MODE');
            if (! Schema::hasTable('invoice_correction_financial_snapshots')) {
                Schema::create('invoice_correction_financial_snapshots', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('invoice_financial_correction_id');
                    $table->foreign('invoice_financial_correction_id', 'corr_snap_corr_fk')->references('id')->on('invoice_financial_corrections')->restrictOnDelete();
                    $table->unique('invoice_financial_correction_id', 'corr_snap_corr_uniq');
                    $table->foreignId('order_id')->constrained()->restrictOnDelete();
                    $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                    $table->foreignId('currency_id')->constrained()->restrictOnDelete();
                    $table->foreignId('original_invoice_id')->constrained('invoices')->restrictOnDelete();
                    $table->foreignId('replacement_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
                    $table->jsonb('payload');
                    $table->char('payload_hash', 64);
                    $table->timestampTz('captured_at', 6);
                });
            }

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_invoice_correction_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE i invoices%ROWTYPE; c invoice_collections%ROWTYPE; s order_financial_source_snapshots%ROWTYPE; places integer;
BEGIN
    SELECT * INTO i FROM invoices WHERE id=NEW.original_invoice_id;
    SELECT * INTO c FROM invoice_collections WHERE id=NEW.original_collection_id;
    SELECT * INTO s FROM order_financial_source_snapshots WHERE id=NEW.original_snapshot_id;
    SELECT decimal_places INTO places FROM currencies WHERE id=NEW.currency_id;
    IF i.id IS NULL OR c.id IS NULL OR c.invoice_id IS DISTINCT FROM i.id
       OR i.order_id IS DISTINCT FROM NEW.order_id OR c.order_id IS DISTINCT FROM NEW.order_id
       OR c.currency_id IS DISTINCT FROM NEW.currency_id OR i.currency_id IS DISTINCT FROM NEW.currency_id
       OR (SELECT branch_id FROM orders WHERE id=NEW.order_id) IS DISTINCT FROM NEW.branch_id
       OR c.amount IS DISTINCT FROM NEW.original_amount OR places IS DISTINCT FROM NEW.currency_decimal_places
       OR (NEW.original_snapshot_id IS NOT NULL AND (s.invoice_id IS DISTINCT FROM i.id OR s.order_id IS DISTINCT FROM NEW.order_id))
       OR NEW.original_amount <> round(NEW.original_amount,places)
       OR (NEW.corrected_amount IS NOT NULL AND NEW.corrected_amount <> round(NEW.corrected_amount,places)) THEN
        RAISE EXCEPTION 'Invoice correction source does not match its historical Invoice and Collection' USING ERRCODE='23514';
    END IF;
    IF TG_OP='INSERT' THEN
        IF NEW.state <> 'REQUESTED' OR NEW.corrected_amount IS NOT NULL OR NEW.prepared_by IS NOT NULL
           OR NEW.finalized_by IS NOT NULL OR NEW.replacement_invoice_id IS NOT NULL THEN
            RAISE EXCEPTION 'Correction must begin REQUESTED' USING ERRCODE='23514';
        END IF;
    ELSE
        IF OLD.state IN ('COMPLETED','ABANDONED')
           OR NEW.state NOT IN ('PREPARED','READY_TO_FINALIZE','COMPLETED','ABANDONED')
           OR (OLD.state='REQUESTED' AND NEW.state NOT IN ('PREPARED','ABANDONED'))
           OR (OLD.state='PREPARED' AND NEW.state NOT IN ('READY_TO_FINALIZE','ABANDONED'))
           OR (OLD.state='READY_TO_FINALIZE' AND NEW.state <> 'COMPLETED') THEN
            RAISE EXCEPTION 'Invalid Invoice correction state transition' USING ERRCODE='23514';
        END IF;
        IF (to_jsonb(NEW)-'state'-'corrected_amount'-'customer_delta'-'proposal_hash'-'prepared_by'-'prepared_at'-'finalized_by'-'finalized_at'-'replacement_invoice_id')
            IS DISTINCT FROM
           (to_jsonb(OLD)-'state'-'corrected_amount'-'customer_delta'-'proposal_hash'-'prepared_by'-'prepared_at'-'finalized_by'-'finalized_at'-'replacement_invoice_id')
           OR (OLD.state <> 'REQUESTED' AND (NEW.corrected_amount IS DISTINCT FROM OLD.corrected_amount
               OR NEW.customer_delta IS DISTINCT FROM OLD.customer_delta OR NEW.proposal_hash IS DISTINCT FROM OLD.proposal_hash
               OR NEW.prepared_by IS DISTINCT FROM OLD.prepared_by OR NEW.prepared_at IS DISTINCT FROM OLD.prepared_at)) THEN
            RAISE EXCEPTION 'Prepared Invoice correction facts are immutable' USING ERRCODE='55000';
        END IF;
        IF NEW.state IN ('PREPARED','READY_TO_FINALIZE','COMPLETED') AND
           (NEW.prepared_by IS NULL OR NEW.prepared_at IS NULL OR NEW.corrected_amount IS NULL
            OR NEW.customer_delta IS NULL OR NEW.proposal_hash !~ '^[0-9a-f]{64}$') THEN
            RAISE EXCEPTION 'Prepared correction lacks complete frozen facts' USING ERRCODE='23514';
        END IF;
        IF NEW.state='READY_TO_FINALIZE' AND
           ((NEW.customer_delta < 0 AND NOT EXISTS (SELECT 1 FROM customer_refunds WHERE invoice_financial_correction_id=NEW.id))
            OR (NEW.customer_delta=0 AND EXISTS (SELECT 1 FROM customer_refunds WHERE invoice_financial_correction_id=NEW.id))) THEN
            RAISE EXCEPTION 'Correction refund disposition is incomplete' USING ERRCODE='23514';
        END IF;
        IF NEW.state='ABANDONED' AND EXISTS (SELECT 1 FROM customer_refunds WHERE invoice_financial_correction_id=NEW.id) THEN
            RAISE EXCEPTION 'A correction with a real refund cannot be abandoned' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION protect_issued_invoice_facts() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='DELETE' THEN
        IF OLD.status IN ('ISSUED','REPLACED','VOIDED') OR EXISTS (SELECT 1 FROM invoice_collections WHERE invoice_id=OLD.id) THEN
            RAISE EXCEPTION 'Issued Invoice history is immutable' USING ERRCODE='55000';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.status IN ('ISSUED','REPLACED','VOIDED') OR EXISTS (SELECT 1 FROM invoice_collections WHERE invoice_id=OLD.id) THEN
        IF (to_jsonb(NEW)-'status'-'voided_at'-'voided_by'-'void_reason'-'replaced_at'-'replaced_by'-'replacement_reason'-'replaced_by_invoice_id'-'superseded_at'-'updated_at')
            IS DISTINCT FROM
           (to_jsonb(OLD)-'status'-'voided_at'-'voided_by'-'void_reason'-'replaced_at'-'replaced_by'-'replacement_reason'-'replaced_by_invoice_id'-'superseded_at'-'updated_at') THEN
            RAISE EXCEPTION 'Issued Invoice financial facts are immutable' USING ERRCODE='55000';
        END IF;
        IF OLD.status='VOIDED' OR NEW.status NOT IN ('ISSUED','REPLACED','VOIDED')
            OR (OLD.status='REPLACED' AND (NEW.status <> 'REPLACED' OR OLD.replaced_by_invoice_id IS NOT NULL
                OR NEW.replaced_by_invoice_id IS NULL OR NEW.replaced_at IS DISTINCT FROM OLD.replaced_at
                OR NEW.replaced_by IS DISTINCT FROM OLD.replaced_by OR NEW.replacement_reason IS DISTINCT FROM OLD.replacement_reason
                OR NEW.superseded_at IS DISTINCT FROM OLD.superseded_at))
            OR (NEW.status='ISSUED' AND (to_jsonb(NEW)-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'updated_at'))
            OR (NEW.status='REPLACED' AND (NEW.replaced_at IS NULL OR NEW.replaced_by IS NULL OR NEW.superseded_at IS NULL))
            OR (NEW.status='VOIDED' AND (NEW.voided_at IS NULL OR NEW.voided_by IS NULL OR NEW.superseded_at IS NULL)) THEN
            RAISE EXCEPTION 'Invalid issued Invoice transition' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION validate_invoice_correction_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c invoice_financial_corrections%ROWTYPE;
BEGIN
    SELECT * INTO c FROM invoice_financial_corrections WHERE id=NEW.invoice_financial_correction_id FOR UPDATE;
    IF c.id IS NULL OR c.state NOT IN ('READY_TO_FINALIZE','COMPLETED')
       OR NEW.order_id IS DISTINCT FROM c.order_id OR NEW.branch_id IS DISTINCT FROM c.branch_id
       OR NEW.currency_id IS DISTINCT FROM c.currency_id OR NEW.original_invoice_id IS DISTINCT FROM c.original_invoice_id
       OR NEW.replacement_invoice_id IS DISTINCT FROM c.replacement_invoice_id
       OR NEW.payload_hash IS DISTINCT FROM encode(sha256(convert_to(NEW.payload::text,'UTF8')),'hex')
       OR NEW.payload->>'proposal_hash' IS DISTINCT FROM c.proposal_hash
       OR NEW.payload->>'total' IS DISTINCT FROM c.corrected_amount::text THEN
        RAISE EXCEPTION 'Corrected financial snapshot differs from frozen correction' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS invoice_correction_snapshot_insert ON invoice_correction_financial_snapshots;
CREATE TRIGGER invoice_correction_snapshot_insert BEFORE INSERT ON invoice_correction_financial_snapshots
FOR EACH ROW EXECUTE FUNCTION validate_invoice_correction_snapshot();
DROP TRIGGER IF EXISTS invoice_correction_snapshot_immutable ON invoice_correction_financial_snapshots;
CREATE TRIGGER invoice_correction_snapshot_immutable BEFORE UPDATE OR DELETE ON invoice_correction_financial_snapshots
FOR EACH ROW EXECUTE FUNCTION reject_collected_money_mutation();

CREATE OR REPLACE FUNCTION protect_corrected_replacement_invoice() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM invoice_financial_corrections c
        WHERE c.replacement_invoice_id=OLD.id AND c.state='COMPLETED') THEN
        RAISE EXCEPTION 'Completed corrected replacement Invoice is immutable' USING ERRCODE='55000';
    END IF;
    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS corrected_replacement_invoice_immutable ON invoices;
CREATE TRIGGER corrected_replacement_invoice_immutable BEFORE UPDATE OR DELETE ON invoices
FOR EACH ROW EXECUTE FUNCTION protect_corrected_replacement_invoice();

CREATE OR REPLACE FUNCTION prevent_corrected_replacement_collection() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM invoice_financial_corrections c
        WHERE c.replacement_invoice_id=NEW.invoice_id AND c.state='COMPLETED') THEN
        RAISE EXCEPTION 'Corrected replacement Invoice is already satisfied by original Collection and refund' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS corrected_replacement_collection_guard ON invoice_collections;
CREATE TRIGGER corrected_replacement_collection_guard BEFORE INSERT ON invoice_collections
FOR EACH ROW EXECUTE FUNCTION prevent_corrected_replacement_collection();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Invoice correction snapshots are intentionally forward-only.');
    }
};
