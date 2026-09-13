<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('home_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('operational_status', 32)->default('UNAVAILABLE')->index();
            $table->string('employee_code', 64)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('captain_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->string('assignment_type', 32)->index();
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->timestampTz('starts_at')->index();
            $table->timestampTz('ends_at')->nullable()->index();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['captain_id', 'assignment_type', 'starts_at']);
            $table->index(['branch_id', 'assignment_type', 'starts_at', 'ends_at'], 'captain_assignment_branch_active_idx');
        });

        Schema::create('captain_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('captain_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('captain_group_id')->constrained()->restrictOnDelete();
            $table->timestampTz('joined_at')->index();
            $table->timestampTz('left_at')->nullable()->index();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['captain_id', 'captain_group_id', 'joined_at'], 'captain_group_member_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captain_group_members');
        Schema::dropIfExists('captain_groups');
        Schema::dropIfExists('captain_branch_assignments');
        Schema::dropIfExists('captains');
    }
};
