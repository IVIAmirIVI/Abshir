<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Corrected Invoice snapshot integrity requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_correction_financial_snapshots, invoice_financial_corrections, invoices, invoice_items IN ACCESS EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_invoice_correction_snapshot_facts(p_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    s invoice_correction_financial_snapshots%ROWTYPE;
    c invoice_financial_corrections%ROWTYPE;
    original invoices%ROWTYPE;
    replacement invoices%ROWTYPE;
    item_count integer;
    allocation_count integer;
    item_sum numeric;
    delivery_sum numeric;
    product_sum numeric;
    bad_count integer;
BEGIN
    SELECT * INTO s FROM invoice_correction_financial_snapshots WHERE id=p_id;
    IF NOT FOUND THEN RETURN; END IF;
    SELECT * INTO c FROM invoice_financial_corrections WHERE id=s.invoice_financial_correction_id;
    SELECT * INTO original FROM invoices WHERE id=c.original_invoice_id;
    SELECT * INTO replacement FROM invoices WHERE id=c.replacement_invoice_id;
    IF c.id IS NULL OR c.state <> 'COMPLETED' OR original.id IS NULL
       OR s.order_id IS DISTINCT FROM c.order_id OR s.branch_id IS DISTINCT FROM c.branch_id
       OR s.currency_id IS DISTINCT FROM c.currency_id OR s.original_invoice_id IS DISTINCT FROM c.original_invoice_id
       OR s.replacement_invoice_id IS DISTINCT FROM c.replacement_invoice_id
       OR s.payload_hash IS DISTINCT FROM encode(sha256(convert_to(s.payload::text,'UTF8')),'hex')
       OR s.payload->>'proposal_hash' IS DISTINCT FROM c.proposal_hash
       OR s.payload->>'total' IS DISTINCT FROM c.corrected_amount::text
       OR s.payload->>'original_invoice_id' IS DISTINCT FROM c.original_invoice_id::text
       OR s.payload->>'currency_decimal_places' IS DISTINCT FROM c.currency_decimal_places::text
       OR jsonb_typeof(s.payload->'items') <> 'array'
       OR jsonb_typeof(s.payload->'allocations') <> 'array'
       OR (SELECT branch_id FROM orders WHERE id=c.order_id) IS DISTINCT FROM c.branch_id
       OR (SELECT amount FROM invoice_collections WHERE id=c.original_collection_id) IS DISTINCT FROM c.original_amount
       OR c.customer_delta IS DISTINCT FROM c.corrected_amount-c.original_amount THEN
        RAISE EXCEPTION 'Corrected snapshot has invalid canonical header' USING ERRCODE='23514';
    END IF;
    IF c.kind='CANCEL_AND_REFUND' THEN
        IF c.corrected_amount <> 0 OR c.replacement_invoice_id IS NOT NULL
           OR s.payload->>'replacement_invoice_id' IS NOT NULL
           OR jsonb_array_length(s.payload->'items') <> 0
           OR jsonb_array_length(s.payload->'allocations') <> 0
           OR (s.payload->>'subtotal')::numeric <> 0
           OR (s.payload->>'delivery_total')::numeric <> 0 THEN
            RAISE EXCEPTION 'Cancellation snapshot must represent zero customer obligation' USING ERRCODE='23514';
        END IF;
        RETURN;
    END IF;
    IF c.kind <> 'REPRICE' OR c.original_snapshot_id IS NULL OR replacement.id IS NULL
       OR s.payload->>'replacement_invoice_id' IS DISTINCT FROM replacement.id::text
       OR replacement.order_id IS DISTINCT FROM c.order_id
       OR replacement.currency_id IS DISTINCT FROM c.currency_id
       OR replacement.status <> 'ISSUED' OR replacement.superseded_at IS NOT NULL
       OR replacement.source_items_fingerprint IS DISTINCT FROM c.proposal_hash
       OR replacement.grand_total IS DISTINCT FROM c.corrected_amount
       OR replacement.items_subtotal IS DISTINCT FROM (s.payload->>'subtotal')::numeric
       OR replacement.delivery_total IS DISTINCT FROM (s.payload->>'delivery_total')::numeric
       OR original.status <> 'REPLACED' OR original.replaced_by_invoice_id IS DISTINCT FROM replacement.id THEN
        RAISE EXCEPTION 'Replacement Invoice differs from corrected financial snapshot' USING ERRCODE='23514';
    END IF;

    SELECT count(*),coalesce(sum((line.value->>'line_total')::numeric),0)
      INTO item_count,item_sum FROM jsonb_array_elements(s.payload->'items') line;
    IF item_count=0 OR item_count<>(SELECT count(*) FROM invoice_items WHERE invoice_id=original.id)
       OR item_count<>(SELECT count(*) FROM invoice_items WHERE invoice_id=replacement.id)
       OR item_count<>(SELECT count(*) FROM jsonb_array_elements(c.proposal_payload->'items'))
       OR item_sum IS DISTINCT FROM (s.payload->>'subtotal')::numeric
       OR item_sum IS DISTINCT FROM replacement.items_subtotal THEN
        RAISE EXCEPTION 'Corrected Item set or subtotal is incomplete' USING ERRCODE='23514';
    END IF;
    SELECT count(*) INTO bad_count FROM jsonb_array_elements(s.payload->'items') WITH ORDINALITY line(value, n)
      LEFT JOIN invoice_items oi ON oi.id=(line.value->>'invoice_item_id')::bigint AND oi.invoice_id=original.id
      LEFT JOIN invoice_items ri ON ri.invoice_id=replacement.id AND ri.sort_order=line.n
      LEFT JOIN jsonb_array_elements(c.proposal_payload->'items') proposal ON (proposal.value->>'invoice_item_id')::bigint=oi.id
      WHERE oi.id IS NULL OR ri.id IS NULL OR proposal.value IS NULL
         OR (line.value->>'unit_price')::numeric IS DISTINCT FROM (proposal.value->>'unit_price')::numeric
         OR (line.value->>'unit_price')::numeric IS DISTINCT FROM ri.unit_price
         OR (line.value->>'line_total')::numeric IS DISTINCT FROM ri.line_total
         OR (line.value->>'line_total')::numeric IS DISTINCT FROM round(oi.quantity*(line.value->>'unit_price')::numeric,c.currency_decimal_places)
         OR (line.value->>'unit_price')::numeric <> round((line.value->>'unit_price')::numeric,c.currency_decimal_places);
    IF bad_count>0 OR (SELECT count(DISTINCT (value->>'invoice_item_id')::bigint) FROM jsonb_array_elements(s.payload->'items'))<>item_count THEN
        RAISE EXCEPTION 'Corrected Item facts differ from original, proposal or replacement' USING ERRCODE='23514';
    END IF;

    SELECT count(*),coalesce(sum((a.value->>'customer_amount')::numeric),0),
        coalesce(sum((a.value->>'product_funding_share')::numeric),0)
      INTO allocation_count,delivery_sum,product_sum FROM jsonb_array_elements(s.payload->'allocations') a;
    IF allocation_count=0
       OR allocation_count<>(SELECT count(*) FROM order_financial_source_snapshot_lines WHERE order_financial_source_snapshot_id=c.original_snapshot_id)
       OR allocation_count<>(SELECT count(*) FROM jsonb_array_elements(c.proposal_payload->'delivery'))
       OR delivery_sum IS DISTINCT FROM (s.payload->>'delivery_total')::numeric
       OR product_sum IS DISTINCT FROM (s.payload->>'subtotal')::numeric
       OR delivery_sum+item_sum IS DISTINCT FROM c.corrected_amount
       OR (SELECT count(DISTINCT (value->>'order_captain_id')::bigint) FROM jsonb_array_elements(s.payload->'allocations'))<>allocation_count THEN
        RAISE EXCEPTION 'Corrected Captain allocation totals or set are incomplete' USING ERRCODE='23514';
    END IF;
    SELECT count(*) INTO bad_count FROM jsonb_array_elements(s.payload->'allocations') a
      LEFT JOIN order_financial_source_snapshot_lines l
        ON l.order_financial_source_snapshot_id=c.original_snapshot_id
       AND l.order_captain_id=(a.value->>'order_captain_id')::bigint
      LEFT JOIN jsonb_array_elements(c.proposal_payload->'delivery') proposal
        ON (proposal.value->>'order_captain_id')::bigint=l.order_captain_id
      WHERE l.id IS NULL OR proposal.value IS NULL
         OR l.captain_id IS DISTINCT FROM (a.value->>'captain_id')::bigint
         OR l.captain_entitlement IS DISTINCT FROM (a.value->>'captain_entitlement')::numeric
         OR (a.value->>'customer_amount')::numeric IS DISTINCT FROM (proposal.value->>'customer_amount')::numeric
         OR (a.value->>'company_collectible')::numeric IS DISTINCT FROM greatest((a.value->>'customer_amount')::numeric-l.captain_entitlement,0)
         OR (a.value->>'captain_shortfall')::numeric IS DISTINCT FROM greatest(l.captain_entitlement-(a.value->>'customer_amount')::numeric,0)
         OR (a.value->>'customer_amount')::numeric <> round((a.value->>'customer_amount')::numeric,c.currency_decimal_places)
         OR (a.value->>'product_funding_share')::numeric <> round((a.value->>'product_funding_share')::numeric,c.currency_decimal_places)
         OR (a.value->>'product_funding_share')::numeric < 0;
    IF bad_count>0 OR s.payload->>'primary_captain_id' IS DISTINCT FROM
        (SELECT captain_id::text FROM order_financial_source_snapshot_lines
         WHERE order_financial_source_snapshot_id=c.original_snapshot_id AND is_primary) THEN
        RAISE EXCEPTION 'Corrected Captain facts differ from immutable historical participants' USING ERRCODE='23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION validate_invoice_correction_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM validate_invoice_correction_snapshot_facts(NEW.id);
    RETURN NULL;
END;
$$;
DROP TRIGGER IF EXISTS invoice_correction_snapshot_insert ON invoice_correction_financial_snapshots;
CREATE CONSTRAINT TRIGGER invoice_correction_snapshot_insert AFTER INSERT ON invoice_correction_financial_snapshots
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION validate_invoice_correction_snapshot();
SQL);
            foreach (DB::table('invoice_correction_financial_snapshots')->pluck('id') as $id) {
                DB::select('SELECT validate_invoice_correction_snapshot_facts(?)', [$id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Corrected Invoice snapshots are intentionally forward-only.');
    }
};
