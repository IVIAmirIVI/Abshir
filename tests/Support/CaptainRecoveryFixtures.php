<?php

namespace Tests\Support;

use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\OrderCancellationReason;
use App\Services\CaptainReceivableRecoveryEvidenceService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderShopReturnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait CaptainRecoveryFixtures
{
    protected function readyRecoveryCancellation(): array
    {
        $f = $this->fixture('USD', captainPercent: '100', purchase: true);
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '70');
        $delivery = DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->where('t.event_type', 'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE')->select('e.*')->sole();
        $this->payout($f, $delivery, '40');
        $c = app(InvoiceFinancialCorrectionService::class)->request($f['employee'], $f['invoice'], InvoiceFinancialCorrectionKind::CancelAndRefund, [],
            'Cancel after paid purchase', 'Return goods', (string) Str::uuid());
        $c = $this->prepareAndRefund($f, $c);
        app(OrderCancellationIntentService::class)->create($f['employee'], $f['order']->fresh(), OrderCancellationReason::CustomerCancelled, 'Return goods', (string) Str::uuid());
        app(OrderShopReturnService::class)->record($f['employee'], $f['order']->fresh(), $f['order']->shops()->sole(), [
            'returning_order_captain_id' => $f['order']->captainAssignments()->sole()->id, 'refund_amount' => '100',
            'returned_at' => now()->subSeconds(20)->format('Y-m-d H:i:s.uP'), 'refunded_at' => now()->subSeconds(10)->format('Y-m-d H:i:s.uP'),
            'refund_method' => 'CASH', 'request_key' => (string) Str::uuid()], ['RETURN_AND_REFUND_PROOF' => $this->proof()]);

        return [$f, $c];
    }
}
