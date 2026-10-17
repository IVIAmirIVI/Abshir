<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Shared bill payments require PostgreSQL.');
        }
        DB::statement('LOCK TABLE orders, order_captain_selections, order_captains, order_bill_payment_requests, order_bill_payments, financial_transactions IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION shared_bill_payment_collector(p_order bigint) RETURNS bigint LANGUAGE plpgsql AS $$
DECLARE sel order_captain_selections%ROWTYPE;
BEGIN
    PERFORM id FROM orders WHERE id=p_order FOR UPDATE;
    SELECT * INTO sel FROM order_captain_selections WHERE order_id=p_order ORDER BY id DESC LIMIT 1;
    IF sel.contract IS DISTINCT FROM 'SHARED_COMPANY_V1' THEN
        RAISE EXCEPTION 'Shared bill payment requires an explicit shared collector selection' USING ERRCODE='23514';
    END IF;
    PERFORM assert_order_captain_selection(p_order);
    RETURN sel.collecting_order_captain_id;
END;
$$;
CREATE FUNCTION shared_bill_payment_collector_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE selected_contract text;
BEGIN
    PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
    SELECT contract INTO selected_contract FROM order_captain_selections WHERE order_id=NEW.order_id ORDER BY id DESC LIMIT 1;
    IF selected_contract='SHARED_COMPANY_V1' THEN
        IF NEW.order_captain_id IS DISTINCT FROM shared_bill_payment_collector(NEW.order_id) THEN
            RAISE EXCEPTION 'Shared bill payment must use the selected collecting Captain' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER shared_bill_payment_collector_valid BEFORE INSERT ON order_bill_payments
FOR EACH ROW EXECUTE FUNCTION shared_bill_payment_collector_guard();
CREATE OR REPLACE FUNCTION selected_shared_financial_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE selected_contract text;
BEGIN
    PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
    SELECT contract INTO selected_contract FROM order_captain_selections WHERE order_id=NEW.order_id ORDER BY id DESC LIMIT 1;
    IF selected_contract='SHARED_COMPANY_V1' THEN
        IF TG_TABLE_NAME='invoices' THEN
            IF NOT EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=NEW.order_id AND purchase_mode='SHARED_TWO_V1') THEN
                RAISE EXCEPTION 'Shared invoice requires confirmed physical purchases first' USING ERRCODE='23514';
            END IF;
        ELSIF TG_TABLE_NAME IN ('order_bill_payment_requests','order_bill_payments') THEN
            PERFORM shared_bill_payment_collector(NEW.order_id);
        ELSIF TG_TABLE_NAME <> 'order_shop_purchase_confirmations' OR to_jsonb(NEW)->>'purchase_mode'='SINGLE_PAYER' THEN
            RAISE EXCEPTION 'Selected shared Order requires the shared financial contract, not legacy settlement' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
SQL);

        // Extend the installed guards, retaining all existing legacy source and mutation checks.
        $this->extend('shared_purchase_legacy_boundary()',
            "    IF EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=parent_id AND purchase_mode='SHARED_TWO_V1')",
            <<<'SQL'
    IF TG_TABLE_NAME IN ('order_bill_payment_requests','order_bill_payments') AND TG_OP<>'DELETE'
       AND (old_parent_id IS NULL OR old_parent_id=parent_id)
       AND (SELECT contract FROM order_captain_selections WHERE order_id=parent_id ORDER BY id DESC LIMIT 1)='SHARED_COMPANY_V1' THEN
        PERFORM shared_bill_payment_collector(parent_id);
        -- Existing request/payment guards still enforce delivery, immutable facts, fee/evidence and the exact event set.
        RETURN NEW;
    END IF;
SQL);
        $this->extend('order_financial_event_source_order_id(financial_transactions)',
            '    o_id:=mc4_previous_order_event_source_order_id(p_transaction);',
            '',
            <<<'SQL'
    o_id:=mc4_previous_order_event_source_order_id(p_transaction);
    IF o_id IS NOT NULL AND p_transaction.event_type IN ('BILL_PAYMENT_CASH_COLLECTED','BILL_PAYMENT_PRINCIPAL_HANDED_OVER','BILL_PAYMENT_SERVICE_FEE_RETAINED')
       AND (SELECT contract FROM order_captain_selections WHERE order_id=o_id ORDER BY id DESC LIMIT 1)='SHARED_COMPANY_V1' THEN
        -- The previous resolver has already enforced the canonical source type, version and authoritative Order.
        IF p_transaction.event_type='BILL_PAYMENT_PRINCIPAL_HANDED_OVER' THEN
            IF NOT EXISTS(SELECT 1 FROM order_bill_payment_principal_handovers h JOIN order_bill_payments p ON p.id=h.bill_payment_id
                WHERE h.id=p_transaction.source_id::bigint AND p.order_id=o_id AND p.order_captain_id=shared_bill_payment_collector(o_id)) THEN
                RAISE EXCEPTION 'Shared bill handover has no authoritative collector payment' USING ERRCODE='23514';
            END IF;
        ELSIF NOT EXISTS(SELECT 1 FROM order_bill_payments p WHERE p.id=p_transaction.source_id::bigint
            AND p.order_id=o_id AND p.order_captain_id=shared_bill_payment_collector(o_id)) THEN
            RAISE EXCEPTION 'Shared bill event has no authoritative collector payment' USING ERRCODE='23514';
        END IF;
        RETURN o_id;
    END IF;
SQL);
        // The extended resolver now acquires the parent lock through the collector validator.
        DB::statement('ALTER FUNCTION order_financial_event_source_order_id(financial_transactions) VOLATILE');
        $this->extend('shared_shop_purchase_validate()',
            '       OR EXISTS (SELECT 1 FROM order_bill_payment_requests WHERE order_id=NEW.order_id)', '',
            <<<'SQL'
       OR EXISTS (SELECT 1 FROM order_bill_payments WHERE order_id=NEW.order_id)
       OR (EXISTS (SELECT 1 FROM order_bill_payment_requests WHERE order_id=NEW.order_id)
           AND (SELECT contract FROM order_captain_selections WHERE order_id=NEW.order_id ORDER BY id DESC LIMIT 1) IS DISTINCT FROM 'SHARED_COMPANY_V1')
SQL);
    }

    private function extend(string $signature, string $anchor, string $before, ?string $replacement = null): void
    {
        $definition = DB::scalar('SELECT pg_get_functiondef(to_regprocedure(?))', [$signature]);
        if (! is_string($definition) || substr_count($definition, $anchor) !== 1) {
            throw new RuntimeException('Unexpected shared guard definition: '.$signature);
        }
        DB::unprepared(str_replace($anchor, $replacement ?? ($before."\n".$anchor), $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Shared bill payment history is forward-only.');
    }
};
