<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('code', 64)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name')->index();
            $table->string('code', 64)->nullable()->unique();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_mobile_visible')->default(false)->index();
            $table->boolean('accepts_online_orders')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('shop_partnerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->timestampTz('starts_at')->index();
            $table->timestampTz('ends_at')->nullable()->index();
            $table->string('status', 32)->index();
            $table->boolean('mobile_enabled')->default(false);
            $table->boolean('online_orders_enabled')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shop_id', 'starts_at']);
        });

        DB::statement('CREATE UNIQUE INDEX shop_one_open_partnership_idx ON shop_partnerships (shop_id) WHERE ends_at IS NULL');

        Schema::create('shop_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('listed_price', 18, 4)->nullable();
            $table->boolean('is_available')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['shop_id', 'product_id', 'unit_id'], 'shop_product_unit_unique');
            $table->index(['shop_id', 'is_active']);
            $table->index(['product_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_products');
        Schema::dropIfExists('shop_partnerships');
        Schema::dropIfExists('shops');
        Schema::dropIfExists('shop_types');
    }
};
