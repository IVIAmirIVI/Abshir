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
CREATE TABLE shop_commission_epoch (id integer PRIMARY KEY CHECK(id=1), invoice_cutoff bigint NOT NULL);
INSERT INTO shop_commission_epoch VALUES(1,COALESCE((SELECT max(id) FROM invoices),0));
CREATE TABLE shop_commission_legacy_invoices (id bigint PRIMARY KEY REFERENCES invoices(id) ON DELETE RESTRICT);
INSERT INTO shop_commission_legacy_invoices SELECT id FROM invoices WHERE issued_at IS NOT NULL;
CREATE TABLE shop_commission_activation_events (
 id bigserial PRIMARY KEY, draft_id bigint NOT NULL REFERENCES shop_commission_drafts(id) ON DELETE RESTRICT,
 configuration_key uuid NOT NULL, previous_id bigint UNIQUE REFERENCES shop_commission_activation_events(id) ON DELETE RESTRICT,
 state varchar(8) NOT NULL CHECK(state IN ('ACTIVE','INACTIVE')),
 recorded_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, recorded_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 reason text NOT NULL CHECK(length(btrim(reason)) BETWEEN 1 AND 1000)
);
CREATE INDEX commission_activation_identity ON shop_commission_activation_events(configuration_key,id DESC);
CREATE TABLE shop_commission_issue_instants (invoice_id bigint PRIMARY KEY REFERENCES invoices(id) ON DELETE RESTRICT, frozen_at timestamptz(6) NOT NULL);
CREATE TABLE shop_commission_invoice_snapshots (
 id bigserial PRIMARY KEY, invoice_id bigint NOT NULL UNIQUE REFERENCES invoices(id) ON DELETE RESTRICT,
 order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT, branch_id bigint NOT NULL REFERENCES branches(id) ON DELETE RESTRICT,
 invoice_version integer NOT NULL, invoice_reference text NOT NULL, frozen_at timestamptz(6) NOT NULL,
 recorded_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 correction_id bigint UNIQUE REFERENCES invoice_financial_corrections(id) ON DELETE RESTRICT
);
CREATE TABLE shop_commission_lines (
 id bigserial PRIMARY KEY, snapshot_id bigint NOT NULL REFERENCES shop_commission_invoice_snapshots(id) ON DELETE RESTRICT,
 invoice_item_id bigint NOT NULL UNIQUE REFERENCES invoice_items(id) ON DELETE RESTRICT,
 shop_id bigint REFERENCES shops(id) ON DELETE RESTRICT, product_id bigint REFERENCES products(id) ON DELETE RESTRICT,
 rule_id bigint REFERENCES shop_commission_drafts(id) ON DELETE RESTRICT,
 activation_id bigint REFERENCES shop_commission_activation_events(id) ON DELETE RESTRICT,
 original_line_id bigint REFERENCES shop_commission_lines(id) ON DELETE RESTRICT,
 rule_version integer, rule_scope varchar(16), method varchar(16), rule_value numeric,
 quantity numeric NOT NULL, discounted_base numeric NOT NULL, amount numeric NOT NULL,
 currency_id bigint NOT NULL REFERENCES currencies(id) ON DELETE RESTRICT, decimal_places integer NOT NULL CHECK(decimal_places BETWEEN 0 AND 4),
 CHECK(quantity > 0 AND quantity NOT IN ('NaN'::numeric,'Infinity'::numeric,'-Infinity'::numeric)),
 CHECK(discounted_base >= 0 AND discounted_base NOT IN ('NaN'::numeric,'Infinity'::numeric,'-Infinity'::numeric)),
 CHECK(amount >= 0 AND amount NOT IN ('NaN'::numeric,'Infinity'::numeric,'-Infinity'::numeric)),
 CHECK((rule_id IS NULL AND activation_id IS NULL AND rule_version IS NULL AND rule_scope IS NULL AND method IS NULL AND rule_value IS NULL AND amount=0)
 OR (rule_id IS NOT NULL AND activation_id IS NOT NULL AND rule_version IS NOT NULL AND rule_scope IS NOT NULL AND rule_scope IN ('PRODUCT','PRODUCT_GROUP','SHOP')
 AND method IS NOT NULL AND method IN ('PERCENTAGE','FIXED') AND rule_value IS NOT NULL))
);
CREATE INDEX commission_lines_shop ON shop_commission_lines(shop_id,currency_id,snapshot_id);
CREATE TABLE shop_commission_accounts (
 id bigserial PRIMARY KEY, shop_id bigint NOT NULL REFERENCES shops(id) ON DELETE RESTRICT,
 branch_id bigint NOT NULL REFERENCES branches(id) ON DELETE RESTRICT, currency_id bigint NOT NULL REFERENCES currencies(id) ON DELETE RESTRICT,
 UNIQUE(shop_id,branch_id,currency_id)
);
CREATE TABLE shop_commission_transactions (
 id bigserial PRIMARY KEY, account_id bigint NOT NULL REFERENCES shop_commission_accounts(id) ON DELETE RESTRICT,
 kind varchar(8) NOT NULL CHECK(kind IN ('EARNING','REVERSAL','PAYMENT')),
 amount numeric NOT NULL CHECK(amount>0 AND amount NOT IN ('NaN'::numeric,'Infinity'::numeric,'-Infinity'::numeric)),
 line_id bigint UNIQUE REFERENCES shop_commission_lines(id) ON DELETE RESTRICT,
 reversal_of bigint UNIQUE REFERENCES shop_commission_transactions(id) ON DELETE RESTRICT,
 correction_id bigint REFERENCES invoice_financial_corrections(id) ON DELETE RESTRICT,
 request_key uuid UNIQUE, payload_hash varchar(64), payment_method varchar(8), reference varchar(255),
 reason text, occurred_at timestamptz(6) NOT NULL, recorded_at timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
 recorded_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 CHECK((kind='EARNING' AND line_id IS NOT NULL AND reversal_of IS NULL AND correction_id IS NULL AND request_key IS NULL AND payment_method IS NULL)
 OR (kind='REVERSAL' AND line_id IS NULL AND reversal_of IS NOT NULL AND correction_id IS NOT NULL AND request_key IS NULL AND payment_method IS NULL AND reason IS NOT NULL AND length(btrim(reason))>0)
 OR (kind='PAYMENT' AND line_id IS NULL AND reversal_of IS NULL AND correction_id IS NULL AND request_key IS NOT NULL
 AND payload_hash IS NOT NULL AND length(payload_hash)=64 AND payment_method IS NOT NULL AND payment_method IN ('CASH','TRANSFER')))
);
CREATE INDEX commission_transactions_account ON shop_commission_transactions(account_id,id DESC);
CREATE FUNCTION commission_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Shop commission records are immutable' USING ERRCODE='23514'; END $$;
DO $$ DECLARE tab text; BEGIN
 FOREACH tab IN ARRAY ARRAY['shop_commission_epoch','shop_commission_legacy_invoices','shop_commission_activation_events','shop_commission_issue_instants','shop_commission_invoice_snapshots','shop_commission_lines','shop_commission_accounts','shop_commission_transactions'] LOOP
  EXECUTE format('CREATE TRIGGER commission_immutable BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION commission_append_only()',tab);
  EXECUTE format('CREATE TRIGGER commission_no_truncate BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION commission_append_only()',tab);
 END LOOP;
END $$;
CREATE TRIGGER commission_no_legacy_append BEFORE INSERT ON shop_commission_legacy_invoices FOR EACH ROW EXECUTE FUNCTION commission_append_only();
CREATE FUNCTION stamp_commission_invoice_issue() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status='ISSUED' AND (TG_OP='INSERT' OR OLD.status<>'ISSUED') AND NOT EXISTS(SELECT 1 FROM shop_commission_legacy_invoices WHERE id=NEW.id)
 AND NOT EXISTS(SELECT 1 FROM invoice_financial_corrections c WHERE c.order_id=NEW.order_id AND c.state='READY_TO_FINALIZE' AND c.kind='REPRICE' AND c.proposal_hash=NEW.source_items_fingerprint) THEN
  INSERT INTO shop_commission_issue_instants VALUES(NEW.id,clock_timestamp());
 END IF;
 RETURN NULL;
END $$;
CREATE TRIGGER commission_issue_instant AFTER INSERT OR UPDATE OF status ON invoices FOR EACH ROW EXECUTE FUNCTION stamp_commission_invoice_issue();
CREATE FUNCTION guard_commission_issue_instant() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF pg_trigger_depth()<2 THEN RAISE EXCEPTION 'Commission issuance instant must originate from Invoice issuance' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER commission_issue_instant_source BEFORE INSERT ON shop_commission_issue_instants FOR EACH ROW EXECUTE FUNCTION guard_commission_issue_instant();
CREATE FUNCTION commission_issue_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM shop_commission_invoice_snapshots WHERE invoice_id=NEW.invoice_id) THEN
  RAISE EXCEPTION 'Invoice issuance requires its complete commission snapshot' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER commission_issue_complete AFTER INSERT ON shop_commission_issue_instants DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commission_issue_complete();
CREATE FUNCTION guard_commission_activation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d shop_commission_drafts%ROWTYPE; p shop_partnerships%ROWTYPE; prior shop_commission_activation_events%ROWTYPE;
BEGIN
 SELECT * INTO STRICT d FROM shop_commission_drafts WHERE id=NEW.draft_id;
 PERFORM pg_advisory_xact_lock(161010,(SELECT shop_id::integer FROM shop_partnerships WHERE id=d.shop_partnership_id));
 SELECT * INTO STRICT p FROM shop_partnerships WHERE id=d.shop_partnership_id;
 SELECT * INTO prior FROM shop_commission_activation_events WHERE configuration_key=d.configuration_key ORDER BY id DESC LIMIT 1;
 IF NEW.configuration_key IS DISTINCT FROM d.configuration_key OR NEW.previous_id IS DISTINCT FROM prior.id
 OR (prior.id IS NOT NULL AND NEW.recorded_at < prior.recorded_at) THEN
  RAISE EXCEPTION 'Invalid commission activation chain' USING ERRCODE='23514';
 END IF;
 IF NEW.state='ACTIVE' THEN
  IF d.method IS NULL OR (EXISTS(SELECT 1 FROM shop_commission_drafts WHERE replaces_id=d.id))
  OR p.status<>'ACTIVE' OR p.starts_at>NEW.recorded_at OR (p.ends_at IS NOT NULL AND p.ends_at<=NEW.recorded_at)
  OR (d.effective_until IS NOT NULL AND d.effective_until<=NEW.recorded_at)
  OR d.effective_from < p.starts_at OR (p.ends_at IS NOT NULL AND (d.effective_until IS NULL OR d.effective_until>p.ends_at))
  OR NOT EXISTS(SELECT 1 FROM shops WHERE id=p.shop_id AND is_active)
  OR NOT EXISTS(SELECT 1 FROM currencies WHERE id=d.currency_id AND is_active)
  OR (d.method='FIXED' AND d.commission_value<>trunc(d.commission_value,(SELECT decimal_places FROM currencies WHERE id=d.currency_id)))
  OR (d.product_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM products WHERE id=d.product_id AND is_active))
  OR (d.product_group_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM product_groups WHERE id=d.product_group_id AND is_active)) THEN
   RAISE EXCEPTION 'Commission agreement is not eligible for activation' USING ERRCODE='23514';
  END IF;
  IF EXISTS(SELECT 1 FROM shop_partnerships other WHERE other.shop_id=p.shop_id AND other.id<>p.id AND other.status='ACTIVE'
      AND tstzrange(other.starts_at,other.ends_at,'[)') && tstzrange(p.starts_at,p.ends_at,'[)')) THEN
   RAISE EXCEPTION 'Ambiguous Shop partnership intervals' USING ERRCODE='23514'; END IF;
  IF d.product_group_id IS NOT NULL AND EXISTS(
   SELECT 1 FROM shop_commission_activation_events a JOIN shop_commission_drafts other ON other.id=a.draft_id
   WHERE a.state='ACTIVE' AND NOT EXISTS(SELECT 1 FROM shop_commission_activation_events newer WHERE newer.configuration_key=a.configuration_key AND newer.id>a.id)
   AND other.configuration_key<>d.configuration_key AND other.shop_partnership_id=d.shop_partnership_id
   AND other.currency_id=d.currency_id AND other.product_group_id IS NOT NULL AND other.group_priority=d.group_priority
   AND tstzrange(other.effective_from,other.effective_until,'[)') && tstzrange(d.effective_from,d.effective_until,'[)')
  ) THEN RAISE EXCEPTION 'Ambiguous commission group priority' USING ERRCODE='23514'; END IF;
 ELSE
  IF prior.id IS NULL OR prior.state<>'ACTIVE' OR prior.draft_id<>NEW.draft_id THEN
   RAISE EXCEPTION 'Only the active agreement can be deactivated' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER commission_activation_guard BEFORE INSERT ON shop_commission_activation_events FOR EACH ROW EXECUTE FUNCTION guard_commission_activation();
CREATE FUNCTION resolve_active_shop_commission(sid bigint,pid bigint,cid bigint,at_time timestamptz) RETURNS bigint LANGUAGE plpgsql AS $$
DECLARE winner bigint; matches integer;
BEGIN
 WITH candidates AS (
  SELECT d.id, CASE WHEN d.product_id IS NOT NULL THEN 3 WHEN d.product_group_id IS NOT NULL THEN 2 ELSE 1 END rank,
      COALESCE(d.group_priority,0) priority
  FROM shop_commission_drafts d JOIN shop_partnerships p ON p.id=d.shop_partnership_id
  JOIN shop_commission_activation_events a ON a.draft_id=d.id
  WHERE p.shop_id=sid AND p.status<>'SUSPENDED' AND p.starts_at<=at_time AND (p.ends_at IS NULL OR p.ends_at>at_time)
  AND a.state='ACTIVE' AND a.recorded_at<=at_time
  AND NOT EXISTS(SELECT 1 FROM shop_commission_activation_events newer WHERE newer.configuration_key=a.configuration_key AND newer.id>a.id AND newer.recorded_at<=at_time)
  AND d.currency_id=cid AND d.effective_from<=at_time AND (d.effective_until IS NULL OR d.effective_until>at_time)
  AND (d.product_id=pid OR (d.product_group_id IS NOT NULL AND EXISTS(
     SELECT 1 FROM product_group_members m WHERE m.product_id=pid AND m.product_group_id=d.product_group_id
     AND m.joined_at<=at_time AND (m.left_at IS NULL OR m.left_at>at_time))) OR (d.product_id IS NULL AND d.product_group_id IS NULL))
 ), top_rank AS (SELECT * FROM candidates WHERE rank=(SELECT max(rank) FROM candidates)),
 top_priority AS (SELECT * FROM top_rank WHERE priority=(SELECT max(priority) FROM top_rank))
 SELECT min(id),count(*) INTO winner,matches FROM top_priority;
 IF matches>1 THEN RAISE EXCEPTION 'Ambiguous active commission rules' USING ERRCODE='23514'; END IF;
 RETURN winner;
END $$;
CREATE FUNCTION guard_commission_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE i invoices%ROWTYPE; bid bigint;
BEGIN
 SELECT * INTO STRICT i FROM invoices WHERE id=NEW.invoice_id;
 SELECT branch_id INTO bid FROM orders WHERE id=i.order_id;
 IF EXISTS(SELECT 1 FROM shop_commission_legacy_invoices WHERE id=i.id) OR i.status<>'ISSUED'
 OR NEW.order_id<>i.order_id OR NEW.branch_id<>bid OR NEW.invoice_version<>i.version
 OR NEW.invoice_reference<>i.invoice_number
 OR NEW.recorded_by IS DISTINCT FROM i.issued_by THEN
  RAISE EXCEPTION 'Commission snapshot requires a new issued Invoice source' USING ERRCODE='23514'; END IF;
 IF NEW.correction_id IS NULL AND NOT EXISTS(SELECT 1 FROM shop_commission_issue_instants WHERE invoice_id=i.id AND frozen_at=NEW.frozen_at) THEN
  RAISE EXCEPTION 'Commission snapshot must use the authoritative issuance instant' USING ERRCODE='23514'; END IF;
 IF NEW.correction_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM invoice_financial_corrections c WHERE c.id=NEW.correction_id AND c.state='COMPLETED'
 AND c.replacement_invoice_id=i.id AND c.order_id=i.order_id AND c.branch_id=bid AND c.currency_id=i.currency_id AND c.finalized_at=NEW.frozen_at) THEN
  RAISE EXCEPTION 'Invalid commission correction snapshot' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER commission_snapshot_guard BEFORE INSERT ON shop_commission_invoice_snapshots FOR EACH ROW EXECUTE FUNCTION guard_commission_snapshot();
CREATE FUNCTION guard_commission_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE b shop_commission_invoice_snapshots%ROWTYPE; ii invoice_items%ROWTYPE; oi order_items%ROWTYPE;
 os order_shops%ROWTYPE; d shop_commission_drafts%ROWTYPE; original shop_commission_lines%ROWTYPE;
 expected_rule bigint; expected_activation bigint; places integer; expected numeric;
BEGIN
 SELECT * INTO STRICT b FROM shop_commission_invoice_snapshots WHERE id=NEW.snapshot_id;
 SELECT * INTO STRICT ii FROM invoice_items WHERE id=NEW.invoice_item_id;
 SELECT * INTO STRICT oi FROM order_items WHERE id=ii.order_item_id;
 SELECT * INTO STRICT os FROM order_shops WHERE id=oi.order_shop_id;
 SELECT decimal_places INTO places FROM currencies WHERE id=ii.currency_id FOR SHARE;
 IF ii.invoice_id<>b.invoice_id OR os.order_id<>b.order_id OR NEW.shop_id IS DISTINCT FROM os.shop_id
 OR NEW.product_id IS DISTINCT FROM oi.product_id OR NEW.currency_id<>ii.currency_id OR NEW.quantity<>ii.quantity
 OR NEW.discounted_base<>ii.line_total THEN
  RAISE EXCEPTION 'Commission line source mismatch' USING ERRCODE='23514'; END IF;
 IF b.correction_id IS NOT NULL THEN
  SELECT original_line.* INTO original FROM shop_commission_lines original_line
   JOIN shop_commission_invoice_snapshots ob ON ob.id=original_line.snapshot_id
   JOIN invoice_financial_corrections c ON c.original_invoice_id=ob.invoice_id
   JOIN invoice_items old_item ON old_item.id=original_line.invoice_item_id
   WHERE c.id=b.correction_id AND old_item.order_item_id=ii.order_item_id;
  IF original.id IS NULL OR NEW.original_line_id IS DISTINCT FROM original.id THEN
   RAISE EXCEPTION 'Commission correction has no exact original line' USING ERRCODE='23514'; END IF;
  expected_rule:=original.rule_id; expected_activation:=original.activation_id; places:=original.decimal_places;
 ELSE
  IF NEW.original_line_id IS NOT NULL THEN RAISE EXCEPTION 'Unexpected original commission line' USING ERRCODE='23514'; END IF;
  expected_rule:=resolve_active_shop_commission(os.shop_id,oi.product_id,ii.currency_id,b.frozen_at);
  SELECT id INTO expected_activation FROM shop_commission_activation_events WHERE draft_id=expected_rule AND state='ACTIVE' AND recorded_at<=b.frozen_at ORDER BY id DESC LIMIT 1;
 END IF;
 IF NEW.rule_id IS DISTINCT FROM expected_rule OR NEW.activation_id IS DISTINCT FROM expected_activation OR NEW.decimal_places<>places THEN
  RAISE EXCEPTION 'Wrong frozen commission rule or precision' USING ERRCODE='23514'; END IF;
 IF expected_rule IS NOT NULL THEN
  SELECT * INTO STRICT d FROM shop_commission_drafts WHERE id=expected_rule;
  IF NEW.rule_version IS DISTINCT FROM d.version OR NEW.method IS DISTINCT FROM d.method OR NEW.rule_value IS DISTINCT FROM d.commission_value
  OR NEW.rule_scope IS DISTINCT FROM (CASE WHEN d.product_id IS NOT NULL THEN 'PRODUCT' WHEN d.product_group_id IS NOT NULL THEN 'PRODUCT_GROUP' ELSE 'SHOP' END) THEN
   RAISE EXCEPTION 'Commission rule snapshot mismatch' USING ERRCODE='23514'; END IF;
  expected:=round(CASE WHEN d.method='PERCENTAGE' THEN ii.line_total*d.commission_value/100 ELSE ii.quantity*d.commission_value END,places);
 ELSE expected:=0; END IF;
 IF NEW.amount<>expected THEN RAISE EXCEPTION 'Commission calculation mismatch' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER commission_line_guard BEFORE INSERT ON shop_commission_lines FOR EACH ROW EXECUTE FUNCTION guard_commission_line();
CREATE FUNCTION commission_snapshot_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (SELECT count(*) FROM shop_commission_lines WHERE snapshot_id=NEW.id)<>(SELECT count(*) FROM invoice_items WHERE invoice_id=NEW.invoice_id) THEN
  RAISE EXCEPTION 'Incomplete commission Invoice snapshot' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER commission_snapshot_complete AFTER INSERT ON shop_commission_invoice_snapshots
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION commission_snapshot_complete();
CREATE FUNCTION guard_commission_transaction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a shop_commission_accounts%ROWTYPE; l shop_commission_lines%ROWTYPE; b shop_commission_invoice_snapshots%ROWTYPE;
 original shop_commission_transactions%ROWTYPE; c invoice_financial_corrections%ROWTYPE; places integer; balance numeric;
BEGIN
 SELECT * INTO STRICT a FROM shop_commission_accounts WHERE id=NEW.account_id FOR UPDATE;
 SELECT decimal_places INTO places FROM currencies WHERE id=a.currency_id FOR SHARE;
 IF NEW.amount<>trunc(NEW.amount,places) OR (NEW.kind='PAYMENT' AND NEW.occurred_at>clock_timestamp()) THEN
  RAISE EXCEPTION 'Invalid commission transaction precision/time' USING ERRCODE='23514'; END IF;
 IF NEW.kind='EARNING' THEN
  SELECT * INTO STRICT l FROM shop_commission_lines WHERE id=NEW.line_id;
  SELECT * INTO STRICT b FROM shop_commission_invoice_snapshots WHERE id=l.snapshot_id;
  IF a.shop_id IS DISTINCT FROM l.shop_id OR a.branch_id<>b.branch_id OR a.currency_id<>l.currency_id OR NEW.amount<>l.amount
  OR NOT EXISTS(SELECT 1 FROM invoices i JOIN orders o ON o.id=i.order_id WHERE i.id=b.invoice_id AND i.status='ISSUED' AND i.superseded_at IS NULL AND o.operational_status='DELIVERED'
   AND (EXISTS(SELECT 1 FROM invoice_collections ic WHERE ic.invoice_id=i.id AND ic.order_id=o.id AND ic.currency_id=i.currency_id AND ic.amount=i.grand_total)
     OR EXISTS(SELECT 1 FROM invoice_financial_corrections corr JOIN invoice_collections ic ON ic.id=corr.original_collection_id
       WHERE corr.id=b.correction_id AND corr.state='COMPLETED' AND corr.replacement_invoice_id=i.id AND corr.corrected_amount=i.grand_total
       AND ic.order_id=o.id AND ic.currency_id=i.currency_id))) THEN
   RAISE EXCEPTION 'Commission is not earned by a delivered and collected current Invoice' USING ERRCODE='23514'; END IF;
 ELSEIF NEW.kind='REVERSAL' THEN
  SELECT * INTO STRICT original FROM shop_commission_transactions WHERE id=NEW.reversal_of;
  SELECT * INTO STRICT c FROM invoice_financial_corrections WHERE id=NEW.correction_id;
  SELECT * INTO STRICT l FROM shop_commission_lines WHERE id=original.line_id;
  SELECT * INTO STRICT b FROM shop_commission_invoice_snapshots WHERE id=l.snapshot_id;
  IF original.kind<>'EARNING' OR original.account_id<>a.id OR original.amount<>NEW.amount OR c.state<>'COMPLETED'
   OR c.original_invoice_id<>b.invoice_id OR c.order_id<>b.order_id OR c.branch_id<>a.branch_id OR c.currency_id<>a.currency_id THEN
    RAISE EXCEPTION 'Commission reversal needs the exact completed correction and original earning' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT COALESCE(sum(CASE WHEN kind='EARNING' THEN amount ELSE -amount END),0) INTO balance FROM shop_commission_transactions WHERE account_id=a.id;
  IF NEW.amount>balance THEN RAISE EXCEPTION 'Shop payment exceeds commission outstanding' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER commission_transaction_guard BEFORE INSERT ON shop_commission_transactions FOR EACH ROW EXECUTE FUNCTION guard_commission_transaction();
CREATE FUNCTION freeze_commission_currency_precision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.decimal_places IS DISTINCT FROM OLD.decimal_places AND EXISTS(SELECT 1 FROM shop_commission_lines WHERE currency_id=OLD.id AND rule_id IS NOT NULL) THEN
  RAISE EXCEPTION 'Currency precision is frozen by commission financial snapshots' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER commission_currency_precision BEFORE UPDATE OF decimal_places ON currencies FOR EACH ROW EXECUTE FUNCTION freeze_commission_currency_precision();
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Immutable Shop commission history is forward-only.');
    }
};
