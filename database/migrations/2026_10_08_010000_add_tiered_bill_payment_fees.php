<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Tiered bill-payment integrity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE bill_payment_fee_rules ADD COLUMN fee_mode varchar(16) NOT NULL DEFAULT 'FIXED', ADD COLUMN fee_tiers jsonb;
ALTER TABLE bill_payment_fee_rules ALTER COLUMN fixed_fee DROP NOT NULL;
ALTER TABLE order_bill_payment_requests ALTER COLUMN service_fee DROP NOT NULL;

CREATE OR REPLACE FUNCTION valid_bill_payment_tiers(tiers jsonb, places integer) RETURNS boolean AS $$
DECLARE tier jsonb; lower_amount numeric; upper_amount numeric; next_amount numeric := 0; fee numeric; ordinal integer := 0;
BEGIN
    IF tiers IS NULL OR jsonb_typeof(tiers) <> 'array' OR jsonb_array_length(tiers) = 0 THEN RETURN false; END IF;
    FOR tier IN SELECT value FROM jsonb_array_elements(tiers) LOOP
        ordinal := ordinal + 1;
        IF jsonb_typeof(tier) <> 'object' OR jsonb_typeof(tier->'from') IS DISTINCT FROM 'string'
           OR jsonb_typeof(tier->'value') IS DISTINCT FROM 'string'
           OR (tier->>'from') !~ '^\d+(\.\d{1,4})?$' OR (tier->>'value') !~ '^\d+(\.\d{1,4})?$'
           OR (tier->>'type') IS NULL OR (tier->>'type') NOT IN ('FIXED', 'PERCENTAGE') THEN RETURN false; END IF;
        lower_amount := (tier->>'from')::numeric;
        fee := (tier->>'value')::numeric;
        IF lower_amount IS DISTINCT FROM next_amount OR fee <= 0 THEN RETURN false; END IF;
        IF tier->>'until' IS NULL THEN
            IF ordinal <> jsonb_array_length(tiers) THEN RETURN false; END IF;
            upper_amount := NULL;
        ELSE
            IF jsonb_typeof(tier->'until') IS DISTINCT FROM 'string' OR (tier->>'until') !~ '^\d+(\.\d{1,4})?$'
               OR ordinal = jsonb_array_length(tiers) THEN RETURN false; END IF;
            upper_amount := (tier->>'until')::numeric;
            IF upper_amount <= lower_amount THEN RETURN false; END IF;
        END IF;
        IF tier->>'type' = 'FIXED' AND fee <> round(fee, places) THEN RETURN false; END IF;
        next_amount := upper_amount;
    END LOOP;
    RETURN next_amount IS NULL;
EXCEPTION WHEN invalid_text_representation OR numeric_value_out_of_range THEN RETURN false;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION verify_bill_payment_rule_configuration() RETURNS trigger AS $$
DECLARE currency_row record;
BEGIN
    SELECT * INTO currency_row FROM currencies WHERE id = NEW.currency_id FOR UPDATE;
    IF NEW.fee_mode = 'FIXED' THEN
        IF NEW.fixed_fee IS NULL OR NEW.fee_tiers IS NOT NULL THEN
            RAISE EXCEPTION 'Fixed bill fee requires an amount and no tiers' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.fee_mode = 'TIERED' THEN
        IF NEW.fixed_fee IS NOT NULL OR NOT valid_bill_payment_tiers(NEW.fee_tiers, currency_row.decimal_places)
           OR currency_row.code IS DISTINCT FROM 'SYP' THEN
            RAISE EXCEPTION 'Tiered SYP bill fee requires positive contiguous non-overlapping ranges covering every positive principal' USING ERRCODE = '23514';
        END IF;
    ELSE
        RAISE EXCEPTION 'Unsupported bill fee mode' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_fee_configuration_guard BEFORE INSERT OR UPDATE ON bill_payment_fee_rules
FOR EACH ROW EXECUTE FUNCTION verify_bill_payment_rule_configuration();
ALTER TABLE bill_payment_fee_rules ADD CONSTRAINT bill_payment_fee_mode_shape CHECK (
    (fee_mode = 'FIXED' AND fixed_fee IS NOT NULL AND fee_tiers IS NULL)
    OR (fee_mode = 'TIERED' AND fixed_fee IS NULL AND fee_tiers IS NOT NULL)
);

CREATE OR REPLACE FUNCTION calculated_bill_payment_fee(rule_id bigint, principal numeric) RETURNS numeric AS $$
DECLARE rule_row record; tier jsonb; places integer; currency_code text; result numeric; matches integer := 0;
BEGIN
    SELECT * INTO rule_row FROM bill_payment_fee_rules WHERE id = rule_id;
    IF rule_row.id IS NULL THEN
        RAISE EXCEPTION 'Missing bill fee rule' USING ERRCODE = '23514';
    END IF;
    SELECT decimal_places, code INTO places, currency_code FROM currencies WHERE id = rule_row.currency_id;
    IF principal IS NULL OR principal::text IN ('NaN', 'Infinity', '-Infinity')
       OR principal <= 0 OR principal <> round(principal, places) THEN
        RAISE EXCEPTION 'Invalid bill principal' USING ERRCODE = '23514';
    END IF;
    IF rule_row.fee_mode = 'FIXED' THEN RETURN rule_row.fixed_fee; END IF;
    IF currency_code IS DISTINCT FROM 'SYP' THEN
        RAISE EXCEPTION 'Tiered bill fees require SYP' USING ERRCODE = '23514';
    END IF;
    FOR tier IN SELECT value FROM jsonb_array_elements(rule_row.fee_tiers) LOOP
        IF principal >= (tier->>'from')::numeric AND (tier->>'until' IS NULL OR principal < (tier->>'until')::numeric) THEN
            matches := matches + 1;
            IF tier->>'type' = 'FIXED' THEN result := (tier->>'value')::numeric;
            ELSE result := round(principal * (tier->>'value')::numeric / 500, 0) * 5; END IF;
        END IF;
    END LOOP;
    IF matches <> 1 OR result IS NULL OR result <= 0 THEN
        RAISE EXCEPTION 'Bill fee must match exactly one positive fee tier' USING ERRCODE = '23514';
    END IF;
    RETURN result;
END;
$$ LANGUAGE plpgsql;
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
    IF rule_row.id IS NULL OR rule_row.currency_id <> NEW.currency_id OR ((rule_row.fee_mode = 'FIXED' AND rule_row.fixed_fee IS DISTINCT FROM NEW.service_fee) OR (rule_row.fee_mode = 'TIERED' AND NEW.service_fee IS NOT NULL))
       OR NEW.requested_at < rule_row.effective_from
       OR (rule_row.effective_until IS NOT NULL AND NEW.requested_at >= rule_row.effective_until) THEN
        RAISE EXCEPTION 'Bill-payment request must reference its exact applicable fee rule' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION verify_completed_bill_payment_source() RETURNS trigger AS $$
DECLARE request_row record; collector_row record; delivery_time timestamptz;
BEGIN
    SELECT * INTO request_row FROM order_bill_payment_requests WHERE id = NEW.request_id;
    SELECT * INTO collector_row FROM order_captains WHERE id = NEW.order_captain_id;
    SELECT changed_at INTO delivery_time FROM order_status_history
    WHERE order_id = NEW.order_id AND new_status = 'DELIVERED'
    ORDER BY changed_at DESC, id DESC LIMIT 1;
    IF request_row.id IS NULL OR request_row.status <> 'REQUESTED'
       OR request_row.order_id <> NEW.order_id OR request_row.currency_id <> NEW.currency_id
       OR request_row.fee_rule_id <> NEW.fee_rule_id OR NEW.service_fee IS DISTINCT FROM calculated_bill_payment_fee(NEW.fee_rule_id, NEW.principal_amount)
       OR collector_row.id IS NULL OR collector_row.order_id <> NEW.order_id
       OR collector_row.captain_id <> NEW.captain_id OR collector_row.removed_at IS NOT NULL
       OR collector_row.assigned_at > delivery_time
       OR delivery_time IS NULL OR NEW.delivered_at IS DISTINCT FROM delivery_time
       OR NEW.delivered_at > NEW.paid_at
       OR NOT EXISTS (SELECT 1 FROM orders WHERE id = NEW.order_id AND operational_status = 'DELIVERED') THEN
        RAISE EXCEPTION 'Completed bill payment does not match its request, collector or delivery history' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION validate_completed_bill_payment_at_commit(payment_id bigint) RETURNS void AS $$
DECLARE payment_row record; request_row record; handover_row record; delivery_time timestamptz;
BEGIN
    SELECT * INTO payment_row FROM order_bill_payments WHERE id = payment_id;
    IF payment_row.id IS NULL THEN
        RAISE EXCEPTION 'Successful bill payment source is missing: %', payment_id USING ERRCODE = '23514';
    END IF;
    SELECT * INTO request_row FROM order_bill_payment_requests WHERE id = payment_row.request_id;
    SELECT * INTO handover_row FROM order_bill_payment_principal_handovers WHERE bill_payment_id = payment_id;
    SELECT changed_at INTO delivery_time FROM order_status_history
      WHERE order_id = payment_row.order_id AND new_status = 'DELIVERED'
      ORDER BY changed_at DESC, id DESC LIMIT 1;
    IF request_row.id IS NULL OR request_row.status <> 'COMPLETED'
       OR request_row.order_id <> payment_row.order_id OR request_row.currency_id <> payment_row.currency_id
       OR request_row.fee_rule_id <> payment_row.fee_rule_id OR payment_row.service_fee IS DISTINCT FROM calculated_bill_payment_fee(payment_row.fee_rule_id, payment_row.principal_amount)
       OR request_row.completed_at IS DISTINCT FROM payment_row.paid_at
       OR handover_row.id IS NULL
       OR handover_row.order_id <> payment_row.order_id
       OR handover_row.order_captain_id <> payment_row.order_captain_id
       OR handover_row.captain_id <> payment_row.captain_id
       OR handover_row.currency_id <> payment_row.currency_id
       OR handover_row.principal_amount <> payment_row.principal_amount
       OR handover_row.occurred_at IS DISTINCT FROM payment_row.paid_at
       OR delivery_time IS NULL OR payment_row.delivered_at IS DISTINCT FROM delivery_time
       OR NOT EXISTS (SELECT 1 FROM order_bill_payment_evidence WHERE bill_payment_id = payment_id)
       OR (SELECT count(*) FROM financial_transactions t WHERE t.source_type = 'ORDER_BILL_PAYMENT'
           AND t.source_id = payment_id::text) <> 2
       OR (SELECT count(*) FROM financial_transactions t WHERE t.source_type = 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER'
           AND t.source_id = handover_row.id::text) <> 1 THEN
        RAISE EXCEPTION 'Successful bill payment % lacks its exact request, handover, evidence or financial event set', payment_id USING ERRCODE = '23514';
    END IF;

    PERFORM validate_bill_payment_event('ORDER_BILL_PAYMENT', payment_id::text,
        'BILL_PAYMENT_CASH_COLLECTED', payment_row.currency_id, (SELECT branch_id FROM orders WHERE id = payment_row.order_id),
        payment_row.captain_id, payment_row.delivered_at, 'CAPTAIN_CASH_CUSTODY', 'INCREASE', payment_row.principal_amount);
    PERFORM validate_bill_payment_event('ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER', handover_row.id::text,
        'BILL_PAYMENT_PRINCIPAL_HANDED_OVER', payment_row.currency_id, (SELECT branch_id FROM orders WHERE id = payment_row.order_id),
        payment_row.captain_id, handover_row.occurred_at, 'CAPTAIN_CASH_CUSTODY', 'DECREASE', payment_row.principal_amount);
    PERFORM validate_bill_payment_event('ORDER_BILL_PAYMENT', payment_id::text,
        'BILL_PAYMENT_SERVICE_FEE_RETAINED', payment_row.currency_id, (SELECT branch_id FROM orders WHERE id = payment_row.order_id),
        payment_row.captain_id, payment_row.paid_at, 'CAPTAIN_DEPOSIT', 'DECREASE', payment_row.service_fee);
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION guard_bill_payment_fee_history() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Bill-payment fee history cannot be deleted' USING ERRCODE = '55000';
    END IF;
    PERFORM id FROM currencies WHERE id = OLD.currency_id FOR UPDATE;
    IF (to_jsonb(NEW) - 'effective_until' - 'superseded_by' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'effective_until' - 'superseded_by' - 'updated_at')
       OR (OLD.effective_until IS NOT NULL AND NEW.effective_until IS DISTINCT FROM OLD.effective_until)
       OR (OLD.superseded_by IS NOT NULL AND NEW.superseded_by IS DISTINCT FROM OLD.superseded_by)
       OR (NEW.effective_until IS NOT NULL AND NEW.effective_until <= OLD.effective_from)
       OR (NEW.superseded_by IS NOT NULL AND NEW.effective_until IS NULL)
       OR EXISTS (SELECT 1 FROM order_bill_payment_requests r WHERE r.fee_rule_id = OLD.id
           AND (r.currency_id <> NEW.currency_id OR r.service_fee IS DISTINCT FROM NEW.fixed_fee
               OR r.requested_at < NEW.effective_from
               OR (NEW.effective_until IS NOT NULL AND r.requested_at >= NEW.effective_until))) THEN
        RAISE EXCEPTION 'Bill-payment fee facts are immutable; preserve every request snapshot when versioning' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Historical bill-payment fee configuration is forward-only.');
    }
};
