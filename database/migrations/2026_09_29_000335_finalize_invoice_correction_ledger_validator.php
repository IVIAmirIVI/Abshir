<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Invoice correction ledger integrity requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE financial_transactions, financial_entries, invoice_correction_reversals IN ACCESS EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_invoice_correction_ledger_transaction(p_id bigint)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    t financial_transactions%ROWTYPE;
    c invoice_financial_corrections%ROWTYPE;
    s invoice_correction_financial_snapshots%ROWTYPE;
    original financial_transactions%ROWTYPE;
    collection_method text;
    primary_captain bigint;
    expected_count integer;
BEGIN
    SELECT * INTO t FROM financial_transactions WHERE id=p_id;
    IF NOT FOUND THEN RETURN; END IF;
    IF t.event_type NOT IN ('INVOICE_CORRECTION_REVERSE_ORIGINAL', 'INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT',
        'INVOICE_CORRECTION_CASH_SHORTFALL_DUE', 'INVOICE_CORRECTION_SHAM_PRODUCT_DUE',
        'INVOICE_CORRECTION_SHAM_DELIVERY_DUE') THEN
        IF t.source_type IN ('INVOICE_FINANCIAL_CORRECTION','INVOICE_FINANCIAL_CORRECTION_REVERSAL') THEN
            RAISE EXCEPTION 'Invoice correction source requires a canonical correction event' USING ERRCODE='23514';
        END IF;
        RETURN;
    END IF;
    IF t.source_version <> 1 OR t.status <> 'POSTED' OR t.source_id !~ '^[1-9][0-9]{0,18}$'
       OR (length(t.source_id)=19 AND t.source_id > '9223372036854775807') THEN
        RAISE EXCEPTION 'Invoice correction event has invalid version, status or source ID' USING ERRCODE='23514';
    END IF;
    IF t.event_type='INVOICE_CORRECTION_REVERSE_ORIGINAL' THEN
        IF t.source_type <> 'INVOICE_FINANCIAL_CORRECTION_REVERSAL' OR t.transaction_kind <> 'REVERSAL'
           OR t.reversal_of_transaction_id IS NULL OR t.source_id <> t.reversal_of_transaction_id::text THEN
            RAISE EXCEPTION 'Invoice correction reversal has invalid canonical link' USING ERRCODE='23514';
        END IF;
        SELECT * INTO original FROM financial_transactions WHERE id=t.reversal_of_transaction_id;
        SELECT correction_source.* INTO c FROM invoice_correction_reversals r
            JOIN invoice_financial_corrections correction_source ON correction_source.id=r.invoice_financial_correction_id
            WHERE r.original_transaction_id=original.id AND r.reversal_transaction_id=t.id;
        IF c.id IS NULL OR c.state <> 'COMPLETED' OR original.transaction_kind <> 'EVENT'
           OR original.source_type <> 'INVOICE_COLLECTION'
           OR original.source_id <> c.original_collection_id::text
           OR original.event_type NOT IN ('ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT',
             'ORDER_CASH_DELIVERY_SHORTFALL_DUE','ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE',
             'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE')
           OR t.branch_id IS DISTINCT FROM c.branch_id OR t.currency_id IS DISTINCT FROM c.currency_id
           OR EXISTS (
               (SELECT e.financial_account_id,e.currency_id,e.amount,e.direction FROM financial_entries e WHERE e.financial_transaction_id=t.id
                EXCEPT ALL
                SELECT e.financial_account_id,e.currency_id,e.amount,
                    CASE e.direction WHEN 'INCREASE' THEN 'DECREASE' ELSE 'INCREASE' END
                    FROM financial_entries e WHERE e.financial_transaction_id=original.id)
               UNION ALL
               (SELECT e.financial_account_id,e.currency_id,e.amount,
                    CASE e.direction WHEN 'INCREASE' THEN 'DECREASE' ELSE 'INCREASE' END
                    FROM financial_entries e WHERE e.financial_transaction_id=original.id
                EXCEPT ALL
                SELECT e.financial_account_id,e.currency_id,e.amount,e.direction FROM financial_entries e WHERE e.financial_transaction_id=t.id)
           ) THEN
            RAISE EXCEPTION 'Invoice correction reversal does not exactly reverse its authoritative original' USING ERRCODE='23514';
        END IF;
        RETURN;
    END IF;

    IF t.source_type <> 'INVOICE_FINANCIAL_CORRECTION' OR t.transaction_kind <> 'EVENT' THEN
        RAISE EXCEPTION 'Invoice correction event requires its canonical source' USING ERRCODE='23514';
    END IF;
    SELECT * INTO c FROM invoice_financial_corrections WHERE id=t.source_id::bigint;
    SELECT * INTO s FROM invoice_correction_financial_snapshots WHERE invoice_financial_correction_id=c.id;
    SELECT ic.collection_method INTO collection_method FROM invoice_collections ic WHERE ic.id=c.original_collection_id;
    IF c.id IS NULL OR c.state <> 'COMPLETED' OR c.kind <> 'REPRICE' OR s.id IS NULL
       OR t.branch_id IS DISTINCT FROM c.branch_id OR t.currency_id IS DISTINCT FROM c.currency_id
       OR s.branch_id IS DISTINCT FROM c.branch_id OR s.currency_id IS DISTINCT FROM c.currency_id
       OR (collection_method='CASH' AND t.event_type IN ('INVOICE_CORRECTION_SHAM_PRODUCT_DUE','INVOICE_CORRECTION_SHAM_DELIVERY_DUE'))
       OR (collection_method='SHAM_CASH' AND t.event_type IN ('INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT','INVOICE_CORRECTION_CASH_SHORTFALL_DUE')) THEN
        RAISE EXCEPTION 'Invoice correction event differs from completed correction source' USING ERRCODE='23514';
    END IF;
    primary_captain := (s.payload->>'primary_captain_id')::bigint;
    WITH expected AS (
        SELECT primary_captain AS captain_id, 'CAPTAIN_DEPOSIT'::text AS account_kind,
            'DECREASE'::text AS direction, sum((a.value->>'company_collectible')::numeric) AS amount
        FROM jsonb_array_elements(s.payload->'allocations') a
        WHERE t.event_type='INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT'
        HAVING sum((a.value->>'company_collectible')::numeric)>0
        UNION ALL
        SELECT (a.value->>'captain_id')::bigint,'CAPTAIN_COMPANY_RECEIVABLE','INCREASE',
            (a.value->>'captain_shortfall')::numeric
        FROM jsonb_array_elements(s.payload->'allocations') a
        WHERE t.event_type='INVOICE_CORRECTION_CASH_SHORTFALL_DUE'
          AND (a.value->>'captain_shortfall')::numeric>0
        UNION ALL
        SELECT (a.value->>'captain_id')::bigint,'CAPTAIN_COMPANY_RECEIVABLE','INCREASE',
            (a.value->>'product_funding_share')::numeric
        FROM jsonb_array_elements(s.payload->'allocations') a
        WHERE t.event_type='INVOICE_CORRECTION_SHAM_PRODUCT_DUE'
          AND (a.value->>'product_funding_share')::numeric>0
        UNION ALL
        SELECT (a.value->>'captain_id')::bigint,'CAPTAIN_COMPANY_RECEIVABLE','INCREASE',
            (a.value->>'captain_entitlement')::numeric
        FROM jsonb_array_elements(s.payload->'allocations') a
        WHERE t.event_type='INVOICE_CORRECTION_SHAM_DELIVERY_DUE'
          AND (a.value->>'captain_entitlement')::numeric>0
    ), actual AS (
        SELECT o.captain_id,a.account_kind,e.direction,e.amount
        FROM financial_entries e JOIN financial_accounts a ON a.id=e.financial_account_id
        JOIN financial_owners o ON o.id=a.financial_owner_id
        WHERE e.financial_transaction_id=t.id AND e.currency_id=c.currency_id
          AND a.currency_id=c.currency_id AND o.owner_type='CAPTAIN'
    )
    SELECT count(*) INTO expected_count FROM expected;
    IF expected_count=0 OR EXISTS (
        WITH expected AS (
            SELECT primary_captain AS captain_id, 'CAPTAIN_DEPOSIT'::text AS account_kind,
                'DECREASE'::text AS direction, sum((a.value->>'company_collectible')::numeric) AS amount
            FROM jsonb_array_elements(s.payload->'allocations') a
            WHERE t.event_type='INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT'
            HAVING sum((a.value->>'company_collectible')::numeric)>0
            UNION ALL
            SELECT (a.value->>'captain_id')::bigint,'CAPTAIN_COMPANY_RECEIVABLE','INCREASE',
                (a.value->>'captain_shortfall')::numeric FROM jsonb_array_elements(s.payload->'allocations') a
            WHERE t.event_type='INVOICE_CORRECTION_CASH_SHORTFALL_DUE' AND (a.value->>'captain_shortfall')::numeric>0
            UNION ALL
            SELECT (a.value->>'captain_id')::bigint,'CAPTAIN_COMPANY_RECEIVABLE','INCREASE',
                (a.value->>'product_funding_share')::numeric FROM jsonb_array_elements(s.payload->'allocations') a
            WHERE t.event_type='INVOICE_CORRECTION_SHAM_PRODUCT_DUE' AND (a.value->>'product_funding_share')::numeric>0
            UNION ALL
            SELECT (a.value->>'captain_id')::bigint,'CAPTAIN_COMPANY_RECEIVABLE','INCREASE',
                (a.value->>'captain_entitlement')::numeric FROM jsonb_array_elements(s.payload->'allocations') a
            WHERE t.event_type='INVOICE_CORRECTION_SHAM_DELIVERY_DUE' AND (a.value->>'captain_entitlement')::numeric>0
        ), actual AS (
            SELECT o.captain_id,a.account_kind,e.direction,e.amount
            FROM financial_entries e JOIN financial_accounts a ON a.id=e.financial_account_id
            JOIN financial_owners o ON o.id=a.financial_owner_id
            WHERE e.financial_transaction_id=t.id AND e.currency_id=c.currency_id
              AND a.currency_id=c.currency_id AND o.owner_type='CAPTAIN'
        )
        SELECT 1 FROM (
            (SELECT * FROM expected EXCEPT ALL SELECT * FROM actual)
            UNION ALL (SELECT * FROM actual EXCEPT ALL SELECT * FROM expected)
        ) difference
    ) OR (SELECT count(*) FROM financial_entries WHERE financial_transaction_id=t.id)<>expected_count THEN
        RAISE EXCEPTION 'Invoice correction event has an incomplete or extra FinancialEntry set' USING ERRCODE='23514';
    END IF;
END;
$$;
SQL);
            foreach (DB::table('financial_transactions')->whereIn('event_type', [
                'INVOICE_CORRECTION_REVERSE_ORIGINAL', 'INVOICE_CORRECTION_CASH_DEPOSIT_DEBIT',
                'INVOICE_CORRECTION_CASH_SHORTFALL_DUE', 'INVOICE_CORRECTION_SHAM_PRODUCT_DUE',
                'INVOICE_CORRECTION_SHAM_DELIVERY_DUE',
            ])->pluck('id') as $id) {
                DB::select('SELECT validate_invoice_correction_ledger_transaction(?)', [$id]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Invoice correction ledger integrity is intentionally forward-only.');
    }
};
