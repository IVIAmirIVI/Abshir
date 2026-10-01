<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Collected Invoice source snapshot continuity requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoices, order_financial_source_snapshots, invoice_financial_corrections IN ACCESS EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
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
        expected_share := (minor_base + CASE WHEN ordinal <= minor_remainder THEN 1 ELSE 0 END)
            / power(10::numeric, precision_digits);
        IF ln.product_funding_share IS DISTINCT FROM expected_share THEN
            RAISE EXCEPTION 'Financial snapshot % Order % Invoice % has incorrect product split on line %', s.id, s.order_id, s.invoice_id, ln.id USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$ LANGUAGE plpgsql;
SQL);
            foreach (DB::table('order_financial_source_snapshots')->pluck('id') as $id) {
                DB::select('SELECT validate_financial_snapshot_fingerprint(?)', [$id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Invoice snapshot continuity is intentionally forward-only.');
    }
};
