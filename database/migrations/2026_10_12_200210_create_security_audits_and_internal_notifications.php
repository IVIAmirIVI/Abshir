<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('resource_kind', 16);
            $table->string('resource_key', 64);
            $table->string('action', 40);
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('before_values');
            $table->jsonb('after_values');
            $table->uuid('operation_key')->nullable()->unique();
            $table->string('payload_hash', 64)->nullable();
            $table->timestampTz('recorded_at', 6);
            $table->index(['resource_kind', 'resource_key', 'recorded_at']);
            $table->index(['branch_id', 'recorded_at']);
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at', 6)->nullable();
            $table->timestamps(6);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE security_audits ADD CONSTRAINT security_audit_resource CHECK(resource_kind IN ('USER','ROLE','BRANCH','NOTICE'));
ALTER TABLE security_audits ADD CONSTRAINT security_audit_action CHECK(action IN ('USER_CREATED','USER_ACCESS_CHANGED','USER_STATUS_CHANGED','ROLE_CREATED','ROLE_ACCESS_CHANGED','BRANCH_CREATED','BRANCH_ACCESS_CHANGED','BRANCH_STATUS_CHANGED','NOTICE_SENT'));
ALTER TABLE security_audits ADD CONSTRAINT security_audit_values CHECK(jsonb_typeof(before_values)='object' AND jsonb_typeof(after_values)='object');
CREATE FUNCTION security_audit_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Security audit history is append-only' USING ERRCODE='23514'; END $$;
CREATE TRIGGER security_audit_immutable BEFORE UPDATE OR DELETE ON security_audits FOR EACH ROW EXECUTE FUNCTION security_audit_immutable();
CREATE TRIGGER security_audit_no_truncate BEFORE TRUNCATE ON security_audits FOR EACH STATEMENT EXECUTE FUNCTION security_audit_immutable();
CREATE UNIQUE INDEX internal_notice_recipient_identity ON notifications(notifiable_type,notifiable_id,((data::jsonb)->>'request_key'))
    WHERE type='App\Notifications\InternalStaffNotice';
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Security audit history is forward-only.');
    }
};
