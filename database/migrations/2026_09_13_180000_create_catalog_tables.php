<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 16)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('default_currency_id')->nullable()->after('phone')->constrained('currencies')->restrictOnDelete();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name')->index();
            $table->string('code', 64)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->string('symbol', 32)->nullable();
            $table->boolean('allows_decimal')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('default_unit_id')->constrained('units')->restrictOnDelete();
            $table->string('name')->index();
            $table->string('sku', 100)->nullable()->unique();
            $table->string('barcode', 100)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('storage_path');
            $table->string('alt_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });

        DB::statement('CREATE UNIQUE INDEX product_one_primary_image_idx ON product_images (product_id) WHERE is_primary = TRUE');

        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('code', 64)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('product_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_group_id')->constrained()->restrictOnDelete();
            $table->timestampTz('joined_at')->index();
            $table->timestampTz('left_at')->nullable()->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'product_group_id', 'joined_at'], 'product_group_member_history_idx');
        });

        DB::statement('CREATE UNIQUE INDEX product_group_one_open_membership_idx ON product_group_members (product_id, product_group_id) WHERE left_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_group_members');
        Schema::dropIfExists('product_groups');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
        Schema::table('branches', fn (Blueprint $table) => $table->dropConstrainedForeignId('default_currency_id'));
        Schema::dropIfExists('currencies');
    }
};
