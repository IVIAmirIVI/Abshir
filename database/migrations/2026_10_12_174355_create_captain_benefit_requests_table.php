<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captain_benefit_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->uuid('proposal_key');
            $table->unsignedInteger('version');
            $table->foreignId('replaces_id')->nullable()->unique()->constrained('captain_benefit_requests')->restrictOnDelete();
            $table->foreignId('captain_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->text('amount');
            $table->text('description');
            $table->string('category', 30)->nullable();
            $table->date('expense_date')->nullable();
            $table->text('revision_note')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('recorded_at');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->string('evidence_mime', 30)->nullable();
            $table->unsignedInteger('evidence_size')->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->unique(['proposal_key', 'version']);
            $table->index(['captain_id', 'branch_id', 'recorded_at']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE captain_benefit_requests ALTER COLUMN amount TYPE NUMERIC USING amount::NUMERIC;
ALTER TABLE captain_benefit_requests ADD CONSTRAINT benefit_amount CHECK (amount > 0 AND amount::text NOT IN ('NaN', 'Infinity', '-Infinity'));
ALTER TABLE captain_benefit_requests ADD CONSTRAINT benefit_description CHECK (length(btrim(description)) BETWEEN 1 AND 5000);
ALTER TABLE captain_benefit_requests ADD CONSTRAINT benefit_kind CHECK (
    (kind='BONUS' AND category IS NULL AND expense_date IS NULL) OR
    (kind='EXPENSE' AND category IS NOT NULL AND category IN ('TRANSPORT','COMMUNICATION','EQUIPMENT','OTHER') AND expense_date IS NOT NULL));
ALTER TABLE captain_benefit_requests ADD CONSTRAINT benefit_version CHECK (
    (version=1 AND replaces_id IS NULL AND revision_note IS NULL) OR
    (version>1 AND replaces_id IS NOT NULL AND revision_note IS NOT NULL AND length(btrim(revision_note)) BETWEEN 1 AND 2000));
ALTER TABLE captain_benefit_requests ADD CONSTRAINT benefit_evidence CHECK (
    (evidence_path IS NULL AND evidence_name IS NULL AND evidence_mime IS NULL AND evidence_size IS NULL AND evidence_sha256 IS NULL) OR
    (evidence_path IS NOT NULL AND evidence_name IS NOT NULL AND evidence_mime IS NOT NULL AND evidence_mime IN ('image/jpeg','image/png','image/webp') AND evidence_size IS NOT NULL AND evidence_size BETWEEN 1 AND 5242880 AND evidence_sha256 IS NOT NULL));
CREATE FUNCTION guard_captain_benefit_request() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous captain_benefit_requests%ROWTYPE; precision integer;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Captain proposal history is append-only' USING ERRCODE='23514';
    END IF;
    SELECT decimal_places INTO precision FROM currencies WHERE id=NEW.currency_id FOR SHARE;
    IF scale(trim_scale(NEW.amount)) > precision THEN
        RAISE EXCEPTION 'Proposal amount exceeds currency precision' USING ERRCODE='23514';
    END IF;
    IF NEW.order_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM orders WHERE id=NEW.order_id AND branch_id=NEW.branch_id) THEN
        RAISE EXCEPTION 'Proposal Order belongs to another branch' USING ERRCODE='23514';
    END IF;
    IF NEW.replaces_id IS NOT NULL THEN
        SELECT * INTO previous FROM captain_benefit_requests WHERE id=NEW.replaces_id FOR KEY SHARE;
        IF NOT FOUND OR NEW.version<>previous.version+1 OR
            ROW(NEW.proposal_key,NEW.captain_id,NEW.branch_id,NEW.currency_id,NEW.order_id,NEW.kind,NEW.created_by_user_id,NEW.created_at)
            IS DISTINCT FROM
            ROW(previous.proposal_key,previous.captain_id,previous.branch_id,previous.currency_id,previous.order_id,previous.kind,previous.created_by_user_id,previous.created_at) THEN
            RAISE EXCEPTION 'Proposal revision identity mismatch' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER captain_benefit_guard BEFORE INSERT OR UPDATE OR DELETE ON captain_benefit_requests FOR EACH ROW EXECUTE FUNCTION guard_captain_benefit_request();
CREATE TRIGGER captain_benefit_truncate_guard BEFORE TRUNCATE ON captain_benefit_requests FOR EACH STATEMENT EXECUTE FUNCTION guard_captain_benefit_request();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Captain proposal history is forward-only.');
    }
};
