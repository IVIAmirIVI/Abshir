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
            throw new RuntimeException('Audited participant selection requires PostgreSQL.');
        }
        Schema::create('order_captain_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('collecting_order_captain_id')->constrained('order_captains')->restrictOnDelete();
            $table->foreignId('selected_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->jsonb('assignment_ids');
            $table->string('contract', 32);
            $table->text('reason')->nullable();
            $table->timestampTz('selected_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION assert_order_captain_selection(p_order bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE s order_captain_selections%ROWTYPE; actual_ids jsonb; primary_id bigint;
BEGIN
    PERFORM id FROM orders WHERE id=p_order FOR UPDATE;
    SELECT * INTO s FROM order_captain_selections WHERE order_id=p_order ORDER BY id DESC LIMIT 1;
    IF NOT FOUND THEN RETURN; END IF;
    SELECT jsonb_agg(id ORDER BY id), min(id) FILTER (WHERE assignment_role='PRIMARY_COLLECTION')
        INTO actual_ids, primary_id FROM order_captains WHERE order_id=p_order AND removed_at IS NULL;
    IF jsonb_typeof(s.assignment_ids) IS DISTINCT FROM 'array'
       OR jsonb_array_length(s.assignment_ids) NOT BETWEEN 1 AND 2
       OR actual_ids IS DISTINCT FROM s.assignment_ids
       OR s.branch_id IS DISTINCT FROM (SELECT branch_id FROM orders WHERE id=p_order)
       OR EXISTS (SELECT 1 FROM order_captains WHERE order_id=p_order AND removed_at IS NULL AND assigned_at>s.selected_at)
       OR primary_id IS DISTINCT FROM s.collecting_order_captain_id
       OR (SELECT count(*) FROM order_captains WHERE order_id=p_order AND removed_at IS NULL AND assignment_role='PRIMARY_COLLECTION')<>1
       OR s.contract IS DISTINCT FROM (CASE jsonb_array_length(s.assignment_ids) WHEN 1 THEN 'SINGLE_V1' ELSE 'SHARED_COMPANY_V1' END) THEN
        RAISE EXCEPTION 'Participant selection must match one/two active Captains and its explicit collector' USING ERRCODE='23514';
    END IF;
END;
$$;
CREATE FUNCTION order_captain_selection_validate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
    IF NEW.branch_id IS DISTINCT FROM (SELECT branch_id FROM orders WHERE id=NEW.order_id)
       OR NEW.selected_at > clock_timestamp() OR NEW.selected_at < (SELECT ordered_at FROM orders WHERE id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM invoices WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_bill_payment_requests WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM orders WHERE id=NEW.order_id AND operational_status IN ('DELIVERED','CANCELLED'))
       OR (EXISTS (SELECT 1 FROM order_captain_selections WHERE order_id=NEW.order_id) AND btrim(coalesce(NEW.reason,''))='') THEN
        RAISE EXCEPTION 'Captain selection is bound by existing source history or lacks a change reason' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER captain_selection_validate BEFORE INSERT ON order_captain_selections
FOR EACH ROW EXECUTE FUNCTION order_captain_selection_validate();
CREATE TRIGGER captain_selection_immutable BEFORE UPDATE OR DELETE ON order_captain_selections
FOR EACH ROW EXECUTE FUNCTION order_return_immutable();
CREATE FUNCTION order_captain_selection_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME='orders' THEN
        PERFORM assert_order_captain_selection(NEW.id);
        RETURN NEW;
    END IF;
    IF TG_OP='DELETE' THEN
        PERFORM assert_order_captain_selection(OLD.order_id);
        RETURN OLD;
    END IF;
    PERFORM assert_order_captain_selection(NEW.order_id);
    IF TG_OP='UPDATE' AND OLD.order_id IS DISTINCT FROM NEW.order_id THEN
        PERFORM assert_order_captain_selection(OLD.order_id);
    END IF;
    RETURN NEW;
END;
$$;
CREATE CONSTRAINT TRIGGER captain_selection_complete AFTER INSERT ON order_captain_selections
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION order_captain_selection_complete();
CREATE CONSTRAINT TRIGGER captain_selection_assignments_complete AFTER INSERT OR UPDATE OR DELETE ON order_captains
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION order_captain_selection_complete();
CREATE CONSTRAINT TRIGGER captain_selection_branch_complete AFTER UPDATE OF branch_id ON orders
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION order_captain_selection_complete();
CREATE FUNCTION order_captain_selection_lock() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous_order bigint;
BEGIN
    IF TG_OP IN ('UPDATE','DELETE') THEN previous_order:=OLD.order_id; END IF;
    IF TG_OP='DELETE' THEN
        PERFORM id FROM orders WHERE id=previous_order FOR UPDATE;
        RETURN OLD;
    END IF;
    PERFORM id FROM orders WHERE id IN (NEW.order_id,previous_order) ORDER BY id FOR UPDATE;
    RETURN NEW;
END;
$$;
CREATE TRIGGER captain_selection_parent_lock BEFORE INSERT OR UPDATE OR DELETE ON order_captains
FOR EACH ROW EXECUTE FUNCTION order_captain_selection_lock();
CREATE FUNCTION selected_captain_assignment_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM order_captain_selections
        WHERE order_id=OLD.order_id AND assignment_ids @> jsonb_build_array(OLD.id)) THEN
        IF TG_OP='DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;
    IF TG_OP='DELETE' THEN
        RAISE EXCEPTION 'Audited Captain participation cannot be deleted' USING ERRCODE='23514';
    END IF;
    IF NEW.order_id IS DISTINCT FROM OLD.order_id OR NEW.captain_id IS DISTINCT FROM OLD.captain_id
       OR NEW.assignment_role IS DISTINCT FROM OLD.assignment_role OR NEW.assigned_at IS DISTINCT FROM OLD.assigned_at
       OR NEW.assigned_by IS DISTINCT FROM OLD.assigned_by
       OR (OLD.removed_at IS NOT NULL AND to_jsonb(NEW) IS DISTINCT FROM to_jsonb(OLD))
       OR (NEW.removed_at IS NULL AND (NEW.removed_by IS DISTINCT FROM OLD.removed_by OR NEW.removal_reason IS DISTINCT FROM OLD.removal_reason))
       OR (NEW.removed_at IS NOT NULL AND (NEW.removed_at<OLD.assigned_at OR NEW.removed_at>clock_timestamp()
           OR NEW.removed_by IS NULL OR btrim(coalesce(NEW.removal_reason,''))='')) THEN
        RAISE EXCEPTION 'Audited Captain identity/history is immutable; close and replace participation with a new selection' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER captain_selection_identity_immutable BEFORE UPDATE OR DELETE ON order_captains
FOR EACH ROW EXECUTE FUNCTION selected_captain_assignment_immutable();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Captain selection audit is forward-only.');
    }
};
