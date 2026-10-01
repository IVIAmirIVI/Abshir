<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Corrected Invoice guard requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_corrected_replacement_invoice() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM invoice_financial_corrections c
        WHERE c.replacement_invoice_id=OLD.id AND c.state='COMPLETED') THEN
        RAISE EXCEPTION 'Completed corrected replacement Invoice is immutable' USING ERRCODE='55000';
    END IF;
    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Corrected Invoice history is intentionally forward-only.');
    }
};
