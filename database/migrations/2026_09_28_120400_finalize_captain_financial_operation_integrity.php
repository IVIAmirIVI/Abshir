<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Captain financial operation integrity requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            // Currency -> global Account -> financial sources -> ledger is the live posting order.
            // Hold writers while examining history and replacing the final reversal guard.
            DB::statement('LOCK TABLE currencies, financial_accounts, captain_deposit_receipts, captain_receivable_payouts, captain_receivable_payout_allocations, financial_transactions, financial_entries IN ACCESS EXCLUSIVE MODE');

            DB::unprepared(<<<'SQL'
DO $$
DECLARE
    operation_row record;
    allocation_row record;
    transaction_row record;
    source_kind text;
    expected_source text;
    expected_event text;
    source_exists boolean;
    places integer;
BEGIN
    FOR operation_row IN
        SELECT 'DEPOSIT' AS operation_kind, d.id, d.captain_id, d.branch_id, d.currency_id,
               d.amount, d.request_key, d.evidence_path, d.evidence_name, d.evidence_mime,
               d.evidence_size, d.evidence_sha256
        FROM captain_deposit_receipts d
        UNION ALL
        SELECT 'PAYOUT', p.id, p.captain_id, p.branch_id, p.currency_id,
               p.amount, p.request_key, p.evidence_path, p.evidence_name, p.evidence_mime,
               p.evidence_size, p.evidence_sha256
        FROM captain_receivable_payouts p
        ORDER BY operation_kind, id
    LOOP
        SELECT decimal_places INTO places FROM currencies WHERE id = operation_row.currency_id;
        IF places IS NULL OR operation_row.amount IS NULL
           OR operation_row.amount::text IN ('NaN', 'Infinity', '-Infinity')
           OR operation_row.amount <= 0 OR operation_row.amount <> round(operation_row.amount, places)
           OR operation_row.request_key IS NULL OR btrim(operation_row.request_key) = ''
           OR operation_row.evidence_path IS NULL OR btrim(operation_row.evidence_path) = ''
           OR operation_row.evidence_name IS NULL OR btrim(operation_row.evidence_name) = ''
           OR operation_row.evidence_mime NOT IN ('image/jpeg', 'image/png', 'image/webp')
           OR operation_row.evidence_size NOT BETWEEN 1 AND 5242880
           OR operation_row.evidence_sha256 !~ '^[0-9a-f]{64}$' THEN
            RAISE EXCEPTION 'Captain % source_id=% captain=% branch=% currency=% has invalid amount, request key, or evidence metadata',
                operation_row.operation_kind, operation_row.id, operation_row.captain_id,
                operation_row.branch_id, operation_row.currency_id USING ERRCODE = '23514';
        END IF;
        BEGIN
            PERFORM validate_captain_financial_operation(operation_row.operation_kind, operation_row.id);
        EXCEPTION WHEN OTHERS THEN
            RAISE EXCEPTION 'Captain % source_id=% captain=% branch=% currency=% failed final ledger/entry/allocation validation: %',
                operation_row.operation_kind, operation_row.id, operation_row.captain_id,
                operation_row.branch_id, operation_row.currency_id, SQLERRM USING ERRCODE = '23514';
        END;
    END LOOP;

    FOR allocation_row IN
        SELECT a.id, a.captain_receivable_payout_id AS payout_id, a.financial_entry_id,
               a.amount, p.currency_id, c.decimal_places
        FROM captain_receivable_payout_allocations a
        JOIN captain_receivable_payouts p ON p.id = a.captain_receivable_payout_id
        JOIN currencies c ON c.id = p.currency_id
        ORDER BY a.id
    LOOP
        IF allocation_row.amount IS NULL OR allocation_row.amount::text IN ('NaN', 'Infinity', '-Infinity')
           OR allocation_row.amount <= 0
           OR allocation_row.amount <> round(allocation_row.amount, allocation_row.decimal_places) THEN
            RAISE EXCEPTION 'Captain payout_id=% allocation_id=% financial_entry_id=% currency=% amount=% violates exact Currency precision',
                allocation_row.payout_id, allocation_row.id, allocation_row.financial_entry_id,
                allocation_row.currency_id, allocation_row.amount USING ERRCODE = '23514';
        END IF;
    END LOOP;

    -- Inspect both sides of the identity. Earlier versions could have accepted
    -- an extra ledger event under a known source family but another event name.
    FOR transaction_row IN
        SELECT t.id, t.source_type, t.source_id, t.event_type, t.transaction_kind,
               t.source_version, t.status, t.reversal_of_transaction_id
        FROM financial_transactions t
        WHERE t.source_type IN ('CAPTAIN_DEPOSIT_RECEIPT', 'CAPTAIN_RECEIVABLE_PAYOUT')
           OR t.event_type IN ('CAPTAIN_DEPOSIT_RECEIVED', 'CAPTAIN_RECEIVABLE_PAID')
           OR t.reversal_of_transaction_id IN (
               SELECT id FROM financial_transactions
               WHERE event_type IN ('CAPTAIN_DEPOSIT_RECEIVED', 'CAPTAIN_RECEIVABLE_PAID'))
        ORDER BY t.id
    LOOP
        IF transaction_row.reversal_of_transaction_id IS NOT NULL THEN
            RAISE EXCEPTION 'Captain financial transaction_id=% reverses protected original transaction_id=%',
                transaction_row.id, transaction_row.reversal_of_transaction_id USING ERRCODE = '23514';
        END IF;
        expected_source := CASE transaction_row.event_type
            WHEN 'CAPTAIN_DEPOSIT_RECEIVED' THEN 'CAPTAIN_DEPOSIT_RECEIPT'
            WHEN 'CAPTAIN_RECEIVABLE_PAID' THEN 'CAPTAIN_RECEIVABLE_PAYOUT'
            ELSE NULL END;
        expected_event := CASE transaction_row.source_type
            WHEN 'CAPTAIN_DEPOSIT_RECEIPT' THEN 'CAPTAIN_DEPOSIT_RECEIVED'
            WHEN 'CAPTAIN_RECEIVABLE_PAYOUT' THEN 'CAPTAIN_RECEIVABLE_PAID'
            ELSE NULL END;
        IF expected_source IS NULL OR expected_event IS NULL
           OR transaction_row.source_type IS DISTINCT FROM expected_source
           OR transaction_row.event_type IS DISTINCT FROM expected_event
           OR transaction_row.transaction_kind <> 'EVENT'
           OR transaction_row.status <> 'POSTED' OR transaction_row.source_version <> 1
           OR transaction_row.source_id !~ '^[1-9][0-9]{0,18}$'
           OR (transaction_row.source_id ~ '^[1-9][0-9]{0,18}$'
               AND transaction_row.source_id::numeric > 9223372036854775807) THEN
            RAISE EXCEPTION 'Captain financial transaction_id=% has noncanonical event/source identity %/%/%',
                transaction_row.id, transaction_row.event_type, transaction_row.source_type,
                transaction_row.source_id USING ERRCODE = '23514';
        END IF;
        IF transaction_row.source_type = 'CAPTAIN_DEPOSIT_RECEIPT' THEN
            SELECT EXISTS(SELECT 1 FROM captain_deposit_receipts WHERE id = transaction_row.source_id::bigint)
                INTO source_exists;
        ELSE
            SELECT EXISTS(SELECT 1 FROM captain_receivable_payouts WHERE id = transaction_row.source_id::bigint)
                INTO source_exists;
        END IF;
        IF NOT source_exists THEN
            RAISE EXCEPTION 'Captain financial transaction_id=% has missing % source_id=%',
                transaction_row.id, transaction_row.source_type, transaction_row.source_id USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION validate_captain_financial_source_amount() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE places integer;
BEGIN
    SELECT decimal_places INTO places FROM currencies WHERE id = NEW.currency_id FOR UPDATE;
    IF places IS NULL OR NEW.amount::text IN ('NaN', 'Infinity', '-Infinity')
       OR NEW.amount <= 0 OR NEW.amount <> round(NEW.amount, places) THEN
        RAISE EXCEPTION 'Captain financial source amount violates Currency precision' USING ERRCODE = '23514';
    END IF;
    IF btrim(NEW.request_key) = '' OR btrim(NEW.evidence_path) = ''
       OR btrim(NEW.evidence_name) = ''
       OR NEW.evidence_mime NOT IN ('image/jpeg', 'image/png', 'image/webp')
       OR NEW.evidence_size < 1 OR NEW.evidence_size > 5242880
       OR NEW.evidence_sha256 !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'Captain financial evidence/request metadata is invalid' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION protect_paid_receivable_from_reversal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_account_id bigint;
    original_event_type text;
BEGIN
    IF NEW.transaction_kind <> 'REVERSAL' THEN RETURN NEW; END IF;
    SELECT event_type INTO original_event_type FROM financial_transactions
    WHERE id = NEW.reversal_of_transaction_id;
    IF original_event_type IN ('CAPTAIN_DEPOSIT_RECEIVED', 'CAPTAIN_RECEIVABLE_PAID') THEN
        RAISE EXCEPTION 'FINANCIAL_SOURCE_REQUIRES_CONTROLLED_CORRECTION: original transaction_id=% event=%',
            NEW.reversal_of_transaction_id, original_event_type USING ERRCODE = '23514';
    END IF;
    FOR source_account_id IN
        SELECT DISTINCT e.financial_account_id FROM financial_entries e
        JOIN financial_accounts a ON a.id = e.financial_account_id
        WHERE e.financial_transaction_id = NEW.reversal_of_transaction_id
          AND e.direction = 'INCREASE' AND a.account_kind = 'CAPTAIN_COMPANY_RECEIVABLE'
        ORDER BY e.financial_account_id
    LOOP
        PERFORM id FROM financial_accounts WHERE id = source_account_id FOR UPDATE;
    END LOOP;
    IF EXISTS (SELECT 1 FROM captain_receivable_payout_allocations a
        JOIN financial_entries e ON e.id = a.financial_entry_id
        WHERE e.financial_transaction_id = NEW.reversal_of_transaction_id) THEN
        RAISE EXCEPTION 'Paid Receivable source requires controlled future correction' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Captain financial operation hardening is intentionally forward-only.');
    }
};
