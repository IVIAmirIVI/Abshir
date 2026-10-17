<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION assert_special_delivery_allocation(a jsonb) RETURNS void LANGUAGE plpgsql AS $$
DECLARE p order_delivery_pricings%ROWTYPE; policy product_delivery_policies%ROWTYPE; item order_items%ROWTYPE;
    details jsonb; part jsonb; places integer; units numeric; part_fee numeric; part_captain numeric;
    base_fee numeric:=0; custom_captain numeric:=0; standard_fee numeric:=0; extra numeric; cap_id bigint;
    allocated numeric; entitlement numeric; base_entitlement numeric; assigned jsonb; expected_count integer;
BEGIN
    SELECT * INTO STRICT p FROM order_delivery_pricings WHERE id=(a->>'order_delivery_pricing_id')::bigint FOR SHARE;
    SELECT decimal_places INTO STRICT places FROM currencies WHERE id=p.currency_id;
    IF (SELECT count(*) FROM order_shops WHERE order_id=p.order_id)>1 THEN
        RAISE EXCEPTION 'Special multi-Shop pricing requires an approved combination policy' USING ERRCODE='23514';
    END IF;
    details:=a->'special_delivery_components'; cap_id:=(a->>'captain_id')::bigint;
    IF jsonb_typeof(details) IS DISTINCT FROM 'object' OR jsonb_typeof(details->'parts') IS DISTINCT FROM 'array'
       OR jsonb_array_length(details->'parts')=0 OR NOT EXISTS (
           SELECT 1 FROM order_captains WHERE id=(a->>'order_captain_id')::bigint
           AND order_id=p.order_id AND captain_id=cap_id AND removed_at IS NULL)
       OR (a->>'currency_id')::bigint IS DISTINCT FROM p.currency_id
       OR (a->>'applied_unit_fee')::numeric IS DISTINCT FROM p.applied_unit_fee
       OR (a->>'base_captain_share_percent_snapshot')::numeric IS DISTINCT FROM p.captain_share_percent_snapshot
       OR (a->>'captain_share_percent_snapshot')::numeric NOT BETWEEN 0 AND 100 THEN
        RAISE EXCEPTION 'Invalid special delivery allocation source' USING ERRCODE='23514';
    END IF;
    IF (SELECT count(*) FROM jsonb_array_elements(details->'parts')) <>
       (SELECT count(DISTINCT value->>'item_id') FROM jsonb_array_elements(details->'parts')) THEN
        RAISE EXCEPTION 'Duplicated special delivery portion' USING ERRCODE='23514';
    END IF;
    FOR part IN SELECT value FROM jsonb_array_elements(details->'parts') LOOP
        SELECT i.* INTO STRICT item FROM order_items i JOIN order_shops s ON s.id=i.order_shop_id
            WHERE i.id=(part->>'item_id')::bigint AND s.order_id=p.order_id;
        SELECT * INTO STRICT policy FROM product_delivery_policies WHERE id=item.special_delivery_policy_id;
        IF part->>'units' !~ '^[1-9][0-9]{0,5}$' OR (part->>'policy_id')::bigint IS DISTINCT FROM policy.id
           OR (part->>'product_id')::bigint IS DISTINCT FROM policy.product_id
           OR item.product_id IS DISTINCT FROM policy.product_id OR item.unit_id IS DISTINCT FROM policy.unit_id OR policy.currency_id<>p.currency_id
           OR part->>'mode' IS DISTINCT FROM policy.allocation_mode THEN
            RAISE EXCEPTION 'Invalid special product portion policy' USING ERRCODE='23514';
        END IF;
        units:=(part->>'units')::numeric;
        assigned:=coalesce(item.special_delivery_assignments,'{}'::jsonb);
        IF jsonb_typeof(assigned)<>'object' OR item.current_quantity<>trunc(item.current_quantity) THEN
            RAISE EXCEPTION 'Invalid special product quantity' USING ERRCODE='23514';
        END IF;
        IF assigned='{}'::jsonb THEN
            IF p.captain_count_snapshot<>1 OR units<>item.current_quantity THEN
                RAISE EXCEPTION 'Explicit shared delivery portions are required' USING ERRCODE='23514';
            END IF;
        ELSE
            IF EXISTS (SELECT 1 FROM jsonb_each_text(assigned) e WHERE e.key !~ '^[1-9][0-9]*$'
                OR e.value !~ '^[0-9]{1,6}$') THEN
                RAISE EXCEPTION 'Invalid delivery portion assignment' USING ERRCODE='23514';
            END IF;
            IF (assigned->>cap_id::text)::numeric IS DISTINCT FROM units
               OR (SELECT sum(value::numeric) FROM jsonb_each_text(assigned)) IS DISTINCT FROM item.current_quantity
               OR EXISTS (SELECT 1 FROM jsonb_each_text(assigned) e WHERE NOT EXISTS (
                   SELECT 1 FROM order_captains WHERE order_id=p.order_id AND captain_id=e.key::bigint AND removed_at IS NULL)) THEN
                RAISE EXCEPTION 'Special delivery assignment does not match the source quantity and participants' USING ERRCODE='23514';
            END IF;
        END IF;
        part_fee:=policy.delivery_fee*units;
        part_captain:=CASE policy.allocation_mode WHEN 'FIXED' THEN policy.captain_fixed*units
            WHEN 'PERCENTAGE' THEN round(part_fee*policy.captain_percentage/100,places) ELSE 0 END;
        IF (part->>'base_fee')::numeric IS DISTINCT FROM part_fee
           OR (part->>'custom_captain')::numeric IS DISTINCT FROM part_captain THEN
            RAISE EXCEPTION 'Special delivery portion money differs from immutable policy' USING ERRCODE='23514';
        END IF;
        base_fee:=base_fee+part_fee;
        IF policy.allocation_mode='DEFAULT' THEN standard_fee:=standard_fee+part_fee;
        ELSE custom_captain:=custom_captain+part_captain; END IF;
    END LOOP;
    SELECT count(*) INTO expected_count FROM order_items i JOIN order_shops s ON s.id=i.order_shop_id
        WHERE s.order_id=p.order_id AND i.special_delivery_policy_id IS NOT NULL
        AND (CASE WHEN coalesce(i.special_delivery_assignments,'{}'::jsonb)='{}'::jsonb AND p.captain_count_snapshot=1
             THEN i.current_quantity ELSE coalesce((i.special_delivery_assignments->>cap_id::text)::numeric,0) END)>0;
    IF expected_count<>jsonb_array_length(details->'parts') THEN
        RAISE EXCEPTION 'Special delivery portion set is incomplete' USING ERRCODE='23514';
    END IF;
    extra:=p.applied_unit_fee-base_fee;
    standard_fee:=standard_fee+extra;
    entitlement:=custom_captain+round(standard_fee*(a->>'captain_share_percent_snapshot')::numeric/100,places);
    base_entitlement:=custom_captain+round(standard_fee*p.captain_share_percent_snapshot/100,places);
    allocated:=p.applied_unit_fee-(a->>'discount_allocated')::numeric;
    IF extra<0 OR allocated<base_fee
       OR (details->>'special_base_fee')::numeric IS DISTINCT FROM base_fee
       OR (details->>'custom_captain')::numeric IS DISTINCT FROM custom_captain
       OR (details->>'extra_fee')::numeric IS DISTINCT FROM extra
       OR (details->>'standard_fee')::numeric IS DISTINCT FROM standard_fee
       OR (a->>'captain_entitlement')::numeric IS DISTINCT FROM entitlement
       OR (a->>'base_captain_entitlement')::numeric IS DISTINCT FROM base_entitlement
       OR (a->>'fuel_allowance_amount')::numeric IS DISTINCT FROM entitlement-base_entitlement
       OR (a->>'customer_delivery_amount_allocated')::numeric IS DISTINCT FROM allocated
       OR (a->>'company_collectible')::numeric IS DISTINCT FROM greatest(allocated-entitlement,0)
       OR (a->>'captain_shortfall')::numeric IS DISTINCT FROM greatest(entitlement-allocated,0) THEN
        RAISE EXCEPTION 'Special delivery money does not reconcile' USING ERRCODE='23514';
    END IF;
END;
$$;
CREATE FUNCTION protect_special_delivery_metadata() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.special_delivery_components IS DISTINCT FROM OLD.special_delivery_components THEN
        RAISE EXCEPTION 'Special delivery allocation snapshots are immutable' USING ERRCODE='55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER special_delivery_metadata_guard BEFORE UPDATE ON order_delivery_allocations
FOR EACH ROW EXECUTE FUNCTION protect_special_delivery_metadata();
CREATE FUNCTION validate_special_delivery_source_set() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a record;
BEGIN
    FOR a IN SELECT * FROM order_delivery_allocations WHERE order_delivery_pricing_id=NEW.order_delivery_pricing_id LOOP
        IF a.special_delivery_components IS NOT NULL THEN
            PERFORM assert_special_delivery_allocation(to_jsonb(a));
        ELSIF EXISTS (SELECT 1 FROM order_items i JOIN order_shops s ON s.id=i.order_shop_id
            WHERE s.order_id=NEW.order_id AND i.special_delivery_policy_id IS NOT NULL) THEN
            RAISE EXCEPTION 'Special products require an attributable delivery snapshot' USING ERRCODE='23514';
        END IF;
    END LOOP;
    RETURN NEW;
END;
$$;
CREATE TRIGGER special_delivery_source_set_guard BEFORE INSERT ON order_financial_source_snapshots
FOR EACH ROW EXECUTE FUNCTION validate_special_delivery_source_set();
CREATE FUNCTION protect_special_order_item_source() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(NEW.special_delivery_policy_id,NEW.special_delivery_assignments) IS DISTINCT FROM
       ROW(OLD.special_delivery_policy_id,OLD.special_delivery_assignments)
       AND EXISTS (SELECT 1 FROM invoices i JOIN order_shops s ON s.order_id=i.order_id
           WHERE s.id=OLD.order_shop_id AND i.status='ISSUED') THEN
        RAISE EXCEPTION 'Issued special delivery sources are immutable' USING ERRCODE='55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER special_order_item_source_guard BEFORE UPDATE ON order_items
FOR EACH ROW EXECUTE FUNCTION protect_special_order_item_source();
SQL);
        $definition = DB::scalar("SELECT pg_get_functiondef('validate_delivery_fuel_snapshot()'::regprocedure)");
        $anchor = '    SELECT * INTO STRICT pricing FROM order_delivery_pricings';
        if (substr_count($definition, $anchor) !== 1 || str_contains($definition, 'assert_special_delivery_allocation')) {
            throw new RuntimeException('Unexpected installed fuel allocation guard; review before activation.');
        }
        $branch = "    IF NEW.special_delivery_components IS NOT NULL THEN\n        PERFORM assert_special_delivery_allocation(to_jsonb(NEW));\n        RETURN NEW;\n    END IF;\n";
        DB::unprepared(str_replace($anchor, $branch.$anchor, $definition));
    }

    public function down(): void
    {
        throw new RuntimeException('Special delivery allocation history requires a reviewed forward migration.');
    }
};
