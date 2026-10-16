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
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Mixed credit settlement requires PostgreSQL.');
        }
        DB::statement('LOCK TABLE invoice_collections, financial_transactions, financial_entries, customer_credit_entries IN ACCESS EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
CREATE TABLE customer_credit_settlements (
 id bigserial PRIMARY KEY, request_key uuid NOT NULL UNIQUE, payload_hash char(64) NOT NULL,
 invoice_id bigint NOT NULL UNIQUE REFERENCES invoices(id), order_id bigint NOT NULL REFERENCES orders(id),
 customer_id bigint NOT NULL REFERENCES customers(id), branch_id bigint NOT NULL REFERENCES branches(id),
 currency_id bigint NOT NULL REFERENCES currencies(id), recorded_by bigint NOT NULL REFERENCES users(id),
 credit_amount numeric NOT NULL CHECK(credit_amount>0 AND credit_amount::text NOT IN ('NaN','Infinity','-Infinity')),
 actual_amount numeric NOT NULL CHECK(actual_amount>=0 AND actual_amount::text NOT IN ('NaN','Infinity','-Infinity')),
 collection_method text NOT NULL CHECK(collection_method IN ('CASH','SHAM_CASH')),
 collected_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 reason text NOT NULL CHECK(length(btrim(reason)) BETWEEN 1 AND 2000), payment_reference text
);
CREATE INDEX credit_settlement_customer ON customer_credit_settlements(customer_id,branch_id,currency_id,id);
CREATE INDEX credit_settlement_order ON customer_credit_settlements(order_id);
CREATE TABLE customer_credit_redemptions (
 id bigserial PRIMARY KEY, settlement_id bigint NOT NULL REFERENCES customer_credit_settlements(id),
 grant_id bigint NOT NULL REFERENCES customer_credit_entries(id),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')),
 UNIQUE(settlement_id,grant_id)
);
CREATE INDEX credit_redemption_grant ON customer_credit_redemptions(grant_id);
CREATE FUNCTION credit_collection_amount(c invoice_collections) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT COALESCE((SELECT credit_amount FROM customer_credit_settlements WHERE invoice_id=c.invoice_id),0)
$$;
CREATE FUNCTION credit_collection_settled_total(c invoice_collections) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT c.amount+credit_collection_amount(c)
$$;
CREATE FUNCTION credit_collection_delivery_cash(c invoice_collections,delivery numeric) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT delivery-credit_collection_amount(c)
$$;
CREATE FUNCTION credit_v3_company(c invoice_collections,delivery numeric,entitlement numeric) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT greatest(delivery-credit_collection_amount(c)-entitlement,0)
$$;
CREATE FUNCTION credit_v3_shortfall(c invoice_collections,delivery numeric,entitlement numeric) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT greatest(entitlement-(delivery-credit_collection_amount(c)),0)
$$;
CREATE FUNCTION credit_settlement_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o orders%ROWTYPE; i invoices%ROWTYPE; places integer;
BEGIN
 SELECT * INTO STRICT o FROM orders WHERE id=NEW.order_id FOR UPDATE;
 PERFORM id FROM customers WHERE id=o.customer_id FOR UPDATE;
 SELECT * INTO STRICT i FROM invoices WHERE id=NEW.invoice_id FOR UPDATE;
 SELECT decimal_places INTO STRICT places FROM currencies WHERE id=NEW.currency_id AND is_active FOR UPDATE;
 IF o.accounting_contract_version NOT IN (2,3) OR o.customer_id<>NEW.customer_id OR o.branch_id<>NEW.branch_id
 OR o.operational_status='CANCELLED' OR EXISTS(SELECT 1 FROM order_cancellation_intents WHERE order_id=o.id)
 OR i.order_id<>o.id OR i.status<>'ISSUED' OR i.superseded_at IS NOT NULL OR i.currency_id<>NEW.currency_id
 OR NEW.actual_amount+NEW.credit_amount<>i.grand_total OR NEW.credit_amount>i.delivery_total
 OR NEW.collected_at<i.issued_at OR NEW.collected_at>clock_timestamp()
 OR scale(trim_scale(NEW.credit_amount))>places OR scale(trim_scale(NEW.actual_amount))>places
 OR EXISTS(SELECT 1 FROM invoice_collections WHERE invoice_id=i.id)
 OR EXISTS(SELECT 1 FROM invoice_financial_corrections WHERE order_id=o.id AND state<>'ABANDONED')
 OR NOT benefit_actor_allowed(NEW.recorded_by,o.branch_id,'orders.view')
 OR NOT benefit_actor_allowed(NEW.recorded_by,o.branch_id,'customers.view')
 OR NOT benefit_actor_allowed(NEW.recorded_by,o.branch_id,'invoice_collections.create')
 OR NOT benefit_actor_allowed(NEW.recorded_by,o.branch_id,'invoices.view')
 OR NOT benefit_actor_allowed(NEW.recorded_by,o.branch_id,'customer_credits.view')
 OR NOT benefit_actor_allowed(NEW.recorded_by,o.branch_id,'customer_credits.redeem')
 OR NOT customer_credit_customer_visible(o.customer_id,o.branch_id,NEW.recorded_by) THEN
  RAISE EXCEPTION 'Invalid mixed credit settlement source, actor, amount or time' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_settlement_guard BEFORE INSERT ON customer_credit_settlements FOR EACH ROW EXECUTE FUNCTION credit_settlement_guard();
CREATE FUNCTION credit_redemption_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s customer_credit_settlements%ROWTYPE; g customer_credit_entries%ROWTYPE; used numeric; places integer;
BEGIN
 SELECT * INTO STRICT s FROM customer_credit_settlements WHERE id=NEW.settlement_id;
 PERFORM id FROM orders WHERE id=s.order_id FOR UPDATE;
 PERFORM id FROM customers WHERE id=s.customer_id FOR UPDATE;
 SELECT * INTO STRICT g FROM customer_credit_entries WHERE id=NEW.grant_id;
 SELECT decimal_places INTO STRICT places FROM currencies WHERE id=s.currency_id FOR SHARE;
 SELECT COALESCE(sum(amount),0) INTO used FROM customer_credit_redemptions WHERE grant_id=g.id;
 IF (g.customer_id,g.branch_id,g.currency_id) IS DISTINCT FROM (s.customer_id,s.branch_id,s.currency_id)
 OR g.occurred_at>s.collected_at OR scale(trim_scale(NEW.amount))>places OR used+NEW.amount>g.amount
 OR EXISTS(SELECT 1 FROM invoice_collections WHERE invoice_id=s.invoice_id) THEN
  RAISE EXCEPTION 'Invalid, late or overspent customer credit grant' USING ERRCODE='23514'; END IF;
 IF g.source_return_case_id IS NOT NULL AND
 (SELECT count(*) FROM customer_return_responsibilities WHERE case_id=g.source_return_case_id)>1 AND used+NEW.amount<>g.amount THEN
  RAISE EXCEPTION 'Partial mixed-party credit needs explicit responsibility attribution' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_redemption_guard BEFORE INSERT ON customer_credit_redemptions FOR EACH ROW EXECUTE FUNCTION credit_redemption_guard();
ALTER FUNCTION validate_invoice_collection_history() RENAME TO credit_pre_mixed_collection_history;
CREATE FUNCTION validate_invoice_collection_history() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s customer_credit_settlements%ROWTYPE; i invoices%ROWTYPE; a order_captains%ROWTYPE;
BEGIN
 SELECT * INTO s FROM customer_credit_settlements WHERE invoice_id=NEW.invoice_id;
 IF s.id IS NULL THEN
  IF NEW.amount<=0 THEN RAISE EXCEPTION 'Ordinary collection must be positive' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO STRICT i FROM invoices WHERE id=NEW.invoice_id FOR UPDATE;
 IF (NEW.order_id,NEW.currency_id,NEW.recorded_by,NEW.amount,NEW.collection_method,NEW.collected_at,NEW.payment_reference)
 IS DISTINCT FROM (s.order_id,s.currency_id,s.recorded_by,s.actual_amount,s.collection_method,s.collected_at,s.payment_reference)
 OR i.status<>'ISSUED' OR i.superseded_at IS NOT NULL OR credit_collection_settled_total(NEW)<>i.grand_total THEN
  RAISE EXCEPTION 'Mixed collection must match its exact cash and credit source' USING ERRCODE='23514'; END IF;
 IF NEW.collection_method='CASH' THEN
  SELECT * INTO a FROM order_captains WHERE id=NEW.order_captain_id;
  IF a.id IS NULL OR a.order_id<>s.order_id OR a.captain_id IS DISTINCT FROM NEW.captain_id OR a.assignment_role<>'PRIMARY_COLLECTION' OR a.removed_at IS NOT NULL THEN
   RAISE EXCEPTION 'Mixed CASH needs the authoritative collector' USING ERRCODE='23514'; END IF;
 ELSIF NEW.captain_id IS NOT NULL OR NEW.order_captain_id IS NOT NULL THEN
  RAISE EXCEPTION 'Mixed SHAM_CASH has no Captain cash collector' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
SQL);
        // The original trigger keeps its OID and its full legacy body for ordinary collections.
        $this->replaceFunction('credit_pre_mixed_collection_history()', [
            'BEGIN' => "BEGIN\n IF EXISTS(SELECT 1 FROM customer_credit_settlements WHERE invoice_id=NEW.invoice_id) THEN RETURN NEW; END IF;",
        ]);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER credit_mixed_collection BEFORE INSERT ON invoice_collections FOR EACH ROW EXECUTE FUNCTION validate_invoice_collection_history();
ALTER TABLE invoice_collections DROP CONSTRAINT invoice_collection_amount_positive;
ALTER TABLE invoice_collections ADD CONSTRAINT invoice_collection_amount_positive CHECK(amount>=0 AND amount::text NOT IN ('NaN','Infinity','-Infinity'));
CREATE FUNCTION assert_credit_settlement(sid bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE s customer_credit_settlements%ROWTYPE; c invoice_collections%ROWTYPE;
BEGIN
 SELECT * INTO STRICT s FROM customer_credit_settlements WHERE id=sid;
 SELECT * INTO c FROM invoice_collections WHERE invoice_id=s.invoice_id;
 IF c.id IS NULL OR c.amount<>s.actual_amount OR c.collection_method<>s.collection_method
 OR c.collected_at<>s.collected_at OR c.recorded_by IS DISTINCT FROM s.recorded_by
 OR (SELECT COALESCE(sum(amount),0) FROM customer_credit_redemptions WHERE settlement_id=s.id)<>s.credit_amount THEN
  RAISE EXCEPTION 'Mixed settlement requires exact atomic collection and credit debits' USING ERRCODE='23514'; END IF;
END $$;
CREATE FUNCTION credit_settlement_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='customer_credit_settlements' THEN PERFORM assert_credit_settlement(NEW.id);
 ELSE PERFORM assert_credit_settlement(NEW.settlement_id); END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER credit_settlement_complete AFTER INSERT ON customer_credit_settlements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_settlement_complete();
CREATE CONSTRAINT TRIGGER credit_redemption_complete AFTER INSERT ON customer_credit_redemptions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_settlement_complete();
CREATE TRIGGER credit_settlement_immutable BEFORE UPDATE OR DELETE ON customer_credit_settlements FOR EACH ROW EXECUTE FUNCTION ret_immutable();
CREATE TRIGGER credit_settlement_no_truncate BEFORE TRUNCATE ON customer_credit_settlements FOR EACH STATEMENT EXECUTE FUNCTION ret_immutable();
CREATE TRIGGER credit_redemption_immutable BEFORE UPDATE OR DELETE ON customer_credit_redemptions FOR EACH ROW EXECUTE FUNCTION ret_immutable();
CREATE TRIGGER credit_redemption_no_truncate BEFORE TRUNCATE ON customer_credit_redemptions FOR EACH STATEMENT EXECUTE FUNCTION ret_immutable();
CREATE OR REPLACE FUNCTION ret_funded_responsibility(party_id bigint, as_of timestamptz DEFAULT 'infinity') RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN (SELECT count(*) FROM customer_return_responsibilities WHERE case_id=c.id)=1 THEN LEAST(r.amount,p.paid)
  WHEN p.paid=c.amount THEN r.amount ELSE 0 END
 FROM customer_return_responsibilities r JOIN customer_return_cases c ON c.id=r.case_id
 CROSS JOIN LATERAL(SELECT
  COALESCE((SELECT sum(m.amount) FROM customer_return_movements m WHERE m.case_id=c.id AND m.kind='COMPENSATION' AND m.occurred_at<=as_of),0)
  +COALESCE((SELECT sum(d.amount) FROM customer_credit_redemptions d JOIN customer_credit_entries g ON g.id=d.grant_id
    JOIN customer_credit_settlements s ON s.id=d.settlement_id JOIN invoice_collections col ON col.invoice_id=s.invoice_id
    WHERE g.source_return_case_id=c.id AND col.collected_at<=as_of),0) AS paid) p WHERE r.id=party_id
$$;
CREATE FUNCTION credit_refund_boundary() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM id FROM orders WHERE id=NEW.order_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM customer_credit_settlements WHERE order_id=NEW.order_id) THEN
  RAISE EXCEPTION 'Mixed credit settlement needs an attributed refund/reversal contract' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_return_boundary BEFORE INSERT ON customer_return_cases FOR EACH ROW EXECUTE FUNCTION credit_refund_boundary();
CREATE TRIGGER credit_cancellation_boundary BEFORE INSERT ON order_cancellation_intents FOR EACH ROW EXECUTE FUNCTION credit_refund_boundary();
SQL);
        foreach (['validate_order_accounting_v2_event(bigint)', 'assert_order_accounting_v2_set(bigint)',
            'validate_order_accounting_v3_event(bigint)', 'assert_order_accounting_v3_set(bigint)'] as $function) {
            $this->replaceFunction($function, ['c.amount IS DISTINCT FROM i.grand_total' => 'credit_collection_settled_total(c) IS DISTINCT FROM i.grand_total']);
        }
        $this->replaceFunction('validate_order_accounting_v2_event(bigint)', [
            'i.delivery_total<=0' => 'credit_collection_delivery_cash(c,i.delivery_total)<=0',
            'e.amount IS DISTINCT FROM i.delivery_total' => 'e.amount IS DISTINCT FROM credit_collection_delivery_cash(c,i.delivery_total)',
        ]);
        $this->replaceFunction('assert_order_accounting_v2_set(bigint)', [
            "required:=c.collection_method='CASH' AND i.delivery_total>0" => "required:=c.collection_method='CASH' AND credit_collection_delivery_cash(c,i.delivery_total)>0",
        ]);
        $this->replaceFunction('validate_order_accounting_v3_event(bigint)', [
            'THEN l.company_collectible' => 'THEN credit_v3_company(c,l.customer_delivery_amount_allocated,l.captain_entitlement)',
            'THEN l.captain_shortfall' => 'THEN credit_v3_shortfall(c,l.customer_delivery_amount_allocated,l.captain_entitlement)',
        ], 2);
        $this->replaceFunction('assert_order_accounting_v3_set(bigint)', [
            'THEN company_collectible' => 'THEN credit_v3_company(c,customer_delivery_amount_allocated,captain_entitlement)',
            'THEN captain_shortfall' => 'THEN credit_v3_shortfall(c,customer_delivery_amount_allocated,captain_entitlement)',
        ]);
        $this->replaceFunction('guard_commission_transaction()', ['ic.amount=i.grand_total' => 'credit_collection_settled_total(ic)=i.grand_total']);
    }

    private function replaceFunction(string $signature, array $replacements, int $expected = 1): void
    {
        $definition = DB::scalar('SELECT pg_get_functiondef(?::regprocedure)', [$signature]);
        foreach ($replacements as $before => $after) {
            if (substr_count($definition, $before) !== $expected) {
                throw new RuntimeException('Unexpected installed financial function: '.$signature);
            }
            $definition = str_replace($before, $after, $definition);
        }
        DB::unprepared($definition);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Mixed settlement and customer credit history are forward-only.');
    }
};
