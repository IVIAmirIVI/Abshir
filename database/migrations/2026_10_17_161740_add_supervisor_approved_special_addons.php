<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_special_addon_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->useCurrent();
            $table->string('reason', 1000);
            $table->jsonb('facts');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE order_special_addon_approvals ADD COLUMN facts_digest text GENERATED ALWAYS AS (md5(facts::text)) STORED;
CREATE UNIQUE INDEX special_addon_approval_replay ON order_special_addon_approvals(order_shop_id,source_order_item_id,facts_digest);
CREATE FUNCTION special_addon_facts(p_shop bigint,p_source bigint) RETURNS jsonb LANGUAGE sql STABLE AS $$
SELECT jsonb_build_object('branch_id',o.branch_id,'destination_area_id',o.destination_area_id,
    'shop_id',s.id,'origin_area_id',s.origin_area_id,'shop_name',s.shop_name_snapshot,
    'items',(SELECT jsonb_agg(jsonb_build_object('id',i.id,'product_id',i.product_id,'name',i.product_name_snapshot,
        'unit_id',i.unit_id,'quantity',i.current_quantity,'special_policy_id',i.special_delivery_policy_id) ORDER BY i.id)
        FROM order_items i WHERE i.order_shop_id=s.id),
    'source_item_id',src.id,'source_shop_id',ss.id,'source_origin_area_id',ss.origin_area_id,
    'source_policy_id',src.special_delivery_policy_id,'source_quantity',src.current_quantity)
FROM order_shops s JOIN orders o ON o.id=s.order_id
JOIN order_items src ON src.id=p_source JOIN order_shops ss ON ss.id=src.order_shop_id AND ss.order_id=o.id
WHERE s.id=p_shop AND s.id<>ss.id AND src.special_delivery_policy_id IS NOT NULL;
$$;
CREATE FUNCTION guard_special_addon_approval() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o orders%ROWTYPE;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Add-on approvals are immutable' USING ERRCODE='55000'; END IF;
    SELECT * INTO STRICT o FROM orders WHERE id=NEW.order_id FOR UPDATE;
    IF NOT benefit_actor_allowed(NEW.approved_by,o.branch_id,'orders.update')
       OR NOT benefit_actor_allowed(NEW.approved_by,o.branch_id,'delivery_pricing.apply')
       OR NOT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
           WHERE ur.user_id=NEW.approved_by AND r.code IN ('SUPER_ADMIN','ORDER_EMPLOYEE')) THEN
        RAISE EXCEPTION 'Unauthorized special add-on approval' USING ERRCODE='23514';
    END IF;
    IF o.operational_status IN ('DELIVERED','CANCELLED') OR EXISTS(SELECT 1 FROM invoices WHERE order_id=o.id AND status='ISSUED')
       OR EXISTS(SELECT 1 FROM order_financial_source_snapshots WHERE order_id=o.id)
       OR EXISTS(SELECT 1 FROM order_cancellation_intents WHERE order_id=o.id)
       OR EXISTS(SELECT 1 FROM order_shop_purchase_confirmations WHERE order_id=o.id AND purchase_mode='SHARED_TWO_V1')
       OR NOT EXISTS(SELECT 1 FROM order_shops s JOIN areas a ON a.id=s.origin_area_id
           JOIN order_items src ON src.id=NEW.source_order_item_id JOIN order_shops ss ON ss.id=src.order_shop_id
           JOIN areas sa ON sa.id=ss.origin_area_id JOIN areas dest ON dest.id=o.destination_area_id
           WHERE s.id=NEW.order_shop_id AND a.branch_id=o.branch_id AND sa.branch_id=o.branch_id AND dest.branch_id=o.branch_id
           AND a.is_active AND sa.is_active AND dest.is_active)
       OR NOT EXISTS(SELECT 1 FROM order_shops WHERE id=NEW.order_shop_id AND order_id=o.id)
       OR NOT EXISTS(SELECT 1 FROM order_items WHERE order_shop_id=NEW.order_shop_id)
       OR EXISTS(SELECT 1 FROM order_items WHERE order_shop_id=NEW.order_shop_id AND special_delivery_policy_id IS NOT NULL)
       OR NEW.facts IS DISTINCT FROM special_addon_facts(NEW.order_shop_id,NEW.source_order_item_id)
       OR NEW.facts IS NULL OR length(btrim(NEW.reason))=0 OR NEW.approved_at IS NULL
       OR NEW.approved_at>clock_timestamp() THEN
        RAISE EXCEPTION 'Invalid or frozen special add-on facts' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER special_addon_approval_guard BEFORE INSERT OR UPDATE OR DELETE ON order_special_addon_approvals
FOR EACH ROW EXECUTE FUNCTION guard_special_addon_approval();
CREATE FUNCTION special_addon_approval_ids(p_order bigint) RETURNS jsonb LANGUAGE plpgsql AS $$
DECLARE anchor_shop bigint; pickup_row record; approval bigint; ids bigint[]:='{}';
BEGIN
    SELECT min(i.order_shop_id) INTO anchor_shop FROM order_items i JOIN order_shops s ON s.id=i.order_shop_id
        WHERE s.order_id=p_order AND i.special_delivery_policy_id IS NOT NULL;
    IF anchor_shop IS NULL THEN RETURN '[]'::jsonb; END IF;
    IF EXISTS(SELECT 1 FROM order_items i JOIN order_shops s ON s.id=i.order_shop_id
        WHERE s.order_id=p_order AND i.special_delivery_policy_id IS NOT NULL AND s.id<>anchor_shop) THEN
        RAISE EXCEPTION 'Multiple special pickup sources are unsupported' USING ERRCODE='23514';
    END IF;
    FOR pickup_row IN SELECT id FROM order_shops WHERE order_id=p_order AND id<>anchor_shop ORDER BY id LOOP
        SELECT a.id INTO approval FROM order_special_addon_approvals a
            JOIN order_items src ON src.id=a.source_order_item_id AND src.order_shop_id=anchor_shop
            WHERE a.order_id=p_order AND a.order_shop_id=pickup_row.id
            AND a.facts=special_addon_facts(pickup_row.id,a.source_order_item_id) ORDER BY a.id DESC LIMIT 1;
        IF approval IS NULL THEN RAISE EXCEPTION 'Explicit current add-on approval required' USING ERRCODE='23514'; END IF;
        ids:=array_append(ids,approval);
    END LOOP;
    RETURN (SELECT coalesce(jsonb_agg(v ORDER BY v),'[]'::jsonb) FROM unnest(ids) v);
END;
$$;
SQL);
        $definition = DB::scalar("SELECT pg_get_functiondef('assert_special_delivery_allocation(jsonb)'::regprocedure)");
        $old = "    IF (SELECT count(*) FROM order_shops WHERE order_id=p.order_id)>1 THEN\n        RAISE EXCEPTION 'Special multi-Shop pricing requires an approved combination policy' USING ERRCODE='23514';\n    END IF;";
        if (substr_count($definition, $old) !== 1) {
            throw new RuntimeException('Unexpected installed special allocation validator; review before activation.');
        }
        $new = "    IF coalesce(a#>'{special_delivery_components,addon_approval_ids}','[]'::jsonb) IS DISTINCT FROM special_addon_approval_ids(p.order_id) THEN\n        RAISE EXCEPTION 'Special add-on approval snapshot mismatch' USING ERRCODE='23514';\n    END IF;";
        DB::unprepared(str_replace($old, $new, $definition));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Add-on approval history requires a reviewed forward migration.');
    }
};
