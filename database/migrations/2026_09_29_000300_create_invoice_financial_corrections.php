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
            throw new RuntimeException('Invoice financial corrections require PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE orders, invoices, invoice_items, invoice_collections, order_financial_source_snapshots, financial_transactions, financial_entries, captain_receivable_payout_allocations IN ACCESS EXCLUSIVE MODE');
            $bad = DB::selectOne(<<<'SQL'
SELECT c.id, c.invoice_id FROM invoice_collections c
JOIN invoices i ON i.id=c.invoice_id
WHERE c.order_id IS DISTINCT FROM i.order_id
   OR c.currency_id IS DISTINCT FROM i.currency_id
   OR c.amount IS DISTINCT FROM i.grand_total
   OR i.status <> 'ISSUED'
   OR i.superseded_at IS NOT NULL
   OR c.collection_method NOT IN ('CASH','SHAM_CASH')
   OR (c.collection_method='CASH' AND (c.captain_id IS NULL OR c.order_captain_id IS NULL))
   OR (c.collection_method='SHAM_CASH' AND (c.captain_id IS NOT NULL OR c.order_captain_id IS NOT NULL))
LIMIT 1
SQL);
            if ($bad) {
                throw new RuntimeException("Collected Invoice history is inconsistent: collection_id={$bad->id}, invoice_id={$bad->invoice_id}");
            }

            Schema::create('invoice_financial_corrections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->constrained()->restrictOnDelete();
                $table->foreignId('original_invoice_id')->constrained('invoices')->restrictOnDelete();
                $table->foreignId('original_collection_id')->constrained('invoice_collections')->restrictOnDelete();
                $table->foreignId('original_snapshot_id')->nullable()->constrained('order_financial_source_snapshots')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('currency_id')->constrained()->restrictOnDelete();
                $table->unsignedSmallInteger('currency_decimal_places');
                $table->string('kind', 32);
                $table->string('state', 32);
                $table->string('request_key', 64)->unique();
                $table->string('reason', 191);
                $table->text('explanation');
                $table->jsonb('proposal_payload');
                $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('prepared_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz('initiated_at', 6);
                $table->timestampTz('prepared_at', 6)->nullable();
                $table->timestampTz('finalized_at', 6)->nullable();
                $table->foreignId('replacement_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
                $table->char('proposal_hash', 64)->nullable();
                $table->timestampTz('created_at', 6)->useCurrent();
            });
            DB::statement('ALTER TABLE invoice_financial_corrections ADD COLUMN original_amount numeric NOT NULL');
            DB::statement('ALTER TABLE invoice_financial_corrections ADD COLUMN corrected_amount numeric NULL');
            DB::statement('ALTER TABLE invoice_financial_corrections ADD COLUMN customer_delta numeric NULL');
            DB::statement("ALTER TABLE invoice_financial_corrections ADD CONSTRAINT invoice_correction_shape CHECK (kind IN ('REPRICE','CANCEL_AND_REFUND') AND state IN ('REQUESTED','PREPARED','READY_TO_FINALIZE','COMPLETED','ABANDONED') AND original_amount::text NOT IN ('NaN','Infinity','-Infinity') AND original_amount > 0 AND jsonb_typeof(proposal_payload) = 'object' AND (corrected_amount IS NULL OR (corrected_amount::text NOT IN ('NaN','Infinity','-Infinity') AND corrected_amount >= 0 AND corrected_amount <= original_amount)) AND (customer_delta IS NULL OR (customer_delta::text NOT IN ('NaN','Infinity','-Infinity') AND customer_delta <= 0)) AND (corrected_amount IS NULL OR customer_delta = corrected_amount - original_amount) AND (kind <> 'CANCEL_AND_REFUND' OR corrected_amount IS NULL OR corrected_amount = 0))");
            DB::statement("CREATE UNIQUE INDEX one_open_invoice_financial_correction ON invoice_financial_corrections(original_collection_id) WHERE state IN ('REQUESTED','PREPARED','READY_TO_FINALIZE')");

            Schema::create('customer_refunds', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('invoice_financial_correction_id');
                $table->foreign('invoice_financial_correction_id', 'refund_correction_fk')->references('id')->on('invoice_financial_corrections')->restrictOnDelete();
                $table->unique('invoice_financial_correction_id', 'refund_correction_unique');
                $table->foreignId('original_invoice_id')->constrained('invoices')->restrictOnDelete();
                $table->foreignId('original_collection_id')->constrained('invoice_collections')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('currency_id')->constrained()->restrictOnDelete();
                $table->string('method', 20);
                $table->foreignId('refunding_captain_id')->nullable()->constrained('captains')->restrictOnDelete();
                $table->foreignId('refunding_order_captain_id')->nullable()->constrained('order_captains')->restrictOnDelete();
                $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
                $table->string('request_key', 64)->unique();
                $table->timestampTz('refunded_at', 6);
                $table->string('reference', 191);
                $table->string('evidence_path', 191);
                $table->string('evidence_name');
                $table->string('evidence_mime', 64);
                $table->unsignedInteger('evidence_size');
                $table->char('evidence_sha256', 64);
                $table->char('payload_hash', 64);
                $table->timestampTz('created_at', 6)->useCurrent();
            });
            DB::statement('ALTER TABLE customer_refunds ADD COLUMN amount numeric NOT NULL');
            DB::statement("ALTER TABLE customer_refunds ADD CONSTRAINT customer_refund_amount_valid CHECK (amount::text NOT IN ('NaN','Infinity','-Infinity') AND amount > 0 AND method IN ('CASH','SHAM_CASH') AND evidence_size BETWEEN 1 AND 5242880 AND evidence_mime IN ('image/jpeg','image/png','image/webp') AND evidence_sha256 ~ '^[0-9a-f]{64}$' AND payload_hash ~ '^[0-9a-f]{64}$')");

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

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_collected_money_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Committed InvoiceCollection is immutable' USING ERRCODE='55000';
END;
$$;
CREATE TRIGGER invoice_collection_immutable BEFORE UPDATE OR DELETE ON invoice_collections
FOR EACH ROW EXECUTE FUNCTION reject_collected_money_mutation();

CREATE OR REPLACE FUNCTION validate_invoice_collection_history() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE i invoices%ROWTYPE; a order_captains%ROWTYPE;
BEGIN
    SELECT * INTO i FROM invoices WHERE id=NEW.invoice_id FOR UPDATE;
    IF i.id IS NULL OR i.order_id IS DISTINCT FROM NEW.order_id OR i.status <> 'ISSUED'
       OR i.superseded_at IS NOT NULL OR i.currency_id IS DISTINCT FROM NEW.currency_id
       OR i.grand_total IS DISTINCT FROM NEW.amount
       OR NEW.collection_method NOT IN ('CASH','SHAM_CASH') THEN
        RAISE EXCEPTION 'Collection must match the full current issued Invoice' USING ERRCODE='23514';
    END IF;
    IF NEW.collection_method='CASH' THEN
        SELECT * INTO a FROM order_captains WHERE id=NEW.order_captain_id;
        IF a.id IS NULL OR a.order_id IS DISTINCT FROM NEW.order_id
           OR a.captain_id IS DISTINCT FROM NEW.captain_id
           OR a.assignment_role <> 'PRIMARY_COLLECTION' THEN
            RAISE EXCEPTION 'CASH Collection requires the historical PRIMARY_COLLECTION assignment' USING ERRCODE='23514';
        END IF;
    ELSIF NEW.captain_id IS NOT NULL OR NEW.order_captain_id IS NOT NULL THEN
        RAISE EXCEPTION 'SHAM_CASH Collection has no Captain collector' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER invoice_collection_history_guard BEFORE INSERT ON invoice_collections
FOR EACH ROW EXECUTE FUNCTION validate_invoice_collection_history();

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
CREATE TRIGGER invoice_correction_source_guard BEFORE INSERT OR UPDATE ON invoice_financial_corrections
FOR EACH ROW EXECUTE FUNCTION validate_invoice_correction_source();
CREATE TRIGGER invoice_correction_delete_guard BEFORE DELETE ON invoice_financial_corrections
FOR EACH ROW EXECUTE FUNCTION reject_collected_money_mutation();

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
CREATE TRIGGER issued_invoice_facts_guard BEFORE UPDATE OR DELETE ON invoices
FOR EACH ROW EXECUTE FUNCTION protect_issued_invoice_facts();

CREATE OR REPLACE FUNCTION validate_replaced_invoice_at_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_invoice invoices%ROWTYPE; collected boolean;
BEGIN
    SELECT * INTO current_invoice FROM invoices WHERE id=NEW.id;
    IF current_invoice.status <> 'REPLACED' THEN RETURN NULL; END IF;
    SELECT EXISTS (SELECT 1 FROM invoice_collections WHERE invoice_id=current_invoice.id) INTO collected;
    IF collected THEN
        IF NOT EXISTS (SELECT 1 FROM invoice_financial_corrections c
            WHERE c.original_invoice_id=current_invoice.id AND c.state='COMPLETED'
              AND (c.kind='CANCEL_AND_REFUND' OR c.replacement_invoice_id=current_invoice.replaced_by_invoice_id)) THEN
            RAISE EXCEPTION 'Collected Invoice replacement requires completed financial correction' USING ERRCODE='23514';
        END IF;
    ELSIF current_invoice.replaced_by_invoice_id IS NULL THEN
        RAISE EXCEPTION 'Uncollected replaced Invoice requires replacement link' USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER replaced_invoice_complete AFTER UPDATE ON invoices
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_replaced_invoice_at_commit();

CREATE OR REPLACE FUNCTION protect_issued_invoice_items() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_status text;
BEGIN
    SELECT status INTO parent_status FROM invoices WHERE id=COALESCE(NEW.invoice_id,OLD.invoice_id);
    IF parent_status IN ('ISSUED','REPLACED','VOIDED') THEN
        RAISE EXCEPTION 'Issued Invoice items are immutable' USING ERRCODE='55000';
    END IF;
    RETURN COALESCE(NEW,OLD);
END;
$$;
CREATE TRIGGER issued_invoice_items_guard BEFORE INSERT OR UPDATE OR DELETE ON invoice_items
FOR EACH ROW EXECUTE FUNCTION protect_issued_invoice_items();

CREATE OR REPLACE FUNCTION validate_customer_refund_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c invoice_financial_corrections%ROWTYPE; original invoice_collections%ROWTYPE; places integer;
BEGIN
    SELECT * INTO c FROM invoice_financial_corrections WHERE id=NEW.invoice_financial_correction_id FOR UPDATE;
    SELECT * INTO original FROM invoice_collections WHERE id=c.original_collection_id;
    SELECT decimal_places INTO places FROM currencies WHERE id=c.currency_id;
    IF c.id IS NULL OR c.state NOT IN ('PREPARED','READY_TO_FINALIZE')
       OR c.corrected_amount IS NULL OR c.customer_delta >= 0
       OR NEW.original_invoice_id IS DISTINCT FROM c.original_invoice_id
       OR NEW.original_collection_id IS DISTINCT FROM c.original_collection_id
       OR NEW.branch_id IS DISTINCT FROM c.branch_id OR NEW.currency_id IS DISTINCT FROM c.currency_id
       OR NEW.method IS DISTINCT FROM original.collection_method
       OR NEW.amount IS DISTINCT FROM -c.customer_delta OR NEW.amount <> round(NEW.amount,places)
       OR (NEW.method='CASH' AND (NEW.refunding_captain_id IS DISTINCT FROM original.captain_id
           OR NEW.refunding_order_captain_id IS DISTINCT FROM original.order_captain_id))
       OR (NEW.method='SHAM_CASH' AND (NEW.refunding_captain_id IS NOT NULL OR NEW.refunding_order_captain_id IS NOT NULL)) THEN
        RAISE EXCEPTION 'CustomerRefund differs from original Collection or prepared correction' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER customer_refund_source_guard BEFORE INSERT ON customer_refunds
FOR EACH ROW EXECUTE FUNCTION validate_customer_refund_source();
CREATE TRIGGER customer_refund_immutable BEFORE UPDATE OR DELETE ON customer_refunds
FOR EACH ROW EXECUTE FUNCTION reject_collected_money_mutation();

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
        RAISE EXCEPTION 'Corrected financial snapshot differs from its frozen source' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER invoice_correction_snapshot_insert BEFORE INSERT ON invoice_correction_financial_snapshots
FOR EACH ROW EXECUTE FUNCTION validate_invoice_correction_snapshot();
CREATE TRIGGER invoice_correction_snapshot_immutable BEFORE UPDATE OR DELETE ON invoice_correction_financial_snapshots
FOR EACH ROW EXECUTE FUNCTION reject_collected_money_mutation();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Invoice financial correction history is intentionally forward-only.');
    }
};
