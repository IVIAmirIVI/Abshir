<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('OrderShopReceipt ownership integrity requires PostgreSQL.');
        }

        // Laravel executes PostgreSQL migrations in one transaction. Block every
        // table that could change the precheck result until the guards are installed.
        DB::statement('LOCK TABLE orders, order_shops, order_shop_purchase_confirmations, order_cancellation_intents, order_cancellation_intent_shops, order_shop_receipts IN SHARE ROW EXCLUSIVE MODE');

        $invalid = DB::selectOne(<<<'SQL'
SELECT * FROM (
    SELECT i.order_id, i.id AS intent_id, s.id AS order_shop_id, r.id AS receipt_id,
        'RECEIPT_OUTSIDE_FROZEN_SET' AS issue
    FROM order_cancellation_intents i
    JOIN order_shops s ON s.order_id=i.order_id
    JOIN order_shop_receipts r ON r.order_shop_id=s.id
    WHERE i.classification='AFTER_PURCHASE'
      AND NOT EXISTS (
          SELECT 1 FROM order_shop_purchase_confirmations p
          JOIN order_cancellation_intent_shops frozen
            ON frozen.purchase_confirmation_id=p.id
           AND frozen.order_cancellation_intent_id=i.id
           AND frozen.order_shop_id=s.id
          WHERE p.order_id=i.order_id AND p.order_shop_id=s.id
      )
    UNION ALL
    SELECT i.order_id, i.id AS intent_id, s.id AS order_shop_id, NULL::bigint AS receipt_id,
        'PICKUP_OUTSIDE_FROZEN_SET' AS issue
    FROM order_cancellation_intents i
    JOIN order_shops s ON s.order_id=i.order_id
    WHERE i.classification='AFTER_PURCHASE' AND s.picked_up_at IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM order_shop_purchase_confirmations p
          JOIN order_cancellation_intent_shops frozen
            ON frozen.purchase_confirmation_id=p.id
           AND frozen.order_cancellation_intent_id=i.id
           AND frozen.order_shop_id=s.id
          WHERE p.order_id=i.order_id AND p.order_shop_id=s.id
      )
) conflicts ORDER BY intent_id, order_shop_id, receipt_id NULLS LAST LIMIT 1
SQL);
        if ($invalid) {
            throw new RuntimeException("Phase 9C.2 receipt ownership upgrade rejected order_id={$invalid->order_id}, intent_id={$invalid->intent_id}, order_shop_id={$invalid->order_shop_id}, receipt_id=".($invalid->receipt_id ?? 'NULL').", issue={$invalid->issue}.");
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION phase9c2_receipt_owner_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.order_shop_id IS DISTINCT FROM OLD.order_shop_id THEN
        RAISE EXCEPTION 'OrderShopReceipt ownership is immutable, receipt_id=%, old_order_shop_id=%, new_order_shop_id=%',
            OLD.id, OLD.order_shop_id, NEW.order_shop_id USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER phase9c2_receipt_owner_immutable BEFORE UPDATE OF order_shop_id ON order_shop_receipts
FOR EACH ROW EXECUTE FUNCTION phase9c2_receipt_owner_immutable();

CREATE OR REPLACE FUNCTION phase9c2_receipt_intent_delete_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_order_id bigint;
BEGIN
    SELECT order_id INTO parent_order_id FROM order_shops WHERE id=OLD.order_shop_id;
    PERFORM id FROM orders WHERE id=parent_order_id FOR UPDATE;
    IF EXISTS (SELECT 1 FROM order_cancellation_intents WHERE order_id=parent_order_id) THEN
        RAISE EXCEPTION 'Cancellation intent preserves historical OrderShopReceipt, receipt_id=%', OLD.id USING ERRCODE='23514';
    END IF;
    RETURN OLD;
END;
$$;
CREATE TRIGGER phase9c2_receipt_intent_delete_guard BEFORE DELETE ON order_shop_receipts
FOR EACH ROW EXECUTE FUNCTION phase9c2_receipt_intent_delete_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Phase 9C.2 receipt ownership integrity is forward-only.');
    }
};
