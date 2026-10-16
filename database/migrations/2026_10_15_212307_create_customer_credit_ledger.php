<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE customer_return_cases ADD COLUMN compensation_method text NOT NULL DEFAULT 'CASH'
 CHECK(compensation_method IN ('CASH','TRANSFER','CREDIT'));
CREATE TABLE customer_credit_entries (
 id bigserial PRIMARY KEY, request_key uuid NOT NULL UNIQUE, payload_hash char(64) NOT NULL,
 customer_id bigint NOT NULL REFERENCES customers(id), branch_id bigint NOT NULL REFERENCES branches(id),
 currency_id bigint NOT NULL REFERENCES currencies(id), source_order_id bigint REFERENCES orders(id),
 source_return_case_id bigint UNIQUE REFERENCES customer_return_cases(id),
 kind text NOT NULL CHECK(kind='GRANT'), reason text NOT NULL CHECK(reason IN ('OVERCHARGE','ERROR','RETURN')),
 notes text NOT NULL CHECK(length(btrim(notes)) BETWEEN 1 AND 5000), reference text NOT NULL CHECK(length(btrim(reference)) BETWEEN 1 AND 191),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')),
 recorded_by bigint NOT NULL REFERENCES users(id), occurred_at timestamptz NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(), UNIQUE(branch_id,reference),
 CHECK((reason='RETURN')=(source_return_case_id IS NOT NULL))
);
CREATE INDEX customer_credit_bucket ON customer_credit_entries(customer_id,branch_id,currency_id,id);
CREATE INDEX customer_credit_order ON customer_credit_entries(source_order_id);
CREATE FUNCTION customer_credit_customer_visible(cid bigint,bid bigint,actor bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=actor AND r.code='SUPER_ADMIN')
 OR EXISTS(SELECT 1 FROM customer_addresses a JOIN areas ar ON ar.id=a.area_id WHERE a.customer_id=cid AND ar.branch_id=bid)
 OR EXISTS(SELECT 1 FROM customers c WHERE c.id=cid AND c.created_by_user_id=actor AND NOT EXISTS(SELECT 1 FROM customer_addresses a WHERE a.customer_id=cid))
$$;
CREATE FUNCTION customer_credit_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r customer_return_cases%ROWTYPE; o orders%ROWTYPE; places integer;
BEGIN
 IF NEW.source_order_id IS NOT NULL THEN
  SELECT * INTO STRICT o FROM orders WHERE id=NEW.source_order_id FOR UPDATE;
  IF o.customer_id<>NEW.customer_id OR o.branch_id<>NEW.branch_id
   OR NOT benefit_actor_allowed(NEW.recorded_by,o.branch_id,'orders.view')
   OR EXISTS(SELECT 1 FROM invoices WHERE order_id=o.id AND superseded_at IS NULL AND currency_id<>NEW.currency_id) THEN
   RAISE EXCEPTION 'Invalid customer credit Order source' USING ERRCODE='23514'; END IF;
 END IF;
 PERFORM id FROM customers WHERE id=NEW.customer_id FOR UPDATE;
 SELECT decimal_places INTO STRICT places FROM currencies WHERE id=NEW.currency_id AND is_active FOR SHARE;
 IF scale(trim_scale(NEW.amount))>places OR NEW.occurred_at>clock_timestamp()
 OR NOT benefit_actor_allowed(NEW.recorded_by,NEW.branch_id,'customer_credits.grant')
 OR NOT benefit_actor_allowed(NEW.recorded_by,NEW.branch_id,'customers.view')
 OR NOT customer_credit_customer_visible(NEW.customer_id,NEW.branch_id,NEW.recorded_by) THEN
  RAISE EXCEPTION 'Invalid customer credit actor, scope, precision or date' USING ERRCODE='23514'; END IF;
 IF NEW.source_return_case_id IS NOT NULL THEN
  SELECT * INTO STRICT r FROM customer_return_cases WHERE id=NEW.source_return_case_id FOR UPDATE;
  IF r.compensation_method<>'CREDIT' OR r.sealed_at IS NULL OR r.order_id IS DISTINCT FROM NEW.source_order_id
   OR r.branch_id<>NEW.branch_id OR r.currency_id<>NEW.currency_id OR r.amount<>NEW.amount OR r.recorded_by<>NEW.recorded_by
   OR r.occurred_at<>NEW.occurred_at OR EXISTS(SELECT 1 FROM customer_return_movements WHERE case_id=r.id AND kind='COMPENSATION') THEN
   RAISE EXCEPTION 'Invalid or duplicate return credit compensation' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER customer_credit_guard BEFORE INSERT ON customer_credit_entries FOR EACH ROW EXECUTE FUNCTION customer_credit_guard();
CREATE TRIGGER customer_credit_immutable BEFORE UPDATE OR DELETE ON customer_credit_entries FOR EACH ROW EXECUTE FUNCTION ret_immutable();
CREATE TRIGGER customer_credit_no_truncate BEFORE TRUNCATE ON customer_credit_entries FOR EACH STATEMENT EXECUTE FUNCTION ret_immutable();
CREATE FUNCTION credit_return_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r customer_return_cases%ROWTYPE;
BEGIN
 SELECT * INTO STRICT r FROM customer_return_cases WHERE id=NEW.id;
 IF (r.compensation_method='CREDIT') IS DISTINCT FROM EXISTS(SELECT 1 FROM customer_credit_entries e WHERE e.source_return_case_id=r.id AND e.amount=r.amount) THEN
  RAISE EXCEPTION 'Return credit choice requires its exact atomic grant' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER credit_return_complete AFTER INSERT OR UPDATE ON customer_return_cases DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_return_complete();
CREATE FUNCTION credit_return_payment_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.kind='COMPENSATION' AND (SELECT compensation_method FROM customer_return_cases WHERE id=NEW.case_id)<>NEW.method THEN
  RAISE EXCEPTION 'Return compensation method is immutable; no cash payment for credit' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_return_payment_guard BEFORE INSERT ON customer_return_movements FOR EACH ROW EXECUTE FUNCTION credit_return_payment_guard();
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Customer credit history is forward-only.');
    }
};
