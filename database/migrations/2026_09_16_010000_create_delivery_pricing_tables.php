<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('destination_area_id')->nullable()->after('customer_address_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('destination_area_confirmed_by')->nullable()->after('destination_area_id')->constrained('users')->nullOnDelete();
            $table->timestampTz('destination_area_confirmed_at')->nullable()->after('destination_area_confirmed_by');
        });

        Schema::create('delivery_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('to_area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('delivery_unit_fee', 18, 4);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by')->nullable()->constrained('delivery_pricing_rules')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['from_area_id', 'to_area_id', 'currency_id'], 'delivery_rule_dimensions_idx');
            $table->index(['effective_from', 'effective_until'], 'delivery_rule_effective_idx');
        });

        Schema::create('order_delivery_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('delivery_pricing_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('from_area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('to_area_id')->constrained('areas')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('suggested_unit_fee', 18, 4);
            $table->decimal('applied_unit_fee', 18, 4);
            $table->unsignedInteger('captain_count_snapshot');
            $table->decimal('captain_share_percent_snapshot', 7, 4);
            $table->decimal('gross_delivery_total', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->text('discount_reason')->nullable();
            $table->foreignId('discount_applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('discount_applied_at')->nullable();
            $table->decimal('customer_delivery_total', 18, 4);
            $table->string('status', 40);
            $table->timestampTz('priced_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('order_delivery_pricings')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'version']);
            $table->index(['order_id', 'superseded_at']);
        });

        Schema::create('order_delivery_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_delivery_pricing_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('applied_unit_fee', 18, 4);
            $table->decimal('captain_share_percent_snapshot', 7, 4);
            $table->decimal('captain_entitlement', 18, 4);
            $table->decimal('discount_allocated', 18, 4);
            $table->decimal('customer_delivery_amount_allocated', 18, 4);
            $table->decimal('company_collectible', 18, 4);
            $table->decimal('captain_shortfall', 18, 4);
            $table->timestamps();
            $table->unique(['order_delivery_pricing_id', 'order_captain_id'], 'delivery_allocation_assignment_unique');
            $table->index(['captain_id', 'created_at']);
        });

        Schema::create('delivery_fee_override_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_delivery_pricing_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('from_area_id')->constrained('areas')->restrictOnDelete();
            $table->decimal('suggested_unit_fee', 18, 4);
            $table->decimal('requested_unit_fee', 18, 4);
            $table->decimal('difference_amount', 18, 4);
            $table->decimal('difference_percent', 9, 4);
            $table->text('reason');
            $table->string('status', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'requested_at']);
            $table->index(['order_id', 'status']);
        });

        Schema::create('delivery_pricing_user_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('max_discount_percent', 7, 4)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX order_one_current_delivery_pricing_idx ON order_delivery_pricings (order_id) WHERE superseded_at IS NULL');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE delivery_pricing_rules ADD CONSTRAINT delivery_rule_fee_positive CHECK (delivery_unit_fee > 0)');
            DB::statement('ALTER TABLE delivery_pricing_rules ADD CONSTRAINT delivery_rule_period_valid CHECK (effective_until IS NULL OR effective_until > effective_from)');
            DB::statement('ALTER TABLE order_delivery_pricings ADD CONSTRAINT order_delivery_values_valid CHECK (suggested_unit_fee > 0 AND applied_unit_fee > 0 AND captain_count_snapshot > 0 AND captain_share_percent_snapshot BETWEEN 0 AND 100 AND discount_amount >= 0 AND discount_amount <= gross_delivery_total AND customer_delivery_total >= 0)');
            DB::statement('ALTER TABLE order_delivery_allocations ADD CONSTRAINT delivery_allocation_values_valid CHECK (captain_share_percent_snapshot BETWEEN 0 AND 100 AND captain_entitlement >= 0 AND discount_allocated >= 0 AND customer_delivery_amount_allocated >= 0 AND company_collectible >= 0 AND captain_shortfall >= 0)');
            DB::statement('ALTER TABLE delivery_pricing_user_limits ADD CONSTRAINT delivery_discount_limit_valid CHECK (max_discount_percent BETWEEN 0 AND 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_pricing_user_limits');
        Schema::dropIfExists('delivery_fee_override_requests');
        Schema::dropIfExists('order_delivery_allocations');
        Schema::dropIfExists('order_delivery_pricings');
        Schema::dropIfExists('delivery_pricing_rules');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destination_area_confirmed_by');
            $table->dropConstrainedForeignId('destination_area_id');
            $table->dropColumn('destination_area_confirmed_at');
        });
    }
};
