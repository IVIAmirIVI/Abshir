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
        Schema::table('shop_commission_drafts', function (Blueprint $table) {
            $table->string('method', 16)->nullable();
            $table->integer('group_priority')->nullable();
            $table->timestampTz('effective_from', 6)->nullable();
            $table->timestampTz('effective_until', 6)->nullable();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE shop_commission_drafts ADD COLUMN commission_value NUMERIC;
ALTER TABLE shop_commission_drafts ALTER COLUMN recorded_at TYPE timestamptz(6);
ALTER TABLE shop_commission_drafts DROP CONSTRAINT commission_draft_target;
ALTER TABLE shop_commission_drafts ADD CONSTRAINT commission_draft_target CHECK (
    (product_id IS NOT NULL AND product_group_id IS NULL) OR
    (product_id IS NULL AND product_group_id IS NOT NULL) OR
    (product_id IS NULL AND product_group_id IS NULL AND method IS NOT NULL)
);
ALTER TABLE shop_commission_drafts ADD CONSTRAINT commission_agreement_configuration CHECK (
    (method IS NULL AND commission_value IS NULL AND group_priority IS NULL AND effective_from IS NULL AND effective_until IS NULL) OR
    (method IS NOT NULL AND method IN ('FIXED','PERCENTAGE') AND commission_value IS NOT NULL
     AND commission_value NOT IN ('NaN'::numeric,'Infinity'::numeric,'-Infinity'::numeric)
     AND commission_value >= 0 AND (method <> 'PERCENTAGE' OR commission_value <= 100)
     AND currency_id IS NOT NULL AND effective_from IS NOT NULL
     AND (effective_until IS NULL OR effective_until > effective_from)
     AND ((product_group_id IS NOT NULL AND group_priority IS NOT NULL AND group_priority >= 0)
          OR (product_group_id IS NULL AND group_priority IS NULL)))
);
CREATE UNIQUE INDEX commission_draft_shop_root ON shop_commission_drafts (shop_partnership_id,currency_id)
    WHERE version=1 AND product_id IS NULL AND product_group_id IS NULL;
CREATE FUNCTION guard_shop_commission_agreement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE places integer;
BEGIN
    IF NEW.method='FIXED' THEN
        SELECT decimal_places INTO places FROM currencies WHERE id=NEW.currency_id FOR SHARE;
        IF NEW.commission_value <> trunc(NEW.commission_value, places) THEN
            RAISE EXCEPTION 'Fixed commission exceeds Currency precision' USING ERRCODE='23514';
        END IF;
    END IF;
    IF NEW.replaces_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM shop_commission_drafts WHERE id=NEW.replaces_id AND method IS NOT NULL
    ) AND NEW.method IS NULL THEN
        RAISE EXCEPTION 'Configured agreement cannot become an unconfigured legacy draft' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER shop_commission_agreement_guard BEFORE INSERT ON shop_commission_drafts
    FOR EACH ROW EXECUTE FUNCTION guard_shop_commission_agreement();
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Commission agreement history is forward-only.');
    }
};
