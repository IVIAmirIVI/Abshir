<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
LOCK TABLE customer_return_cases, customer_return_movements IN ACCESS EXCLUSIVE MODE;
ALTER TABLE customer_return_cases ADD COLUMN compensation_contract_version smallint NOT NULL DEFAULT 1 CHECK(compensation_contract_version IN (1,2));
ALTER TABLE customer_return_cases ALTER COLUMN compensation_contract_version SET DEFAULT 2;
CREATE FUNCTION ret_compensation_limit(cid bigint) RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN r.compensation_contract_version=2 THEN i.grand_total ELSE c.amount END
 FROM customer_return_cases r JOIN invoices i ON i.id=r.invoice_id JOIN invoice_collections c ON c.id=r.collection_id WHERE r.id=cid
$$;
CREATE FUNCTION ret_independent_decision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c customer_return_cases%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME='customer_return_cases' THEN
  IF NEW.compensation_contract_version<>2 THEN RAISE EXCEPTION 'New return decisions require independent compensation V2' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO STRICT c FROM customer_return_cases WHERE id=NEW.case_id FOR UPDATE;
  IF NEW.kind='COMPENSATION' AND c.compensation_contract_version=2
   AND (NEW.amount<>c.amount OR EXISTS(SELECT 1 FROM customer_return_movements WHERE case_id=c.id AND kind='COMPENSATION')) THEN
   RAISE EXCEPTION 'Return requires one full compensation payment, not installments' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER ret_independent_decision BEFORE INSERT ON customer_return_cases FOR EACH ROW EXECUTE FUNCTION ret_independent_decision_guard();
CREATE TRIGGER ret_full_compensation BEFORE INSERT ON customer_return_movements FOR EACH ROW EXECUTE FUNCTION ret_independent_decision_guard();
DROP TRIGGER credit_return_boundary ON customer_return_cases;
SQL);
        $definition = DB::scalar("SELECT pg_get_functiondef('ret_case_complete()'::regprocedure)");
        $before = 'SELECT amount FROM invoice_collections WHERE id=c.collection_id';
        if (substr_count($definition, $before) !== 2) {
            throw new RuntimeException('Unexpected return completion prerequisite.');
        }
        DB::unprepared(str_replace($before, 'SELECT ret_compensation_limit(c.id)', $definition));
    }

    public function down(): void
    {
        throw new LogicException('Independent compensation history is forward-only.');
    }
};
