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
CREATE TABLE customer_return_cases (
 id bigserial PRIMARY KEY, request_key uuid NOT NULL UNIQUE, payload_hash char(64) NOT NULL,
 order_id bigint NOT NULL REFERENCES orders(id), invoice_id bigint NOT NULL REFERENCES invoices(id),
 collection_id bigint NOT NULL REFERENCES invoice_collections(id), branch_id bigint NOT NULL REFERENCES branches(id),
 currency_id bigint NOT NULL REFERENCES currencies(id), reason text NOT NULL CHECK(reason IN ('QUALITY','DAMAGE','WRONG_ITEM','OTHER')),
 notes text NOT NULL CHECK(length(btrim(notes)) BETWEEN 1 AND 5000),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')),
 product_scope text NOT NULL CHECK(product_scope IN ('NONE','PARTIAL','FULL')),
 invoice_refund_scope text NOT NULL CHECK(invoice_refund_scope IN ('NONE','PARTIAL','FULL')),
 recorded_by bigint NOT NULL REFERENCES users(id), occurred_at timestamptz NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(), sealed_at timestamptz
);
CREATE INDEX ret_case_order ON customer_return_cases(order_id,id);
CREATE TABLE customer_return_lines (
 id bigserial PRIMARY KEY, case_id bigint NOT NULL REFERENCES customer_return_cases(id),
 kind text NOT NULL CHECK(kind IN ('PRODUCT','DELIVERY','BILL_SERVICE')),
 invoice_item_id bigint REFERENCES invoice_items(id), bill_payment_id bigint REFERENCES order_bill_payments(id),
 quantity numeric NOT NULL CHECK(quantity>=0 AND quantity::text NOT IN ('NaN','Infinity','-Infinity')),
 amount numeric NOT NULL CHECK(amount>=0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')),
 CHECK((kind='PRODUCT' AND invoice_item_id IS NOT NULL AND bill_payment_id IS NULL AND quantity>0)
 OR (kind='DELIVERY' AND invoice_item_id IS NULL AND bill_payment_id IS NULL AND quantity=0 AND amount>0)
 OR (kind='BILL_SERVICE' AND invoice_item_id IS NULL AND bill_payment_id IS NOT NULL AND quantity=0 AND amount>0))
);
CREATE UNIQUE INDEX ret_line_identity ON customer_return_lines(case_id,kind,COALESCE(invoice_item_id,bill_payment_id,0));
CREATE INDEX ret_item ON customer_return_lines(invoice_item_id);
CREATE INDEX ret_service ON customer_return_lines(bill_payment_id);
CREATE TABLE customer_return_responsibilities (
 id bigserial PRIMARY KEY, case_id bigint NOT NULL REFERENCES customer_return_cases(id),
 party text NOT NULL CHECK(party IN ('COMPANY','SHOP','CAPTAIN')),
 order_captain_id bigint REFERENCES order_captains(id), order_shop_id bigint REFERENCES order_shops(id),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')),
 CHECK((party='COMPANY' AND order_captain_id IS NULL AND order_shop_id IS NULL)
 OR (party='CAPTAIN' AND order_captain_id IS NOT NULL AND order_shop_id IS NULL)
 OR (party='SHOP' AND order_captain_id IS NULL AND order_shop_id IS NOT NULL))
);
CREATE UNIQUE INDEX ret_party_identity ON customer_return_responsibilities(case_id,party,COALESCE(order_captain_id,order_shop_id,0));
CREATE INDEX ret_captain_liability ON customer_return_responsibilities(order_captain_id,case_id);
CREATE TABLE customer_return_movements (
 id bigserial PRIMARY KEY, request_key uuid NOT NULL UNIQUE, payload_hash char(64) NOT NULL,
 case_id bigint NOT NULL REFERENCES customer_return_cases(id), responsibility_id bigint REFERENCES customer_return_responsibilities(id),
 kind text NOT NULL CHECK(kind IN ('COMPENSATION','RECOVERY')),
 method text NOT NULL CHECK(method IN ('CASH','TRANSFER')), funding_source text NOT NULL CHECK(length(btrim(funding_source)) BETWEEN 1 AND 255),
 branch_id bigint NOT NULL REFERENCES branches(id), currency_id bigint NOT NULL REFERENCES currencies(id),
 amount numeric NOT NULL CHECK(amount>0 AND amount::text NOT IN ('NaN','Infinity','-Infinity')),
 reference text NOT NULL CHECK(length(btrim(reference)) BETWEEN 1 AND 191),
 occurred_at timestamptz NOT NULL, recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(), recorded_by bigint NOT NULL REFERENCES users(id),
 evidence_path text NOT NULL, evidence_name text NOT NULL, evidence_mime text NOT NULL CHECK(evidence_mime IN ('image/jpeg','image/png','image/webp')),
 evidence_size integer NOT NULL CHECK(evidence_size BETWEEN 1 AND 5242880), evidence_sha256 char(64) NOT NULL UNIQUE,
 actual_confirmed boolean NOT NULL CHECK(actual_confirmed),
 CHECK((kind='COMPENSATION' AND responsibility_id IS NULL) OR (kind='RECOVERY' AND responsibility_id IS NOT NULL)),
 UNIQUE(branch_id,currency_id,kind,method,reference)
);
CREATE INDEX ret_movement_case ON customer_return_movements(case_id,id);
CREATE INDEX ret_recovery_source ON customer_return_movements(responsibility_id);
CREATE FUNCTION ret_assert_no_legacy(oid bigint) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM invoice_financial_corrections WHERE order_id=oid AND state<>'ABANDONED')
 OR EXISTS(SELECT 1 FROM customer_refunds r JOIN invoice_collections c ON c.id=r.original_collection_id WHERE c.order_id=oid) THEN
  RAISE EXCEPTION 'RET legacy conflict: correction or refund already exists' USING ERRCODE='23514'; END IF;
END $$;
CREATE FUNCTION ret_funded_responsibility(party_id bigint, as_of timestamptz DEFAULT 'infinity') RETURNS numeric LANGUAGE sql STABLE AS $$
 SELECT CASE WHEN (SELECT count(*) FROM customer_return_responsibilities WHERE case_id=c.id)=1 THEN LEAST(r.amount,p.paid)
  WHEN p.paid=c.amount THEN r.amount ELSE 0 END
 FROM customer_return_responsibilities r JOIN customer_return_cases c ON c.id=r.case_id
 CROSS JOIN LATERAL(SELECT COALESCE(sum(m.amount),0) AS paid FROM customer_return_movements m
  WHERE m.case_id=c.id AND m.kind='COMPENSATION' AND m.occurred_at<=as_of) p WHERE r.id=party_id
$$;
CREATE FUNCTION ret_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'RET history is append-only' USING ERRCODE='23514'; END $$;
CREATE FUNCTION ret_case_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o orders%ROWTYPE; i invoices%ROWTYPE; c invoice_collections%ROWTYPE; places integer;
BEGIN
 IF TG_OP='UPDATE' THEN
  IF OLD.sealed_at IS NOT NULL OR NEW.sealed_at IS NULL OR (to_jsonb(NEW)-'sealed_at') IS DISTINCT FROM (to_jsonb(OLD)-'sealed_at') THEN
   RAISE EXCEPTION 'RET case is immutable' USING ERRCODE='23514'; END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO STRICT o FROM orders WHERE id=NEW.order_id FOR UPDATE;
 PERFORM ret_assert_no_legacy(o.id);
 SELECT * INTO STRICT i FROM invoices WHERE id=NEW.invoice_id;
 SELECT * INTO STRICT c FROM invoice_collections WHERE id=NEW.collection_id;
 SELECT decimal_places INTO places FROM currencies WHERE id=NEW.currency_id FOR SHARE;
 IF o.operational_status<>'DELIVERED' OR i.issued_at IS NULL OR i.status<>'ISSUED' OR i.superseded_at IS NOT NULL OR i.order_id<>o.id OR c.order_id<>o.id OR c.invoice_id<>i.id
 OR i.currency_id<>NEW.currency_id OR c.currency_id<>NEW.currency_id OR o.branch_id<>NEW.branch_id
 OR NEW.sealed_at IS NOT NULL OR NEW.occurred_at<c.collected_at OR NEW.occurred_at>clock_timestamp()
 OR scale(trim_scale(NEW.amount))>places OR NOT EXISTS(SELECT 1 FROM currencies WHERE id=NEW.currency_id AND is_active)
 OR NOT benefit_actor_allowed(NEW.recorded_by,NEW.branch_id,'order_shop_returns.create')
 OR NOT benefit_actor_allowed(NEW.recorded_by,NEW.branch_id,'orders.view')
 OR NOT benefit_actor_allowed(NEW.recorded_by,NEW.branch_id,'invoices.view') THEN
  RAISE EXCEPTION 'Invalid RET collected source, actor, time or precision' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER ret_case_guard BEFORE INSERT OR UPDATE ON customer_return_cases FOR EACH ROW EXECUTE FUNCTION ret_case_guard();
CREATE TRIGGER ret_case_no_delete BEFORE DELETE ON customer_return_cases FOR EACH ROW EXECUTE FUNCTION ret_immutable();
CREATE FUNCTION ret_child_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c customer_return_cases%ROWTYPE; i invoice_items%ROWTYPE; b order_bill_payments%ROWTYPE; places integer; used numeric;
BEGIN
 SELECT * INTO STRICT c FROM customer_return_cases WHERE id=NEW.case_id FOR UPDATE;
 IF c.sealed_at IS NOT NULL THEN RAISE EXCEPTION 'RET case is sealed' USING ERRCODE='23514'; END IF;
 SELECT decimal_places INTO places FROM currencies WHERE id=c.currency_id FOR SHARE;
 IF scale(trim_scale(NEW.amount))>places THEN RAISE EXCEPTION 'RET precision' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='customer_return_lines' THEN
  IF NEW.kind='PRODUCT' THEN
   SELECT * INTO STRICT i FROM invoice_items WHERE id=NEW.invoice_item_id;
   SELECT COALESCE(sum(quantity),0) INTO used FROM customer_return_lines WHERE invoice_item_id=i.id;
   IF i.invoice_id<>c.invoice_id OR i.currency_id<>c.currency_id OR scale(trim_scale(NEW.quantity))>4 OR used+NEW.quantity>i.quantity
    OR NEW.amount*i.quantity>i.line_total*NEW.quantity
    OR (NEW.quantity<>trunc(NEW.quantity) AND EXISTS(SELECT 1 FROM order_items oi JOIN units u ON u.id=oi.unit_id WHERE oi.id=i.order_item_id AND NOT u.allows_decimal)) THEN
    RAISE EXCEPTION 'RET product source, quantity or amount' USING ERRCODE='23514'; END IF;
  ELSIF NEW.kind='DELIVERY' THEN
   SELECT COALESCE(sum(l.amount),0) INTO used FROM customer_return_lines l JOIN customer_return_cases r ON r.id=l.case_id WHERE r.invoice_id=c.invoice_id AND l.kind='DELIVERY';
   IF used+NEW.amount>(SELECT delivery_total FROM invoices WHERE id=c.invoice_id) THEN RAISE EXCEPTION 'RET delivery limit' USING ERRCODE='23514'; END IF;
  ELSE
   SELECT * INTO STRICT b FROM order_bill_payments WHERE id=NEW.bill_payment_id;
   SELECT COALESCE(sum(amount),0) INTO used FROM customer_return_lines WHERE bill_payment_id=b.id;
   IF b.order_id<>c.order_id OR b.currency_id<>c.currency_id OR used+NEW.amount>b.service_fee OR b.paid_at>c.occurred_at THEN
    RAISE EXCEPTION 'RET service fee source or limit; external principal is not refundable here' USING ERRCODE='23514'; END IF;
  END IF;
 ELSE
  IF NEW.party='CAPTAIN' AND NOT EXISTS(SELECT 1 FROM order_captains WHERE id=NEW.order_captain_id AND order_id=c.order_id) THEN
   RAISE EXCEPTION 'RET responsible Captain is not an Order participant' USING ERRCODE='23514'; END IF;
  IF NEW.party='SHOP' AND NOT EXISTS(SELECT 1 FROM order_shops WHERE id=NEW.order_shop_id AND order_id=c.order_id) THEN
   RAISE EXCEPTION 'RET responsible Shop is not part of the Order' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER ret_line_guard BEFORE INSERT ON customer_return_lines FOR EACH ROW EXECUTE FUNCTION ret_child_guard();
CREATE TRIGGER ret_responsibility_guard BEFORE INSERT ON customer_return_responsibilities FOR EACH ROW EXECUTE FUNCTION ret_child_guard();
CREATE FUNCTION ret_case_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c customer_return_cases%ROWTYPE; total numeric; used numeric; scope text; own_base numeric;
BEGIN
 SELECT * INTO STRICT c FROM customer_return_cases WHERE id=NEW.id;
 IF c.sealed_at IS NULL OR c.amount<>(SELECT COALESCE(sum(amount),0) FROM customer_return_lines WHERE case_id=c.id)
 OR c.amount<>(SELECT COALESCE(sum(amount),0) FROM customer_return_responsibilities WHERE case_id=c.id) THEN
  RAISE EXCEPTION 'RET requires sealed complete lines and exact responsibility allocation' USING ERRCODE='23514'; END IF;
 SELECT CASE WHEN NOT EXISTS(SELECT 1 FROM customer_return_lines WHERE case_id=c.id AND kind='PRODUCT') THEN 'NONE'
  WHEN NOT EXISTS(SELECT 1 FROM invoice_items i WHERE i.invoice_id=c.invoice_id AND NOT EXISTS(
   SELECT 1 FROM customer_return_lines l WHERE l.case_id=c.id AND l.invoice_item_id=i.id AND l.quantity=i.quantity)) THEN 'FULL' ELSE 'PARTIAL' END INTO scope;
 SELECT COALESCE(sum(amount),0) INTO own_base FROM customer_return_lines WHERE case_id=c.id AND kind<>'BILL_SERVICE';
 IF c.product_scope<>scope OR c.invoice_refund_scope<>(CASE WHEN own_base=0 THEN 'NONE'
  WHEN own_base=(SELECT amount FROM invoice_collections WHERE id=c.collection_id) THEN 'FULL' ELSE 'PARTIAL' END) THEN
  RAISE EXCEPTION 'RET full products and full Invoice compensation must be distinguished' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(sum(l.amount),0) INTO total FROM customer_return_lines l JOIN customer_return_cases r ON r.id=l.case_id
 WHERE r.collection_id=c.collection_id AND l.kind<>'BILL_SERVICE';
 SELECT COALESCE(sum(amount),0) INTO used FROM customer_refunds WHERE original_collection_id=c.collection_id;
 IF total+used>(SELECT amount FROM invoice_collections WHERE id=c.collection_id) THEN
  RAISE EXCEPTION 'RET exceeds legitimately refundable collection' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER ret_complete AFTER INSERT OR UPDATE ON customer_return_cases DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ret_case_complete();
CREATE FUNCTION ret_movement_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c customer_return_cases%ROWTYPE; r customer_return_responsibilities%ROWTYPE; used numeric; places integer;
BEGIN
 PERFORM o.id FROM orders o JOIN customer_return_cases rc ON rc.order_id=o.id WHERE rc.id=NEW.case_id FOR UPDATE OF o;
 SELECT * INTO STRICT c FROM customer_return_cases WHERE id=NEW.case_id FOR UPDATE;
 SELECT decimal_places INTO places FROM currencies WHERE id=c.currency_id FOR SHARE;
 IF c.sealed_at IS NULL OR NEW.branch_id<>c.branch_id OR NEW.currency_id<>c.currency_id OR NEW.occurred_at<c.occurred_at OR NEW.occurred_at>clock_timestamp()
 OR scale(trim_scale(NEW.amount))>places OR NOT EXISTS(SELECT 1 FROM currencies WHERE id=c.currency_id AND is_active)
 OR NOT benefit_actor_allowed(NEW.recorded_by,c.branch_id,'order_shop_returns.create')
 OR NOT benefit_actor_allowed(NEW.recorded_by,c.branch_id,'orders.view')
 OR NOT benefit_actor_allowed(NEW.recorded_by,c.branch_id,'invoices.view') THEN RAISE EXCEPTION 'Invalid RET money source/actor/time/precision' USING ERRCODE='23514'; END IF;
 PERFORM ret_assert_no_legacy(c.order_id);
 IF EXISTS(SELECT 1 FROM invoices WHERE id=c.invoice_id AND superseded_at IS NOT NULL) THEN
  RAISE EXCEPTION 'RET legacy conflict: Invoice source was superseded' USING ERRCODE='23514'; END IF;
 IF NEW.kind='COMPENSATION' THEN
  SELECT COALESCE(sum(amount),0) INTO used FROM customer_return_movements WHERE case_id=c.id AND kind='COMPENSATION';
  IF used+NEW.amount>c.amount THEN RAISE EXCEPTION 'RET compensation exceeds remaining amount' USING ERRCODE='23514'; END IF;
  IF (SELECT count(*) FROM customer_return_responsibilities WHERE case_id=c.id)>1 AND used+NEW.amount<>c.amount THEN
   RAISE EXCEPTION 'RET ambiguous partial funding requires explicit attribution' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO STRICT r FROM customer_return_responsibilities WHERE id=NEW.responsibility_id FOR UPDATE;
  SELECT COALESCE(sum(amount),0) INTO used FROM customer_return_movements WHERE responsibility_id=r.id;
  IF r.case_id<>c.id OR r.party='COMPANY' OR used+NEW.amount>r.amount THEN
   RAISE EXCEPTION 'RET recovery exceeds exact party obligation' USING ERRCODE='23514'; END IF;
  IF r.party='CAPTAIN' AND used+NEW.amount>ret_funded_responsibility(r.id,NEW.occurred_at) THEN
   RAISE EXCEPTION 'RET recovery exceeds funded obligation' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER ret_movement_guard BEFORE INSERT ON customer_return_movements FOR EACH ROW EXECUTE FUNCTION ret_movement_guard();
CREATE FUNCTION ret_legacy_refund_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE oid bigint;
BEGIN
 IF TG_TABLE_NAME='invoice_financial_corrections' THEN
  IF TG_OP='UPDATE' AND NEW.state='ABANDONED' THEN RETURN NEW; END IF;
  oid:=NEW.order_id;
 ELSE SELECT order_id INTO STRICT oid FROM invoice_collections WHERE id=NEW.original_collection_id; END IF;
 PERFORM id FROM orders WHERE id=oid FOR UPDATE;
 IF EXISTS(SELECT 1 FROM customer_return_cases c WHERE c.order_id=oid) THEN
  RAISE EXCEPTION 'RET case already exists; legacy correction/refund is incompatible' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER ret_legacy_refund_guard BEFORE INSERT ON customer_refunds FOR EACH ROW EXECUTE FUNCTION ret_legacy_refund_guard();
CREATE TRIGGER ret_correction_guard BEFORE INSERT OR UPDATE ON invoice_financial_corrections FOR EACH ROW EXECUTE FUNCTION ret_legacy_refund_guard();
SQL);
        foreach (['customer_return_cases', 'customer_return_lines', 'customer_return_responsibilities', 'customer_return_movements'] as $table) {
            if ($table !== 'customer_return_cases') {
                DB::unprepared("CREATE TRIGGER ret_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION ret_immutable()");
            }
            DB::unprepared("CREATE TRIGGER ret_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION ret_immutable()");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Customer return and money history is forward-only.');
    }
};
