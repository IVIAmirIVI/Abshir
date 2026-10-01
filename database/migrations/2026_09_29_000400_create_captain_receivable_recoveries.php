<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Captain recovery requires PostgreSQL.');
        }
        DB::transaction(function (): void {
            DB::unprepared(<<<'SQL'
CREATE TABLE captain_receivable_recoveries (
 id bigserial PRIMARY KEY,
 invoice_financial_correction_id bigint NOT NULL REFERENCES invoice_financial_corrections(id) ON DELETE RESTRICT,
 captain_id bigint NOT NULL REFERENCES captains(id) ON DELETE RESTRICT,
 branch_id bigint NOT NULL REFERENCES branches(id) ON DELETE RESTRICT,
 currency_id bigint NOT NULL REFERENCES currencies(id) ON DELETE RESTRICT,
 amount numeric NOT NULL CHECK(amount::text NOT IN ('NaN','Infinity','-Infinity') AND amount>0),
 method varchar(16) NOT NULL DEFAULT 'CASH' CHECK(method='CASH'),
 received_by_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 received_at timestamptz NOT NULL,
 request_key uuid NOT NULL UNIQUE,
 reference varchar(191),
 evidence_path varchar(255) NOT NULL CHECK(evidence_path ~ '^proof/[0-9a-f-]{36}\.(jpg|png|webp)$'),
 evidence_name varchar(255) NOT NULL CHECK(length(evidence_name)>0),
 evidence_mime varchar(32) NOT NULL CHECK(evidence_mime IN ('image/jpeg','image/png','image/webp')),
 evidence_size bigint NOT NULL CHECK(evidence_size BETWEEN 1 AND 5242880),
 evidence_sha256 char(64) NOT NULL CHECK(evidence_sha256 ~ '^[0-9a-f]{64}$'),
 payload_hash char(64) NOT NULL CHECK(payload_hash ~ '^[0-9a-f]{64}$'),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(invoice_financial_correction_id,captain_id,branch_id,currency_id)
);
CREATE TABLE captain_receivable_recovery_allocations (
 id bigserial PRIMARY KEY,
 captain_receivable_recovery_id bigint NOT NULL REFERENCES captain_receivable_recoveries(id) ON DELETE RESTRICT,
 disposition_id bigint NOT NULL UNIQUE REFERENCES invoice_correction_receivable_dispositions(id) ON DELETE RESTRICT,
 amount numeric NOT NULL CHECK(amount::text NOT IN ('NaN','Infinity','-Infinity') AND amount>0),
 UNIQUE(captain_receivable_recovery_id,disposition_id)
);
CREATE OR REPLACE FUNCTION captain_receivable_recoveries_active() RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT false; $$;
CREATE OR REPLACE FUNCTION guard_captain_recovery_write() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Captain CASH recovery history is immutable' USING ERRCODE='55000'; END IF;
 IF NOT captain_receivable_recoveries_active() THEN RAISE EXCEPTION 'Captain recovery deployment is not active' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER recovery_immutable BEFORE INSERT OR UPDATE OR DELETE ON captain_receivable_recoveries
 FOR EACH ROW EXECUTE FUNCTION guard_captain_recovery_write();
CREATE TRIGGER recovery_allocation_immutable BEFORE INSERT OR UPDATE OR DELETE ON captain_receivable_recovery_allocations
 FOR EACH ROW EXECUTE FUNCTION guard_captain_recovery_write();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Physical CASH recovery history is forward-only.');
    }
};
