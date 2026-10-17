<?php

namespace Tests\Support;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Services\BillPaymentFeeRuleService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderPricingService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderShopPurchaseService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait CustomerReturnFixtures
{
    use SharedPurchaseFixtures;

    protected function sold(int $count = 1, bool $withBill = false, bool $enableV3 = true, string $productAmount = '80', InvoiceCollectionMethod $method = InvoiceCollectionMethod::ShamCash, bool $collect = true, string $deliveryFee = '10', array $master = []): array
    {
        $this->assertSame('abshir_testing', DB::scalar('select current_database()'));
        config(['order_accounting.enable_v3_single_captain' => $enableV3]);
        $this->travelTo(CarbonImmutable::now()->subMinutes(2)->startOfSecond());
        Storage::fake('customer_refunds');
        Storage::fake('order_receipts');
        Storage::fake('order_returns');
        Storage::fake('order_bill_payments');
        $c = $this->sharedContext(2, $count, true, $deliveryFee, $master);
        if ($productAmount !== '80') {
            $c['shop']->items()->sole()->update(['actual_unit_price' => $productAmount]);
            app(OrderPricingService::class)->recalculate($c['order']);
        }
        if ($count === 2) {
            app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, $productAmount), $this->sharedImage());
        } else {
            app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, $productAmount) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
        }
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        if ($withBill) {
            app(BillPaymentFeeRuleService::class)->create($c['admin'], $c['currency']->id, '5', now()->subDay());
            $c['billRequest'] = app(OrderBillPaymentService::class)->request($c['actor'], $c['order'], $c['currency']->id);
        }
        $s = app(InvoiceService::class);
        $c['invoice'] = $s->issue($c['actor'], $c['order'], $s->refresh($c['actor'], $c['order'], $s->createDraft($c['actor'], $c['order'])));
        if (! $collect) {
            return $c;
        }
        app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], $method, $c['invoice']->grand_total, $c['currency']->id, now(), null, 'RET fixture collection');
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
        if ($withBill) {
            $c['bill'] = app(OrderBillPaymentService::class)->complete($c['actor'], $c['order'], $c['billRequest'], [
                'order_captain_id' => $c['assignments'][0]->id, 'currency_id' => $c['currency']->id,
                'bill_number' => 'RET-BILL', 'bill_date' => now()->toDateString(), 'principal_amount' => '100', 'payment_reference' => 'RET-PAID-BILL',
            ], [$this->sharedImage()]);
        }

        return $c;
    }

    protected function data(array $c, string $quantity = '1', string $amount = '80'): array
    {
        return ['request_key' => (string) str()->uuid(), 'invoice_id' => $c['invoice']->id, 'currency_id' => $c['currency']->id,
            'occurred_at' => now()->format('Y-m-d H:i:s.uP'), 'reason' => 'DAMAGE', 'notes' => 'Documented damaged products',
            'lines' => [['kind' => 'PRODUCT', 'invoice_item_id' => $c['invoice']->items()->sole()->id, 'quantity' => $quantity, 'amount' => $amount]],
            'responsibilities' => [['party' => 'COMPANY', 'amount' => $amount]]];
    }

    protected function movement(array $c, string $amount, string $kind = 'COMPENSATION'): array
    {
        return ['request_key' => (string) str()->uuid(), 'currency_id' => $c['currency']->id, 'amount' => $amount, 'kind' => $kind,
            'method' => 'CASH', 'funding_source' => 'Company cash receipt, verified by employee', 'reference' => (string) str()->uuid(),
            'occurred_at' => now()->format('Y-m-d H:i:s.uP'), 'actual_confirmed' => true];
    }

    protected function proof(string $tag): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', $this->sharedImage()->getContent().$tag);
    }
}
