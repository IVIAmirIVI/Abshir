<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Order financial timestamp precision requires PostgreSQL.');
        }

        // Also upgrades databases where 120000 ran before its timestamp portion
        // was added. Reapplying the same widening on fresh installs is harmless.
        DB::unprepared(<<<'SQL'
ALTER TABLE invoices ALTER COLUMN issued_at TYPE timestamptz(6);
ALTER TABLE invoice_collections ALTER COLUMN collected_at TYPE timestamptz(6);
ALTER TABLE order_status_history ALTER COLUMN changed_at TYPE timestamptz(6);
ALTER TABLE financial_transactions ALTER COLUMN occurred_at TYPE timestamptz(6);
ALTER TABLE order_financial_source_snapshots ALTER COLUMN issued_at TYPE timestamptz(6);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Order financial timestamp precision is intentionally forward-only.');
    }
};
