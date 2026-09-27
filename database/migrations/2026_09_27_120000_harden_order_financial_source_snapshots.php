<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Financial source snapshot hardening requires PostgreSQL.');
        }

        // The original rows lack immutable branch/issue-time evidence. Neither value can
        // safely be reconstructed from business rows that may have changed since issue.
        $problems = [];
        foreach (DB::table('order_financial_source_snapshots')->orderBy('id')->get() as $existing) {
            $reasons = ['Branch/issued_at and normalized participant lines were not historically frozen'];
            $invoice = DB::table('invoices')->where('id', $existing->invoice_id)->first();
            $pricing = DB::table('order_delivery_pricings')->where('id', $existing->order_delivery_pricing_id)->first();
            $currency = DB::table('currencies')->where('id', $existing->currency_id)->first();
            if (! $invoice || $invoice->order_id !== $existing->order_id || $invoice->version !== $existing->invoice_version
                || $invoice->currency_id !== $existing->currency_id
                || $invoice->source_order_delivery_pricing_id !== $existing->order_delivery_pricing_id
                || ! $pricing || $pricing->order_id !== $existing->order_id || $pricing->currency_id !== $existing->currency_id) {
                $reasons[] = 'cross-record Invoice/Pricing ownership mismatch';
            }
            if (! $currency || in_array((string) $existing->items_subtotal, ['NaN', 'Infinity', '-Infinity'], true)) {
                $reasons[] = 'non-finite amount or missing Currency';
            } else {
                try {
                    BigDecimal::of($existing->items_subtotal)->toScale($currency->decimal_places, RoundingMode::Unnecessary);
                } catch (Throwable) {
                    $reasons[] = 'items_subtotal exceeds Currency precision';
                }
            }
            $rows = json_decode($existing->captain_allocations, true);
            if (! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
                $reasons[] = 'malformed participant allocation JSON';
            } else {
                $captainIds = [];
                $assignmentIds = [];
                $product = BigDecimal::zero();
                $delivery = BigDecimal::zero();
                $primaryCount = 0;
                foreach ($rows as $row) {
                    if (! is_array($row) || ! isset($row['order_captain_id'], $row['captain_id'], $row['product_funding_share'], $row['customer_delivery_amount_allocated'], $row['captain_entitlement'], $row['company_collectible'], $row['captain_shortfall'], $row['is_primary'])) {
                        $reasons[] = 'missing participant money or identity';
                        break;
                    }
                    $assignmentIds[] = $row['order_captain_id'];
                    $captainIds[] = $row['captain_id'];
                    $primaryCount += $row['is_primary'] === true ? 1 : 0;
                    $assignment = DB::table('order_captains')->where('id', $row['order_captain_id'])->first();
                    $allocation = DB::table('order_delivery_allocations')->where('order_delivery_pricing_id', $existing->order_delivery_pricing_id)
                        ->where('order_captain_id', $row['order_captain_id'])->first();
                    if (! $assignment || $assignment->order_id !== $existing->order_id || $assignment->captain_id !== $row['captain_id']
                        || ! $allocation || $allocation->captain_id !== $row['captain_id']) {
                        $reasons[] = 'participant/source mismatch';
                    } elseif ($allocation->captain_entitlement !== $row['captain_entitlement']
                        || $allocation->customer_delivery_amount_allocated !== $row['customer_delivery_amount_allocated']
                        || $allocation->company_collectible !== $row['company_collectible']
                        || $allocation->captain_shortfall !== $row['captain_shortfall']) {
                        $reasons[] = 'participant financial source mismatch';
                    }
                    try {
                        foreach (['product_funding_share', 'customer_delivery_amount_allocated', 'captain_entitlement', 'company_collectible', 'captain_shortfall'] as $key) {
                            BigDecimal::of($row[$key])->toScale($currency?->decimal_places ?? 4, RoundingMode::Unnecessary);
                        }
                        $product = $product->plus($row['product_funding_share']);
                        $delivery = $delivery->plus($row['customer_delivery_amount_allocated']);
                    } catch (Throwable) {
                        $reasons[] = 'non-finite or excess-precision participant money';
                    }
                }
                if (count($captainIds) !== count(array_unique($captainIds)) || count($assignmentIds) !== count(array_unique($assignmentIds))
                    || $primaryCount !== 1 || ! in_array($existing->primary_order_captain_id, $assignmentIds, true)) {
                    $reasons[] = 'duplicate or invalid PRIMARY_COLLECTION participant';
                }
                try {
                    $reconciled = $product->isEqualTo(BigDecimal::of($existing->items_subtotal))
                        && $invoice && $invoice->delivery_total !== null
                        && $delivery->isEqualTo(BigDecimal::of($invoice->delivery_total));
                } catch (Throwable) {
                    $reconciled = false;
                }
                if (! $reconciled) {
                    $reasons[] = 'product split or delivery allocation does not reconcile';
                }
            }
            $problems[] = "Snapshot {$existing->id}, Order {$existing->order_id}, Invoice {$existing->invoice_id}: ".implode('; ', array_unique($reasons));
        }
        if ($problems !== []) {
            throw new RuntimeException('Existing financial snapshots require deliberate historical correction: '.implode(' | ', $problems));
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE order_financial_source_snapshots DROP CONSTRAINT order_financial_snapshot_valid;
ALTER TABLE order_financial_source_snapshots DROP COLUMN captain_allocations;
ALTER TABLE order_financial_source_snapshots ALTER COLUMN items_subtotal TYPE numeric;
ALTER TABLE order_financial_source_snapshots ADD COLUMN branch_id bigint NOT NULL REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE order_financial_source_snapshots ADD COLUMN issued_at timestamptz NOT NULL;
ALTER TABLE order_financial_source_snapshots ADD CONSTRAINT order_financial_snapshot_amount_valid
    CHECK (items_subtotal::text NOT IN ('NaN', 'Infinity', '-Infinity') AND items_subtotal >= 0);
ALTER TABLE invoices ALTER COLUMN issued_at TYPE timestamptz(6);
ALTER TABLE invoice_collections ALTER COLUMN collected_at TYPE timestamptz(6);
ALTER TABLE order_status_history ALTER COLUMN changed_at TYPE timestamptz(6);
ALTER TABLE financial_transactions ALTER COLUMN occurred_at TYPE timestamptz(6);
ALTER TABLE order_financial_source_snapshots ALTER COLUMN issued_at TYPE timestamptz(6);

CREATE TABLE order_financial_source_snapshot_lines (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    order_financial_source_snapshot_id bigint NOT NULL REFERENCES order_financial_source_snapshots(id) ON DELETE RESTRICT,
    order_captain_id bigint NOT NULL REFERENCES order_captains(id) ON DELETE RESTRICT,
    captain_id bigint NOT NULL REFERENCES captains(id) ON DELETE RESTRICT,
    is_primary boolean NOT NULL,
    product_funding_share numeric NOT NULL,
    captain_entitlement numeric NOT NULL,
    customer_delivery_amount_allocated numeric NOT NULL,
    company_collectible numeric NOT NULL,
    captain_shortfall numeric NOT NULL,
    CONSTRAINT financial_snapshot_line_one_assignment UNIQUE (order_financial_source_snapshot_id, order_captain_id),
    CONSTRAINT financial_snapshot_line_one_captain UNIQUE (order_financial_source_snapshot_id, captain_id),
    CONSTRAINT financial_snapshot_line_money_valid CHECK (
        product_funding_share::text NOT IN ('NaN', 'Infinity', '-Infinity') AND product_funding_share >= 0
        AND captain_entitlement::text NOT IN ('NaN', 'Infinity', '-Infinity') AND captain_entitlement >= 0
        AND customer_delivery_amount_allocated::text NOT IN ('NaN', 'Infinity', '-Infinity') AND customer_delivery_amount_allocated >= 0
        AND company_collectible::text NOT IN ('NaN', 'Infinity', '-Infinity') AND company_collectible >= 0
        AND captain_shortfall::text NOT IN ('NaN', 'Infinity', '-Infinity') AND captain_shortfall >= 0
        AND company_collectible = greatest(customer_delivery_amount_allocated - captain_entitlement, 0)
        AND captain_shortfall = greatest(captain_entitlement - customer_delivery_amount_allocated, 0)
    )
);
CREATE UNIQUE INDEX financial_snapshot_one_primary_idx
    ON order_financial_source_snapshot_lines(order_financial_source_snapshot_id) WHERE is_primary;
CREATE TRIGGER financial_snapshot_line_immutable
    BEFORE UPDATE OR DELETE ON order_financial_source_snapshot_lines
    FOR EACH ROW EXECUTE FUNCTION reject_order_financial_snapshot_mutation();

-- This lock is taken before the source rows are read. It prevents a direct SQL
-- writer (including INSERTs/phantoms) from changing any source table mid-read.
CREATE OR REPLACE FUNCTION lock_financial_snapshot_source(p_order_id bigint) RETURNS void AS $$
BEGIN
    PERFORM 1 FROM orders WHERE id = p_order_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Financial snapshot Order % does not exist', p_order_id USING ERRCODE = '23514'; END IF;
    LOCK TABLE order_delivery_pricings, order_captains, order_delivery_allocations IN SHARE MODE;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION lock_financial_snapshot_insert() RETURNS trigger AS $$
DECLARE target_order bigint;
BEGIN
    IF TG_TABLE_NAME = 'order_financial_source_snapshots' THEN
        target_order := NEW.order_id;
    ELSE
        SELECT order_id INTO target_order FROM order_financial_source_snapshots
            WHERE id = NEW.order_financial_source_snapshot_id;
    END IF;
    PERFORM lock_financial_snapshot_source(target_order);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER financial_snapshot_insert_lock BEFORE INSERT ON order_financial_source_snapshots
    FOR EACH ROW EXECUTE FUNCTION lock_financial_snapshot_insert();
CREATE TRIGGER financial_snapshot_line_insert_lock BEFORE INSERT ON order_financial_source_snapshot_lines
    FOR EACH ROW EXECUTE FUNCTION lock_financial_snapshot_insert();

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
       OR i.status <> 'ISSUED' OR i.issued_at IS DISTINCT FROM s.issued_at
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

CREATE OR REPLACE FUNCTION validate_financial_snapshot_insert() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'order_financial_source_snapshots' THEN
        PERFORM validate_financial_snapshot(NEW.id);
    ELSE
        PERFORM validate_financial_snapshot(NEW.order_financial_source_snapshot_id);
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER financial_snapshot_insert_valid
    AFTER INSERT ON order_financial_source_snapshots DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION validate_financial_snapshot_insert();
CREATE CONSTRAINT TRIGGER financial_snapshot_line_insert_valid
    AFTER INSERT ON order_financial_source_snapshot_lines DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION validate_financial_snapshot_insert();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Financial source snapshot hardening is intentionally forward-only.');
    }
};
