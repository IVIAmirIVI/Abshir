<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\ManagementReportDetailQuery;
use App\Services\ManagementReportQuery;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RefreshAccountingV1Database;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class ManagementReportsV1Test extends TestCase
{
    use RefreshAccountingV1Database, SharedPurchaseFixtures;

    public function test_real_historical_v1_cash_is_not_reinterpreted_as_v2_and_uninstalled_sources_are_unavailable(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
        Storage::fake('order_receipts');
        $c = $this->sharedContext(2, 1);
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'],
            $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $service = app(InvoiceService::class);
        $invoice = $service->issue($c['actor'], $c['order'], $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order'])));
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total,
            $c['currency']->id, now(), null, null);
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }

        $report = app(ManagementReportQuery::class)->snapshot($c['admin'], ['from' => '2026-10-08', 'to' => '2026-10-08', 'branch_id' => '', 'currency_id' => '']);

        $this->assertSame(1, (int) $report['operations']['versions'][0]->version);
        $this->assertSame(1, $report['financial']['collections']['versions'][0]['version']);
        $this->assertSame('90.0000', $report['financial']['collections']['rows'][0]['cash']);
        $this->assertSame('0', $report['financial']['balances']['rows'][0]['CAPTAIN_CASH_CUSTODY']);
        $this->assertSame('-5.0000', $report['financial']['balances']['rows'][0]['CAPTAIN_DEPOSIT']);
        $this->assertSame('ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT', DB::table('financial_transactions')->sole()->event_type);
        $this->assertSame('0', $report['financial']['balances']['rows'][0]['CAPTAIN_COMPANY_RECEIVABLE']);
        $this->assertFalse($report['financial']['commissions']['available']);
        $this->assertFalse($report['financial']['benefits']['available']);
        $filters = ['from' => '2026-10-08', 'to' => '2026-10-08', 'branch_id' => '', 'currency_id' => ''];
        $details = app(ManagementReportDetailQuery::class);
        $this->assertSame(1, $details->report($c['admin'], 'collections', $filters)['query']->sole()->version);
        $this->assertSame('-5.0000', $details->report($c['admin'], 'balances', $filters)['query']->sole()->amount);
        $this->actingAs($c['admin'])->get(route('reports.detail', ['type' => 'balances'] + $filters))->assertOk()->assertSee('-5.0000');
        $csv = $this->get(route('reports.export', ['type' => 'balances'] + $filters))->assertOk()->streamedContent();
        $this->assertStringContainsString('-5.0000', $csv);
        $this->assertStringNotContainsString("'-5.0000", $csv);
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
    }
}
