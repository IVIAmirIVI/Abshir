<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Captain fuel allowance snapshots require PostgreSQL.');
        }
        Schema::table('captains', function (Blueprint $table): void {
            $table->decimal('fuel_allowance_percentage', 7, 4)->default(0);
        });
        Schema::table('order_delivery_allocations', function (Blueprint $table): void {
            // NULL base identifies legacy allocations; no historical money is rewritten.
            $table->decimal('base_captain_share_percent_snapshot', 7, 4)->nullable();
            $table->decimal('fuel_allowance_percent_snapshot', 7, 4)->default(0);
            $table->decimal('base_captain_entitlement', 18, 4)->nullable();
            $table->decimal('fuel_allowance_amount', 18, 4)->default(0);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE captains ADD CONSTRAINT captain_fuel_percentage_valid
CHECK (fuel_allowance_percentage BETWEEN 0 AND 100);
ALTER TABLE order_delivery_allocations ADD CONSTRAINT delivery_fuel_snapshot_valid CHECK (
    fuel_allowance_percent_snapshot BETWEEN 0 AND 100 AND fuel_allowance_amount >= 0
    AND fuel_allowance_amount <> 'NaN'::numeric
    AND ((base_captain_share_percent_snapshot IS NULL AND base_captain_entitlement IS NULL
          AND fuel_allowance_percent_snapshot = 0 AND fuel_allowance_amount = 0)
      OR (base_captain_share_percent_snapshot IS NOT NULL AND base_captain_entitlement IS NOT NULL
          AND base_captain_share_percent_snapshot BETWEEN 0 AND 100 AND base_captain_entitlement >= 0
          AND base_captain_entitlement <> 'NaN'::numeric
          AND captain_share_percent_snapshot = base_captain_share_percent_snapshot + fuel_allowance_percent_snapshot
          AND captain_entitlement = base_captain_entitlement + fuel_allowance_amount))
);
CREATE FUNCTION validate_delivery_fuel_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pricing order_delivery_pricings%ROWTYPE; places integer;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF (OLD.base_captain_share_percent_snapshot IS NOT NULL
            AND (to_jsonb(OLD) - 'updated_at') IS DISTINCT FROM (to_jsonb(NEW) - 'updated_at'))
           OR ROW(NEW.base_captain_share_percent_snapshot, NEW.fuel_allowance_percent_snapshot,
                  NEW.base_captain_entitlement, NEW.fuel_allowance_amount)
              IS DISTINCT FROM ROW(OLD.base_captain_share_percent_snapshot, OLD.fuel_allowance_percent_snapshot,
                  OLD.base_captain_entitlement, OLD.fuel_allowance_amount) THEN
            RAISE EXCEPTION 'Delivery fuel allocation snapshots are immutable' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT pricing FROM order_delivery_pricings WHERE id = NEW.order_delivery_pricing_id FOR SHARE;
    SELECT decimal_places INTO STRICT places FROM currencies WHERE id = pricing.currency_id;
    IF NEW.base_captain_share_percent_snapshot IS NULL OR NEW.base_captain_entitlement IS NULL
       OR NEW.base_captain_share_percent_snapshot IS DISTINCT FROM pricing.captain_share_percent_snapshot
       OR NEW.currency_id IS DISTINCT FROM pricing.currency_id
       OR NEW.applied_unit_fee IS DISTINCT FROM pricing.applied_unit_fee
       OR NEW.captain_share_percent_snapshot NOT BETWEEN 0 AND 100
       OR NEW.base_captain_entitlement IS DISTINCT FROM round(pricing.applied_unit_fee * NEW.base_captain_share_percent_snapshot / 100, places)
       OR NEW.captain_entitlement IS DISTINCT FROM round(pricing.applied_unit_fee * NEW.captain_share_percent_snapshot / 100, places)
       OR NEW.company_collectible IS DISTINCT FROM greatest(NEW.customer_delivery_amount_allocated - NEW.captain_entitlement, 0)
       OR NEW.captain_shortfall IS DISTINCT FROM greatest(NEW.captain_entitlement - NEW.customer_delivery_amount_allocated, 0) THEN
        RAISE EXCEPTION 'Invalid delivery fuel allocation snapshot' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER delivery_fuel_snapshot_guard BEFORE INSERT OR UPDATE ON order_delivery_allocations
FOR EACH ROW EXECUTE FUNCTION validate_delivery_fuel_snapshot();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Fuel allocation history requires a reviewed forward migration.');
    }
};
