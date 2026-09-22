<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 24);
            $table->foreignId('captain_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('shop_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique('captain_id');
            $table->unique('shop_id');
            $table->unique('branch_id');
        });

        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_owner_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->string('account_kind', 48);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['financial_owner_id', 'currency_id', 'account_kind'], 'financial_accounts_identity_unique');
            $table->unique(['id', 'currency_id'], 'financial_accounts_id_currency_unique');
        });

        Schema::create('financial_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_kind', 16);
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_type', 100);
            $table->string('source_id', 191);
            $table->string('event_type', 100);
            $table->unsignedInteger('source_version')->default(1);
            $table->string('payload_hash', 64);
            $table->timestampTz('occurred_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_of_transaction_id')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id', 'event_type', 'source_version'], 'financial_transactions_source_unique');
            $table->unique('reversal_of_transaction_id');
            $table->unique(['id', 'currency_id'], 'financial_transactions_id_currency_unique');
            $table->foreign(['reversal_of_transaction_id', 'currency_id'], 'financial_transactions_reversal_currency_fk')
                ->references(['id', 'currency_id'])->on('financial_transactions')->restrictOnDelete();
            $table->index(['currency_id', 'occurred_at']);
            $table->index(['branch_id', 'occurred_at']);
        });

        Schema::create('financial_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('financial_transaction_id');
            $table->unsignedBigInteger('financial_account_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('direction', 16);
            $table->decimal('amount', 18, 4);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['financial_transaction_id', 'financial_account_id'], 'financial_entries_transaction_account_unique');
            $table->foreign(['financial_transaction_id', 'currency_id'], 'financial_entries_transaction_currency_fk')
                ->references(['id', 'currency_id'])->on('financial_transactions')->restrictOnDelete();
            $table->foreign(['financial_account_id', 'currency_id'], 'financial_entries_account_currency_fk')
                ->references(['id', 'currency_id'])->on('financial_accounts')->restrictOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $table->index(['financial_account_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE financial_owners ADD CONSTRAINT financial_owner_type_valid CHECK (owner_type IN ('CAPTAIN', 'SHOP', 'COMPANY', 'BRANCH'))");
        DB::statement("ALTER TABLE financial_owners ADD CONSTRAINT financial_owner_shape_valid CHECK ((owner_type = 'CAPTAIN' AND captain_id IS NOT NULL AND shop_id IS NULL AND branch_id IS NULL) OR (owner_type = 'SHOP' AND captain_id IS NULL AND shop_id IS NOT NULL AND branch_id IS NULL) OR (owner_type = 'BRANCH' AND captain_id IS NULL AND shop_id IS NULL AND branch_id IS NOT NULL) OR (owner_type = 'COMPANY' AND captain_id IS NULL AND shop_id IS NULL AND branch_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX financial_owners_one_company_unique ON financial_owners (owner_type) WHERE owner_type = 'COMPANY'");
        DB::statement("ALTER TABLE financial_accounts ADD CONSTRAINT financial_account_kind_valid CHECK (account_kind IN ('CAPTAIN_DEPOSIT', 'CAPTAIN_COMPANY_RECEIVABLE', 'CAPTAIN_CASH_CUSTODY'))");
        DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_transaction_kind_valid CHECK (transaction_kind IN ('EVENT', 'TRANSFER', 'REVERSAL'))");
        DB::statement('ALTER TABLE financial_transactions ADD CONSTRAINT financial_source_version_positive CHECK (source_version > 0)');
        DB::statement("ALTER TABLE financial_transactions ADD CONSTRAINT financial_reversal_shape_valid CHECK ((transaction_kind = 'REVERSAL' AND reversal_of_transaction_id IS NOT NULL) OR (transaction_kind <> 'REVERSAL' AND reversal_of_transaction_id IS NULL))");
        DB::statement("ALTER TABLE financial_entries ADD CONSTRAINT financial_entry_direction_valid CHECK (direction IN ('INCREASE', 'DECREASE'))");
        DB::statement('ALTER TABLE financial_entries ADD CONSTRAINT financial_entry_amount_positive CHECK (amount > 0)');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_financial_account_owner_kind() RETURNS trigger AS $$
DECLARE
    linked_owner_type varchar;
BEGIN
    SELECT owner_type INTO linked_owner_type FROM financial_owners WHERE id = NEW.financial_owner_id;
    IF linked_owner_type IS DISTINCT FROM 'CAPTAIN' THEN
        RAISE EXCEPTION 'Captain financial account kinds require a CAPTAIN owner' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER financial_account_owner_kind_guard
BEFORE INSERT OR UPDATE ON financial_accounts
FOR EACH ROW EXECUTE FUNCTION enforce_financial_account_owner_kind();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_financial_entry_precision() RETURNS trigger AS $$
DECLARE
    places integer;
BEGIN
    SELECT decimal_places INTO places FROM currencies WHERE id = NEW.currency_id;
    IF places IS NULL OR places < 0 OR places > 4 OR NEW.amount <> ROUND(NEW.amount, places) THEN
        RAISE EXCEPTION 'Financial amount exceeds the Currency decimal precision' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER financial_entry_precision_guard
BEFORE INSERT OR UPDATE ON financial_entries
FOR EACH ROW EXECUTE FUNCTION enforce_financial_entry_precision();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_financial_history_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Posted financial history is immutable' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER financial_transactions_immutable
BEFORE UPDATE OR DELETE ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION prevent_financial_history_mutation();

CREATE TRIGGER financial_entries_immutable
BEFORE UPDATE OR DELETE ON financial_entries
FOR EACH ROW EXECUTE FUNCTION prevent_financial_history_mutation();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_financial_transaction_entries() RETURNS trigger AS $$
DECLARE
    entry_count integer;
    signed_total numeric(18, 4);
    has_increase boolean;
    has_decrease boolean;
    original_id bigint;
    kind varchar;
BEGIN
    SELECT transaction_kind, reversal_of_transaction_id INTO kind, original_id
    FROM financial_transactions WHERE id = NEW.id;

    SELECT COUNT(*),
           COALESCE(SUM(CASE WHEN direction = 'INCREASE' THEN amount ELSE -amount END), 0),
           COALESCE(BOOL_OR(direction = 'INCREASE'), false),
           COALESCE(BOOL_OR(direction = 'DECREASE'), false)
    INTO entry_count, signed_total, has_increase, has_decrease
    FROM financial_entries WHERE financial_transaction_id = NEW.id;

    IF entry_count = 0 THEN
        RAISE EXCEPTION 'A financial transaction must contain at least one entry' USING ERRCODE = '23514';
    END IF;

    IF kind = 'TRANSFER' AND (signed_total <> 0 OR NOT has_increase OR NOT has_decrease) THEN
        RAISE EXCEPTION 'A financial transfer must be zero-sum with increases and decreases' USING ERRCODE = '23514';
    END IF;

    IF kind = 'REVERSAL' AND EXISTS (
        SELECT 1
        FROM (SELECT * FROM financial_entries WHERE financial_transaction_id = original_id) original
        FULL JOIN (SELECT * FROM financial_entries WHERE financial_transaction_id = NEW.id) reversal
          ON reversal.financial_account_id = original.financial_account_id
         AND reversal.amount = original.amount
         AND reversal.direction = CASE original.direction WHEN 'INCREASE' THEN 'DECREASE' ELSE 'INCREASE' END
        WHERE original.id IS NULL OR reversal.id IS NULL
    ) THEN
        RAISE EXCEPTION 'A financial reversal must exactly oppose every original entry' USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER financial_transaction_entries_guard
AFTER INSERT ON financial_transactions
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION validate_financial_transaction_entries();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Phase 9A financial history is intentionally forward-only.');
    }
};
