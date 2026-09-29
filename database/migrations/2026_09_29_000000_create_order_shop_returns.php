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
            throw new RuntimeException('Order return integrity requires PostgreSQL.');
        }

        Schema::create('order_shop_purchase_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_shop_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('purchased_at');
            $table->uuid('request_key')->unique();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->string('evidence_path', 500)->unique();
            $table->string('evidence_name');
            $table->string('evidence_mime', 64);
            $table->unsignedBigInteger('evidence_size');
            $table->string('evidence_sha256', 64);
            $table->timestamps();
            $table->index(['order_id', 'id']);
        });
        Schema::create('order_cancellation_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('classification', 32);
            $table->string('reason', 40);
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('intent_at');
            $table->timestampTz('frozen_at')->nullable();
            $table->uuid('request_key')->unique();
            $table->timestamps();
        });
        Schema::create('order_cancellation_intent_shops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_cancellation_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_confirmation_id')->unique()->constrained('order_shop_purchase_confirmations')->restrictOnDelete();
            $table->unique(['order_cancellation_intent_id', 'order_shop_id'], 'cancel_intent_shop_unique');
        });
        Schema::create('order_shop_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_cancellation_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_shop_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_confirmation_id')->unique()->constrained('order_shop_purchase_confirmations')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchasing_order_captain_id')->constrained('order_captains')->restrictOnDelete();
            $table->foreignId('returning_order_captain_id')->constrained('order_captains')->restrictOnDelete();
            $table->foreignId('refund_recipient_order_captain_id')->constrained('order_captains')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('returned_at');
            $table->timestampTz('refunded_at');
            $table->string('refund_method', 40);
            $table->string('refund_reference')->nullable();
            $table->text('shortfall_reason')->nullable();
            $table->text('note')->nullable();
            $table->uuid('request_key')->unique();
            $table->timestampTz('sealed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('order_shop_return_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_shop_return_id')->constrained()->restrictOnDelete();
            $table->string('evidence_type', 32);
            $table->string('storage_path', 500)->unique();
            $table->string('original_filename');
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256', 64);
            $table->timestampTz('uploaded_at');
            $table->timestamps();
            $table->unique(['order_shop_return_id', 'evidence_type'], 'return_evidence_type_unique');
        });

        DB::statement('ALTER TABLE order_shop_purchase_confirmations ADD COLUMN amount numeric NOT NULL');
        DB::statement('ALTER TABLE order_shop_returns ADD COLUMN purchase_amount numeric NOT NULL, ADD COLUMN refund_amount numeric NOT NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION order_return_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Completed order purchase, intent set, return or evidence is immutable' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER purchase_immutable BEFORE UPDATE OR DELETE ON order_shop_purchase_confirmations FOR EACH ROW EXECUTE FUNCTION order_return_immutable();
CREATE OR REPLACE FUNCTION protect_order_purchase_currency_precision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.decimal_places IS DISTINCT FROM OLD.decimal_places
       AND EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE currency_id=OLD.id) THEN
        RAISE EXCEPTION 'Currency decimal precision cannot change after verified Shop purchase use' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER currency_purchase_precision_guard BEFORE UPDATE OF decimal_places ON currencies
FOR EACH ROW EXECUTE FUNCTION protect_order_purchase_currency_precision();
CREATE TRIGGER intent_shop_immutable BEFORE UPDATE OR DELETE ON order_cancellation_intent_shops FOR EACH ROW EXECUTE FUNCTION order_return_immutable();
CREATE TRIGGER return_evidence_immutable BEFORE UPDATE OR DELETE ON order_shop_return_evidence FOR EACH ROW EXECUTE FUNCTION order_return_immutable();
CREATE OR REPLACE FUNCTION order_return_seal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.sealed_at IS NOT NULL OR NEW.sealed_at IS NULL
       OR NEW.order_id IS DISTINCT FROM OLD.order_id
       OR NEW.order_cancellation_intent_id IS DISTINCT FROM OLD.order_cancellation_intent_id
       OR NEW.order_shop_id IS DISTINCT FROM OLD.order_shop_id
       OR NEW.purchase_confirmation_id IS DISTINCT FROM OLD.purchase_confirmation_id
       OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
       OR NEW.currency_id IS DISTINCT FROM OLD.currency_id
       OR NEW.purchasing_order_captain_id IS DISTINCT FROM OLD.purchasing_order_captain_id
       OR NEW.returning_order_captain_id IS DISTINCT FROM OLD.returning_order_captain_id
       OR NEW.refund_recipient_order_captain_id IS DISTINCT FROM OLD.refund_recipient_order_captain_id
       OR NEW.recorded_by IS DISTINCT FROM OLD.recorded_by
       OR NEW.purchase_amount IS DISTINCT FROM OLD.purchase_amount
       OR NEW.refund_amount IS DISTINCT FROM OLD.refund_amount
       OR NEW.returned_at IS DISTINCT FROM OLD.returned_at
       OR NEW.refunded_at IS DISTINCT FROM OLD.refunded_at
       OR NEW.refund_method IS DISTINCT FROM OLD.refund_method
       OR NEW.refund_reference IS DISTINCT FROM OLD.refund_reference
       OR NEW.shortfall_reason IS DISTINCT FROM OLD.shortfall_reason
       OR NEW.note IS DISTINCT FROM OLD.note OR NEW.request_key IS DISTINCT FROM OLD.request_key THEN
        RAISE EXCEPTION 'Completed OrderShop return is immutable' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER return_immutable BEFORE UPDATE ON order_shop_returns FOR EACH ROW EXECUTE FUNCTION order_return_seal();
CREATE TRIGGER return_no_delete BEFORE DELETE ON order_shop_returns FOR EACH ROW EXECUTE FUNCTION order_return_immutable();

CREATE OR REPLACE FUNCTION order_return_lock_parent() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'order_shop_return_evidence' THEN
        SELECT order_id INTO parent_id FROM order_shop_returns WHERE id = NEW.order_shop_return_id;
    ELSIF TG_TABLE_NAME = 'order_cancellation_intent_shops' THEN
        SELECT order_id INTO parent_id FROM order_cancellation_intents WHERE id = NEW.order_cancellation_intent_id;
    ELSE
        parent_id := NEW.order_id;
    END IF;
    PERFORM id FROM orders WHERE id = parent_id FOR UPDATE;
    RETURN NEW;
END;
$$;
CREATE TRIGGER purchase_parent_lock BEFORE INSERT ON order_shop_purchase_confirmations FOR EACH ROW EXECUTE FUNCTION order_return_lock_parent();
CREATE TRIGGER intent_parent_lock BEFORE INSERT ON order_cancellation_intents FOR EACH ROW EXECUTE FUNCTION order_return_lock_parent();
CREATE TRIGGER intent_shop_parent_lock BEFORE INSERT ON order_cancellation_intent_shops FOR EACH ROW EXECUTE FUNCTION order_return_lock_parent();
CREATE TRIGGER return_parent_lock BEFORE INSERT ON order_shop_returns FOR EACH ROW EXECUTE FUNCTION order_return_lock_parent();
CREATE TRIGGER return_evidence_parent_lock BEFORE INSERT ON order_shop_return_evidence FOR EACH ROW EXECUTE FUNCTION order_return_lock_parent();

CREATE OR REPLACE FUNCTION order_purchase_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE shop_order bigint; order_branch bigint; assignment_order bigint; places integer; order_state text;
BEGIN
    SELECT order_id INTO shop_order FROM order_shops WHERE id = NEW.order_shop_id;
    SELECT branch_id, operational_status INTO order_branch, order_state FROM orders WHERE id = NEW.order_id;
    SELECT order_id INTO assignment_order FROM order_captains WHERE id = NEW.order_captain_id;
    SELECT decimal_places INTO places FROM currencies WHERE id = NEW.currency_id FOR UPDATE;
    IF shop_order IS DISTINCT FROM NEW.order_id OR order_branch IS DISTINCT FROM NEW.branch_id
       OR assignment_order IS DISTINCT FROM NEW.order_id OR order_state IN ('DELIVERED', 'CANCELLED')
       OR EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id = NEW.order_id)
       OR places IS NULL OR NEW.amount::text IN ('NaN', 'Infinity', '-Infinity')
       OR NEW.amount <= 0 OR NEW.amount <> round(NEW.amount, places)
       OR NEW.purchased_at > now() OR NEW.purchased_at IS NULL
       OR btrim(NEW.evidence_path) = '' OR btrim(NEW.evidence_name) = ''
       OR NEW.evidence_mime NOT IN ('image/jpeg', 'image/png', 'image/webp')
       OR NEW.evidence_size NOT BETWEEN 1 AND 5242880
       OR NEW.evidence_sha256 !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'Invalid OrderShop purchase confirmation source_id=%', NEW.id USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER purchase_validate BEFORE INSERT ON order_shop_purchase_confirmations FOR EACH ROW EXECUTE FUNCTION order_purchase_validate();

CREATE OR REPLACE FUNCTION order_intent_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE order_state text;
BEGIN
    SELECT operational_status INTO order_state FROM orders WHERE id = NEW.order_id;
    IF NEW.classification <> 'AFTER_PURCHASE' OR NEW.reason NOT IN ('CUSTOMER_CANCELLED', 'CUSTOMER_UNREACHABLE', 'CUSTOMER_REFUSED', 'SHOP_UNAVAILABLE', 'OPERATIONAL', 'OTHER')
       OR order_state IN ('DELIVERED', 'CANCELLED')
       OR NOT EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id = NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_shops s WHERE s.order_id=NEW.order_id
           AND (s.picked_up_at IS NOT NULL OR EXISTS (SELECT 1 FROM order_shop_receipts r WHERE r.order_shop_id=s.id))
           AND NOT EXISTS (SELECT 1 FROM order_shop_purchase_confirmations p WHERE p.order_shop_id=s.id)) THEN
        RAISE EXCEPTION 'Invalid AFTER_PURCHASE cancellation intent for order_id=%', NEW.order_id USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER intent_validate BEFORE INSERT ON order_cancellation_intents FOR EACH ROW EXECUTE FUNCTION order_intent_validate();
CREATE OR REPLACE FUNCTION order_intent_financial_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM invoices WHERE order_id=NEW.order_id AND status='ISSUED' AND superseded_at IS NULL)
       OR EXISTS (SELECT 1 FROM invoice_collections WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoices i
           ON t.source_type='CUSTOMER_INVOICE' AND t.source_id=i.id::text
           WHERE i.order_id=NEW.order_id AND t.transaction_kind='EVENT')
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoice_collections c
           ON t.source_type='INVOICE_COLLECTION' AND t.source_id=c.id::text
           WHERE c.order_id=NEW.order_id AND t.transaction_kind='EVENT') THEN
        RAISE EXCEPTION 'FINANCIAL_CORRECTION_REQUIRED for cancellation intent order_id=%', NEW.order_id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER intent_financial_boundary BEFORE INSERT ON order_cancellation_intents FOR EACH ROW EXECUTE FUNCTION order_intent_financial_boundary();
CREATE OR REPLACE FUNCTION order_intent_freeze() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.frozen_at IS NOT NULL OR NEW.frozen_at IS NULL OR NEW.order_id IS DISTINCT FROM OLD.order_id
       OR NEW.classification IS DISTINCT FROM OLD.classification OR NEW.reason IS DISTINCT FROM OLD.reason
       OR NEW.notes IS DISTINCT FROM OLD.notes OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
       OR NEW.intent_at IS DISTINCT FROM OLD.intent_at OR NEW.request_key IS DISTINCT FROM OLD.request_key THEN
        RAISE EXCEPTION 'Cancellation intent facts are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER intent_immutable BEFORE UPDATE ON order_cancellation_intents FOR EACH ROW EXECUTE FUNCTION order_intent_freeze();
CREATE TRIGGER intent_no_delete BEFORE DELETE ON order_cancellation_intents FOR EACH ROW EXECUTE FUNCTION order_return_immutable();

CREATE OR REPLACE FUNCTION order_intent_set_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE intent_row order_cancellation_intents%ROWTYPE;
BEGIN
    SELECT * INTO intent_row FROM order_cancellation_intents WHERE id = NEW.id;
    IF intent_row.id IS NULL OR intent_row.frozen_at IS NULL OR NOT EXISTS
        (SELECT 1 FROM order_cancellation_intent_shops WHERE order_cancellation_intent_id = intent_row.id)
       OR EXISTS (
          (SELECT p.id FROM order_shop_purchase_confirmations p WHERE p.order_id = intent_row.order_id
           EXCEPT SELECT s.purchase_confirmation_id FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id = intent_row.id)
          UNION ALL
          (SELECT s.purchase_confirmation_id FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id = intent_row.id
           EXCEPT SELECT p.id FROM order_shop_purchase_confirmations p WHERE p.order_id = intent_row.order_id)
       ) OR EXISTS (SELECT 1 FROM order_cancellation_intent_shops s JOIN order_shop_purchase_confirmations p ON p.id=s.purchase_confirmation_id
          WHERE s.order_cancellation_intent_id=intent_row.id AND s.order_shop_id<>p.order_shop_id) THEN
        RAISE EXCEPTION 'Cancellation intent required Shop set is incomplete or inconsistent, intent_id=%', intent_row.id USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE CONSTRAINT TRIGGER intent_set_complete AFTER INSERT OR UPDATE ON order_cancellation_intents
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION order_intent_set_validate();
CREATE OR REPLACE FUNCTION order_intent_shop_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE intent_order bigint; frozen timestamptz; confirmation_order bigint;
BEGIN
    SELECT order_id, frozen_at INTO intent_order, frozen FROM order_cancellation_intents WHERE id=NEW.order_cancellation_intent_id;
    SELECT order_id INTO confirmation_order FROM order_shop_purchase_confirmations WHERE id=NEW.purchase_confirmation_id;
    IF frozen IS NOT NULL OR intent_order IS DISTINCT FROM confirmation_order THEN
        RAISE EXCEPTION 'Frozen cancellation intent Shop set cannot change' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER intent_shop_insert_validate BEFORE INSERT ON order_cancellation_intent_shops FOR EACH ROW EXECUTE FUNCTION order_intent_shop_insert();

CREATE OR REPLACE FUNCTION order_return_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p order_shop_purchase_confirmations%ROWTYPE; i order_cancellation_intents%ROWTYPE;
    returning_order bigint; places integer;
BEGIN
    SELECT * INTO p FROM order_shop_purchase_confirmations WHERE id=NEW.purchase_confirmation_id;
    SELECT * INTO i FROM order_cancellation_intents WHERE id=NEW.order_cancellation_intent_id;
    SELECT order_id INTO returning_order FROM order_captains WHERE id=NEW.returning_order_captain_id;
    SELECT decimal_places INTO places FROM currencies WHERE id=NEW.currency_id FOR UPDATE;
    IF p.id IS NULL OR i.id IS NULL OR i.frozen_at IS NULL
       OR p.order_id IS DISTINCT FROM NEW.order_id OR i.order_id IS DISTINCT FROM NEW.order_id
       OR p.order_shop_id IS DISTINCT FROM NEW.order_shop_id OR p.branch_id IS DISTINCT FROM NEW.branch_id
       OR p.currency_id IS DISTINCT FROM NEW.currency_id
       OR p.order_captain_id IS DISTINCT FROM NEW.purchasing_order_captain_id
       OR p.order_captain_id IS DISTINCT FROM NEW.refund_recipient_order_captain_id
       OR returning_order IS DISTINCT FROM NEW.order_id
       OR NOT EXISTS (SELECT 1 FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id=i.id AND s.purchase_confirmation_id=p.id AND s.order_shop_id=p.order_shop_id)
       OR NEW.purchase_amount IS DISTINCT FROM p.amount OR places IS NULL
       OR NEW.purchase_amount::text IN ('NaN', 'Infinity', '-Infinity')
       OR NEW.refund_amount::text IN ('NaN', 'Infinity', '-Infinity')
       OR NEW.refund_amount <= 0 OR NEW.refund_amount > p.amount
       OR NEW.refund_amount <> round(NEW.refund_amount, places)
       OR (NEW.refund_amount < p.amount AND (NEW.shortfall_reason IS NULL OR btrim(NEW.shortfall_reason)=''))
       OR NEW.returned_at < p.purchased_at OR NEW.returned_at > now()
       OR NEW.refunded_at < NEW.returned_at OR NEW.refunded_at > now()
       OR btrim(NEW.refund_method) = '' THEN
        RAISE EXCEPTION 'Invalid completed OrderShop return source_id=%', NEW.id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER return_validate BEFORE INSERT ON order_shop_returns FOR EACH ROW EXECUTE FUNCTION order_return_validate();
CREATE OR REPLACE FUNCTION order_return_evidence_validate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.evidence_type NOT IN ('RETURN_PROOF','REFUND_PROOF','RETURN_AND_REFUND_PROOF')
       OR EXISTS (SELECT 1 FROM order_shop_returns WHERE id=NEW.order_shop_return_id AND sealed_at IS NOT NULL)
       OR btrim(NEW.storage_path)='' OR btrim(NEW.original_filename)=''
       OR NEW.mime_type NOT IN ('image/jpeg','image/png','image/webp')
       OR NEW.file_size NOT BETWEEN 1 AND 5242880 OR NEW.sha256 !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'Invalid OrderShop return evidence source_id=%', NEW.id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER return_evidence_validate BEFORE INSERT ON order_shop_return_evidence FOR EACH ROW EXECUTE FUNCTION order_return_evidence_validate();
CREATE OR REPLACE FUNCTION order_return_evidence_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE return_id bigint;
BEGIN
    return_id := NEW.id;
    IF NOT EXISTS (SELECT 1 FROM order_shop_returns WHERE id=return_id AND sealed_at IS NOT NULL)
       OR NOT EXISTS (SELECT 1 FROM order_shop_return_evidence WHERE order_shop_return_id=return_id AND evidence_type IN ('RETURN_PROOF','RETURN_AND_REFUND_PROOF'))
       OR NOT EXISTS (SELECT 1 FROM order_shop_return_evidence WHERE order_shop_return_id=return_id AND evidence_type IN ('REFUND_PROOF','RETURN_AND_REFUND_PROOF')) THEN
        RAISE EXCEPTION 'Completed OrderShop return requires return and refund proof, return_id=%', return_id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE CONSTRAINT TRIGGER return_evidence_complete AFTER INSERT ON order_shop_returns DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION order_return_evidence_complete();

CREATE OR REPLACE FUNCTION order_intent_blocks_advancement() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.id) THEN
        IF NEW.branch_id IS DISTINCT FROM OLD.branch_id
           OR (NEW.operational_status IS DISTINCT FROM OLD.operational_status AND NEW.operational_status <> 'CANCELLED') THEN
            RAISE EXCEPTION 'Cancellation intent freezes Order advancement and Branch' USING ERRCODE='23514';
        END IF;
    ELSIF NEW.branch_id IS DISTINCT FROM OLD.branch_id
       AND EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=NEW.id) THEN
        RAISE EXCEPTION 'Purchase confirmation freezes Order Branch' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER order_intent_blocks_advancement BEFORE UPDATE OF operational_status, branch_id ON orders
FOR EACH ROW EXECUTE FUNCTION order_intent_blocks_advancement();

CREATE OR REPLACE FUNCTION order_intent_blocks_invoice_issue() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status='ISSUED' AND EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.order_id) THEN
        RAISE EXCEPTION 'Invoice issue blocked by cancellation intent' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER order_intent_blocks_invoice_issue BEFORE INSERT OR UPDATE OF status ON invoices
FOR EACH ROW EXECUTE FUNCTION order_intent_blocks_invoice_issue();

CREATE OR REPLACE FUNCTION order_intent_blocks_collection() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.order_id) THEN
        RAISE EXCEPTION 'Collection blocked by cancellation intent' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER order_intent_blocks_collection BEFORE INSERT ON invoice_collections
FOR EACH ROW EXECUTE FUNCTION order_intent_blocks_collection();

CREATE OR REPLACE FUNCTION order_intent_blocks_bill_payment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.order_id) THEN
        RAISE EXCEPTION 'Bill payment blocked by cancellation intent' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER order_intent_blocks_bill_payment BEFORE INSERT ON order_bill_payments
FOR EACH ROW EXECUTE FUNCTION order_intent_blocks_bill_payment();

CREATE OR REPLACE FUNCTION order_cancel_final_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c order_cancellations%ROWTYPE; i order_cancellation_intents%ROWTYPE;
BEGIN
    SELECT * INTO c FROM order_cancellations WHERE order_id=NEW.id;
    IF c.id IS NULL THEN RAISE EXCEPTION 'Final CANCELLED Order requires cancellation source' USING ERRCODE='23514'; END IF;
    IF EXISTS (SELECT 1 FROM invoices inv WHERE inv.order_id=NEW.id AND inv.status='ISSUED' AND inv.superseded_at IS NULL)
       OR EXISTS (SELECT 1 FROM invoice_collections WHERE order_id=NEW.id)
       OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id=NEW.id)
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoices inv
           ON t.source_type='CUSTOMER_INVOICE' AND t.source_id=inv.id::text
           WHERE inv.order_id=NEW.id AND t.transaction_kind='EVENT')
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoice_collections col
           ON t.source_type='INVOICE_COLLECTION' AND t.source_id=col.id::text
           WHERE col.order_id=NEW.id AND t.transaction_kind='EVENT') THEN
        RAISE EXCEPTION 'FINANCIAL_CORRECTION_REQUIRED for final cancellation order_id=%', NEW.id USING ERRCODE='23514';
    END IF;
    IF c.classification='BEFORE_PURCHASE' THEN
        IF EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=NEW.id)
           OR EXISTS (SELECT 1 FROM order_shops s WHERE s.order_id=NEW.id AND (s.picked_up_at IS NOT NULL OR EXISTS(SELECT 1 FROM order_shop_receipts r WHERE r.order_shop_id=s.id))) THEN
            RAISE EXCEPTION 'BEFORE_PURCHASE cancellation conflicts with purchase evidence' USING ERRCODE='23514';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO i FROM order_cancellation_intents WHERE order_id=NEW.id;
    IF i.id IS NULL OR i.frozen_at IS NULL OR c.classification<>'AFTER_PURCHASE'
       OR c.reason<>i.reason OR EXISTS (
          SELECT 1 FROM order_cancellation_intent_shops s WHERE s.order_cancellation_intent_id=i.id
          AND NOT EXISTS (SELECT 1 FROM order_shop_returns r WHERE r.order_cancellation_intent_id=i.id
              AND r.purchase_confirmation_id=s.purchase_confirmation_id AND r.order_shop_id=s.order_shop_id)
       ) OR EXISTS (SELECT 1 FROM order_bill_payment_requests WHERE order_id=NEW.id AND status='REQUESTED')
       OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id=NEW.id)
       OR EXISTS (SELECT 1 FROM invoices inv WHERE inv.order_id=NEW.id AND inv.status='ISSUED' AND inv.superseded_at IS NULL)
       OR EXISTS (SELECT 1 FROM invoice_collections WHERE order_id=NEW.id)
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoices inv
           ON t.source_type='CUSTOMER_INVOICE' AND t.source_id=inv.id::text
           WHERE inv.order_id=NEW.id AND t.transaction_kind='EVENT')
       OR EXISTS (SELECT 1 FROM financial_transactions t JOIN invoice_collections col
           ON t.source_type='INVOICE_COLLECTION' AND t.source_id=col.id::text
           WHERE col.order_id=NEW.id AND t.transaction_kind='EVENT') THEN
        RAISE EXCEPTION 'AFTER_PURCHASE final cancellation lacks returns or requires financial correction, order_id=%', NEW.id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE CONSTRAINT TRIGGER order_cancel_final_complete AFTER UPDATE OF operational_status ON orders
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.operational_status='CANCELLED') EXECUTE FUNCTION order_cancel_final_validate();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Order purchase and return history is intentionally forward-only.');
    }
};
