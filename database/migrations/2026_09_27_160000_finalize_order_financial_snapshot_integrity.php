<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Final financial snapshot integrity requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            // Keep the scan and the schema change atomic with concurrent snapshot inserts.
            DB::statement('LOCK TABLE order_financial_source_snapshots, order_financial_source_snapshot_lines IN ACCESS EXCLUSIVE MODE');
            $existing = DB::table('order_financial_source_snapshots')
                ->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                $details = $existing->map(function ($row): string {
                    $reasons = [];
                    foreach (['validate_financial_snapshot', 'verify_financial_snapshot_allocation_set'] as $validator) {
                        try {
                            // A validator error must not prevent checking the remaining rows.
                            DB::transaction(fn () => DB::select("SELECT {$validator}(?)", [$row->id]));
                        } catch (Throwable $exception) {
                            $reasons[] = $validator.': '.strtok($exception->getMessage(), "\n");
                        }
                    }
                    try {
                        if (! hash_equals($row->payload_hash, $this->legacyHash($row))) {
                            $reasons[] = 'pre-final payload fingerprint mismatch';
                        }
                    } catch (Throwable $exception) {
                        $reasons[] = 'pre-final payload fingerprint cannot be reconstructed: '.strtok($exception->getMessage(), "\n");
                    }
                    $reasons[] = 'the pre-final snapshot did not freeze Currency.decimal_places; its historical precision cannot be proven from immutable data';

                    return "snapshot_id={$row->id}, order_id={$row->order_id}, invoice_id={$row->invoice_id}: ".implode('; ', $reasons);
                })->implode(' | ');
                throw new RuntimeException('Financial snapshot migration requires deliberate historical correction: '.$details);
            }

            DB::unprepared(<<<'SQL'
ALTER TABLE order_financial_source_snapshots
    ADD COLUMN currency_decimal_places smallint NOT NULL,
    ADD COLUMN fingerprint_version smallint NOT NULL DEFAULT 2,
    ADD CONSTRAINT financial_snapshot_precision_valid CHECK (currency_decimal_places BETWEEN 0 AND 4),
    ADD CONSTRAINT financial_snapshot_fingerprint_version_valid CHECK (fingerprint_version = 2);

-- PostgreSQL owns the serialization. Both application creation and deferred
-- validation call this one contract; PHP never implements a second encoder.
CREATE OR REPLACE FUNCTION financial_order_snapshot_canonical_payload(p_header jsonb, p_lines jsonb)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE normalized_lines jsonb;
BEGIN
    IF jsonb_typeof(p_header) <> 'object' OR jsonb_typeof(p_lines) <> 'array' THEN
        RAISE EXCEPTION 'Financial snapshot fingerprint requires a header and ordered lines' USING ERRCODE = '23514';
    END IF;

    SELECT coalesce(jsonb_agg(jsonb_build_object(
        'order_captain_id', (line.value->>'order_captain_id')::bigint,
        'captain_id', (line.value->>'captain_id')::bigint,
        'is_primary', (line.value->>'is_primary')::boolean,
        'role', CASE WHEN (line.value->>'is_primary')::boolean THEN 'PRIMARY_COLLECTION' ELSE 'SUPPORT' END,
        'product_funding_share', round((line.value->>'product_funding_share')::numeric, 4)::text,
        'customer_delivery_amount_allocated', round((line.value->>'customer_delivery_amount_allocated')::numeric, 4)::text,
        'captain_entitlement', round((line.value->>'captain_entitlement')::numeric, 4)::text,
        'company_collectible', round((line.value->>'company_collectible')::numeric, 4)::text,
        'captain_shortfall', round((line.value->>'captain_shortfall')::numeric, 4)::text
    ) ORDER BY (line.value->>'order_captain_id')::bigint), '[]'::jsonb)
      INTO normalized_lines FROM jsonb_array_elements(p_lines) AS line(value);

    RETURN jsonb_build_object(
        'fingerprint_version', (p_header->>'fingerprint_version')::smallint,
        'order_id', (p_header->>'order_id')::bigint,
        'invoice_id', (p_header->>'invoice_id')::bigint,
        'invoice_version', (p_header->>'invoice_version')::integer,
        'branch_id', (p_header->>'branch_id')::bigint,
        'currency_id', (p_header->>'currency_id')::bigint,
        'currency_decimal_places', (p_header->>'currency_decimal_places')::smallint,
        'issued_at', to_char((p_header->>'issued_at')::timestamptz AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US') || '+00:00',
        'items_subtotal', round((p_header->>'items_subtotal')::numeric, 4)::text,
        'source_order_delivery_pricing_id', (p_header->>'order_delivery_pricing_id')::bigint,
        'primary_order_captain_id', (p_header->>'primary_order_captain_id')::bigint,
        'primary_captain_id', (p_header->>'primary_captain_id')::bigint,
        'captain_allocations', normalized_lines
    )::text;
END;
$$;

CREATE OR REPLACE FUNCTION financial_order_snapshot_hash_parts(p_header jsonb, p_lines jsonb)
RETURNS text LANGUAGE sql STABLE AS $$
    SELECT encode(sha256(convert_to(financial_order_snapshot_canonical_payload(p_header, p_lines), 'UTF8')), 'hex');
$$;

CREATE OR REPLACE FUNCTION financial_order_snapshot_expected_hash(p_snapshot_id bigint)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE s order_financial_source_snapshots%ROWTYPE;
    primary_captain bigint;
    line_payload jsonb;
BEGIN
    SELECT * INTO s FROM order_financial_source_snapshots WHERE id = p_snapshot_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Financial snapshot % is missing', p_snapshot_id USING ERRCODE = '23514';
    END IF;
    SELECT captain_id INTO primary_captain FROM order_financial_source_snapshot_lines
        WHERE order_financial_source_snapshot_id = p_snapshot_id AND is_primary;
    SELECT coalesce(jsonb_agg(jsonb_build_object(
        'order_captain_id', order_captain_id,
        'captain_id', captain_id,
        'is_primary', is_primary,
        'product_funding_share', product_funding_share,
        'customer_delivery_amount_allocated', customer_delivery_amount_allocated,
        'captain_entitlement', captain_entitlement,
        'company_collectible', company_collectible,
        'captain_shortfall', captain_shortfall
    ) ORDER BY order_captain_id), '[]'::jsonb)
      INTO line_payload FROM order_financial_source_snapshot_lines
      WHERE order_financial_source_snapshot_id = p_snapshot_id;

    RETURN financial_order_snapshot_hash_parts(jsonb_build_object(
        'fingerprint_version', s.fingerprint_version,
        'order_id', s.order_id,
        'invoice_id', s.invoice_id,
        'invoice_version', s.invoice_version,
        'branch_id', s.branch_id,
        'currency_id', s.currency_id,
        'currency_decimal_places', s.currency_decimal_places,
        'issued_at', s.issued_at,
        'items_subtotal', s.items_subtotal,
        'order_delivery_pricing_id', s.order_delivery_pricing_id,
        'primary_order_captain_id', s.primary_order_captain_id,
        'primary_captain_id', primary_captain
    ), line_payload);
END;
$$;

CREATE OR REPLACE FUNCTION validate_financial_snapshot_fingerprint(p_snapshot_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE s order_financial_source_snapshots%ROWTYPE;
    current_precision integer;
    expected_hash text;
BEGIN
    PERFORM validate_financial_snapshot(p_snapshot_id);
    PERFORM verify_financial_snapshot_allocation_set(p_snapshot_id);
    SELECT * INTO s FROM order_financial_source_snapshots WHERE id = p_snapshot_id;
    SELECT decimal_places INTO current_precision FROM currencies WHERE id = s.currency_id;
    IF s.fingerprint_version <> 2 OR s.currency_decimal_places IS DISTINCT FROM current_precision THEN
        RAISE EXCEPTION 'Financial snapshot % Order % Invoice % has invalid frozen Currency precision or fingerprint version',
            s.id, s.order_id, s.invoice_id USING ERRCODE = '23514';
    END IF;
    expected_hash := financial_order_snapshot_expected_hash(p_snapshot_id);
    IF s.payload_hash IS DISTINCT FROM expected_hash THEN
        RAISE EXCEPTION 'Financial snapshot % Order % Invoice % payload fingerprint mismatch',
            s.id, s.order_id, s.invoice_id USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION validate_financial_snapshot_fingerprint_insert() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'order_financial_source_snapshots' THEN
        PERFORM validate_financial_snapshot_fingerprint(NEW.id);
    ELSE
        PERFORM validate_financial_snapshot_fingerprint(NEW.order_financial_source_snapshot_id);
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER financial_snapshot_fingerprint_valid
    AFTER INSERT ON order_financial_source_snapshots DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION validate_financial_snapshot_fingerprint_insert();
CREATE CONSTRAINT TRIGGER financial_snapshot_line_fingerprint_valid
    AFTER INSERT ON order_financial_source_snapshot_lines DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION validate_financial_snapshot_fingerprint_insert();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Final financial snapshot integrity is intentionally forward-only.');
    }

    private function legacyHash(object $snapshot): string
    {
        $money = fn ($value): string => (string) BigDecimal::of($value)->toScale(4, RoundingMode::Unnecessary);
        $lines = DB::table('order_financial_source_snapshot_lines')
            ->where('order_financial_source_snapshot_id', $snapshot->id)
            ->orderBy('order_captain_id')->get()->map(fn ($line) => [
                'order_captain_id' => (int) $line->order_captain_id,
                'captain_id' => (int) $line->captain_id,
                'is_primary' => (bool) $line->is_primary,
                'product_funding_share' => $money($line->product_funding_share),
                'captain_entitlement' => $money($line->captain_entitlement),
                'customer_delivery_amount_allocated' => $money($line->customer_delivery_amount_allocated),
                'company_collectible' => $money($line->company_collectible),
                'captain_shortfall' => $money($line->captain_shortfall),
            ])->all();
        $payload = [
            'invoice_id' => (int) $snapshot->invoice_id,
            'invoice_version' => (int) $snapshot->invoice_version,
            'order_id' => (int) $snapshot->order_id,
            'branch_id' => (int) $snapshot->branch_id,
            'issued_at' => CarbonImmutable::parse($snapshot->issued_at)->utc()->format('Y-m-d\TH:i:s.uP'),
            'order_delivery_pricing_id' => (int) $snapshot->order_delivery_pricing_id,
            'currency_id' => (int) $snapshot->currency_id,
            'primary_order_captain_id' => (int) $snapshot->primary_order_captain_id,
            'items_subtotal' => $money($snapshot->items_subtotal),
            'captain_allocations' => $lines,
        ];
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$entry) {
                $entry = $canonical($entry);
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($payload), JSON_THROW_ON_ERROR));
    }
};
