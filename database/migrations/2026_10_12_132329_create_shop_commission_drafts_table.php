<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shop_commission_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('configuration_key');
            $table->unsignedInteger('version');
            $table->foreignId('replaces_id')->nullable()->unique()->constrained('shop_commission_drafts')->restrictOnDelete();
            $table->foreignId('shop_partnership_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('target_name_snapshot');
            $table->string('currency_code_snapshot', 3)->nullable();
            $table->text('terms');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->unique(['configuration_key', 'version']);
            $table->index(['shop_partnership_id', 'recorded_at']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE shop_commission_drafts ADD CONSTRAINT commission_draft_target CHECK ((product_id IS NULL) <> (product_group_id IS NULL));
ALTER TABLE shop_commission_drafts ADD CONSTRAINT commission_draft_version CHECK (version > 0 AND ((version = 1 AND replaces_id IS NULL) OR (version > 1 AND replaces_id IS NOT NULL)));
ALTER TABLE shop_commission_drafts ADD CONSTRAINT commission_draft_terms CHECK (length(btrim(terms)) > 0 AND length(terms) <= 5000);
ALTER TABLE shop_commission_drafts ADD CONSTRAINT commission_draft_currency CHECK ((currency_id IS NULL) = (currency_code_snapshot IS NULL));
CREATE UNIQUE INDEX commission_draft_product_root ON shop_commission_drafts (shop_partnership_id, product_id, COALESCE(currency_id, 0)) WHERE version=1 AND product_id IS NOT NULL;
CREATE UNIQUE INDEX commission_draft_group_root ON shop_commission_drafts (shop_partnership_id, product_group_id, COALESCE(currency_id, 0)) WHERE version=1 AND product_group_id IS NOT NULL;
CREATE FUNCTION guard_shop_commission_draft() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous shop_commission_drafts%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Commission drafts are append-only; add a revision' USING ERRCODE='23514';
    END IF;
    IF NEW.replaces_id IS NOT NULL THEN
        SELECT * INTO previous FROM shop_commission_drafts WHERE id=NEW.replaces_id FOR KEY SHARE;
        IF NOT FOUND OR NEW.version<>previous.version+1
            OR NEW.configuration_key IS DISTINCT FROM previous.configuration_key
            OR NEW.shop_partnership_id IS DISTINCT FROM previous.shop_partnership_id
            OR NEW.product_id IS DISTINCT FROM previous.product_id
            OR NEW.product_group_id IS DISTINCT FROM previous.product_group_id
            OR NEW.currency_id IS DISTINCT FROM previous.currency_id
            OR NEW.target_name_snapshot IS DISTINCT FROM previous.target_name_snapshot
            OR NEW.currency_code_snapshot IS DISTINCT FROM previous.currency_code_snapshot THEN
            RAISE EXCEPTION 'Commission draft revision identity mismatch' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER shop_commission_draft_guard BEFORE INSERT OR UPDATE OR DELETE ON shop_commission_drafts FOR EACH ROW EXECUTE FUNCTION guard_shop_commission_draft();
CREATE TRIGGER shop_commission_draft_truncate_guard BEFORE TRUNCATE ON shop_commission_drafts FOR EACH STATEMENT EXECUTE FUNCTION guard_shop_commission_draft();
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Commission draft history is forward-only; rollback is not supported.');
    }
};
