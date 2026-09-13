<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('areas')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 64)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'parent_id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name')->index();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('customer_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('phone', 50);
            $table->string('normalized_phone', 32)->index();
            $table->string('label', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['customer_id', 'normalized_phone']);
            $table->index(['customer_id', 'is_primary']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('address_line_1', 500);
            $table->string('address_line_2', 500)->nullable();
            $table->string('landmark', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['customer_id', 'is_primary']);
            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_phones');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('areas');
    }
};
