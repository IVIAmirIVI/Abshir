<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Bill-payment fee timeline requires PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE bill_payment_fee_rules
    ALTER COLUMN effective_from TYPE timestamptz(6),
    ALTER COLUMN effective_until TYPE timestamptz(6);
ALTER TABLE order_bill_payment_requests ALTER COLUMN requested_at TYPE timestamptz(6);

CREATE OR REPLACE FUNCTION validate_bill_payment_event(
    expected_source_type text, expected_source_id text, expected_event_type text,
    expected_currency_id bigint, expected_branch_id bigint, expected_captain_id bigint,
    expected_occurred_at timestamptz, expected_kind text, expected_direction text, expected_amount numeric
) RETURNS void AS $$
DECLARE matches bigint; candidates jsonb;
BEGIN
    SELECT count(*) INTO matches
    FROM financial_transactions t
    JOIN financial_entries e ON e.financial_transaction_id = t.id
    JOIN financial_accounts a ON a.id = e.financial_account_id
    JOIN financial_owners o ON o.id = a.financial_owner_id
    WHERE t.source_type = expected_source_type AND t.source_id = expected_source_id
      AND t.event_type = expected_event_type AND t.source_version = 1
      AND t.transaction_kind = 'EVENT' AND t.status = 'POSTED' AND t.posted_at IS NOT NULL
      AND t.currency_id = expected_currency_id AND t.branch_id = expected_branch_id
      AND t.occurred_at = expected_occurred_at
      AND e.currency_id = expected_currency_id AND e.direction = expected_direction AND e.amount = expected_amount
      AND a.currency_id = expected_currency_id AND a.account_kind = expected_kind
      AND o.owner_type = 'CAPTAIN' AND o.captain_id = expected_captain_id
      AND (SELECT count(*) FROM financial_entries all_entries WHERE all_entries.financial_transaction_id = t.id) = 1;
    IF matches <> 1 THEN
        SELECT jsonb_agg(jsonb_build_object('transaction', t.id, 'status', t.status,
            'source', t.source_type, 'event', t.event_type, 'amount', e.amount,
            'direction', e.direction, 'account_kind', a.account_kind,
            'captain', o.captain_id, 'occurred_at', t.occurred_at)) INTO candidates
        FROM financial_transactions t
        LEFT JOIN financial_entries e ON e.financial_transaction_id = t.id
        LEFT JOIN financial_accounts a ON a.id = e.financial_account_id
        LEFT JOIN financial_owners o ON o.id = a.financial_owner_id
        WHERE t.source_type = expected_source_type AND t.source_id = expected_source_id;
        RAISE EXCEPTION 'Bill-payment financial event % has incorrect source, status, account or amount: %',
            expected_event_type, coalesce(candidates::text, 'none') USING ERRCODE = '23514';
    END IF;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Bill-payment fee timeline is intentionally forward-only.');
    }
};
