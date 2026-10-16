<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
LOCK TABLE customer_credit_entries, customer_credit_redemptions, financial_transactions, financial_entries IN ACCESS EXCLUSIVE MODE;
CREATE TABLE customer_credit_cash_payouts (
 id bigserial PRIMARY KEY, request_key uuid NOT NULL UNIQUE, payload_hash char(64) NOT NULL,
 customer_id bigint NOT NULL REFERENCES customers(id), branch_id bigint NOT NULL REFERENCES branches(id),
 currency_id bigint NOT NULL REFERENCES currencies(id), captain_id bigint NOT NULL REFERENCES captains(id),
 recorded_by bigint NOT NULL REFERENCES users(id), occurred_at timestamptz NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')),
 reason text NOT NULL CHECK(length(btrim(reason)) BETWEEN 1 AND 2000),
 reference text NOT NULL CHECK(length(btrim(reference)) BETWEEN 1 AND 191),
 personal_cash_confirmed boolean NOT NULL CHECK(personal_cash_confirmed),
 evidence_path text NOT NULL, evidence_name text NOT NULL, evidence_mime text NOT NULL CHECK(evidence_mime IN ('image/jpeg','image/png','image/webp')),
 evidence_size integer NOT NULL CHECK(evidence_size BETWEEN 1 AND 5242880), evidence_sha256 char(64) NOT NULL UNIQUE,
 UNIQUE(branch_id,currency_id,reference)
);
CREATE INDEX credit_cash_customer ON customer_credit_cash_payouts(customer_id,branch_id,currency_id,id);
CREATE TABLE customer_credit_cash_payout_grants (
 id bigserial PRIMARY KEY, payout_id bigint NOT NULL REFERENCES customer_credit_cash_payouts(id),
 grant_id bigint NOT NULL REFERENCES customer_credit_entries(id),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')), UNIQUE(payout_id,grant_id)
);
CREATE INDEX credit_cash_grant ON customer_credit_cash_payout_grants(grant_id);
CREATE TABLE customer_credit_cash_payout_attributions (
 payout_grant_id bigint NOT NULL REFERENCES customer_credit_cash_payout_grants(id),
 responsibility_id bigint NOT NULL REFERENCES customer_return_responsibilities(id),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')), PRIMARY KEY(payout_grant_id,responsibility_id)
);
CREATE INDEX credit_cash_party ON customer_credit_cash_payout_attributions(responsibility_id);
CREATE FUNCTION customer_credit_grant_spent(gid bigint) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT COALESCE((SELECT sum(amount) FROM customer_credit_redemptions WHERE grant_id=gid),0)
 +COALESCE((SELECT sum(amount) FROM customer_credit_cash_payout_grants WHERE grant_id=gid),0)
$$;
CREATE FUNCTION credit_cash_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE places integer; available numeric;
BEGIN
 PERFORM id FROM customers WHERE id=NEW.customer_id FOR UPDATE;
 SELECT decimal_places INTO STRICT places FROM currencies WHERE id=NEW.currency_id AND is_active FOR SHARE;
 SELECT COALESCE(sum(g.amount-customer_credit_grant_spent(g.id)),0) INTO available FROM customer_credit_entries g
 WHERE g.customer_id=NEW.customer_id AND g.branch_id=NEW.branch_id AND g.currency_id=NEW.currency_id;
 IF NEW.amount<>available OR scale(trim_scale(NEW.amount))>places OR NEW.occurred_at>clock_timestamp()
 OR NOT benefit_actor_allowed(NEW.recorded_by,NEW.branch_id,'customer_credits.payout')
 OR NOT benefit_actor_allowed(NEW.recorded_by,NEW.branch_id,'customer_credits.view')
 OR NOT benefit_actor_allowed(NEW.recorded_by,NEW.branch_id,'customers.view')
 OR NOT customer_credit_customer_visible(NEW.customer_id,NEW.branch_id,NEW.recorded_by)
 OR NOT EXISTS(SELECT 1 FROM captains ca JOIN users u ON u.id=ca.user_id WHERE ca.id=NEW.captain_id AND u.is_active AND
  (ca.home_branch_id=NEW.branch_id OR EXISTS(SELECT 1 FROM captain_branch_assignments a WHERE a.captain_id=ca.id AND a.branch_id=NEW.branch_id
   AND a.starts_at<=NEW.occurred_at AND (a.ends_at IS NULL OR a.ends_at>NEW.occurred_at)))) THEN
  RAISE EXCEPTION 'Credit cash payout requires full available balance, authorized scope and personal funds evidence' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_cash_source_guard BEFORE INSERT ON customer_credit_cash_payouts FOR EACH ROW EXECUTE FUNCTION credit_cash_source_guard();
CREATE FUNCTION credit_cash_grant_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p customer_credit_cash_payouts%ROWTYPE; g customer_credit_entries%ROWTYPE;
BEGIN
 SELECT * INTO STRICT p FROM customer_credit_cash_payouts WHERE id=NEW.payout_id;
 PERFORM id FROM customers WHERE id=p.customer_id FOR UPDATE;
 SELECT * INTO STRICT g FROM customer_credit_entries WHERE id=NEW.grant_id;
 IF (g.customer_id,g.branch_id,g.currency_id) IS DISTINCT FROM (p.customer_id,p.branch_id,p.currency_id)
 OR g.occurred_at>p.occurred_at OR NEW.amount<>g.amount-customer_credit_grant_spent(g.id)
 OR EXISTS(SELECT 1 FROM financial_transactions WHERE source_type='CUSTOMER_CREDIT_CASH_PAYOUT' AND source_id=p.id::text) THEN
  RAISE EXCEPTION 'Credit cash payout grant is late, foreign or not its entire remaining amount' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_cash_grant_guard BEFORE INSERT ON customer_credit_cash_payout_grants FOR EACH ROW EXECUTE FUNCTION credit_cash_grant_guard();
CREATE FUNCTION credit_cash_attribution_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d customer_credit_cash_payout_grants%ROWTYPE; p customer_credit_cash_payouts%ROWTYPE;
 r customer_return_responsibilities%ROWTYPE; cid bigint; used numeric; places integer;
BEGIN
 SELECT * INTO STRICT d FROM customer_credit_cash_payout_grants WHERE id=NEW.payout_grant_id;
 SELECT * INTO STRICT p FROM customer_credit_cash_payouts WHERE id=d.payout_id;
 PERFORM id FROM customers WHERE id=p.customer_id FOR UPDATE;
 SELECT source_return_case_id INTO cid FROM customer_credit_entries WHERE id=d.grant_id;
 SELECT * INTO STRICT r FROM customer_return_responsibilities WHERE id=NEW.responsibility_id FOR UPDATE;
 SELECT decimal_places INTO STRICT places FROM currencies WHERE id=p.currency_id;
 SELECT COALESCE(sum(amount),0) INTO used FROM customer_credit_cash_payout_attributions WHERE responsibility_id=r.id;
 used:=used+COALESCE((SELECT sum(amount) FROM customer_credit_responsibility_attributions WHERE responsibility_id=r.id),0)+credit_legacy_responsibility_funding(r.id);
 IF cid IS DISTINCT FROM r.case_id OR scale(trim_scale(NEW.amount))>places OR used+NEW.amount>r.amount
 OR EXISTS(SELECT 1 FROM financial_transactions WHERE source_type='CUSTOMER_CREDIT_CASH_PAYOUT' AND source_id=p.id::text) THEN
  RAISE EXCEPTION 'Invalid cash credit responsibility attribution' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_cash_attribution_guard BEFORE INSERT ON customer_credit_cash_payout_attributions FOR EACH ROW EXECUTE FUNCTION credit_cash_attribution_guard();
CREATE FUNCTION assert_credit_cash_payout(pid bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE p customer_credit_cash_payouts%ROWTYPE; t financial_transactions%ROWTYPE; e record;
BEGIN
 SELECT * INTO STRICT p FROM customer_credit_cash_payouts WHERE id=pid;
 SELECT * INTO t FROM financial_transactions WHERE source_type='CUSTOMER_CREDIT_CASH_PAYOUT' AND source_id=p.id::text;
 IF t.id IS NULL OR (SELECT count(*) FROM financial_transactions WHERE source_type='CUSTOMER_CREDIT_CASH_PAYOUT' AND source_id=p.id::text)<>1
 OR t.event_type<>'CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE' OR t.status<>'POSTED' OR t.transaction_kind<>'EVENT' OR t.source_version<>1
 OR t.branch_id IS DISTINCT FROM p.branch_id OR t.currency_id<>p.currency_id OR t.recorded_by_user_id IS DISTINCT FROM p.recorded_by OR t.occurred_at<>p.occurred_at
 OR (SELECT COALESCE(sum(amount),0) FROM customer_credit_cash_payout_grants WHERE payout_id=p.id)<>p.amount THEN
  RAISE EXCEPTION 'Credit cash payout requires exact atomic debits and canonical Captain entitlement' USING ERRCODE='23514'; END IF;
 SELECT fe.*,a.account_kind,a.currency_id AS account_currency,fo.owner_type,fo.captain_id INTO e
 FROM financial_entries fe JOIN financial_accounts a ON a.id=fe.financial_account_id JOIN financial_owners fo ON fo.id=a.financial_owner_id WHERE fe.financial_transaction_id=t.id;
 IF (SELECT count(*) FROM financial_entries WHERE financial_transaction_id=t.id)<>1 OR e.account_kind<>'CAPTAIN_COMPANY_RECEIVABLE'
 OR e.owner_type<>'CAPTAIN' OR e.captain_id IS DISTINCT FROM p.captain_id OR e.account_currency<>p.currency_id OR e.currency_id<>p.currency_id
 OR e.direction<>'INCREASE' OR e.amount<>p.amount THEN
  RAISE EXCEPTION 'Credit cash entitlement has incorrect Captain, account, amount, direction or Currency' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM customer_credit_cash_payout_grants d JOIN customer_credit_entries g ON g.id=d.grant_id
 WHERE d.payout_id=p.id AND (SELECT COALESCE(sum(amount),0) FROM customer_credit_cash_payout_attributions WHERE payout_grant_id=d.id)
 <>CASE WHEN g.source_return_case_id IS NULL THEN 0 ELSE d.amount END) THEN
  RAISE EXCEPTION 'Cash credit consumption requires exact responsibility attribution' USING ERRCODE='23514'; END IF;
END $$;
CREATE FUNCTION credit_cash_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.reversal_of_transaction_id IS NOT NULL AND EXISTS(SELECT 1 FROM financial_transactions WHERE id=NEW.reversal_of_transaction_id AND source_type='CUSTOMER_CREDIT_CASH_PAYOUT') THEN
  RAISE EXCEPTION 'Customer cash handover cannot be reversed without a recovery contract' USING ERRCODE='23514'; END IF;
 IF NEW.event_type='CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE' OR NEW.source_type='CUSTOMER_CREDIT_CASH_PAYOUT' THEN
  IF NEW.source_type<>'CUSTOMER_CREDIT_CASH_PAYOUT' OR NEW.event_type<>'CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE' OR NEW.source_version<>1
  OR NEW.transaction_kind<>'EVENT' OR NEW.reversal_of_transaction_id IS NOT NULL OR NEW.source_id !~ '^[1-9][0-9]{0,17}$'
  OR NOT EXISTS(SELECT 1 FROM customer_credit_cash_payouts WHERE id=NEW.source_id::bigint) THEN
   RAISE EXCEPTION 'Noncanonical customer cash payout event identity' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_cash_event_guard BEFORE INSERT ON financial_transactions FOR EACH ROW EXECUTE FUNCTION credit_cash_event_guard();
CREATE FUNCTION credit_cash_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE tid bigint; sid bigint; t financial_transactions%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME='customer_credit_cash_payouts' THEN sid:=NEW.id;
 ELSIF TG_TABLE_NAME='customer_credit_cash_payout_grants' THEN sid:=NEW.payout_id;
 ELSIF TG_TABLE_NAME='customer_credit_cash_payout_attributions' THEN SELECT payout_id INTO sid FROM customer_credit_cash_payout_grants WHERE id=NEW.payout_grant_id;
 ELSE
  IF TG_TABLE_NAME='financial_entries' THEN SELECT * INTO t FROM financial_transactions WHERE id=NEW.financial_transaction_id; ELSE t:=NEW; END IF;
  IF t.source_type='CUSTOMER_CREDIT_CASH_PAYOUT' THEN sid:=t.source_id::bigint; END IF;
 END IF;
 IF sid IS NOT NULL THEN PERFORM assert_credit_cash_payout(sid); END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER credit_cash_header_complete AFTER INSERT ON customer_credit_cash_payouts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_cash_complete();
CREATE CONSTRAINT TRIGGER credit_cash_grants_complete AFTER INSERT ON customer_credit_cash_payout_grants DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_cash_complete();
CREATE CONSTRAINT TRIGGER credit_cash_parts_complete AFTER INSERT ON customer_credit_cash_payout_attributions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_cash_complete();
CREATE CONSTRAINT TRIGGER credit_cash_transaction_complete AFTER INSERT OR UPDATE ON financial_transactions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_cash_complete();
CREATE CONSTRAINT TRIGGER credit_cash_entry_complete AFTER INSERT ON financial_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_cash_complete();
CREATE FUNCTION credit_cash_payout_metadata() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p captain_receivable_payouts%ROWTYPE; t financial_transactions%ROWTYPE; uid bigint;
BEGIN
 SELECT tx.* INTO t FROM financial_entries e JOIN financial_transactions tx ON tx.id=e.financial_transaction_id WHERE e.id=NEW.financial_entry_id;
 IF t.event_type='CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE' THEN
  PERFORM assert_credit_cash_payout(t.source_id::bigint);
  SELECT * INTO STRICT p FROM captain_receivable_payouts WHERE id=NEW.captain_receivable_payout_id;
  SELECT user_id INTO uid FROM captains WHERE id=p.captain_id;
  IF p.payment_method IS NULL OR p.received_by_user_id IS DISTINCT FROM uid THEN
   RAISE EXCEPTION 'Personal funds reimbursement requires actual payout method and Captain receiver' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_cash_payout_metadata BEFORE INSERT ON captain_receivable_payout_allocations FOR EACH ROW EXECUTE FUNCTION credit_cash_payout_metadata();
SQL);
        foreach (['customer_credit_cash_payouts', 'customer_credit_cash_payout_grants', 'customer_credit_cash_payout_attributions'] as $table) {
            DB::unprepared("CREATE TRIGGER credit_cash_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ret_immutable(); CREATE TRIGGER credit_cash_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION ret_immutable();");
        }
        $this->replace('credit_redemption_guard()', 'SELECT COALESCE(sum(amount),0) INTO used FROM customer_credit_redemptions WHERE grant_id=g.id;', 'SELECT customer_credit_grant_spent(g.id) INTO used;');
        $this->replace('credit_attribution_guard()', 'NEW.amount+used+credit_legacy_responsibility_funding(r.id)>r.amount', 'NEW.amount+used+credit_legacy_responsibility_funding(r.id)+COALESCE((SELECT sum(amount) FROM customer_credit_cash_payout_attributions WHERE responsibility_id=r.id),0)>r.amount');
        $this->replace('ret_funded_responsibility(bigint,timestamp with time zone)', 'WHERE a.responsibility_id=r.id AND col.collected_at<=as_of),0)', "WHERE a.responsibility_id=r.id AND col.collected_at<=as_of),0)+COALESCE((SELECT sum(a.amount) FROM customer_credit_cash_payout_attributions a JOIN customer_credit_cash_payout_grants d ON d.id=a.payout_grant_id JOIN customer_credit_cash_payouts p ON p.id=d.payout_id JOIN financial_transactions t ON t.source_type='CUSTOMER_CREDIT_CASH_PAYOUT' AND t.source_id=p.id::text AND t.status='POSTED' WHERE a.responsibility_id=r.id AND p.occurred_at<=as_of),0)");
        $this->replace('validate_captain_financial_operation_9c3a(text,bigint)', "'ORDER_V3_SHAM_DELIVERY_ENTITLEMENT_DUE')", "'ORDER_V3_SHAM_DELIVERY_ENTITLEMENT_DUE','CUSTOMER_CREDIT_PERSONAL_FUNDS_DUE')");
    }

    private function replace(string $signature, string $before, string $after): void
    {
        $definition = DB::scalar('SELECT pg_get_functiondef(?::regprocedure)', [$signature]);
        if (substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Unexpected cash credit prerequisite: '.$signature);
        }
        DB::unprepared(str_replace($before, $after, $definition));
    }

    public function down(): void
    {
        throw new LogicException('Personal-funds credit payout history is forward-only.');
    }
};
