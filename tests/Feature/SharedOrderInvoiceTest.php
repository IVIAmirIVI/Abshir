<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Services\DeliveryPricingRuleService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialSourceSnapshotService;
use App\Services\OrderPricingService;
use App\Services\OrderShopService;
use App\Services\SharedOrderShopPurchaseService;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SharedOrderInvoiceTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_one_issued_invoice_uses_each_shop_actual_remainder_once_and_freezes_two_captains(): void
    {
        $c = $this->prepared();
        $service = app(InvoiceService::class);
        $invoice = $service->issue($c['actor'], $c['order'], $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order'])));
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertTrue(BigDecimal::of($invoice->items_subtotal)->isEqualTo('1004'));
        $this->assertTrue(BigDecimal::of($invoice->delivery_total)->isEqualTo('18'));
        $this->assertTrue(BigDecimal::of($invoice->grand_total)->isEqualTo('1022'));
        $this->assertCount(2, $invoice->items);
        $source = app(OrderFinancialSourceSnapshotService::class)->read($invoice);
        $this->assertSame(['503.0000', '501.0000'], array_column($source['captain_allocations'], 'product_funding_share'));
        $this->assertSame(['5.0000', '5.0000'], array_column($source['captain_allocations'], 'captain_entitlement'));
        $this->assertSame($c['order']->captainSelections()->latest('id')->first()->collecting_order_captain_id, $source['primary_order_captain_id']);
        DB::select('SELECT validate_financial_snapshot_fingerprint(?)', [DB::table('order_financial_source_snapshots')->value('id')]);
        $this->assertThrows(fn () => $service->createDraft($c['actor'], $c['order']), ValidationException::class);
        $this->assertThrows(fn () => $service->issue($c['actor'], $c['order'], $invoice), ValidationException::class);
        $this->actingAs($c['actor'])->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()->assertSee($c['captains'][0]->user->name)->assertSee($c['captains'][1]->user->name)->assertDontSee('shared_orders.')->assertDontSee(__('shared_orders.shared_correction_blocked'));
        $this->get(route('orders.invoices.print', [$c['order'], $invoice]))->assertOk()->assertSee($c['captains'][0]->user->name)->assertSee($c['captains'][1]->user->name);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $collection = app(InvoiceCollectionService::class)->record($c['actor'], $invoice,
            InvoiceCollectionMethod::Cash, '1022', $c['currency']->id, now());
        $this->assertSame($source['primary_order_captain_id'], $collection->order_captain_id);
        $this->assertDatabaseCount('invoice_collections', 1);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertTrue(BigDecimal::of(DB::table('financial_entries')->value('amount'))->isEqualTo('18'));
        $this->assertThrows(fn () => $service->void($c['admin'], $c['order'], $invoice, 'Unsupported refund'), ValidationException::class);
        $this->assertThrows(fn () => $service->replace($c['admin'], $c['order'], $invoice, 'Unsupported refund'), ValidationException::class);
        $this->get(route('orders.invoices.show', [$c['order'], $invoice]))->assertOk()->assertSee(__('shared_orders.shared_correction_blocked'))->assertDontSee(route('orders.invoices.void', [$c['order'], $invoice]), false);
    }

    public function test_shared_invoice_requires_every_confirmed_purchase_and_branch_authorization(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
        $service = app(InvoiceService::class);
        $this->assertThrows(fn () => $service->createDraft($c['actor'], $c['order']), ValidationException::class);
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        $foreign = $this->sharedContext();
        $this->assertThrows(fn () => $service->createDraft($foreign['actor'], $c['order']), HttpException::class);
        $draft = $service->createDraft($c['admin'], $c['order']);
        $this->assertSame(InvoiceStatus::Draft, $draft->status);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_postgresql_rejects_forged_shared_totals_reparenting_and_snapshot_mutation(): void
    {
        $c = $this->prepared();
        $service = app(InvoiceService::class);
        $draft = $service->createDraft($c['actor'], $c['order']);
        $foreign = $this->sharedContext(0, 1);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $draft->id)->update(['order_id' => $foreign['order']->id])), QueryException::class);
        $ready = $service->refresh($c['actor'], $c['order'], $draft);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $ready->id)->update(['items_subtotal' => '1003', 'grand_total' => '1021'])), QueryException::class);
        $issued = $service->issue($c['actor'], $c['order'], $ready);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_financial_source_snapshot_lines')->update(['product_funding_share' => '502'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $issued->id)->update(['status' => 'VOIDED'])), QueryException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $issued->id)->delete()), QueryException::class);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    private function prepared(): array
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext(0);
        $c['shop']->items()->sole()->update(['actual_unit_price' => '1001']);
        $second = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'ONE_TIME', 'shop_name' => 'Second',
            'origin_area_id' => $c['shop']->origin_area_id, 'business_classification' => 'SHOP']);
        $item = $c['shop']->items()->sole()->replicate();
        $item->forceFill(['order_shop_id' => $second->id, 'actual_unit_price' => '3'])->save();
        app(DeliveryPricingRuleService::class)->create($c['admin'], ['from_area_id' => $c['shop']->origin_area_id,
            'to_area_id' => $c['order']->destination_area_id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'MULTI_SHOP', 'delivery_unit_fee' => '10', 'effective_from' => now()->subDay()]);
        app(OrderPricingService::class)->recalculate($c['order']);
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
        app(OrderDeliveryPricingService::class)->price($c['admin'], $c['order'], $c['shop']->originArea, null, '2', 'Combined discount once');
        foreach ([[$c['shop'], '1001'], [$second, '3']] as [$shop, $amount]) {
            app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $shop,
                $this->sharedData($c, $amount), $this->sharedImage());
        }

        return $c;
    }
}
