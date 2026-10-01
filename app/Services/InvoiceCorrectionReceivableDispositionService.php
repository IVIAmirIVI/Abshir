<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\InvoiceFinancialCorrection;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceCorrectionReceivableDispositionService
{
    public function __construct(private readonly FinancialAccountService $accounts, private readonly FinancialPostingService $posting) {}

    public function active(): bool
    {
        return DB::scalar("SELECT to_regprocedure('receivable_dispositions_active()') IS NOT NULL")
            && DB::scalar('SELECT receivable_dispositions_active()');
    }

    /** Caller already owns Order/source/Currency locks. Freeze paid facts before releasing Accounts. */
    public function captureLocked(InvoiceFinancialCorrection $correction, array $preparedFacts): void
    {
        $currency = Currency::query()->lockForUpdate()->findOrFail($correction->currency_id);
        if ($correction->order->operational_status !== OrderOperationalStatus::Delivered) {
            // No crystallized original Receivable exists yet. Keep the established v1 path,
            // which posts whole corrected entitlements if this Order is delivered later.
            $correction->update($preparedFacts);

            return;
        }
        if ($correction->order->operational_status === OrderOperationalStatus::Delivered) {
            $ids = [];
            foreach (DB::table('order_financial_source_snapshot_lines')->where('order_financial_source_snapshot_id', $correction->original_snapshot_id)
                ->orderBy('captain_id')->pluck('captain_id') as $captainId) {
                $ids[] = $this->accounts->getOrCreate($this->accounts->ownerForCaptain(Captain::findOrFail($captainId)),
                    $currency, FinancialAccountKind::CaptainCompanyReceivable)->id;
            }
            FinancialAccount::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        }
        $expected = DB::select('SELECT * FROM expected_receivable_dispositions(?) ORDER BY concept,captain_id', [$correction->id]);
        $version = collect($expected)->contains(fn ($row) => BigDecimal::of((string) $row->historical_paid)->isGreaterThan(0)) ? 2 : 1;
        $correction->update($preparedFacts + ['receivable_contract_version' => $version, 'receivable_dispositions_captured' => true]);
        foreach ($expected as $row) {
            $o = BigDecimal::of((string) $row->original_entitlement);
            $p = BigDecimal::of((string) $row->historical_paid);
            $c = BigDecimal::of((string) $row->corrected_entitlement);
            $facts = [
                'invoice_financial_correction_id' => $correction->id,
                'original_receivable_financial_entry_id' => $row->original_entry_id,
                'original_receivable_financial_transaction_id' => $row->original_transaction_id,
                'financial_account_id' => $row->financial_account_id,
                'captain_id' => $row->captain_id, 'order_captain_id' => $row->order_captain_id,
                'branch_id' => $correction->branch_id, 'currency_id' => $currency->id,
                'currency_decimal_places' => $currency->decimal_places, 'contract_version' => $version, 'concept' => $row->concept,
                'original_entitlement' => (string) $o, 'historical_paid' => (string) $p, 'original_unpaid' => (string) $o->minus($p),
                'corrected_entitlement' => (string) $c, 'residual_payable' => (string) BigDecimal::max($c->minus($p), 0),
                'recovery_required' => (string) BigDecimal::max($p->minus($c), 0),
                'proposal_hash' => $correction->proposal_hash, 'prepared_by' => $correction->prepared_by,
                'captured_at' => $correction->prepared_at->utc()->format('Y-m-d H:i:s.uP'),
            ];
            $facts['payload_hash'] = DB::scalar("SELECT encode(sha256(convert_to(receivable_disposition_payload(jsonb_populate_record(NULL::invoice_correction_receivable_dispositions,?::jsonb))::text,'UTF8')),'hex')", [json_encode($facts, JSON_THROW_ON_ERROR)]);
            $id = DB::table('invoice_correction_receivable_dispositions')->insertGetId($facts);
            DB::insert('INSERT INTO invoice_correction_receivable_disposition_allocations (disposition_id,captain_receivable_payout_allocation_id,captain_receivable_payout_id,original_financial_entry_id,amount,covered_amount,excess_amount,payout_occurred_at) SELECT ?,allocation_id,payout_id,entry_id,amount,covered,excess,occurred_at FROM disposition_expected_allocations(?,?::numeric)', [$id, $row->original_entry_id, (string) $c]);
        }
        DB::select('SELECT validate_receivable_disposition_set(?)', [$correction->id]);
    }

    public function assertFinalizableLocked(InvoiceFinancialCorrection $correction): void
    {
        DB::select('SELECT validate_receivable_disposition_set(?)', [$correction->id]);
        $required = $correction->receivableDispositions()->where('recovery_required', '>', 0)->first();
        if ($required) {
            throw ValidationException::withMessages(['correction' => 'CAPTAIN_RECOVERY_REQUIRED: disposition_id='.$required->id.', amount='.$required->recovery_required.'. Physical CASH recovery is deferred to Phase 9C.3B.2.']);
        }
    }

    public function postLocked(InvoiceFinancialCorrection $correction): array
    {
        $posted = [];
        $currency = Currency::findOrFail($correction->currency_id);
        $branch = Branch::findOrFail($correction->branch_id);
        foreach ($correction->receivableDispositions()->orderBy('id')->get() as $d) {
            foreach ([
                [FinancialEventType::ReceivableUnpaidRemainderRetired, FinancialEntryDirection::Decrease, $d->original_unpaid],
                [FinancialEventType::ReceivableCorrectedResidualDue, FinancialEntryDirection::Increase, $d->residual_payable],
            ] as [$event, $direction, $amount]) {
                if (BigDecimal::of((string) $amount)->isZero()) {
                    continue;
                }
                $posted[] = $this->posting->postEvent($currency, 'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION', $d->id,
                    $event->value, 2, $correction->finalized_at,
                    [['account' => FinancialAccount::findOrFail($d->financial_account_id), 'direction' => $direction, 'amount' => (string) $amount]],
                    $branch, null, null, json_encode(['disposition_hash' => $d->payload_hash], JSON_THROW_ON_ERROR));
            }
        }

        return $posted;
    }
}
