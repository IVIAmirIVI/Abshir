<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE captain_status_changes ALTER COLUMN previous_status DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::table('captain_status_changes')->whereNull('previous_status')->update([
                'previous_status' => DB::raw('new_status'),
            ]);
            DB::statement('ALTER TABLE captain_status_changes ALTER COLUMN previous_status SET NOT NULL');
        }
    }
};
