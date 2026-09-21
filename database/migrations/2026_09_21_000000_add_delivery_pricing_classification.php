<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRICING_CLASSES = ['SINGLE_RESTAURANT', 'SINGLE_SHOP', 'MULTI_SHOP'];

    public function up(): void
    {
        Schema::table('shop_types', function (Blueprint $table) {
            $table->string('business_classification', 24)->nullable()->after('description')->index();
        });
        Schema::table('order_shops', function (Blueprint $table) {
            $table->string('business_classification_snapshot', 24)->nullable()->after('shop_type')->index();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->string('pricing_class', 32)->nullable()->after('pricing_status')->index();
        });
        Schema::table('delivery_pricing_rules', function (Blueprint $table) {
            $table->string('order_pricing_class', 32)->nullable()->after('currency_id');
            $table->index(['from_area_id', 'to_area_id', 'currency_id', 'order_pricing_class'], 'delivery_rule_class_dimensions_idx');
        });
        Schema::table('order_delivery_pricings', function (Blueprint $table) {
            $table->string('order_pricing_class_snapshot', 32)->nullable()->after('currency_id')->index();
        });
        Schema::table('delivery_fee_override_requests', function (Blueprint $table) {
            $table->string('order_pricing_class_snapshot', 32)->nullable()->after('from_area_id')->index();
        });

        DB::table('orders')->whereIn('id', function ($query): void {
            $query->select('order_id')->from('order_shops')->groupBy('order_id')->havingRaw('COUNT(*) >= 2');
        })->update(['pricing_class' => 'MULTI_SHOP']);

        $legacyRules = DB::table('delivery_pricing_rules')->whereNull('order_pricing_class')->orderBy('id')->get();
        $copies = [];
        foreach ($legacyRules as $legacy) {
            foreach (self::PRICING_CLASSES as $pricingClass) {
                $copies[$legacy->id][$pricingClass] = DB::table('delivery_pricing_rules')->insertGetId([
                    'from_area_id' => $legacy->from_area_id,
                    'to_area_id' => $legacy->to_area_id,
                    'currency_id' => $legacy->currency_id,
                    'order_pricing_class' => $pricingClass,
                    'delivery_unit_fee' => $legacy->delivery_unit_fee,
                    'effective_from' => $legacy->effective_from,
                    'effective_until' => $legacy->effective_until,
                    'is_active' => $legacy->is_active,
                    'created_by' => $legacy->created_by,
                    'superseded_by' => null,
                    'notes' => $legacy->notes,
                    'created_at' => $legacy->created_at,
                    'updated_at' => $legacy->updated_at,
                ]);
            }
        }
        foreach ($legacyRules as $legacy) {
            if ($legacy->superseded_by === null || ! isset($copies[$legacy->superseded_by])) {
                continue;
            }
            foreach (self::PRICING_CLASSES as $pricingClass) {
                DB::table('delivery_pricing_rules')->where('id', $copies[$legacy->id][$pricingClass])->update([
                    'superseded_by' => $copies[$legacy->superseded_by][$pricingClass],
                ]);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE shop_types ADD CONSTRAINT shop_type_business_classification_valid CHECK (business_classification IS NULL OR business_classification IN ('RESTAURANT', 'SHOP'))");
            DB::statement("ALTER TABLE order_shops ADD CONSTRAINT order_shop_business_classification_valid CHECK (business_classification_snapshot IS NULL OR business_classification_snapshot IN ('RESTAURANT', 'SHOP'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT order_pricing_class_valid CHECK (pricing_class IS NULL OR pricing_class IN ('SINGLE_RESTAURANT', 'SINGLE_SHOP', 'MULTI_SHOP'))");
            DB::statement("ALTER TABLE delivery_pricing_rules ADD CONSTRAINT delivery_rule_pricing_class_valid CHECK (order_pricing_class IS NULL OR order_pricing_class IN ('SINGLE_RESTAURANT', 'SINGLE_SHOP', 'MULTI_SHOP'))");
            DB::statement("ALTER TABLE order_delivery_pricings ADD CONSTRAINT order_delivery_pricing_class_valid CHECK (order_pricing_class_snapshot IS NULL OR order_pricing_class_snapshot IN ('SINGLE_RESTAURANT', 'SINGLE_SHOP', 'MULTI_SHOP'))");
            DB::statement("ALTER TABLE delivery_fee_override_requests ADD CONSTRAINT delivery_override_pricing_class_valid CHECK (order_pricing_class_snapshot IS NULL OR order_pricing_class_snapshot IN ('SINGLE_RESTAURANT', 'SINGLE_SHOP', 'MULTI_SHOP'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('This financial classification migration is forward-only; rollback could destroy rules created after deployment.');
    }
};
