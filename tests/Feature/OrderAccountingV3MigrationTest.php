<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Services\CorrectedOrderFinancialService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RefreshAccountingV1Database;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class OrderAccountingV3MigrationTest extends TestCase
{
    use RefreshAccountingV1Database, SharedPurchaseFixtures;

    public function test_forward_installation_preserves_actual_v1_financial_history_and_validators(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
        config(['order_accounting.enable_v3_single_captain' => false]);
        Storage::fake('order_receipts');
        $c = $this->sharedContext(0, 1);
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $service = app(InvoiceService::class);
        $invoice = $service->issue($c['actor'], $c['order'], $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order'])));
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now(), null, null);
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $tables = ['financial_transactions', 'financial_entries', 'invoices', 'invoice_collections', 'order_financial_source_snapshots', 'order_financial_source_snapshot_lines'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->assertSame('ORDER_CASH_COMPANY_DELIVERY_SHARE_DEPOSIT_DEBIT', DB::table('financial_transactions')->sole()->event_type);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $remaining = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) >= '2026_10_11_185816_add_versioned_order_accounting_contracts.php'));
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $remaining, '--realpath' => true, '--force' => true]));
        $this->assertSame(1, (int) $c['order']->fresh()->accounting_contract_version);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($before['financial_transactions'], DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $c['order']->id)->update(['accounting_contract_version' => 3])), QueryException::class);
        $this->assertTrue(DB::scalar("SELECT to_regprocedure('validate_order_accounting_v2_event(bigint)') IS NOT NULL"));
        $this->assertTrue(DB::scalar("SELECT to_regprocedure('validate_order_accounting_v3_event(bigint)') IS NOT NULL"));
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
