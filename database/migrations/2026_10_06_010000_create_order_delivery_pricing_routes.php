<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_delivery_pricings', function (Blueprint $table): void {
            $table->unsignedInteger('route_count_snapshot')->nullable()->after('order_pricing_class_snapshot');
        });

        Schema::create('order_delivery_pricing_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_delivery_pricing_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('order_shop_id');
            $table->string('shop_name_snapshot');
            $table->foreignId('from_area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('to_area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('delivery_pricing_rule_id')->constrained()->restrictOnDelete();
            $table->decimal('proposed_unit_fee', 18, 4);
            $table->boolean('is_selected_max')->default(false);
            $table->timestamps();
            $table->unique(['order_delivery_pricing_id', 'order_shop_id'], 'delivery_pricing_route_shop_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_delivery_pricings ADD CONSTRAINT delivery_pricing_route_count_positive CHECK (route_count_snapshot IS NULL OR route_count_snapshot > 0)');
            DB::statement('ALTER TABLE order_delivery_pricing_routes ADD CONSTRAINT delivery_pricing_route_fee_positive CHECK (proposed_unit_fee > 0)');
            DB::statement('CREATE UNIQUE INDEX delivery_pricing_one_selected_route_idx ON order_delivery_pricing_routes (order_delivery_pricing_id) WHERE is_selected_max');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_order_delivery_pricing_route_mutation()
RETURNS trigger AS $$
DECLARE
    expected_count integer;
    existing_count integer;
BEGIN
    IF TG_OP = 'INSERT' THEN
        SELECT route_count_snapshot INTO expected_count
        FROM order_delivery_pricings
        WHERE id = NEW.order_delivery_pricing_id
        FOR UPDATE;

        IF expected_count IS NULL THEN
            RAISE EXCEPTION 'Legacy delivery pricing cannot receive route snapshots';
        END IF;

        SELECT count(*) INTO existing_count
        FROM order_delivery_pricing_routes
        WHERE order_delivery_pricing_id = NEW.order_delivery_pricing_id;

        IF existing_count >= expected_count THEN
            RAISE EXCEPTION 'Delivery pricing route snapshot set is already complete';
        END IF;

        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'Order delivery pricing route snapshots are immutable';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER order_delivery_pricing_routes_immutable
BEFORE INSERT OR UPDATE OR DELETE ON order_delivery_pricing_routes
FOR EACH ROW EXECUTE FUNCTION prevent_order_delivery_pricing_route_mutation();

CREATE OR REPLACE FUNCTION prevent_delivery_pricing_route_count_mutation()
RETURNS trigger AS $$
BEGIN
    IF OLD.route_count_snapshot IS NOT NULL AND NEW.route_count_snapshot IS DISTINCT FROM OLD.route_count_snapshot THEN
        RAISE EXCEPTION 'Delivery pricing route count snapshot is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER order_delivery_pricing_route_count_immutable
BEFORE UPDATE OF route_count_snapshot ON order_delivery_pricings
FOR EACH ROW EXECUTE FUNCTION prevent_delivery_pricing_route_count_mutation();
SQL);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: delivery pricing route derivation snapshots must not be discarded.');
    }
};
