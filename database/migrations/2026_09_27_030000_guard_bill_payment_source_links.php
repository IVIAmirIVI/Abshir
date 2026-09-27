<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION verify_completed_bill_payment_source() RETURNS trigger AS $$
DECLARE request_row record; collector_row record;
BEGIN
    SELECT * INTO request_row FROM order_bill_payment_requests WHERE id = NEW.request_id;
    SELECT * INTO collector_row FROM order_captains WHERE id = NEW.order_captain_id;
    IF request_row.id IS NULL OR request_row.status <> 'REQUESTED'
       OR request_row.order_id <> NEW.order_id OR request_row.currency_id <> NEW.currency_id
       OR request_row.fee_rule_id <> NEW.fee_rule_id OR request_row.service_fee <> NEW.service_fee
       OR collector_row.id IS NULL OR collector_row.order_id <> NEW.order_id
       OR collector_row.captain_id <> NEW.captain_id OR collector_row.removed_at IS NOT NULL
       OR NEW.delivered_at > NEW.paid_at THEN
        RAISE EXCEPTION 'Completed bill payment does not match its request or collector' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER completed_bill_payment_source_guard BEFORE INSERT ON order_bill_payments FOR EACH ROW EXECUTE FUNCTION verify_completed_bill_payment_source();

CREATE OR REPLACE FUNCTION verify_bill_payment_handover_source() RETURNS trigger AS $$
DECLARE payment_row record;
BEGIN
    SELECT * INTO payment_row FROM order_bill_payments WHERE id = NEW.bill_payment_id;
    IF payment_row.id IS NULL OR payment_row.order_id <> NEW.order_id
       OR payment_row.order_captain_id <> NEW.order_captain_id
       OR payment_row.captain_id <> NEW.captain_id OR payment_row.currency_id <> NEW.currency_id
       OR payment_row.principal_amount <> NEW.principal_amount
       OR NEW.occurred_at < payment_row.delivered_at THEN
        RAISE EXCEPTION 'Principal handover must exactly match the successful bill payment' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_handover_source_guard BEFORE INSERT ON order_bill_payment_principal_handovers FOR EACH ROW EXECUTE FUNCTION verify_bill_payment_handover_source();

CREATE OR REPLACE FUNCTION verify_bill_payment_request_completion() RETURNS trigger AS $$
BEGIN
    IF NEW.status = 'COMPLETED' AND NOT EXISTS (
        SELECT 1 FROM order_bill_payments p
        JOIN order_bill_payment_principal_handovers h ON h.bill_payment_id = p.id
        JOIN order_bill_payment_evidence e ON e.bill_payment_id = p.id
        WHERE p.request_id = NEW.id AND p.paid_at = NEW.completed_at
    ) THEN
        RAISE EXCEPTION 'Completed request requires bill payment, exact handover and evidence' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_request_completion_guard AFTER UPDATE ON order_bill_payment_requests FOR EACH ROW EXECUTE FUNCTION verify_bill_payment_request_completion();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment source integrity is intentionally forward-only.');
    }
};
