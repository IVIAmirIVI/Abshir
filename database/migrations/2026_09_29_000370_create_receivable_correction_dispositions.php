<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Receivable dispositions require PostgreSQL.');
        }
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE invoice_financial_corrections IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
ALTER TABLE invoice_financial_corrections ADD COLUMN receivable_contract_version smallint NOT NULL DEFAULT 1
    CHECK (receivable_contract_version IN (1,2));
ALTER TABLE invoice_financial_corrections ADD COLUMN receivable_dispositions_captured boolean NOT NULL DEFAULT false;
CREATE TABLE invoice_correction_receivable_dispositions (
    id bigserial PRIMARY KEY,
    invoice_financial_correction_id bigint NOT NULL REFERENCES invoice_financial_corrections(id) ON DELETE RESTRICT,
    original_receivable_financial_entry_id bigint REFERENCES financial_entries(id) ON DELETE RESTRICT,
    original_receivable_financial_transaction_id bigint REFERENCES financial_transactions(id) ON DELETE RESTRICT,
    financial_account_id bigint NOT NULL REFERENCES financial_accounts(id) ON DELETE RESTRICT,
    captain_id bigint NOT NULL REFERENCES captains(id) ON DELETE RESTRICT,
    order_captain_id bigint NOT NULL REFERENCES order_captains(id) ON DELETE RESTRICT,
    branch_id bigint NOT NULL REFERENCES branches(id) ON DELETE RESTRICT,
    currency_id bigint NOT NULL REFERENCES currencies(id) ON DELETE RESTRICT,
    currency_decimal_places smallint NOT NULL,
    contract_version smallint NOT NULL CHECK (contract_version IN (1,2)),
    concept varchar(40) NOT NULL CHECK (concept IN ('CASH_SHORTFALL','SHAM_PRODUCT','SHAM_DELIVERY')),
    original_entitlement numeric NOT NULL,
    historical_paid numeric NOT NULL,
    original_unpaid numeric NOT NULL,
    corrected_entitlement numeric NOT NULL,
    residual_payable numeric NOT NULL,
    recovery_required numeric NOT NULL,
    proposal_hash char(64) NOT NULL,
    payload_hash char(64) NOT NULL,
    captured_at timestamptz NOT NULL,
    prepared_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE (invoice_financial_correction_id,concept,captain_id),
    UNIQUE (invoice_financial_correction_id,original_receivable_financial_entry_id),
    CHECK (original_entitlement::text NOT IN ('NaN','Infinity','-Infinity') AND original_entitlement>=0
       AND historical_paid::text NOT IN ('NaN','Infinity','-Infinity') AND historical_paid>=0 AND historical_paid<=original_entitlement
       AND corrected_entitlement::text NOT IN ('NaN','Infinity','-Infinity') AND corrected_entitlement>=0
       AND original_unpaid=original_entitlement-historical_paid
       AND residual_payable=greatest(corrected_entitlement-historical_paid,0)
       AND recovery_required=greatest(historical_paid-corrected_entitlement,0)
       AND original_unpaid::text NOT IN ('NaN','Infinity','-Infinity')
       AND residual_payable::text NOT IN ('NaN','Infinity','-Infinity') AND recovery_required::text NOT IN ('NaN','Infinity','-Infinity')
       AND (original_entitlement>0 OR corrected_entitlement>0)
       AND ((original_entitlement=0 AND original_receivable_financial_entry_id IS NULL AND original_receivable_financial_transaction_id IS NULL)
         OR (original_entitlement>0 AND original_receivable_financial_entry_id IS NOT NULL AND original_receivable_financial_transaction_id IS NOT NULL))
       AND original_entitlement=round(original_entitlement,currency_decimal_places)
       AND historical_paid=round(historical_paid,currency_decimal_places)
       AND corrected_entitlement=round(corrected_entitlement,currency_decimal_places)
       AND proposal_hash ~ '^[0-9a-f]{64}$' AND payload_hash ~ '^[0-9a-f]{64}$')
);
CREATE TABLE invoice_correction_receivable_disposition_allocations (
    id bigserial PRIMARY KEY,
    disposition_id bigint NOT NULL REFERENCES invoice_correction_receivable_dispositions(id) ON DELETE RESTRICT,
    captain_receivable_payout_allocation_id bigint NOT NULL REFERENCES captain_receivable_payout_allocations(id) ON DELETE RESTRICT,
    captain_receivable_payout_id bigint NOT NULL REFERENCES captain_receivable_payouts(id) ON DELETE RESTRICT,
    original_financial_entry_id bigint NOT NULL REFERENCES financial_entries(id) ON DELETE RESTRICT,
    amount numeric NOT NULL,
    covered_amount numeric NOT NULL,
    excess_amount numeric NOT NULL,
    payout_occurred_at timestamptz NOT NULL,
    UNIQUE (disposition_id,captain_receivable_payout_allocation_id),
    CHECK (amount::text NOT IN ('NaN','Infinity','-Infinity') AND amount>0
      AND covered_amount::text NOT IN ('NaN','Infinity','-Infinity') AND covered_amount>=0
      AND excess_amount::text NOT IN ('NaN','Infinity','-Infinity') AND excess_amount>=0
      AND amount=covered_amount+excess_amount)
);
CREATE OR REPLACE FUNCTION receivable_dispositions_active() RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT false; $$;
CREATE OR REPLACE FUNCTION guard_receivable_disposition_write() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE correction_id bigint;
BEGIN
    IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Receivable disposition history is immutable' USING ERRCODE='55000'; END IF;
    IF NOT receivable_dispositions_active() THEN
        RAISE EXCEPTION 'Paid-source correction deployment is not active' USING ERRCODE='23514';
    END IF;
    IF TG_TABLE_NAME='invoice_correction_receivable_dispositions' THEN
        correction_id:=NEW.invoice_financial_correction_id;
    ELSE
        SELECT invoice_financial_correction_id INTO correction_id
          FROM invoice_correction_receivable_dispositions WHERE id=NEW.disposition_id;
    END IF;
    IF NOT EXISTS(SELECT 1 FROM invoice_financial_corrections WHERE id=correction_id
        AND receivable_dispositions_captured AND state IN ('PREPARED','READY_TO_FINALIZE')) THEN
        RAISE EXCEPTION 'Disposition capture requires an active prepared correction' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER disposition_immutable BEFORE INSERT OR UPDATE OR DELETE ON invoice_correction_receivable_dispositions
FOR EACH ROW EXECUTE FUNCTION guard_receivable_disposition_write();
CREATE TRIGGER disposition_allocation_immutable BEFORE INSERT OR UPDATE OR DELETE ON invoice_correction_receivable_disposition_allocations
FOR EACH ROW EXECUTE FUNCTION guard_receivable_disposition_write();
CREATE OR REPLACE FUNCTION guard_receivable_contract_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='INSERT' AND NEW.receivable_contract_version<>1 THEN
        RAISE EXCEPTION 'Correction must start with the original contract' USING ERRCODE='23514';
    END IF;
    IF TG_OP='UPDATE' AND NEW.receivable_contract_version IS DISTINCT FROM OLD.receivable_contract_version
       AND NOT (OLD.state='REQUESTED' AND NEW.state='PREPARED' AND NEW.receivable_contract_version=2
                AND receivable_dispositions_active()) THEN
        RAISE EXCEPTION 'Correction contract is frozen or not active' USING ERRCODE='23514';
    END IF;
    IF NEW.receivable_contract_version=2 AND NOT receivable_dispositions_active() THEN
        RAISE EXCEPTION 'Paid-source correction deployment is not active' USING ERRCODE='23514';
    END IF;
    IF TG_OP='UPDATE' AND NEW.receivable_dispositions_captured IS DISTINCT FROM OLD.receivable_dispositions_captured
      AND NOT (OLD.state='REQUESTED' AND NEW.state='PREPARED' AND NEW.receivable_dispositions_captured
               AND receivable_dispositions_active()) THEN
        RAISE EXCEPTION 'Disposition capture identity is frozen' USING ERRCODE='23514';
    END IF;
    IF TG_OP='INSERT' AND NEW.receivable_dispositions_captured THEN
        RAISE EXCEPTION 'Disposition capture requires preparation' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER receivable_contract_version_guard BEFORE INSERT OR UPDATE ON invoice_financial_corrections
FOR EACH ROW EXECUTE FUNCTION guard_receivable_contract_version();
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Receivable dispositions are intentionally forward-only.');
    }
};
