<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Bill-payment source integrity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION verify_bill_payment_request_fee_source() RETURNS trigger AS $$
DECLARE rule_row record;
BEGIN
    IF TG_OP = 'INSERT' AND (NEW.status <> 'REQUESTED' OR NEW.completed_at IS NOT NULL OR NEW.cancelled_at IS NOT NULL
       OR NEW.cancelled_by IS NOT NULL OR NEW.cancellation_reason IS NOT NULL) THEN
        RAISE EXCEPTION 'Bill-payment requests must start REQUESTED' USING ERRCODE = '23514';
    END IF;
    -- Trigger-name ordering puts this guard before the monetary guard. Lock here first.
    PERFORM id FROM currencies WHERE id = NEW.currency_id FOR UPDATE;
    SELECT * INTO rule_row FROM bill_payment_fee_rules WHERE id = NEW.fee_rule_id;
    IF rule_row.id IS NULL OR rule_row.currency_id <> NEW.currency_id OR rule_row.fixed_fee <> NEW.service_fee
       OR NEW.requested_at < rule_row.effective_from
       OR (rule_row.effective_until IS NOT NULL AND NEW.requested_at >= rule_row.effective_until) THEN
        RAISE EXCEPTION 'Bill-payment request must reference its exact applicable fee rule' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION require_exact_bill_payment_event_source() RETURNS trigger AS $$
DECLARE payment_id bigint;
BEGIN
    IF NEW.event_type NOT IN ('BILL_PAYMENT_CASH_COLLECTED', 'BILL_PAYMENT_PRINCIPAL_HANDED_OVER', 'BILL_PAYMENT_SERVICE_FEE_RETAINED')
       AND NEW.source_type NOT IN ('ORDER_BILL_PAYMENT', 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER') THEN
        RETURN NULL;
    END IF;
    IF NEW.source_id !~ '^[0-9]+$' OR NEW.source_id <> (NEW.source_id::bigint)::text THEN
        RAISE EXCEPTION 'Bill-payment event source ID must use its canonical numeric identity' USING ERRCODE = '23514';
    END IF;
    IF NEW.event_type = 'BILL_PAYMENT_PRINCIPAL_HANDED_OVER' AND NEW.source_type = 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER' THEN
        SELECT bill_payment_id INTO payment_id FROM order_bill_payment_principal_handovers WHERE id = NEW.source_id::bigint;
    ELSIF NEW.event_type IN ('BILL_PAYMENT_CASH_COLLECTED', 'BILL_PAYMENT_SERVICE_FEE_RETAINED')
       AND NEW.source_type = 'ORDER_BILL_PAYMENT' THEN
        payment_id := NEW.source_id::bigint;
    ELSE
        RAISE EXCEPTION 'Bill-payment event type and source do not match' USING ERRCODE = '23514';
    END IF;
    IF payment_id IS NULL THEN
        RAISE EXCEPTION 'Bill-payment event has no successful source' USING ERRCODE = '23514';
    END IF;
    PERFORM validate_completed_bill_payment_at_commit(payment_id);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment source integrity is intentionally forward-only.');
    }
};
