<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Shared purchase remainder integrity requires PostgreSQL.');
        }
        // Forward only: existing immutable allocations are not rewritten or recalculated.
        DB::statement('LOCK TABLE order_shop_purchase_confirmations, order_shop_purchase_allocations IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION shared_shop_purchase_validate() RETURNS trigger LANGUAGE plpgsql AS $$
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
       OR NEW.amount <> round(NEW.amount, places)
       OR NEW.purchased_at > clock_timestamp() OR NEW.purchased_at < o.ordered_at
       OR btrim(NEW.evidence_path)='' OR btrim(NEW.evidence_name)=''
       OR NEW.evidence_mime NOT IN ('image/jpeg','image/png','image/webp')
       OR NEW.evidence_size NOT BETWEEN 1 AND 5242880 OR NEW.evidence_sha256 !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'Invalid shared Shop purchase; exact minor units, two active payers and unsettled Order required' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION shared_shop_purchase_allocation_validate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p order_shop_purchase_confirmations%ROWTYPE; places integer; expected numeric; lowest_assignment bigint;
BEGIN
    SELECT * INTO p FROM order_shop_purchase_confirmations WHERE id=NEW.purchase_confirmation_id;
    PERFORM id FROM orders WHERE id=p.order_id FOR UPDATE;
    SELECT decimal_places INTO places FROM currencies WHERE id=p.currency_id FOR UPDATE;
    SELECT min(id) INTO lowest_assignment FROM order_captains WHERE order_id=p.order_id AND removed_at IS NULL;
    expected := (trunc(p.amount * power(10::numeric, places) / 2)
        + CASE WHEN NEW.order_captain_id=lowest_assignment THEN mod(p.amount * power(10::numeric, places), 2) ELSE 0 END)
        / power(10::numeric, places);
    IF p.purchase_mode IS DISTINCT FROM 'SHARED_TWO_V1' OR places IS NULL
       OR NOT EXISTS (SELECT 1 FROM order_captains WHERE id=NEW.order_captain_id AND order_id=p.order_id AND removed_at IS NULL)
       OR NEW.amount::text IN ('NaN','Infinity','-Infinity') OR NEW.amount<0
       OR NEW.amount <> round(NEW.amount, places) OR NEW.amount IS DISTINCT FROM expected THEN
        RAISE EXCEPTION 'Invalid physical shared purchase allocation' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Approved shared purchase allocations are forward-only.');
    }
};
