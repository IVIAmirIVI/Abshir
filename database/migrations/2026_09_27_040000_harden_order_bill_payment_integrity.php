<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Bill-payment financial integrity requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
LOCK TABLE bill_payment_fee_rules, order_bill_payment_requests, order_bill_payments,
    order_bill_payment_principal_handovers, order_bill_payment_evidence IN ACCESS EXCLUSIVE MODE;
DO $$
DECLARE bad text;
BEGIN
    SELECT string_agg('rule=' || f.id, ', ' ORDER BY f.id) INTO bad
    FROM bill_payment_fee_rules f JOIN currencies c ON c.id = f.currency_id
    WHERE f.fixed_fee::text IN ('NaN', 'Infinity', '-Infinity') OR f.fixed_fee <= 0
       OR f.fixed_fee <> round(f.fixed_fee, c.decimal_places);
    IF bad IS NOT NULL THEN RAISE EXCEPTION 'Invalid bill-payment fee history: %', bad USING ERRCODE = '23514'; END IF;

    SELECT string_agg('request=' || r.id, ', ' ORDER BY r.id) INTO bad
    FROM order_bill_payment_requests r
    JOIN currencies c ON c.id = r.currency_id
    LEFT JOIN bill_payment_fee_rules f ON f.id = r.fee_rule_id
    WHERE r.service_fee::text IN ('NaN', 'Infinity', '-Infinity') OR r.service_fee <= 0
       OR r.service_fee <> round(r.service_fee, c.decimal_places)
       OR f.id IS NULL OR r.currency_id <> f.currency_id OR r.service_fee <> f.fixed_fee
       OR r.requested_at < f.effective_from
       OR (f.effective_until IS NOT NULL AND r.requested_at >= f.effective_until)
       OR (r.status = 'REQUESTED' AND (r.completed_at IS NOT NULL OR r.cancelled_at IS NOT NULL OR r.cancelled_by IS NOT NULL OR r.cancellation_reason IS NOT NULL))
       OR (r.status = 'COMPLETED' AND (r.completed_at IS NULL OR r.cancelled_at IS NOT NULL OR r.cancelled_by IS NOT NULL OR r.cancellation_reason IS NOT NULL))
       OR (r.status = 'CANCELLED' AND (r.completed_at IS NOT NULL OR r.cancelled_at IS NULL OR r.cancelled_by IS NULL OR btrim(coalesce(r.cancellation_reason, '')) = ''));
    IF bad IS NOT NULL THEN RAISE EXCEPTION 'Invalid bill-payment request history: %', bad USING ERRCODE = '23514'; END IF;

    SELECT string_agg('payment=' || p.id, ', ' ORDER BY p.id) INTO bad
    FROM order_bill_payments p JOIN currencies c ON c.id = p.currency_id
    WHERE p.principal_amount::text IN ('NaN', 'Infinity', '-Infinity')
       OR p.service_fee::text IN ('NaN', 'Infinity', '-Infinity')
       OR p.total_customer_cash::text IN ('NaN', 'Infinity', '-Infinity')
       OR p.principal_amount <= 0 OR p.service_fee <= 0 OR p.total_customer_cash <= 0
       OR p.principal_amount <> round(p.principal_amount, c.decimal_places)
       OR p.service_fee <> round(p.service_fee, c.decimal_places)
       OR p.total_customer_cash <> round(p.total_customer_cash, c.decimal_places)
       OR p.total_customer_cash <> p.principal_amount + p.service_fee;
    IF bad IS NOT NULL THEN RAISE EXCEPTION 'Invalid successful bill-payment money: %', bad USING ERRCODE = '23514'; END IF;

    SELECT string_agg('handover=' || h.id, ', ' ORDER BY h.id) INTO bad
    FROM order_bill_payment_principal_handovers h JOIN currencies c ON c.id = h.currency_id
    WHERE h.principal_amount::text IN ('NaN', 'Infinity', '-Infinity') OR h.principal_amount <= 0
       OR h.principal_amount <> round(h.principal_amount, c.decimal_places);
    IF bad IS NOT NULL THEN RAISE EXCEPTION 'Invalid bill-payment handover money: %', bad USING ERRCODE = '23514'; END IF;

    SELECT string_agg('payment=' || p.id, ', ' ORDER BY p.id) INTO bad
    FROM order_bill_payments p
    LEFT JOIN order_bill_payment_requests r ON r.id = p.request_id
    WHERE r.id IS NULL OR r.status <> 'COMPLETED' OR r.completed_at IS DISTINCT FROM p.paid_at
       OR NOT EXISTS (SELECT 1 FROM order_bill_payment_principal_handovers h WHERE h.bill_payment_id = p.id)
       OR NOT EXISTS (SELECT 1 FROM order_bill_payment_evidence e WHERE e.bill_payment_id = p.id)
       OR (SELECT count(*) FROM financial_transactions t WHERE t.source_type = 'ORDER_BILL_PAYMENT' AND t.source_id = p.id::text) <> 2
       OR (SELECT count(*) FROM financial_transactions t JOIN order_bill_payment_principal_handovers h ON h.id::text = t.source_id
           WHERE h.bill_payment_id = p.id AND t.source_type = 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER') <> 1;
    IF bad IS NOT NULL THEN RAISE EXCEPTION 'Incomplete successful bill-payment source set: %', bad USING ERRCODE = '23514'; END IF;

    SELECT string_agg('request=' || r.id, ', ' ORDER BY r.id) INTO bad
    FROM order_bill_payment_requests r WHERE r.status = 'COMPLETED'
      AND NOT EXISTS (SELECT 1 FROM order_bill_payments p WHERE p.request_id = r.id);
    IF bad IS NOT NULL THEN RAISE EXCEPTION 'Completed requests without successful payment: %', bad USING ERRCODE = '23514'; END IF;

    SELECT string_agg('evidence=' || e.id, ', ' ORDER BY e.id) INTO bad FROM order_bill_payment_evidence e;
    IF bad IS NOT NULL THEN
        RAISE EXCEPTION 'Existing evidence requires manual SHA-256 verification before migration: %', bad USING ERRCODE = '23514';
    END IF;
END;
$$;

ALTER TABLE bill_payment_fee_rules ALTER COLUMN fixed_fee TYPE numeric USING fixed_fee::numeric;
ALTER TABLE order_bill_payment_requests ALTER COLUMN service_fee TYPE numeric USING service_fee::numeric;
ALTER TABLE order_bill_payments ALTER COLUMN principal_amount TYPE numeric USING principal_amount::numeric,
    ALTER COLUMN service_fee TYPE numeric USING service_fee::numeric,
    ALTER COLUMN total_customer_cash TYPE numeric USING total_customer_cash::numeric;
ALTER TABLE order_bill_payment_principal_handovers ALTER COLUMN principal_amount TYPE numeric USING principal_amount::numeric;

ALTER TABLE order_bill_payment_evidence ADD COLUMN sha256 char(64);
ALTER TABLE order_bill_payment_evidence ALTER COLUMN sha256 SET NOT NULL;
ALTER TABLE order_bill_payment_evidence ADD CONSTRAINT bill_payment_evidence_metadata_valid
CHECK (sha256 ~ '^[0-9a-f]{64}$' AND file_size > 0 AND file_size <= 5242880
    AND mime_type IN ('image/jpeg', 'image/png', 'image/webp'));

ALTER TABLE order_bill_payment_requests DROP CONSTRAINT bill_payment_request_state_shape;
ALTER TABLE order_bill_payment_requests ADD CONSTRAINT bill_payment_request_state_shape CHECK (
    (status = 'REQUESTED' AND completed_at IS NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'COMPLETED' AND completed_at IS NOT NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
    OR (status = 'CANCELLED' AND completed_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL
        AND btrim(coalesce(cancellation_reason, '')) <> '')
);
ALTER TABLE order_bill_payment_requests DROP CONSTRAINT order_bill_payment_requests_cancelled_by_foreign;
ALTER TABLE order_bill_payment_requests ADD CONSTRAINT order_bill_payment_requests_cancelled_by_foreign
FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE RESTRICT;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_bill_payment_currency_precision() RETURNS trigger AS $$
DECLARE places integer; amount numeric;
BEGIN
    SELECT decimal_places INTO places FROM currencies WHERE id = NEW.currency_id FOR UPDATE;
    IF places IS NULL OR places < 0 OR places > 4 THEN
        RAISE EXCEPTION 'Invalid bill-payment Currency precision' USING ERRCODE = '23514';
    END IF;
    IF TG_TABLE_NAME = 'bill_payment_fee_rules' THEN
        amount := NEW.fixed_fee;
        IF amount::text IN ('NaN', 'Infinity', '-Infinity') OR amount <= 0 OR amount <> round(amount, places) THEN
            RAISE EXCEPTION 'Bill-payment fee must be finite, positive and exact at Currency precision' USING ERRCODE = '23514';
        END IF;
    ELSIF TG_TABLE_NAME = 'order_bill_payment_requests' THEN
        amount := NEW.service_fee;
        IF amount::text IN ('NaN', 'Infinity', '-Infinity') OR amount <= 0 OR amount <> round(amount, places) THEN
            RAISE EXCEPTION 'Bill-payment request fee must be finite, positive and exact at Currency precision' USING ERRCODE = '23514';
        END IF;
    ELSIF TG_TABLE_NAME = 'order_bill_payments' THEN
        IF NEW.principal_amount::text IN ('NaN', 'Infinity', '-Infinity')
           OR NEW.service_fee::text IN ('NaN', 'Infinity', '-Infinity')
           OR NEW.total_customer_cash::text IN ('NaN', 'Infinity', '-Infinity')
           OR NEW.principal_amount <= 0 OR NEW.service_fee <= 0 OR NEW.total_customer_cash <= 0
           OR NEW.principal_amount <> round(NEW.principal_amount, places)
           OR NEW.service_fee <> round(NEW.service_fee, places)
           OR NEW.total_customer_cash <> round(NEW.total_customer_cash, places) THEN
            RAISE EXCEPTION 'Bill-payment amounts must be finite, positive and exact at Currency precision' USING ERRCODE = '23514';
        END IF;
    ELSIF TG_TABLE_NAME = 'order_bill_payment_principal_handovers' THEN
        amount := NEW.principal_amount;
        IF amount::text IN ('NaN', 'Infinity', '-Infinity') OR amount <= 0 OR amount <> round(amount, places) THEN
            RAISE EXCEPTION 'Bill-payment handover must be finite, positive and exact at Currency precision' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
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
           AND (r.currency_id <> NEW.currency_id OR r.service_fee <> NEW.fixed_fee
               OR r.requested_at < NEW.effective_from
               OR (NEW.effective_until IS NOT NULL AND r.requested_at >= NEW.effective_until))) THEN
        RAISE EXCEPTION 'Bill-payment fee facts are immutable; preserve every request snapshot when versioning' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION verify_bill_payment_request_fee_source() RETURNS trigger AS $$
DECLARE rule_row record;
BEGIN
    IF TG_OP = 'INSERT' AND (NEW.status <> 'REQUESTED' OR NEW.completed_at IS NOT NULL OR NEW.cancelled_at IS NOT NULL
       OR NEW.cancelled_by IS NOT NULL OR NEW.cancellation_reason IS NOT NULL) THEN
        RAISE EXCEPTION 'Bill-payment requests must start REQUESTED' USING ERRCODE = '23514';
    END IF;
    SELECT * INTO rule_row FROM bill_payment_fee_rules WHERE id = NEW.fee_rule_id;
    IF rule_row.id IS NULL OR rule_row.currency_id <> NEW.currency_id OR rule_row.fixed_fee <> NEW.service_fee
       OR NEW.requested_at < rule_row.effective_from
       OR (rule_row.effective_until IS NOT NULL AND NEW.requested_at >= rule_row.effective_until) THEN
        RAISE EXCEPTION 'Bill-payment request must reference its exact applicable fee rule' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_request_fee_source_guard BEFORE INSERT OR UPDATE ON order_bill_payment_requests
FOR EACH ROW EXECUTE FUNCTION verify_bill_payment_request_fee_source();

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
       OR request_row.fee_rule_id <> NEW.fee_rule_id OR request_row.service_fee <> NEW.service_fee
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
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_bill_payment_event(
    expected_source_type text, expected_source_id text, expected_event_type text,
    expected_currency_id bigint, expected_branch_id bigint, expected_captain_id bigint,
    expected_occurred_at timestamptz, expected_kind text, expected_direction text, expected_amount numeric
) RETURNS void AS $$
DECLARE matches bigint;
BEGIN
    SELECT count(*) INTO matches
    FROM financial_transactions t
    JOIN financial_entries e ON e.financial_transaction_id = t.id
    JOIN financial_accounts a ON a.id = e.financial_account_id
    JOIN financial_owners o ON o.id = a.financial_owner_id
    WHERE t.source_type = expected_source_type AND t.source_id = expected_source_id
      AND t.event_type = expected_event_type AND t.source_version = 1
      AND t.transaction_kind = 'EVENT' AND t.status = 'POSTED' AND t.posted_at IS NOT NULL
      AND t.currency_id = expected_currency_id AND t.branch_id = expected_branch_id
      AND t.occurred_at = expected_occurred_at
      AND e.currency_id = expected_currency_id AND e.direction = expected_direction AND e.amount = expected_amount
      AND a.currency_id = expected_currency_id AND a.account_kind = expected_kind
      AND o.owner_type = 'CAPTAIN' AND o.captain_id = expected_captain_id
      AND (SELECT count(*) FROM financial_entries all_entries WHERE all_entries.financial_transaction_id = t.id) = 1;
    IF matches <> 1 THEN
        RAISE EXCEPTION 'Bill-payment financial event % has incorrect source, status, account or amount', expected_event_type USING ERRCODE = '23514';
    END IF;
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
       OR request_row.fee_rule_id <> payment_row.fee_rule_id OR request_row.service_fee <> payment_row.service_fee
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

CREATE OR REPLACE FUNCTION require_complete_bill_payment_source() RETURNS trigger AS $$
BEGIN
    PERFORM validate_completed_bill_payment_at_commit(NEW.id);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER completed_bill_payment_commit_guard
AFTER INSERT ON order_bill_payments DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION require_complete_bill_payment_source();

CREATE OR REPLACE FUNCTION require_exact_bill_payment_event_source() RETURNS trigger AS $$
DECLARE payment_id bigint;
BEGIN
    IF NEW.event_type NOT IN ('BILL_PAYMENT_CASH_COLLECTED', 'BILL_PAYMENT_PRINCIPAL_HANDED_OVER', 'BILL_PAYMENT_SERVICE_FEE_RETAINED')
       AND NEW.source_type NOT IN ('ORDER_BILL_PAYMENT', 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER') THEN
        RETURN NULL;
    END IF;
    IF NEW.source_id !~ '^[0-9]+$' THEN
        RAISE EXCEPTION 'Bill-payment event source ID must be numeric' USING ERRCODE = '23514';
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
CREATE CONSTRAINT TRIGGER bill_payment_event_commit_guard
AFTER INSERT ON financial_transactions DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION require_exact_bill_payment_event_source();

CREATE OR REPLACE FUNCTION verify_bill_payment_evidence_source() RETURNS trigger AS $$
DECLARE payment_row record;
BEGIN
    SELECT * INTO payment_row FROM order_bill_payments WHERE id = NEW.bill_payment_id;
    IF payment_row.id IS NULL OR NEW.storage_path NOT LIKE
        payment_row.order_id::text || '/' || payment_row.request_id::text || '/' || payment_row.id::text || '/%'
        OR NEW.sha256 !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'Bill-payment evidence must belong to its immutable payment source' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_evidence_source_guard BEFORE INSERT ON order_bill_payment_evidence
FOR EACH ROW EXECUTE FUNCTION verify_bill_payment_evidence_source();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment financial integrity is intentionally forward-only.');
    }
};
