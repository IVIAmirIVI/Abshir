<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
LOCK TABLE customer_credit_redemptions, customer_return_responsibilities IN ACCESS EXCLUSIVE MODE;
ALTER TABLE customer_credit_redemptions ADD COLUMN attribution_version smallint NOT NULL DEFAULT 1 CHECK(attribution_version IN (1,2));
ALTER TABLE customer_credit_redemptions ALTER COLUMN attribution_version SET DEFAULT 2;
CREATE TABLE customer_credit_responsibility_attributions (
 redemption_id bigint NOT NULL REFERENCES customer_credit_redemptions(id),
 responsibility_id bigint NOT NULL REFERENCES customer_return_responsibilities(id),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')),
 PRIMARY KEY(redemption_id,responsibility_id)
);
CREATE INDEX credit_attribution_party ON customer_credit_responsibility_attributions(responsibility_id);
CREATE FUNCTION credit_legacy_responsibility_funding(party_id bigint, as_of timestamptz DEFAULT 'infinity') RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN (SELECT count(*) FROM customer_return_responsibilities WHERE case_id=c.id)=1 THEN LEAST(r.amount,p.paid)
  WHEN p.paid=c.amount THEN r.amount ELSE 0 END
 FROM customer_return_responsibilities r JOIN customer_return_cases c ON c.id=r.case_id
 CROSS JOIN LATERAL(SELECT
  COALESCE((SELECT sum(m.amount) FROM customer_return_movements m WHERE m.case_id=c.id AND m.kind='COMPENSATION' AND m.occurred_at<=as_of),0)
  +COALESCE((SELECT sum(d.amount) FROM customer_credit_redemptions d JOIN customer_credit_entries g ON g.id=d.grant_id
   JOIN customer_credit_settlements s ON s.id=d.settlement_id JOIN invoice_collections col ON col.invoice_id=s.invoice_id
   WHERE g.source_return_case_id=c.id AND d.attribution_version=1 AND col.collected_at<=as_of),0) AS paid) p WHERE r.id=party_id
$$;
CREATE OR REPLACE FUNCTION ret_funded_responsibility(party_id bigint, as_of timestamptz DEFAULT 'infinity') RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT LEAST(r.amount,credit_legacy_responsibility_funding(r.id,as_of)+COALESCE((
  SELECT sum(a.amount) FROM customer_credit_responsibility_attributions a JOIN customer_credit_redemptions d ON d.id=a.redemption_id
  JOIN customer_credit_settlements s ON s.id=d.settlement_id JOIN invoice_collections col ON col.invoice_id=s.invoice_id
  WHERE a.responsibility_id=r.id AND col.collected_at<=as_of),0)) FROM customer_return_responsibilities r WHERE r.id=party_id
$$;
CREATE FUNCTION credit_attribution_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d customer_credit_redemptions%ROWTYPE; s customer_credit_settlements%ROWTYPE; g customer_credit_entries%ROWTYPE;
 r customer_return_responsibilities%ROWTYPE; places integer; used numeric;
BEGIN
 SELECT * INTO STRICT d FROM customer_credit_redemptions WHERE id=NEW.redemption_id;
 SELECT * INTO STRICT s FROM customer_credit_settlements WHERE id=d.settlement_id;
 PERFORM id FROM orders WHERE id=s.order_id FOR UPDATE;
 PERFORM id FROM customers WHERE id=s.customer_id FOR UPDATE;
 SELECT * INTO STRICT g FROM customer_credit_entries WHERE id=d.grant_id;
 SELECT * INTO STRICT r FROM customer_return_responsibilities WHERE id=NEW.responsibility_id FOR UPDATE;
 SELECT decimal_places INTO STRICT places FROM currencies WHERE id=s.currency_id;
 SELECT COALESCE(sum(amount),0) INTO used FROM customer_credit_responsibility_attributions WHERE responsibility_id=r.id;
 IF d.attribution_version<>2 OR g.source_return_case_id IS DISTINCT FROM r.case_id
 OR scale(trim_scale(NEW.amount))>places OR NEW.amount+used+credit_legacy_responsibility_funding(r.id)>r.amount
 OR EXISTS(SELECT 1 FROM invoice_collections WHERE invoice_id=s.invoice_id) THEN
  RAISE EXCEPTION 'Invalid or excessive explicit credit responsibility attribution' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER credit_attribution_guard BEFORE INSERT ON customer_credit_responsibility_attributions FOR EACH ROW EXECUTE FUNCTION credit_attribution_guard();
CREATE FUNCTION assert_credit_attribution(did bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE d customer_credit_redemptions%ROWTYPE; cid bigint; attributed numeric;
BEGIN
 SELECT * INTO STRICT d FROM customer_credit_redemptions WHERE id=did;
 SELECT source_return_case_id INTO cid FROM customer_credit_entries WHERE id=d.grant_id;
 SELECT COALESCE(sum(amount),0) INTO attributed FROM customer_credit_responsibility_attributions WHERE redemption_id=d.id;
 IF d.attribution_version=2 AND ((cid IS NOT NULL AND attributed<>d.amount) OR (cid IS NULL AND attributed<>0)) THEN
  RAISE EXCEPTION 'Credit consumption requires exact explicit responsibility attribution' USING ERRCODE='23514'; END IF;
END $$;
CREATE FUNCTION credit_attribution_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME='customer_credit_redemptions' THEN PERFORM assert_credit_attribution(NEW.id);
 ELSE PERFORM assert_credit_attribution(NEW.redemption_id); END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER credit_attribution_complete AFTER INSERT ON customer_credit_responsibility_attributions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_attribution_complete();
CREATE CONSTRAINT TRIGGER credit_redemption_attribution_complete AFTER INSERT ON customer_credit_redemptions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION credit_attribution_complete();
CREATE TRIGGER credit_attribution_immutable BEFORE UPDATE OR DELETE ON customer_credit_responsibility_attributions FOR EACH ROW EXECUTE FUNCTION ret_immutable();
CREATE TRIGGER credit_attribution_no_truncate BEFORE TRUNCATE ON customer_credit_responsibility_attributions FOR EACH STATEMENT EXECUTE FUNCTION ret_immutable();
SQL);
        $definition = DB::scalar("SELECT pg_get_functiondef('credit_redemption_guard()'::regprocedure)");
        $before = <<<'SQL'
 IF g.source_return_case_id IS NOT NULL AND
 (SELECT count(*) FROM customer_return_responsibilities WHERE case_id=g.source_return_case_id)>1 AND used+NEW.amount<>g.amount THEN
  RAISE EXCEPTION 'Partial mixed-party credit needs explicit responsibility attribution' USING ERRCODE='23514'; END IF;
SQL;
        if (substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Unexpected credit redemption prerequisite.');
        }
        DB::unprepared(str_replace($before, " IF NEW.attribution_version<>2 THEN RAISE EXCEPTION 'New redemption requires explicit attribution V2' USING ERRCODE='23514'; END IF;", $definition));
        $definition = DB::scalar("SELECT pg_get_functiondef('assert_credit_settlement(bigint)'::regprocedure)");
        $before = " RAISE EXCEPTION 'Mixed settlement requires exact atomic collection and credit debits' USING ERRCODE='23514'; END IF;";
        if (substr_count($definition, $before) !== 1) {
            throw new RuntimeException('Unexpected credit settlement prerequisite.');
        }
        DB::unprepared(str_replace($before, $before."\n PERFORM assert_credit_attribution(d.id) FROM customer_credit_redemptions d WHERE d.settlement_id=s.id;", $definition));
    }

    public function down(): void
    {
        throw new LogicException('Credit responsibility attribution history is forward-only.');
    }
};
