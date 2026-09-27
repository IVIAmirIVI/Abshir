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
CREATE OR REPLACE FUNCTION enforce_bill_payment_currency_precision() RETURNS trigger AS $$
DECLARE places integer;
BEGIN
    SELECT decimal_places INTO places FROM currencies WHERE id = NEW.currency_id FOR SHARE;
    IF places IS NULL OR places < 0 OR places > 4 THEN
        RAISE EXCEPTION 'Invalid bill-payment Currency precision' USING ERRCODE = '23514';
    END IF;
    IF TG_TABLE_NAME = 'bill_payment_fee_rules' THEN
        IF NEW.fixed_fee <> ROUND(NEW.fixed_fee, places) THEN
            RAISE EXCEPTION 'Bill-payment fee exceeds Currency precision' USING ERRCODE = '23514';
        END IF;
    ELSIF TG_TABLE_NAME = 'order_bill_payment_requests' THEN
        IF NEW.service_fee <> ROUND(NEW.service_fee, places) THEN
            RAISE EXCEPTION 'Bill-payment request fee exceeds Currency precision' USING ERRCODE = '23514';
        END IF;
    ELSIF TG_TABLE_NAME = 'order_bill_payments' THEN
        IF NEW.principal_amount <> ROUND(NEW.principal_amount, places)
           OR NEW.service_fee <> ROUND(NEW.service_fee, places)
           OR NEW.total_customer_cash <> ROUND(NEW.total_customer_cash, places) THEN
            RAISE EXCEPTION 'Bill-payment amount exceeds Currency precision' USING ERRCODE = '23514';
        END IF;
    ELSIF TG_TABLE_NAME = 'order_bill_payment_principal_handovers' THEN
        IF NEW.principal_amount <> ROUND(NEW.principal_amount, places) THEN
            RAISE EXCEPTION 'Bill-payment handover exceeds Currency precision' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bill_payment_fee_precision BEFORE INSERT OR UPDATE ON bill_payment_fee_rules FOR EACH ROW EXECUTE FUNCTION enforce_bill_payment_currency_precision();
CREATE TRIGGER bill_payment_request_precision BEFORE INSERT OR UPDATE ON order_bill_payment_requests FOR EACH ROW EXECUTE FUNCTION enforce_bill_payment_currency_precision();
CREATE TRIGGER bill_payment_precision BEFORE INSERT OR UPDATE ON order_bill_payments FOR EACH ROW EXECUTE FUNCTION enforce_bill_payment_currency_precision();
CREATE TRIGGER bill_payment_handover_precision BEFORE INSERT OR UPDATE ON order_bill_payment_principal_handovers FOR EACH ROW EXECUTE FUNCTION enforce_bill_payment_currency_precision();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment precision protection is intentionally forward-only.');
    }
};
