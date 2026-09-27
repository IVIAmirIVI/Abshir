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
            throw new RuntimeException('Order financial source snapshots require PostgreSQL.');
        }

        Schema::create('order_financial_source_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
            $table->unsignedInteger('invoice_version');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_delivery_pricing_id')->constrained('order_delivery_pricings')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('primary_order_captain_id')->constrained('order_captains')->restrictOnDelete();
            $table->decimal('items_subtotal', 18, 4);
            $table->jsonb('captain_allocations');
            $table->char('payload_hash', 64);
            $table->timestampTz('captured_at');
        });

        DB::statement("ALTER TABLE order_financial_source_snapshots ADD CONSTRAINT order_financial_snapshot_valid CHECK (items_subtotal >= 0 AND jsonb_typeof(captain_allocations) = 'array' AND jsonb_array_length(captain_allocations) > 0 AND payload_hash ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_order_financial_snapshot_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Order financial source snapshots are immutable' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER order_financial_source_snapshot_immutable
BEFORE UPDATE OR DELETE ON order_financial_source_snapshots
FOR EACH ROW EXECUTE FUNCTION reject_order_financial_snapshot_mutation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Order financial source snapshots are intentionally forward-only.');
    }
};
