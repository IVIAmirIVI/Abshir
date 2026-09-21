<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('source', 24)->nullable()->after('ordered_at')->index();
            $table->string('entry_mode', 24)->nullable()->after('source')->index();
        });
        Schema::table('order_shops', function (Blueprint $table): void {
            $table->timestampTz('contacted_at')->nullable()->after('sort_order');
            $table->timestampTz('confirmed_at')->nullable()->after('contacted_at');
            $table->timestampTz('ready_at')->nullable()->after('confirmed_at');
            $table->timestampTz('picked_up_at')->nullable()->after('ready_at');
        });
        Schema::create('order_shop_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_shop_id')->constrained()->restrictOnDelete();
            $table->string('storage_path', 1000)->unique();
            $table->string('original_filename', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('uploaded_at')->index();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['order_shop_id', 'uploaded_at']);
        });
        Schema::create('order_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('classification', 32)->index();
            $table->string('reason', 40)->index();
            $table->text('notes')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->index();
            $table->timestamps();
        });
        Schema::create('captain_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_captain_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('source', 32);
            $table->timestampTz('rated_at')->index();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'captain_id']);
            $table->index(['captain_id', 'rated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT order_source_valid CHECK (source IS NULL OR source IN ('WHATSAPP', 'PHONE', 'MANUAL', 'MOBILE_APP'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT order_entry_mode_valid CHECK (entry_mode IS NULL OR entry_mode IN ('LIVE', 'HISTORICAL_MANUAL'))");
            DB::statement("ALTER TABLE order_cancellations ADD CONSTRAINT order_cancellation_class_valid CHECK (classification IN ('BEFORE_PURCHASE', 'AFTER_PURCHASE'))");
            DB::statement("ALTER TABLE order_cancellations ADD CONSTRAINT order_cancellation_reason_valid CHECK (reason IN ('CUSTOMER_CANCELLED', 'CUSTOMER_UNREACHABLE', 'CUSTOMER_REFUSED', 'SHOP_UNAVAILABLE', 'OPERATIONAL', 'OTHER'))");
            DB::statement('ALTER TABLE captain_ratings ADD CONSTRAINT captain_rating_range_valid CHECK (rating BETWEEN 1 AND 5)');
            DB::statement("ALTER TABLE captain_ratings ADD CONSTRAINT captain_rating_source_valid CHECK (source IN ('WHATSAPP_MANUAL', 'CUSTOMER_APP'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Phase 8.5 contains immutable evidence and history and is intentionally forward-only.');
    }
};
