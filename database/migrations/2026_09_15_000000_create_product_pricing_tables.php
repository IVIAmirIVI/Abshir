<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('shop_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 18, 4);
            $table->timestampTz('observed_at');
            $table->string('source', 32);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['product_id', 'shop_id', 'unit_id', 'currency_id', 'observed_at'],
                'price_history_shop_latest_idx'
            );
            $table->index(
                ['product_id', 'unit_id', 'currency_id', 'observed_at'],
                'price_history_market_latest_idx'
            );
            $table->index(['shop_id', 'observed_at'], 'price_history_shop_timeline_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_price_history ADD CONSTRAINT product_price_history_price_positive CHECK (price > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('system_settings');
    }
};
