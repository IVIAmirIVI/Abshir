<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_special')->default(false);
        });
        Schema::create('product_delivery_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('delivery_fee', 18, 4);
            $table->string('allocation_mode', 20);
            $table->decimal('captain_percentage', 7, 4)->nullable();
            $table->decimal('captain_fixed', 18, 4)->nullable();
            $table->decimal('company_fixed', 18, 4)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['product_id', 'version']);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('special_delivery_policy_id')->nullable()->constrained('product_delivery_policies')->restrictOnDelete();
            $table->jsonb('special_delivery_assignments')->nullable();
        });
        Schema::table('order_delivery_allocations', function (Blueprint $table): void {
            $table->jsonb('special_delivery_components')->nullable();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE product_delivery_policies ADD CONSTRAINT special_product_policy_values CHECK (
    delivery_fee > 0 AND delivery_fee <> 'NaN'::numeric AND version > 0
    AND ((allocation_mode='DEFAULT' AND captain_percentage IS NULL AND captain_fixed IS NULL AND company_fixed IS NULL)
      OR (allocation_mode='PERCENTAGE' AND captain_percentage BETWEEN 0 AND 100 AND captain_percentage IS NOT NULL AND captain_fixed IS NULL AND company_fixed IS NULL)
      OR (allocation_mode='FIXED' AND captain_percentage IS NULL AND captain_fixed IS NOT NULL AND company_fixed IS NOT NULL
          AND captain_fixed >= 0 AND company_fixed >= 0 AND captain_fixed + company_fixed = delivery_fee))
);
CREATE FUNCTION protect_product_delivery_policy() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Product delivery policy versions are immutable' USING ERRCODE='55000'; END IF;
    IF NOT EXISTS (SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id
        WHERE u.id=NEW.created_by AND u.is_active AND r.code='SUPER_ADMIN') THEN
        RAISE EXCEPTION 'Special delivery policy requires SUPER_ADMIN' USING ERRCODE='23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM products WHERE id=NEW.product_id AND default_unit_id=NEW.unit_id) THEN
        RAISE EXCEPTION 'Special delivery policy must identify its product unit' USING ERRCODE='23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM currencies WHERE id=NEW.currency_id AND is_active
        AND NEW.delivery_fee=round(NEW.delivery_fee,decimal_places)
        AND (NEW.captain_fixed IS NULL OR NEW.captain_fixed=round(NEW.captain_fixed,decimal_places))
        AND (NEW.company_fixed IS NULL OR NEW.company_fixed=round(NEW.company_fixed,decimal_places))) THEN
        RAISE EXCEPTION 'Invalid special delivery currency precision' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER product_delivery_policy_guard BEFORE INSERT OR UPDATE OR DELETE ON product_delivery_policies
FOR EACH ROW EXECUTE FUNCTION protect_product_delivery_policy();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Special delivery policy history requires a reviewed forward migration.');
    }
};
