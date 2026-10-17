<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Services\CustomerRefundService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\ShopCommissionAccountingService;
use App\Services\ShopCommissionEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RefreshAccountingV1Database;
use Tests\Support\ShopCommissionFixtures;
use Tests\TestCase;

class ShopCommissionCorrectionTest extends TestCase
{
    use RefreshAccountingV1Database, ShopCommissionFixtures;

    public function test_supported_v1_correction_reverses_exact_earning_and_preserves_shop_receipt_as_credit(): void
    {
        foreach (['2026_10_12_132329_create_shop_commission_drafts_table.php', '2026_10_12_140314_extend_shop_commission_drafts_with_agreement_rules.php', '2026_10_12_144251_create_shop_commission_subledger.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $c = $this->commissionOrder();
        app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::ShamCash, $c['invoice']->grand_total, $c['currency']->id, now(), null, 'Direct collection');
        $this->deliverCommissionOrder($c);
        $service = app(ShopCommissionAccountingService::class);
        $earning = DB::table('shop_commission_transactions')->sole();
        $payment = $service->recordPayment($c['admin'], $c['master'], $earning->account_id, ['amount' => '8', 'payment_method' => 'CASH', 'request_key' => (string) str()->uuid(), 'occurred_at' => now()->toIso8601String()]);
        $originalRows = DB::table('shop_commission_transactions')->orderBy('id')->get()->toJson();
        $corrections = app(InvoiceFinancialCorrectionService::class);
        $correction = $corrections->request($c['actor'], $c['invoice'], InvoiceFinancialCorrectionKind::Reprice, [
            'items' => [['invoice_item_id' => $c['invoice']->items()->sole()->id, 'unit_price' => '40']],
            'delivery' => [['order_captain_id' => $c['order']->captainAssignments()->sole()->id, 'customer_amount' => '10']],
        ], 'Correct product price', 'Actual attributable correction', (string) str()->uuid());
        $correction = $corrections->prepare($c['admin'], $correction);
        Storage::fake('customer_refunds');
        app(CustomerRefundService::class)->record($c['admin'], $correction, (string) str()->uuid(), now(), 'Actual refund', $this->sharedImage());
        $correction = $corrections->finalize($c['admin'], $correction);
        $this->assertSame('4.00', $service->balance($earning->account_id)['credit']);
        $this->assertSame('0', $service->balance($earning->account_id)['outstanding']);
        $this->assertSame($originalRows, DB::table('shop_commission_transactions')->whereIn('id', [$earning->id, $payment->id])->orderBy('id')->get()->toJson());
        $this->assertSame('8.00', DB::table('shop_commission_transactions')->where('kind', 'REVERSAL')->sole()->amount);
        $this->assertSame($earning->id, DB::table('shop_commission_transactions')->where('kind', 'REVERSAL')->sole()->reversal_of);
        $this->assertTrue(app(ShopCommissionEligibilityService::class)->inspect($c['admin'], $c['order'])['eligible']);
        $service->correctLocked($correction);
        $this->assertSame(4, DB::table('shop_commission_transactions')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
