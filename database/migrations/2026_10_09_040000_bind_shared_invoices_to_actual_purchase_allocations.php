<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Shared invoice source integrity requires PostgreSQL.');
        }
        DB::statement('LOCK TABLE orders, invoices, order_financial_source_snapshots, order_financial_source_snapshot_lines IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION assert_shared_order_invoice_inputs(p_order bigint, p_currency bigint, p_pricing bigint, p_subtotal numeric)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE sel order_captain_selections%ROWTYPE; p order_delivery_pricings%ROWTYPE; places integer; shop record; principal numeric:=0;
BEGIN
    PERFORM id FROM orders WHERE id=p_order FOR UPDATE;
    SELECT * INTO sel FROM order_captain_selections WHERE order_id=p_order ORDER BY id DESC LIMIT 1;
    IF sel.contract IS DISTINCT FROM 'SHARED_COMPANY_V1' THEN
        RAISE EXCEPTION 'Shared invoice requires an explicit immutable participant selection' USING ERRCODE='23514';
    END IF;
    PERFORM assert_order_captain_selection(p_order);
    SELECT decimal_places INTO places FROM currencies WHERE id=p_currency FOR UPDATE;
    SELECT * INTO p FROM order_delivery_pricings WHERE id=p_pricing;
    IF p.id IS NULL OR p.order_id<>p_order OR p.currency_id<>p_currency OR p.superseded_at IS NOT NULL
       OR p.status<>'PRICED' OR p.captain_count_snapshot<>2 OR places IS NULL
       OR (SELECT jsonb_agg(order_captain_id ORDER BY order_captain_id) FROM order_delivery_allocations WHERE order_delivery_pricing_id=p.id) IS DISTINCT FROM sel.assignment_ids
       OR NOT EXISTS (SELECT 1 FROM order_shops WHERE order_id=p_order) THEN
        RAISE EXCEPTION 'Shared invoice pricing/participant source mismatch' USING ERRCODE='23514';
    END IF;
    FOR shop IN SELECT s.id, c.id AS purchase_id, c.purchase_mode, c.currency_id, c.branch_id,
        c.collecting_order_captain_id, c.amount,
        (SELECT sum(round(i.current_quantity*i.actual_unit_price, places)) FROM order_items i WHERE i.order_shop_id=s.id) AS item_total,
        EXISTS(SELECT 1 FROM order_items i WHERE i.order_shop_id=s.id AND (i.actual_unit_price IS NULL OR i.actual_currency_id IS DISTINCT FROM p_currency OR i.actual_price_confirmed_at IS NULL OR i.actual_price_confirmed_by IS NULL)) AS invalid_items
        FROM order_shops s LEFT JOIN order_shop_purchase_confirmations c ON c.order_shop_id=s.id WHERE s.order_id=p_order ORDER BY s.id
    LOOP
        IF shop.purchase_id IS NULL OR shop.purchase_mode IS DISTINCT FROM 'SHARED_TWO_V1'
           OR shop.currency_id IS DISTINCT FROM p_currency OR shop.branch_id IS DISTINCT FROM sel.branch_id
           OR shop.collecting_order_captain_id IS DISTINCT FROM sel.collecting_order_captain_id
           OR shop.item_total IS DISTINCT FROM shop.amount OR shop.invalid_items
           OR (SELECT jsonb_agg(order_captain_id ORDER BY order_captain_id) FROM order_shop_purchase_allocations WHERE purchase_confirmation_id=shop.purchase_id) IS DISTINCT FROM sel.assignment_ids THEN
            RAISE EXCEPTION 'Shared invoice requires complete matching physical purchases on every Shop' USING ERRCODE='23514';
        END IF;
        PERFORM assert_shared_shop_purchase_complete(shop.purchase_id);
        principal:=principal+shop.amount;
    END LOOP;
    IF principal IS DISTINCT FROM p_subtotal THEN
        RAISE EXCEPTION 'Shared invoice product principal must equal actual purchases once' USING ERRCODE='23514';
    END IF;
END;
$$;
CREATE OR REPLACE FUNCTION validate_financial_snapshot(p_snapshot_id bigint) RETURNS void AS $$
DECLARE
    s order_financial_source_snapshots%ROWTYPE;
    i invoices%ROWTYPE;
    p order_delivery_pricings%ROWTYPE;
    o orders%ROWTYPE;
    precision_digits integer;
    line_count integer;
    active_count integer;
    primary_count integer;
    product_total numeric;
    delivery_total numeric;
    bad_line bigint;
    ln record;
    ordinal integer := 0;
    minor_total numeric;
    minor_base numeric;
    minor_remainder numeric;
    expected_share numeric;
BEGIN
    SELECT * INTO s FROM order_financial_source_snapshots WHERE id = p_snapshot_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'Financial snapshot % is missing', p_snapshot_id USING ERRCODE = '23514'; END IF;
    SELECT * INTO i FROM invoices WHERE id = s.invoice_id;
    SELECT * INTO o FROM orders WHERE id = s.order_id;
    SELECT * INTO p FROM order_delivery_pricings WHERE id = s.order_delivery_pricing_id;
    SELECT decimal_places INTO precision_digits FROM currencies WHERE id = s.currency_id;
    IF i.id IS NULL OR o.id IS NULL OR p.id IS NULL OR precision_digits IS NULL
       OR i.order_id <> s.order_id OR i.version <> s.invoice_version
       OR (i.status <> 'ISSUED' AND NOT EXISTS (SELECT 1 FROM invoice_financial_corrections c WHERE c.original_invoice_id=i.id AND c.state='COMPLETED')) OR i.issued_at IS DISTINCT FROM s.issued_at
       OR i.currency_id <> s.currency_id OR i.source_order_delivery_pricing_id <> s.order_delivery_pricing_id
       OR i.items_subtotal IS DISTINCT FROM s.items_subtotal
       OR o.branch_id <> s.branch_id OR p.order_id <> s.order_id OR p.currency_id <> s.currency_id
       OR i.delivery_total IS DISTINCT FROM p.customer_delivery_total
       OR precision_digits NOT BETWEEN 0 AND 4
       OR s.items_subtotal::text IN ('NaN', 'Infinity', '-Infinity')
       OR s.items_subtotal < 0 OR s.items_subtotal <> round(s.items_subtotal, precision_digits) THEN
        RAISE EXCEPTION 'Financial snapshot % Order % Invoice % has mismatched source/header money', s.id, s.order_id, s.invoice_id USING ERRCODE = '23514';
    END IF;

    SELECT count(*), coalesce(sum(product_funding_share), 0), coalesce(sum(customer_delivery_amount_allocated), 0),
           count(*) FILTER (WHERE is_primary)
      INTO line_count, product_total, delivery_total, primary_count
      FROM order_financial_source_snapshot_lines WHERE order_financial_source_snapshot_id = s.id;
    SELECT count(*) INTO active_count FROM order_captains WHERE order_id = s.order_id AND removed_at IS NULL;
    IF line_count = 0 OR line_count <> p.captain_count_snapshot OR line_count <> active_count
       OR primary_count <> 1 OR product_total <> s.items_subtotal
       OR delivery_total <> i.delivery_total THEN
        RAISE EXCEPTION 'Financial snapshot % Order % Invoice % has unreconciled participants or totals', s.id, s.order_id, s.invoice_id USING ERRCODE = '23514';
    END IF;

    SELECT l.id INTO bad_line FROM order_financial_source_snapshot_lines l
      LEFT JOIN order_captains oc ON oc.id = l.order_captain_id
      LEFT JOIN order_delivery_allocations a ON a.order_captain_id = l.order_captain_id
        AND a.order_delivery_pricing_id = s.order_delivery_pricing_id
      WHERE l.order_financial_source_snapshot_id = s.id AND (
          oc.id IS NULL OR oc.order_id <> s.order_id OR oc.removed_at IS NOT NULL
          OR oc.captain_id <> l.captain_id
          OR l.is_primary IS DISTINCT FROM (oc.assignment_role = 'PRIMARY_COLLECTION')
          OR (l.is_primary AND l.order_captain_id <> s.primary_order_captain_id)
          OR a.id IS NULL OR a.captain_id <> l.captain_id OR a.currency_id <> s.currency_id
          OR a.captain_entitlement IS DISTINCT FROM l.captain_entitlement
          OR a.customer_delivery_amount_allocated IS DISTINCT FROM l.customer_delivery_amount_allocated
          OR a.company_collectible IS DISTINCT FROM l.company_collectible
          OR a.captain_shortfall IS DISTINCT FROM l.captain_shortfall
          OR l.product_funding_share::text IN ('NaN', 'Infinity', '-Infinity')
          OR l.captain_entitlement::text IN ('NaN', 'Infinity', '-Infinity')
          OR l.customer_delivery_amount_allocated::text IN ('NaN', 'Infinity', '-Infinity')
          OR l.company_collectible::text IN ('NaN', 'Infinity', '-Infinity')
          OR l.captain_shortfall::text IN ('NaN', 'Infinity', '-Infinity')
          OR l.product_funding_share <> round(l.product_funding_share, precision_digits)
          OR l.captain_entitlement <> round(l.captain_entitlement, precision_digits)
          OR l.customer_delivery_amount_allocated <> round(l.customer_delivery_amount_allocated, precision_digits)
          OR l.company_collectible <> round(l.company_collectible, precision_digits)
          OR l.captain_shortfall <> round(l.captain_shortfall, precision_digits)
      ) LIMIT 1;
    IF bad_line IS NOT NULL THEN
        RAISE EXCEPTION 'Financial snapshot % Order % Invoice % has invalid line %', s.id, s.order_id, s.invoice_id, bad_line USING ERRCODE = '23514';
    END IF;
    IF EXISTS (
        SELECT 1 FROM order_captains oc WHERE oc.order_id = s.order_id AND oc.removed_at IS NULL
          AND NOT EXISTS (SELECT 1 FROM order_financial_source_snapshot_lines l
              WHERE l.order_financial_source_snapshot_id = s.id AND l.order_captain_id = oc.id)
    ) OR NOT EXISTS (
        SELECT 1 FROM order_financial_source_snapshot_lines l JOIN order_captains oc ON oc.id = l.order_captain_id
          WHERE l.order_financial_source_snapshot_id = s.id AND l.is_primary
            AND l.order_captain_id = s.primary_order_captain_id AND oc.assignment_role = 'PRIMARY_COLLECTION'
    ) THEN
        RAISE EXCEPTION 'Financial snapshot % Order % Invoice % has invalid PRIMARY_COLLECTION or participants', s.id, s.order_id, s.invoice_id USING ERRCODE = '23514';
    END IF;

    minor_total := s.items_subtotal * power(10::numeric, precision_digits);
    minor_base := trunc(minor_total / line_count);
    minor_remainder := mod(minor_total, line_count);
    FOR ln IN SELECT * FROM order_financial_source_snapshot_lines
        WHERE order_financial_source_snapshot_id = s.id ORDER BY order_captain_id LOOP
        ordinal := ordinal + 1;
        IF EXISTS (SELECT 1 FROM order_captain_selections WHERE order_id=s.order_id AND contract='SHARED_COMPANY_V1') THEN
            PERFORM assert_shared_order_invoice_inputs(s.order_id, s.currency_id, s.order_delivery_pricing_id, s.items_subtotal);
            SELECT sum(a.amount) INTO expected_share FROM order_shop_purchase_allocations a
                JOIN order_shop_purchase_confirmations c ON c.id=a.purchase_confirmation_id
                WHERE c.order_id=s.order_id AND a.order_captain_id=ln.order_captain_id AND c.purchase_mode='SHARED_TWO_V1';
        ELSE
            expected_share := (minor_base + CASE WHEN ordinal <= minor_remainder THEN 1 ELSE 0 END)
                / power(10::numeric, precision_digits);
        END IF;
        IF ln.product_funding_share IS DISTINCT FROM expected_share THEN
            RAISE EXCEPTION 'Financial snapshot % Order % Invoice % has incorrect product split on line %', s.id, s.order_id, s.invoice_id, ln.id USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION shared_purchase_legacy_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE row_data jsonb; parent_id bigint; old_parent_id bigint;
BEGIN
    IF TG_OP='DELETE' THEN row_data:=to_jsonb(OLD); ELSE row_data:=to_jsonb(NEW); END IF;
    IF TG_TABLE_NAME='orders' THEN
        parent_id:=(row_data->>'id')::bigint;
    ELSIF TG_TABLE_NAME='order_items' THEN
        SELECT order_id INTO parent_id FROM order_shops WHERE id=(row_data->>'order_shop_id')::bigint;
    ELSE
        parent_id:=(row_data->>'order_id')::bigint;
    END IF;
    IF TG_OP='UPDATE' THEN
        IF TG_TABLE_NAME='order_items' THEN
            SELECT order_id INTO old_parent_id FROM order_shops WHERE id=OLD.order_shop_id;
        ELSIF TG_TABLE_NAME='orders' THEN
            old_parent_id:=OLD.id;
        ELSE
            old_parent_id:=(to_jsonb(OLD)->>'order_id')::bigint;
        END IF;
    END IF;
    IF old_parent_id IS NOT NULL THEN
        PERFORM id FROM orders WHERE id IN (parent_id, old_parent_id) ORDER BY id FOR UPDATE;
    ELSE
        PERFORM id FROM orders WHERE id=parent_id FOR UPDATE;
    END IF;
    IF EXISTS (SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=parent_id AND purchase_mode='SHARED_TWO_V1')
       OR (TG_OP='UPDATE' AND EXISTS (
            SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=old_parent_id AND purchase_mode='SHARED_TWO_V1'
       )) THEN
        IF TG_TABLE_NAME='invoices' AND TG_OP<>'DELETE' THEN
            IF NOT EXISTS (SELECT 1 FROM order_captain_selections WHERE order_id=NEW.order_id AND contract='SHARED_COMPANY_V1') THEN
                RAISE EXCEPTION 'Shared purchase settlement and refund contract is not enabled' USING ERRCODE='23514';
            END IF;
            IF TG_OP='UPDATE' AND NEW.order_id IS DISTINCT FROM OLD.order_id THEN
                RAISE EXCEPTION 'Shared invoice cannot change its Order' USING ERRCODE='23514';
            END IF;
            IF NEW.status NOT IN ('DRAFT','READY','ISSUED') THEN
                RAISE EXCEPTION 'Shared invoice correction and refund contract is not enabled' USING ERRCODE='23514';
            END IF;
            IF NEW.status<>'DRAFT' THEN
                PERFORM assert_shared_order_invoice_inputs(NEW.order_id, NEW.currency_id, NEW.source_order_delivery_pricing_id, NEW.items_subtotal);
                IF NEW.grand_total IS DISTINCT FROM NEW.items_subtotal+NEW.delivery_total
                   OR NEW.delivery_total IS DISTINCT FROM (SELECT customer_delivery_total FROM order_delivery_pricings WHERE id=NEW.source_order_delivery_pricing_id) THEN
                    RAISE EXCEPTION 'Shared invoice totals disagree with authoritative sources' USING ERRCODE='23514';
                END IF;
            END IF;
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'Shared purchase settlement and refund contract is not enabled' USING ERRCODE='23514';
    END IF;
    IF TG_OP='DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END;
$$;

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
        ELSIF TG_TABLE_NAME <> 'order_shop_purchase_confirmations' OR to_jsonb(NEW)->>'purchase_mode'='SINGLE_PAYER' THEN
            RAISE EXCEPTION 'Selected shared Order requires the shared financial contract, not legacy settlement' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE FUNCTION shared_invoice_collection_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
    IF EXISTS (SELECT 1 FROM order_captain_selections WHERE order_id=NEW.order_id AND contract='SHARED_COMPANY_V1') THEN
        RAISE EXCEPTION 'Shared collection requires the gross custody and receivable posting contract' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER shared_invoice_collection_boundary BEFORE INSERT ON invoice_collections FOR EACH ROW EXECUTE FUNCTION shared_invoice_collection_boundary();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Shared Invoice source history is forward-only.');
    }
};
