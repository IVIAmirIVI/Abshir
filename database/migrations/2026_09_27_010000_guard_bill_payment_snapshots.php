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
CREATE OR REPLACE FUNCTION guard_bill_payment_fee_history() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Bill-payment fee history cannot be deleted' USING ERRCODE = '55000';
    END IF;
    IF (to_jsonb(NEW) - 'effective_until' - 'superseded_by' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'effective_until' - 'superseded_by' - 'updated_at')
       OR (OLD.effective_until IS NOT NULL AND NEW.effective_until IS DISTINCT FROM OLD.effective_until)
       OR (OLD.superseded_by IS NOT NULL AND NEW.superseded_by IS DISTINCT FROM OLD.superseded_by)
       OR (NEW.effective_until IS NOT NULL AND NEW.effective_until <= OLD.effective_from)
       OR (NEW.superseded_by IS NOT NULL AND NEW.effective_until IS NULL) THEN
        RAISE EXCEPTION 'Bill-payment fee facts are immutable; create a version' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_fee_history_guard BEFORE UPDATE OR DELETE ON bill_payment_fee_rules FOR EACH ROW EXECUTE FUNCTION guard_bill_payment_fee_history();

CREATE OR REPLACE FUNCTION guard_bill_payment_request_snapshot() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Bill-payment requests cannot be deleted' USING ERRCODE = '55000';
    END IF;
    IF OLD.status <> 'REQUESTED' OR NEW.status NOT IN ('COMPLETED', 'CANCELLED')
       OR (to_jsonb(NEW) - 'status' - 'completed_at' - 'cancelled_at' - 'cancelled_by' - 'cancellation_reason' - 'updated_at')
          IS DISTINCT FROM
          (to_jsonb(OLD) - 'status' - 'completed_at' - 'cancelled_at' - 'cancelled_by' - 'cancellation_reason' - 'updated_at') THEN
        RAISE EXCEPTION 'Bill-payment request snapshot is immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_request_snapshot_guard BEFORE UPDATE OR DELETE ON order_bill_payment_requests FOR EACH ROW EXECUTE FUNCTION guard_bill_payment_request_snapshot();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment snapshots are intentionally forward-only.');
    }
};
