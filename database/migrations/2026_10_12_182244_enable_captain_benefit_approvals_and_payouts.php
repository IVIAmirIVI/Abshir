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
            throw new RuntimeException('Captain benefit approvals require PostgreSQL.');
        }
        Schema::create('captain_benefit_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captain_benefit_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 12);
            $table->text('reason')->nullable();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->timestampTz('recorded_at', 6);
            $table->unique(['captain_benefit_request_id', 'action']);
        });
        Schema::table('captain_receivable_payouts', function (Blueprint $table) {
            $table->string('payment_method', 12)->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE captain_benefit_requests ALTER COLUMN created_at TYPE TIMESTAMPTZ(6), ALTER COLUMN recorded_at TYPE TIMESTAMPTZ(6)');
        foreach (['captain_bonuses.approve', 'captain_expenses.approve'] as $code) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]);
            $permission = DB::table('permissions')->where('code', $code)->value('id');
            $role = DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
            if ($role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
        $definition = DB::scalar("SELECT pg_get_functiondef('validate_captain_financial_operation_9c3a(text,bigint)'::regprocedure)");
        $needle = "'ORDER_V2_DELIVERY_ENTITLEMENT_DUE')";
        if (substr_count($definition, $needle) !== 1) {
            throw new RuntimeException('Unexpected installed payout whitelist; refusing benefit activation.');
        }
        DB::unprepared(str_replace($needle, "'ORDER_V2_DELIVERY_ENTITLEMENT_DUE','CAPTAIN_BONUS_DUE','CAPTAIN_EXPENSE_REIMBURSEMENT_DUE')", $definition));
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX benefit_review_terminal ON captain_benefit_reviews(captain_benefit_request_id) WHERE action IN ('APPROVE','REJECT','CANCEL');
ALTER TABLE captain_benefit_reviews ADD CONSTRAINT benefit_review_action CHECK (action IN ('SUBMIT','APPROVE','REJECT','CANCEL','REVERSE'));
ALTER TABLE captain_benefit_reviews ADD CONSTRAINT benefit_review_reason CHECK (action NOT IN ('REJECT','REVERSE') OR (reason IS NOT NULL AND length(btrim(reason)) BETWEEN 1 AND 2000));
ALTER TABLE captain_receivable_payouts ADD CONSTRAINT benefit_payout_method CHECK (
    (payment_method IS NULL AND received_by_user_id IS NULL) OR
    (payment_method IS NOT NULL AND payment_method IN ('CASH','TRANSFER') AND received_by_user_id IS NOT NULL AND (payment_method<>'TRANSFER' OR (reference IS NOT NULL AND length(btrim(reference))>0))));

CREATE FUNCTION benefit_actor_allowed(p_actor bigint,p_branch bigint,p_permission text) RETURNS boolean LANGUAGE sql STABLE AS $$
SELECT EXISTS(SELECT 1 FROM users u WHERE u.id=p_actor AND u.is_active AND
    (EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code='SUPER_ADMIN') OR
     (EXISTS(SELECT 1 FROM user_branches ub WHERE ub.user_id=u.id AND ub.branch_id=p_branch) AND
      EXISTS(SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=u.id AND p.code=p_permission))));
$$;
CREATE FUNCTION guard_benefit_review() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p captain_benefit_requests%ROWTYPE; submitted_by bigint; places integer; permission text;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Benefit reviews are immutable' USING ERRCODE='23514'; END IF;
    SELECT currency_id INTO places FROM captain_benefit_requests WHERE id=NEW.captain_benefit_request_id;
    PERFORM id FROM currencies WHERE id=places FOR UPDATE;
    SELECT * INTO p FROM captain_benefit_requests WHERE id=NEW.captain_benefit_request_id FOR UPDATE;
    IF p.id IS NULL OR EXISTS(SELECT 1 FROM captain_benefit_requests WHERE replaces_id=p.id) THEN
        RAISE EXCEPTION 'Benefit review requires the latest request revision' USING ERRCODE='23514';
    END IF;
    permission:=CASE WHEN NEW.action IN ('SUBMIT','CANCEL') THEN 'captains.update'
        WHEN p.kind='BONUS' THEN 'captain_bonuses.approve' ELSE 'captain_expenses.approve' END;
    IF NOT benefit_actor_allowed(NEW.actor_id,p.branch_id,permission) THEN
        RAISE EXCEPTION 'Benefit reviewer lacks branch permission' USING ERRCODE='23514';
    END IF;
    IF NEW.recorded_at > clock_timestamp() OR NEW.recorded_at < p.recorded_at THEN
        RAISE EXCEPTION 'Invalid benefit review time' USING ERRCODE='23514';
    END IF;
    SELECT actor_id INTO submitted_by FROM captain_benefit_reviews WHERE captain_benefit_request_id=p.id AND action='SUBMIT';
    IF NEW.action='SUBMIT' AND (submitted_by IS NOT NULL OR EXISTS(SELECT 1 FROM captain_benefit_reviews WHERE captain_benefit_request_id=p.id)) THEN
        RAISE EXCEPTION 'Request is not a proposed draft' USING ERRCODE='23514';
    ELSIF NEW.action IN ('APPROVE','REJECT') THEN
        IF submitted_by IS NULL OR EXISTS(SELECT 1 FROM captain_benefit_reviews WHERE captain_benefit_request_id=p.id AND action IN ('APPROVE','REJECT','CANCEL')) THEN
            RAISE EXCEPTION 'Request is not pending approval' USING ERRCODE='23514';
        END IF;
        IF NEW.action='APPROVE' AND NEW.actor_id IN (p.created_by_user_id,p.recorded_by_user_id,submitted_by) THEN
            RAISE EXCEPTION 'Creator or submitter cannot approve own benefit' USING ERRCODE='23514';
        END IF;
        IF NEW.action='APPROVE' THEN
            SELECT decimal_places INTO places FROM currencies WHERE id=p.currency_id AND is_active;
            IF places IS NULL OR p.amount <> round(p.amount,places) THEN RAISE EXCEPTION 'Invalid benefit Currency precision' USING ERRCODE='23514'; END IF;
            IF p.kind='EXPENSE' AND (p.evidence_path IS NULL OR p.evidence_sha256 !~ '^[0-9a-f]{64}$' OR p.expense_date IS NULL) THEN
                RAISE EXCEPTION 'Expense approval requires evidence' USING ERRCODE='23514';
            END IF;
            IF p.order_id IS NOT NULL AND (NOT EXISTS(SELECT 1 FROM orders WHERE id=p.order_id AND branch_id=p.branch_id) OR
                NOT EXISTS(SELECT 1 FROM order_captains WHERE order_id=p.order_id AND captain_id=p.captain_id)) THEN
                RAISE EXCEPTION 'Invalid informational Order link' USING ERRCODE='23514';
            END IF;
        END IF;
    ELSIF NEW.action='CANCEL' AND EXISTS(SELECT 1 FROM captain_benefit_reviews WHERE captain_benefit_request_id=p.id AND action IN ('APPROVE','REJECT','CANCEL')) THEN
        RAISE EXCEPTION 'Request is no longer cancellable' USING ERRCODE='23514';
    ELSIF NEW.action='REVERSE' AND NOT EXISTS(SELECT 1 FROM captain_benefit_reviews WHERE captain_benefit_request_id=p.id AND action='APPROVE') THEN
        RAISE EXCEPTION 'Reversal requires an approved source' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER benefit_review_guard BEFORE INSERT OR UPDATE OR DELETE ON captain_benefit_reviews FOR EACH ROW EXECUTE FUNCTION guard_benefit_review();
CREATE TRIGGER benefit_review_truncate BEFORE TRUNCATE ON captain_benefit_reviews FOR EACH STATEMENT EXECUTE FUNCTION guard_benefit_review();
CREATE FUNCTION guard_reviewed_benefit_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.replaces_id IS NOT NULL THEN
        PERFORM id FROM currencies WHERE id=NEW.currency_id FOR UPDATE;
        PERFORM id FROM captain_benefit_requests WHERE id=NEW.replaces_id FOR UPDATE;
        IF EXISTS(SELECT 1 FROM captain_benefit_reviews WHERE captain_benefit_request_id=NEW.replaces_id) THEN
            RAISE EXCEPTION 'Submitted benefit facts cannot be revised' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER benefit_reviewed_revision BEFORE INSERT ON captain_benefit_requests FOR EACH ROW EXECUTE FUNCTION guard_reviewed_benefit_revision();

CREATE FUNCTION validate_benefit_review_posting(p_review bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE r captain_benefit_reviews%ROWTYPE; p captain_benefit_requests%ROWTYPE; t financial_transactions%ROWTYPE;
    e record; original_id bigint; expected_event text; source_family text;
BEGIN
    SELECT * INTO r FROM captain_benefit_reviews WHERE id=p_review;
    IF r.id IS NULL OR r.action NOT IN ('APPROVE','REVERSE') THEN RETURN; END IF;
    SELECT * INTO p FROM captain_benefit_requests WHERE id=r.captain_benefit_request_id;
    expected_event:=CASE WHEN r.action='REVERSE' THEN 'CAPTAIN_BENEFIT_REVERSED' WHEN p.kind='BONUS' THEN 'CAPTAIN_BONUS_DUE' ELSE 'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE' END;
    source_family:=CASE WHEN r.action='REVERSE' THEN 'CAPTAIN_BENEFIT_REVERSAL' ELSE 'CAPTAIN_BENEFIT_APPROVAL' END;
    SELECT * INTO t FROM financial_transactions WHERE source_type=source_family AND source_id=r.id::text AND event_type=expected_event;
    IF t.id IS NULL OR t.status<>'POSTED' OR t.source_version<>1 OR t.transaction_kind<>(CASE WHEN r.action='REVERSE' THEN 'REVERSAL' ELSE 'EVENT' END)
        OR t.branch_id IS DISTINCT FROM p.branch_id OR t.currency_id<>p.currency_id OR t.recorded_by_user_id IS DISTINCT FROM r.actor_id
        OR t.occurred_at IS DISTINCT FROM r.recorded_at THEN
        RAISE EXCEPTION 'Benefit review requires exactly its canonical posted transaction' USING ERRCODE='23514';
    END IF;
    IF (SELECT count(*) FROM financial_transactions WHERE source_type=source_family AND source_id=r.id::text)<>1 THEN
        RAISE EXCEPTION 'Duplicate benefit source event' USING ERRCODE='23514';
    END IF;
    SELECT fe.*,a.account_kind,a.currency_id AS account_currency,fo.captain_id,fo.owner_type INTO e
        FROM financial_entries fe JOIN financial_accounts a ON a.id=fe.financial_account_id JOIN financial_owners fo ON fo.id=a.financial_owner_id
        WHERE fe.financial_transaction_id=t.id;
    IF (SELECT count(*) FROM financial_entries WHERE financial_transaction_id=t.id)<>1 OR
        e.account_kind<>'CAPTAIN_COMPANY_RECEIVABLE' OR e.owner_type<>'CAPTAIN' OR e.captain_id<>p.captain_id OR
        e.account_currency<>p.currency_id OR e.currency_id<>p.currency_id OR e.amount<>p.amount OR
        e.direction<>(CASE WHEN r.action='REVERSE' THEN 'DECREASE' ELSE 'INCREASE' END) THEN
        RAISE EXCEPTION 'Benefit source entry has wrong Captain, account, Currency, amount or direction' USING ERRCODE='23514';
    END IF;
    IF r.action='REVERSE' THEN
        SELECT tx.id INTO original_id FROM captain_benefit_reviews ar JOIN financial_transactions tx ON tx.source_id=ar.id::text AND tx.source_type='CAPTAIN_BENEFIT_APPROVAL'
            WHERE ar.captain_benefit_request_id=p.id AND ar.action='APPROVE';
        IF t.reversal_of_transaction_id IS DISTINCT FROM original_id OR t.reason IS DISTINCT FROM r.reason OR
            EXISTS(SELECT 1 FROM financial_entries fe JOIN captain_receivable_payout_allocations pa ON pa.financial_entry_id=fe.id WHERE fe.financial_transaction_id=original_id) THEN
            RAISE EXCEPTION 'Paid benefit cannot be reversed or reversal source is invalid' USING ERRCODE='23514';
        END IF;
    END IF;
END $$;
CREATE FUNCTION guard_benefit_event_identity() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r captain_benefit_reviews%ROWTYPE; original_type text; expected_event text;
BEGIN
    SELECT event_type INTO original_type FROM financial_transactions WHERE id=NEW.reversal_of_transaction_id;
    IF NEW.event_type NOT IN ('CAPTAIN_BONUS_DUE','CAPTAIN_EXPENSE_REIMBURSEMENT_DUE','CAPTAIN_BENEFIT_REVERSED')
        AND NEW.source_type NOT IN ('CAPTAIN_BENEFIT_APPROVAL','CAPTAIN_BENEFIT_REVERSAL')
        AND coalesce(original_type,'') NOT IN ('CAPTAIN_BONUS_DUE','CAPTAIN_EXPENSE_REIMBURSEMENT_DUE') THEN RETURN NEW; END IF;
    IF NEW.source_id !~ '^[1-9][0-9]{0,18}$' OR NEW.source_id::numeric>9223372036854775807 THEN
        RAISE EXCEPTION 'Invalid benefit source identity' USING ERRCODE='23514';
    END IF;
    SELECT * INTO r FROM captain_benefit_reviews WHERE id=NEW.source_id::bigint;
    IF r.id IS NULL THEN RAISE EXCEPTION 'Missing benefit review source' USING ERRCODE='23514'; END IF;
    expected_event:=CASE WHEN r.action='REVERSE' THEN 'CAPTAIN_BENEFIT_REVERSED'
        WHEN (SELECT kind FROM captain_benefit_requests WHERE id=r.captain_benefit_request_id)='BONUS' THEN 'CAPTAIN_BONUS_DUE' ELSE 'CAPTAIN_EXPENSE_REIMBURSEMENT_DUE' END;
    IF NEW.source_version<>1 OR NEW.event_type<>expected_event OR
        (r.action='APPROVE' AND (NEW.source_type<>'CAPTAIN_BENEFIT_APPROVAL' OR NEW.transaction_kind<>'EVENT' OR NEW.reversal_of_transaction_id IS NOT NULL)) OR
        (r.action='REVERSE' AND (NEW.source_type<>'CAPTAIN_BENEFIT_REVERSAL' OR NEW.transaction_kind<>'REVERSAL' OR NEW.reversal_of_transaction_id IS NULL)) OR
        r.action NOT IN ('APPROVE','REVERSE') THEN
        RAISE EXCEPTION 'Noncanonical benefit financial identity' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER benefit_event_identity BEFORE INSERT ON financial_transactions FOR EACH ROW EXECUTE FUNCTION guard_benefit_event_identity();
CREATE FUNCTION benefit_posting_constraint() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t financial_transactions%ROWTYPE;
BEGIN
    IF TG_TABLE_NAME='captain_benefit_reviews' THEN
        PERFORM validate_benefit_review_posting(NEW.id);
    ELSE
        IF TG_TABLE_NAME='financial_entries' THEN SELECT * INTO t FROM financial_transactions WHERE id=NEW.financial_transaction_id;
        ELSE SELECT * INTO t FROM financial_transactions WHERE id=NEW.id; END IF;
        IF t.source_type IN ('CAPTAIN_BENEFIT_APPROVAL','CAPTAIN_BENEFIT_REVERSAL') THEN
            PERFORM validate_benefit_review_posting(t.source_id::bigint);
        END IF;
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER benefit_review_posted AFTER INSERT ON captain_benefit_reviews DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION benefit_posting_constraint();
CREATE CONSTRAINT TRIGGER benefit_transaction_posted AFTER INSERT OR UPDATE ON financial_transactions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION benefit_posting_constraint();
CREATE CONSTRAINT TRIGGER benefit_entry_posted AFTER INSERT ON financial_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION benefit_posting_constraint();
CREATE FUNCTION guard_benefit_payout_metadata() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE payout captain_receivable_payouts%ROWTYPE; receiver bigint; t financial_transactions%ROWTYPE;
BEGIN
    SELECT tx.* INTO t FROM financial_entries e JOIN financial_transactions tx ON tx.id=e.financial_transaction_id WHERE e.id=NEW.financial_entry_id;
    IF t.event_type IN ('CAPTAIN_BONUS_DUE','CAPTAIN_EXPENSE_REIMBURSEMENT_DUE') THEN
        PERFORM validate_benefit_review_posting(t.source_id::bigint);
        SELECT * INTO payout FROM captain_receivable_payouts WHERE id=NEW.captain_receivable_payout_id;
        SELECT user_id INTO receiver FROM captains WHERE id=payout.captain_id;
        IF payout.payment_method IS NULL OR payout.received_by_user_id IS DISTINCT FROM receiver THEN
            RAISE EXCEPTION 'Benefit payout requires actual method and the Captain receiver identity' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER benefit_payout_metadata BEFORE INSERT ON captain_receivable_payout_allocations FOR EACH ROW EXECUTE FUNCTION guard_benefit_payout_metadata();
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Captain benefit accrual and payout history are forward-only.');
    }
};
