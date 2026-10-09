<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Models\User;
use App\Services\CorrectedOrderFinancialService;
use App\Services\DeliveryPricingSettings;
use App\Services\InvoiceService;
use App\Services\OrderBillPaymentService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderPricingService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\SharedOrderFinancialPlanService;
use App\Services\SharedOrderShopPurchaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SharedOrderFinancialPlanTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    #[DataProvider('precisions')]
    public function test_physical_reimbursements_and_two_entitlements_are_exact_without_imaginary_movements(int $places): void
    {
        $c = $this->prepared($places);
        $before = $this->financialRows();
        $cash = app(SharedOrderFinancialPlanService::class)->review($c['admin'], $c['order'], InvoiceCollectionMethod::Cash);
        $sham = app(SharedOrderFinancialPlanService::class)->review($c['admin'], $c['order'], InvoiceCollectionMethod::ShamCash);
        $this->assertSame('80.0000', $cash['items_subtotal']);
        $this->assertSame('20.0000', $cash['delivery_gross']);
        $this->assertSame('100.0000', $cash['customer_invoice_total']);
        $this->assertSame('90.0000', $cash['company_obligations']);
        $this->assertSame('100.0000', $cash['anticipated_cash_custody']);
        $this->assertSame('0.0000', $sham['anticipated_cash_custody']);
        $this->assertSame('0.0000', $cash['deposit_effect']);
        $this->assertFalse($cash['posting_enabled']);
        $this->assertFalse($cash['handover_recorded']);
        $this->assertFalse($cash['payout_recorded']);
        foreach ($cash['participants'] as $row) {
            $this->assertSame(number_format(40, $places, '.', ''), $row['purchase_reimbursement']);
            $this->assertSame(number_format(5, $places, '.', ''), $row['delivery_entitlement']);
            $this->assertSame(number_format(45, $places, '.', ''), $row['company_obligation']);
        }
        $this->assertSame($before, $this->financialRows(), 'Review must never create Accounts, movements or physical payment sources.');
    }

    public static function precisions(): array
    {
        return [[0], [2], [3], [4]];
    }

    public function test_combined_discount_applies_once_without_creating_extra_entitlement_for_shortfall(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $this->select($c);
        app(DeliveryPricingSettings::class)->update($c['admin'], '50', '100');
        app(OrderDeliveryPricingService::class)->price($c['admin'], $c['order'], $c['shop']->originArea, null, '20', 'Company-funded discount');
        $this->purchase($c);
        $result = app(SharedOrderFinancialPlanService::class)->review($c['admin'], $c['order'], InvoiceCollectionMethod::Cash);
        $this->assertSame('20.0000', $result['discount_once']);
        $this->assertSame('0.0000', $result['delivery_total']);
        $this->assertSame('80.0000', $result['customer_invoice_total']);
        $this->assertSame('90.0000', $result['company_obligations']);
        $this->assertSame('-10.0000', $result['company_delivery_net']);
        $this->assertSame(2, count($result['participants']));
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_per_shop_principal_must_match_actual_product_lines(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $this->select($c);
        $this->purchase($c, '82');
        $this->assertThrows(fn () => app(SharedOrderFinancialPlanService::class)->review($c['admin'], $c['order'], InvoiceCollectionMethod::Cash), ValidationException::class);
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_missing_purchase_fails_closed(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        $this->assertThrows(fn () => app(SharedOrderFinancialPlanService::class)->review($c['admin'], $c['order'], InvoiceCollectionMethod::Cash), ValidationException::class);
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_purchase_source_already_freezes_precision_and_review_never_rounds_payer_history(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $c['shop']->items()->sole()->update(['actual_unit_price' => '81']);
        app(OrderPricingService::class)->recalculate($c['order']);
        $this->select($c);
        $this->purchase($c, '81');
        $sources = DB::table('order_shop_purchase_allocations')->orderBy('id')->get()->toJson();
        $this->assertThrows(fn () => DB::transaction(fn () => $c['currency']->update(['decimal_places' => 0])), QueryException::class);
        $this->assertSame(2, $c['currency']->fresh()->decimal_places);
        $before = $this->financialRows();
        $plan = app(SharedOrderFinancialPlanService::class)->review($c['admin'], $c['order'], InvoiceCollectionMethod::Cash);
        $this->assertSame(['40.50', '40.50'], array_column($plan['participants'], 'purchase_reimbursement'));
        $this->assertSame($sources, DB::table('order_shop_purchase_allocations')->orderBy('id')->get()->toJson());
        $this->assertSame($before, $this->financialRows());
    }

    public function test_legacy_shared_purchase_is_not_silently_converted_to_the_new_company_contract(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $this->purchase($c);
        $this->assertThrows(fn () => $this->select($c), ValidationException::class);
        $this->assertThrows(fn () => app(SharedOrderFinancialPlanService::class)->review($c['admin'], $c['order'], InvoiceCollectionMethod::Cash), ValidationException::class);
        $this->assertSame(0, DB::table('order_captain_selections')->count());
    }

    public function test_financial_review_requires_permission_and_order_branch_access(): void
    {
        $c = $this->prepared();
        $foreign = $this->sharedContext();
        $this->assertSame('review_only', app(SharedOrderFinancialPlanService::class)->review($c['actor'], $c['order'], InvoiceCollectionMethod::Cash)['state']);
        $this->assertThrows(fn () => app(SharedOrderFinancialPlanService::class)->review(User::factory()->create(), $c['order'], InvoiceCollectionMethod::Cash), AuthorizationException::class);
        $this->assertThrows(fn () => app(SharedOrderFinancialPlanService::class)->review($foreign['actor'], $c['order'], InvoiceCollectionMethod::Cash), HttpException::class);
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_selected_shared_order_cannot_use_legacy_invoice_posting_bill_or_single_payer_path(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        foreach ([fn () => app(InvoiceService::class)->createDraft($c['actor'], $c['order']),
            fn () => app(OrderBillPaymentService::class)->request($c['actor'], $c['order'], $c['currency']->id),
            fn () => app(CorrectedOrderFinancialService::class)->postIfReady($c['order']),
            fn () => app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'],
                $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage())] as $action) {
            $this->assertThrows($action, ValidationException::class);
        }
        $this->assertSame('unresolved', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('order_bill_payment_requests')->count());
        $this->assertSame(0, DB::table('financial_transactions')->count());
        $this->assertSame(0, DB::table('order_shop_purchase_confirmations')->count());
    }

    public function test_postgres_rejects_direct_legacy_invoice_creation_for_selected_shared_order(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        try {
            DB::transaction(fn () => DB::table('invoices')->insert(['order_id' => $c['order']->id,
                'invoice_number' => 'DIRECT-SHARED', 'version' => 1, 'status' => 'DRAFT', 'created_by' => $c['actor']->id]));
            $this->fail('Direct SQL must not bypass the shared financial contract.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('requires the shared financial contract', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_existing_draft_invoice_cannot_be_moved_into_selected_shared_contract(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        $other = $this->sharedContext(2, 1);
        $invoice = app(InvoiceService::class)->createDraft($other['actor'], $other['order']);
        try {
            DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['order_id' => $c['order']->id]));
            $this->fail('A legacy draft cannot bypass the shared contract through parent reassignment.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('requires the shared financial contract', $exception->getMessage());
        }
        $this->assertSame($other['order']->id, $invoice->fresh()->order_id);
        $this->assertSame(0, $c['order']->invoices()->count());
        $this->assertSame(0, DB::table('financial_entries')->count());
    }

    private function prepared(int $places = 2): array
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext($places);
        $this->select($c);
        $this->purchase($c);

        return $c;
    }

    private function select(array $c): void
    {
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
            array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid());
    }

    private function purchase(array $c, string $amount = '80'): void
    {
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c, $amount), $this->sharedImage());
    }

    private function financialRows(): array
    {
        return collect(['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries',
            'captain_deposit_receipts', 'captain_receivable_payouts', 'invoices', 'invoice_collections'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
