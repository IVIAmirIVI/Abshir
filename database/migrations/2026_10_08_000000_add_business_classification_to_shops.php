<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('business_classification', 16)->nullable();
        });
        DB::statement("ALTER TABLE shops ADD CONSTRAINT shops_business_classification_check CHECK (business_classification IS NULL OR business_classification IN ('SHOP', 'RESTAURANT'))");
    }

    public function down(): void
    {
        throw new RuntimeException('Shop master classifications are forward-only; use a new migration to preserve classified master data.');
    }
};
