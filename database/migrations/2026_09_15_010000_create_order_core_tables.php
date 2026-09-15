<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_phone_id')->nullable()->constrained('customer_phones')->nullOnDelete();
            $table->foreignId('customer_address_id')->nullable()->constrained('customer_addresses')->nullOnDelete();
            $table->string('order_number')->unique();
            $table->timestampTz('ordered_at')->index();
            $table->string('operational_status', 32)->index();
            $table->string('pricing_status', 32)->index();
            $table->string('customer_name_snapshot');
            $table->string('customer_phone_snapshot', 50);
            $table->string('customer_phone_normalized_snapshot', 32)->nullable();
            $table->string('address_label_snapshot', 100)->nullable();
            $table->string('recipient_name_snapshot')->nullable();
            $table->string('area_name_snapshot');
            $table->string('area_path_snapshot')->nullable();
            $table->string('address_line_1_snapshot', 500);
            $table->string('address_line_2_snapshot', 500)->nullable();
            $table->string('landmark_snapshot', 500)->nullable();
            $table->decimal('latitude_snapshot', 10, 7)->nullable();
            $table->decimal('longitude_snapshot', 11, 7)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'ordered_at']);
            $table->index(['customer_id', 'ordered_at']);
        });
        Schema::create('order_shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('shop_type', 24);
            $table->foreignId('shop_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('shop_name_snapshot');
            $table->string('shop_phone_snapshot', 50)->nullable();
            $table->string('shop_address_snapshot', 500)->nullable();
            $table->string('area_name_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['order_id', 'sort_order']);
            $table->index('shop_id');
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_shop_id')->constrained()->restrictOnDelete();
            $table->string('item_type', 24);
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit_name_snapshot');
            $table->string('unit_symbol_snapshot')->nullable();
            $table->decimal('requested_quantity', 18, 4);
            $table->decimal('current_quantity', 18, 4);
            $table->text('notes')->nullable();
            $table->decimal('estimated_unit_price', 18, 4)->nullable();
            $table->string('estimated_price_method', 40)->nullable();
            $table->foreignId('estimated_source_shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->foreignId('estimated_source_price_observation_id')->nullable()->constrained('product_price_history')->nullOnDelete();
            $table->timestampTz('estimated_source_observed_at')->nullable();
            $table->unsignedSmallInteger('estimation_window_days')->nullable();
            $table->foreignId('estimated_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('actual_unit_price', 18, 4)->nullable();
            $table->string('actual_price_source', 32)->nullable();
            $table->foreignId('actual_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->timestampTz('actual_price_confirmed_at')->nullable();
            $table->foreignId('actual_price_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['order_shop_id', 'product_id']);
            $table->index('unit_id');
        });
        Schema::create('order_captains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->string('assignment_role', 32)->index();
            $table->timestampTz('assigned_at');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('removed_at')->nullable()->index();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'removed_at']);
            $table->index(['captain_id', 'removed_at']);
        });
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'changed_at']);
        });
        Schema::create('order_item_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->string('change_type', 32);
            $table->json('previous_values');
            $table->json('new_values');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at');
            $table->text('reason');
            $table->timestamps();
            $table->index(['order_item_id', 'changed_at']);
        });
        DB::statement("CREATE UNIQUE INDEX order_one_active_primary_captain_idx ON order_captains (order_id) WHERE removed_at IS NULL AND assignment_role = 'PRIMARY_COLLECTION'");
        DB::statement('CREATE UNIQUE INDEX order_one_active_captain_idx ON order_captains (order_id, captain_id) WHERE removed_at IS NULL');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_item_quantities_positive CHECK (requested_quantity > 0 AND current_quantity > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_item_prices_positive CHECK ((estimated_unit_price IS NULL OR estimated_unit_price > 0) AND (actual_unit_price IS NULL OR actual_unit_price > 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_changes');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_captains');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('order_shops');
        Schema::dropIfExists('orders');
    }
};
