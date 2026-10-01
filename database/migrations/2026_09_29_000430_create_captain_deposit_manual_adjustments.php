<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Manual Deposit adjustments require PostgreSQL.');
        }
        Schema::create('captain_deposit_manual_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('adjusted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('direction', 8);
            $table->text('reason');
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->timestampTz('occurred_at', 6);
            $table->string('evidence_path', 191);
            $table->string('evidence_name');
            $table->string('evidence_mime', 64);
            $table->unsignedInteger('evidence_size');
            $table->string('evidence_sha256', 64);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['captain_id', 'branch_id', 'currency_id'], 'manual_deposit_context');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE captain_deposit_manual_adjustments ADD COLUMN amount numeric NOT NULL;
ALTER TABLE captain_deposit_manual_adjustments ADD CONSTRAINT manual_deposit_facts CHECK (
    direction IN ('INCREASE','DECREASE') AND reason ~ '[^[:space:]]'
    AND amount::text NOT IN ('NaN','Infinity','-Infinity') AND amount > 0
    AND evidence_path ~ '^proof/[0-9a-f-]{36}\.(jpg|png|webp)$'
    AND evidence_name ~ '[^[:space:]]'
    AND evidence_mime IN ('image/jpeg','image/png','image/webp')
    AND evidence_size BETWEEN 1 AND 5242880
    AND evidence_sha256 ~ '^[0-9a-f]{64}$' AND payload_hash ~ '^[0-9a-f]{64}$'
);
CREATE OR REPLACE FUNCTION captain_manual_deposits_active() RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT false $$;
CREATE OR REPLACE FUNCTION guard_manual_deposit_source() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Manual Deposit adjustment history is immutable' USING ERRCODE='55000';
    END IF;
    RAISE EXCEPTION 'Manual Deposit adjustments are not activated' USING ERRCODE='23514';
END;
$$;
CREATE TRIGGER manual_deposit_source_guard BEFORE INSERT OR UPDATE OR DELETE ON captain_deposit_manual_adjustments
FOR EACH ROW EXECUTE FUNCTION guard_manual_deposit_source();
CREATE OR REPLACE FUNCTION guard_manual_deposit_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.source_type='CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT'
       OR NEW.event_type IN ('CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_INCREASE','CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_DECREASE')
       OR EXISTS (SELECT 1 FROM financial_transactions t WHERE t.id=NEW.reversal_of_transaction_id
           AND t.source_type='CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT') THEN
        RAISE EXCEPTION 'Manual Deposit adjustments are not activated' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER manual_deposit_event_guard BEFORE INSERT ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION guard_manual_deposit_event();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Manual Deposit financial history is forward-only.');
    }
};
