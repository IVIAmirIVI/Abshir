<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number')->unique();
            $table->unsignedInteger('version');
            $table->string('status', 20)->index();
            $table->foreignId('currency_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_order_delivery_pricing_id')->nullable()->constrained('order_delivery_pricings')->restrictOnDelete();
            $table->string('source_items_fingerprint', 64)->nullable();
            $table->decimal('items_subtotal', 18, 4)->nullable();
            $table->decimal('delivery_total', 18, 4)->nullable();
            $table->decimal('grand_total', 18, 4)->nullable();
            $table->string('customer_name_snapshot');
            $table->string('customer_phone_snapshot', 50);
            $table->text('address_snapshot');
            $table->string('area_snapshot')->nullable();
            $table->string('order_number_snapshot');
            $table->timestampTz('ordered_at_snapshot');
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('issued_at')->nullable()->index();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestampTz('replaced_at')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('replacement_reason')->nullable();
            $table->foreignId('replaced_by_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'version']);
            $table->index(['order_id', 'superseded_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->string('unit_name_snapshot');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('line_total', 18, 4);
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->unique(['invoice_id', 'sort_order']);
            $table->index(['invoice_id', 'order_item_id']);
        });

        Schema::create('invoice_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestampTz('collected_at')->index();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'collected_at']);
            $table->index(['captain_id', 'collected_at']);
        });

        DB::statement('CREATE UNIQUE INDEX order_one_current_invoice_idx ON invoices (order_id) WHERE superseded_at IS NULL');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoice_status_valid CHECK (status IN ('DRAFT', 'READY', 'ISSUED', 'VOIDED', 'REPLACED'))");
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoice_totals_valid CHECK ((items_subtotal IS NULL AND delivery_total IS NULL AND grand_total IS NULL) OR (items_subtotal >= 0 AND delivery_total >= 0 AND grand_total = items_subtotal + delivery_total))');
            DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_item_values_valid CHECK (quantity > 0 AND unit_price > 0 AND line_total >= 0)');
            DB::statement('ALTER TABLE invoice_collections ADD CONSTRAINT invoice_collection_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_collections');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
