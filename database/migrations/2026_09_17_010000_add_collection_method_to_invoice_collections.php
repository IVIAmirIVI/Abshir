<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_collections', function (Blueprint $table) {
            $table->string('collection_method', 20)->nullable()->after('order_captain_id')->index();
            $table->string('payment_reference')->nullable()->after('collection_method');
            $table->foreignId('captain_id')->nullable()->change();
            $table->foreignId('order_captain_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE invoice_collections ADD CONSTRAINT invoice_collection_method_valid CHECK (collection_method IS NULL OR collection_method IN ('CASH', 'SHAM_CASH'))");
            DB::statement("ALTER TABLE invoice_collections ADD CONSTRAINT invoice_collection_captain_matches_method CHECK (collection_method IS NULL OR (collection_method = 'CASH' AND captain_id IS NOT NULL AND order_captain_id IS NOT NULL) OR (collection_method = 'SHAM_CASH' AND captain_id IS NULL AND order_captain_id IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoice_collections DROP CONSTRAINT IF EXISTS invoice_collection_method_valid');
            DB::statement('ALTER TABLE invoice_collections DROP CONSTRAINT IF EXISTS invoice_collection_captain_matches_method');
        }

        Schema::table('invoice_collections', function (Blueprint $table) {
            $table->dropIndex(['collection_method']);
            $table->dropColumn(['collection_method', 'payment_reference']);
        });
    }
};
