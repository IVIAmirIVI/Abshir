<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captain_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->index();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at')->index();
            $table->timestamps();
            $table->index(['captain_id', 'changed_at']);
        });

        Schema::create('captain_employment_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->timestampTz('started_at')->index();
            $table->timestampTz('ended_at')->nullable()->index();
            $table->text('end_reason')->nullable();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['captain_id', 'started_at']);
        });

        DB::statement('CREATE UNIQUE INDEX captain_one_open_employment_idx ON captain_employment_periods (captain_id) WHERE ended_at IS NULL');

        if (Schema::hasColumn('captains', 'hired_at')) {
            DB::table('captains')->orderBy('id')->each(function (object $captain): void {
                if ($captain->hired_at === null) {
                    return;
                }

                DB::table('captain_employment_periods')->insert([
                    'captain_id' => $captain->id,
                    'started_at' => $captain->hired_at,
                    'ended_at' => $captain->ended_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            Schema::table('captains', function (Blueprint $table) {
                $table->dropColumn(['hired_at', 'ended_at']);
            });
        }

        if (Schema::hasColumn('captains', 'status_changed_at')) {
            DB::table('captains')->whereNotNull('status_changed_at')->orderBy('id')->each(function (object $captain): void {
                DB::table('captain_status_changes')->insert([
                    'captain_id' => $captain->id,
                    'previous_status' => null,
                    'new_status' => $captain->operational_status,
                    'changed_by_user_id' => $captain->status_changed_by_user_id,
                    'changed_at' => $captain->status_changed_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            Schema::table('captains', function (Blueprint $table) {
                $table->dropConstrainedForeignId('status_changed_by_user_id');
                $table->dropColumn('status_changed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('captains', function (Blueprint $table) {
            $table->date('hired_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->foreignId('status_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::dropIfExists('captain_employment_periods');
        Schema::dropIfExists('captain_status_changes');
    }
};
