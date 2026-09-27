<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Bill-payment assignment integrity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_bill_payment_assignment_source() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM order_bill_payments WHERE order_captain_id = OLD.id) THEN
        RAISE EXCEPTION 'A participating OrderCaptain is part of immutable bill-payment history' USING ERRCODE = '55000';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_assignment_source_guard
BEFORE UPDATE OR DELETE ON order_captains
FOR EACH ROW EXECUTE FUNCTION protect_bill_payment_assignment_source();

CREATE OR REPLACE FUNCTION protect_bill_payment_order_branch() RETURNS trigger AS $$
BEGIN
    IF NEW.branch_id IS DISTINCT FROM OLD.branch_id
       AND EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id = OLD.id) THEN
        RAISE EXCEPTION 'A successful bill payment freezes its financial Branch' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_order_branch_guard
BEFORE UPDATE OF branch_id ON orders
FOR EACH ROW EXECUTE FUNCTION protect_bill_payment_order_branch();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment assignment integrity is intentionally forward-only.');
    }
};
