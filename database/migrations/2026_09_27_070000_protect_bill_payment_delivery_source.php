<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Bill-payment delivery history requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_bill_payment_delivery_history() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.new_status = 'DELIVERED' AND EXISTS (
            SELECT 1 FROM order_bill_payments WHERE order_id = NEW.order_id
        ) THEN
            RAISE EXCEPTION 'A successful bill payment freezes its authoritative delivery history' USING ERRCODE = '55000';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.new_status = 'DELIVERED' AND EXISTS (
        SELECT 1 FROM order_bill_payments
        WHERE order_id = OLD.order_id AND delivered_at = OLD.changed_at
    ) THEN
        RAISE EXCEPTION 'A successful bill payment freezes its authoritative delivery history' USING ERRCODE = '55000';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_delivery_history_guard
BEFORE INSERT OR UPDATE OR DELETE ON order_status_history
FOR EACH ROW EXECUTE FUNCTION protect_bill_payment_delivery_history();

CREATE OR REPLACE FUNCTION protect_bill_payment_delivered_order() RETURNS trigger AS $$
BEGIN
    IF NEW.operational_status IS DISTINCT FROM OLD.operational_status
       AND EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id = OLD.id) THEN
        RAISE EXCEPTION 'An Order with a successful bill payment cannot leave DELIVERED' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_delivered_order_guard
BEFORE UPDATE OF operational_status ON orders
FOR EACH ROW EXECUTE FUNCTION protect_bill_payment_delivered_order();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment delivery source integrity is intentionally forward-only.');
    }
};
