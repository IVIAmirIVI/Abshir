<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Captain payout allocation precision requires PostgreSQL.');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_captain_payout_allocation_precision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE places integer;
BEGIN
    SELECT c.decimal_places INTO places
    FROM captain_receivable_payouts AS p JOIN currencies AS c ON c.id = p.currency_id
    WHERE p.id = NEW.captain_receivable_payout_id;
    IF places IS NULL OR NEW.amount::text IN ('NaN', 'Infinity', '-Infinity')
       OR NEW.amount <= 0 OR NEW.amount <> round(NEW.amount, places) THEN
        RAISE EXCEPTION 'Payout allocation amount violates Currency precision' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER captain_payout_allocation_precision BEFORE INSERT ON captain_receivable_payout_allocations
FOR EACH ROW EXECUTE FUNCTION validate_captain_payout_allocation_precision();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Captain payout allocation precision is intentionally forward-only.');
    }
};
