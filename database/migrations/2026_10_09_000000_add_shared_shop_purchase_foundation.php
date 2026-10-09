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
            throw new RuntimeException('Shared purchase integrity requires PostgreSQL.');
        }
        // Preserve old facts and their original physical payer; never convert historical purchases.
        Schema::table('order_shop_purchase_confirmations', function (Blueprint $table): void {
            $table->string('purchase_mode', 32)->default('SINGLE_PAYER');
            $table->foreignId('collecting_order_captain_id')->nullable()->constrained('order_captains')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE order_shop_purchase_confirmations ALTER COLUMN order_captain_id DROP NOT NULL');
        DB::statement(<<<'SQL'
ALTER TABLE order_shop_purchase_confirmations ADD CONSTRAINT purchase_payer_contract CHECK (
    (purchase_mode='SINGLE_PAYER' AND order_captain_id IS NOT NULL AND collecting_order_captain_id IS NULL)
    OR (purchase_mode='SHARED_TWO_V1' AND order_captain_id IS NULL AND collecting_order_captain_id IS NOT NULL)
)
SQL);
        Schema::create('order_shop_purchase_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_confirmation_id')->constrained('order_shop_purchase_confirmations')->restrictOnDelete();
            $table->foreignId('order_captain_id')->constrained()->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['purchase_confirmation_id', 'order_captain_id'], 'shop_purchase_payer_unique');
        });
        DB::statement('ALTER TABLE order_shop_purchase_allocations ADD COLUMN amount numeric NOT NULL');
        // Keep the original validator unchanged for the original contract.
        DB::unprepared(<<<'SQL'
DROP TRIGGER purchase_validate ON order_shop_purchase_confirmations;
CREATE TRIGGER purchase_validate BEFORE INSERT ON order_shop_purchase_confirmations
FOR EACH ROW WHEN (NEW.purchase_mode='SINGLE_PAYER') EXECUTE FUNCTION order_purchase_validate();
CREATE FUNCTION shared_shop_purchase_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o orders%ROWTYPE; places integer; collector_order bigint; collector_role text;
BEGIN
    SELECT * INTO o FROM orders WHERE id=NEW.order_id FOR UPDATE;
    IF NEW.purchase_mode='SINGLE_PAYER' THEN
        IF EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=NEW.order_id AND purchase_mode='SHARED_TWO_V1') THEN
            RAISE EXCEPTION 'Shared purchase cannot use legacy single-payer sources' USING ERRCODE='23514';
        END IF;
        RETURN NEW;
    END IF;
    SELECT decimal_places INTO places FROM currencies WHERE id=NEW.currency_id FOR UPDATE;
    SELECT order_id, assignment_role INTO collector_order, collector_role FROM order_captains
        WHERE id=NEW.collecting_order_captain_id AND removed_at IS NULL;
    IF NEW.purchase_mode <> 'SHARED_TWO_V1' OR NEW.order_captain_id IS NOT NULL
       OR o.branch_id IS DISTINCT FROM NEW.branch_id OR o.operational_status IN ('DELIVERED','CANCELLED')
       OR NOT EXISTS (SELECT 1 FROM order_shops WHERE id=NEW.order_shop_id AND order_id=NEW.order_id)
       OR collector_order IS DISTINCT FROM NEW.order_id OR collector_role IS DISTINCT FROM 'PRIMARY_COLLECTION'
       OR (SELECT count(*) FROM order_captains WHERE order_id=NEW.order_id AND removed_at IS NULL) <> 2
       OR EXISTS (SELECT 1 FROM order_captains WHERE order_id=NEW.order_id AND removed_at IS NULL AND assigned_at > NEW.purchased_at)
       OR EXISTS (SELECT 1 FROM invoices WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_bill_payment_requests WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=NEW.order_id)
       OR EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=NEW.order_id AND purchase_mode='SINGLE_PAYER')
       OR EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=NEW.order_id
                  AND (currency_id<>NEW.currency_id OR collecting_order_captain_id<>NEW.collecting_order_captain_id))
       OR places IS NULL OR NEW.amount::text IN ('NaN','Infinity','-Infinity') OR NEW.amount<=0
       OR NEW.amount <> round(NEW.amount, places) OR NEW.amount/2 <> round(NEW.amount/2, places)
       OR NEW.purchased_at > clock_timestamp() OR NEW.purchased_at < o.ordered_at
       OR btrim(NEW.evidence_path)='' OR btrim(NEW.evidence_name)=''
       OR NEW.evidence_mime NOT IN ('image/jpeg','image/png','image/webp')
       OR NEW.evidence_size NOT BETWEEN 1 AND 5242880 OR NEW.evidence_sha256 !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'Invalid shared Shop purchase; exact halves, two active payers and unsettled Order required' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER shared_purchase_validate BEFORE INSERT ON order_shop_purchase_confirmations
FOR EACH ROW EXECUTE FUNCTION shared_shop_purchase_validate();
CREATE FUNCTION shared_shop_purchase_allocation_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p order_shop_purchase_confirmations%ROWTYPE; places integer;
BEGIN
    SELECT * INTO p FROM order_shop_purchase_confirmations WHERE id=NEW.purchase_confirmation_id;
    PERFORM id FROM orders WHERE id=p.order_id FOR UPDATE;
    SELECT decimal_places INTO places FROM currencies WHERE id=p.currency_id FOR UPDATE;
    IF p.purchase_mode IS DISTINCT FROM 'SHARED_TWO_V1' OR places IS NULL
       OR NOT EXISTS (SELECT 1 FROM order_captains WHERE id=NEW.order_captain_id AND order_id=p.order_id AND removed_at IS NULL)
       OR NEW.amount::text IN ('NaN','Infinity','-Infinity') OR NEW.amount<=0
       OR NEW.amount <> round(NEW.amount, places) OR NEW.amount*2<>p.amount THEN
        RAISE EXCEPTION 'Invalid physical shared purchase allocation' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER shared_purchase_allocation_validate BEFORE INSERT ON order_shop_purchase_allocations
FOR EACH ROW EXECUTE FUNCTION shared_shop_purchase_allocation_validate();
CREATE TRIGGER shared_purchase_allocation_immutable BEFORE UPDATE OR DELETE ON order_shop_purchase_allocations
FOR EACH ROW EXECUTE FUNCTION order_return_immutable();
CREATE FUNCTION assert_shared_shop_purchase_complete(p_id bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE p order_shop_purchase_confirmations%ROWTYPE;
BEGIN
    SELECT * INTO p FROM order_shop_purchase_confirmations WHERE id=p_id;
    IF p.purchase_mode='SHARED_TWO_V1' AND (
       (SELECT count(*) FROM order_shop_purchase_allocations WHERE purchase_confirmation_id=p.id)<>2
       OR (SELECT sum(amount) FROM order_shop_purchase_allocations WHERE purchase_confirmation_id=p.id) IS DISTINCT FROM p.amount
       OR NOT EXISTS (SELECT 1 FROM order_shop_purchase_allocations WHERE purchase_confirmation_id=p.id AND order_captain_id=p.collecting_order_captain_id)
    ) THEN
        RAISE EXCEPTION 'Shared purchase requires exactly two complete physical payer allocations' USING ERRCODE='23514';
    END IF;
END;
$$;
CREATE FUNCTION shared_shop_purchase_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME='order_shop_purchase_confirmations' THEN
        PERFORM assert_shared_shop_purchase_complete(NEW.id);
    ELSE
        PERFORM assert_shared_shop_purchase_complete(NEW.purchase_confirmation_id);
    END IF;
    RETURN NEW;
END;
$$;
CREATE CONSTRAINT TRIGGER shared_purchase_complete AFTER INSERT ON order_shop_purchase_confirmations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION shared_shop_purchase_complete();
CREATE CONSTRAINT TRIGGER shared_purchase_allocations_complete AFTER INSERT ON order_shop_purchase_allocations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION shared_shop_purchase_complete();
-- Do not let the new physical source flow into legacy settlement or single-payer refunds.
CREATE FUNCTION shared_purchase_legacy_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE row_data jsonb; parent_id bigint; old_parent_id bigint;
BEGIN
    IF TG_OP='DELETE' THEN row_data:=to_jsonb(OLD); ELSE row_data:=to_jsonb(NEW); END IF;
    IF TG_TABLE_NAME='orders' THEN
        parent_id:=(row_data->>'id')::bigint;
    ELSIF TG_TABLE_NAME='order_items' THEN
        SELECT order_id INTO parent_id FROM order_shops WHERE id=(row_data->>'order_shop_id')::bigint;
    ELSE
        parent_id:=(row_data->>'order_id')::bigint;
    END IF;
    IF TG_OP='UPDATE' THEN
        IF TG_TABLE_NAME='order_items' THEN
            SELECT order_id INTO old_parent_id FROM order_shops WHERE id=OLD.order_shop_id;
        ELSIF TG_TABLE_NAME='orders' THEN
            old_parent_id:=OLD.id;
        ELSE
            old_parent_id:=(to_jsonb(OLD)->>'order_id')::bigint;
        END IF;
    END IF;
    IF old_parent_id IS NOT NULL THEN
        PERFORM id FROM orders WHERE id IN (parent_id, old_parent_id) ORDER BY id FOR UPDATE;
    ELSE
        PERFORM id FROM orders WHERE id=parent_id FOR UPDATE;
    END IF;
    IF EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=parent_id AND purchase_mode='SHARED_TWO_V1')
       OR (TG_OP='UPDATE' AND EXISTS (
            SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=old_parent_id AND purchase_mode='SHARED_TWO_V1'
       )) THEN
        RAISE EXCEPTION 'Shared purchase settlement and refund contract is not enabled' USING ERRCODE='23514';
    END IF;
    IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END;
$$;
CREATE TRIGGER shared_purchase_order_boundary BEFORE UPDATE OR DELETE ON orders FOR EACH ROW EXECUTE FUNCTION shared_purchase_legacy_boundary();
SQL);
        foreach (['invoices', 'order_captains', 'order_shops', 'order_items', 'order_cancellations', 'order_cancellation_intents',
            'order_shop_returns', 'order_bill_payment_requests', 'order_bill_payments', 'order_delivery_pricings', 'delivery_fee_override_requests'] as $table) {
            DB::statement("CREATE TRIGGER shared_purchase_boundary BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION shared_purchase_legacy_boundary()");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Shared purchase history is forward-only; rollback requires a reviewed forward migration.');
    }
};
