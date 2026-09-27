<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Bill-payment delivery-source serialization requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
LOCK TABLE orders, order_status_history, order_bill_payments, financial_transactions IN ACCESS EXCLUSIVE MODE;
DO $$
DECLARE bad text;
BEGIN
    SELECT string_agg(format('payment=%s order=%s', p.id, p.order_id), ', ' ORDER BY p.id) INTO bad
    FROM order_bill_payments p
    LEFT JOIN LATERAL (
        SELECT h.changed_at FROM order_status_history h
        WHERE h.order_id = p.order_id AND h.new_status = 'DELIVERED'
        ORDER BY h.changed_at DESC, h.id DESC LIMIT 1
    ) delivery ON true
    LEFT JOIN financial_transactions cash ON cash.source_type = 'ORDER_BILL_PAYMENT'
        AND cash.source_id = p.id::text AND cash.event_type = 'BILL_PAYMENT_CASH_COLLECTED'
        AND cash.source_version = 1
    WHERE delivery.changed_at IS NULL OR p.delivered_at IS DISTINCT FROM delivery.changed_at
        OR cash.id IS NULL OR cash.occurred_at IS DISTINCT FROM delivery.changed_at;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'Contradictory bill-payment delivery source; manual correction required: %', bad USING ERRCODE = '23514';
    END IF;
END;
$$;

-- Replace the earlier visibility-only guard with the serialized guard below.
DROP TRIGGER bill_payment_delivery_history_guard ON order_status_history;
DROP FUNCTION protect_bill_payment_delivery_history();

CREATE OR REPLACE FUNCTION lock_bill_payment_order_before_insert() RETURNS trigger AS $$
BEGIN
    -- This trigger sorts before bill_payment_precision, which locks Currency.
    PERFORM id FROM orders WHERE id = NEW.order_id FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Bill payment has no parent Order: %', NEW.order_id USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER aa_bill_payment_order_lock
BEFORE INSERT ON order_bill_payments
FOR EACH ROW EXECUTE FUNCTION lock_bill_payment_order_before_insert();

CREATE OR REPLACE FUNCTION lock_and_protect_bill_payment_delivery_history() RETURNS trigger AS $$
DECLARE old_order_id bigint; new_order_id bigint; target_order_id bigint;
BEGIN
    IF TG_OP <> 'INSERT' AND OLD.new_status = 'DELIVERED' THEN
        old_order_id := OLD.order_id;
    END IF;
    IF TG_OP <> 'DELETE' AND NEW.new_status = 'DELIVERED' THEN
        new_order_id := NEW.order_id;
    END IF;
    IF old_order_id IS NULL AND new_order_id IS NULL THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;

    -- Lock both affected Orders in a stable order when a DELIVERED row moves.
    FOR target_order_id IN
        SELECT DISTINCT id FROM (VALUES (old_order_id), (new_order_id)) AS affected(id)
        WHERE id IS NOT NULL ORDER BY id
    LOOP
        PERFORM id FROM orders WHERE id = target_order_id FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'DELIVERED history has no parent Order: %', target_order_id USING ERRCODE = '23514';
        END IF;
    END LOOP;

    -- Recheck after the shared lock, using facts committed by the prior holder.
    IF TG_OP <> 'INSERT' AND OLD.new_status = 'DELIVERED'
        AND (TG_OP = 'DELETE' OR OLD.order_id IS DISTINCT FROM NEW.order_id
            OR OLD.new_status IS DISTINCT FROM NEW.new_status
            OR OLD.changed_at IS DISTINCT FROM NEW.changed_at)
        AND EXISTS (
        SELECT 1 FROM order_bill_payments
        WHERE order_id = OLD.order_id AND delivered_at = OLD.changed_at
    ) THEN
        RAISE EXCEPTION 'A successful bill payment freezes its authoritative delivery history' USING ERRCODE = '55000';
    END IF;
    IF TG_OP <> 'DELETE' AND NEW.new_status = 'DELIVERED'
        AND (TG_OP = 'INSERT' OR OLD.order_id IS DISTINCT FROM NEW.order_id
            OR OLD.new_status IS DISTINCT FROM NEW.new_status
            OR OLD.changed_at IS DISTINCT FROM NEW.changed_at)
        AND EXISTS (SELECT 1 FROM order_bill_payments
            WHERE order_id = NEW.order_id AND delivered_at <= NEW.changed_at) THEN
        RAISE EXCEPTION 'A successful bill payment freezes its authoritative delivery history' USING ERRCODE = '55000';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER aa_bill_payment_delivery_source_lock
BEFORE INSERT OR UPDATE OR DELETE ON order_status_history
FOR EACH ROW EXECUTE FUNCTION lock_and_protect_bill_payment_delivery_history();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment delivery-source integrity is intentionally forward-only.');
    }
};
