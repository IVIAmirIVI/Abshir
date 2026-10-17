<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shops', function (Blueprint $table): void {
            $table->foreignId('origin_area_id')->nullable()->after('shop_id')->constrained('areas')->restrictOnDelete();
        });

        DB::statement('UPDATE order_shops AS os SET origin_area_id = s.area_id FROM shops AS s WHERE os.shop_id = s.id AND os.origin_area_id IS NULL AND s.area_id IS NOT NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: Order Shop origin snapshots must not be discarded.');
    }
};
