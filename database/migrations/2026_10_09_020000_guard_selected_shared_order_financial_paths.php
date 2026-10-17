<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Shared financial source boundaries require PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION selected_shared_financial_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_id bigint; selected_contract text;
BEGIN
    parent_id:=NEW.order_id;
    PERFORM id FROM orders WHERE id=parent_id FOR UPDATE;
    SELECT contract INTO selected_contract FROM order_captain_selections
        WHERE order_id=parent_id ORDER BY id DESC LIMIT 1;
    IF selected_contract='SHARED_COMPANY_V1' AND
       (TG_TABLE_NAME <> 'order_shop_purchase_confirmations' OR to_jsonb(NEW)->>'purchase_mode'='SINGLE_PAYER') THEN
        RAISE EXCEPTION 'Selected shared Order requires the shared financial contract, not legacy settlement' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        // No changes to historical sources, no replacement of existing validators.
        foreach (['invoices', 'order_bill_payment_requests', 'order_bill_payments', 'order_shop_purchase_confirmations'] as $table) {
            DB::statement("CREATE TRIGGER selected_shared_financial_guard BEFORE INSERT OR UPDATE OF order_id ON {$table} FOR EACH ROW EXECUTE FUNCTION selected_shared_financial_boundary()");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Shared source protection is forward-only.');
    }
};
