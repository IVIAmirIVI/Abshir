<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Services\CustomerCreditService;
use App\Services\CustomerReturnService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\MixedCustomerCreditService;
use App\Services\ShopCommissionEligibilityService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ShopCommissionFixtures;
use Tests\TestCase;

class CustomerReturnCommissionTest extends TestCase
{
    use RefreshDatabase, ShopCommissionFixtures;

    #[DataProvider('captainCounts')]
    public function test_return_does_not_reverse_earned_commission_or_enable_old_invoice_correction(int $count): void
    {
        $this->travelTo(CarbonImmutable::now()->subMinutes(2)->startOfSecond());
        Storage::fake('customer_refunds');
        $c = $this->commissionOrder($count);
        app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $c['invoice']->grand_total, $c['currency']->id, now(), 'RET collection', 'RET commission fixture');
        $this->deliverCommissionOrder($c);
        $this->assertSame('8.00', DB::table('shop_commission_transactions')->sole()->amount);
        $before = collect(['invoices', 'invoice_items', 'invoice_collections', 'financial_transactions', 'financial_entries', 'shop_commission_transactions'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $s = app(CustomerReturnService::class);
        $case = $s->record($c['actor'], $c['order'], [
            'request_key' => (string) str()->uuid(), 'invoice_id' => $c['invoice']->id, 'currency_id' => $c['currency']->id,
            'occurred_at' => now()->toIso8601String(), 'reason' => 'QUALITY', 'compensation_method' => 'TRANSFER', 'notes' => 'Documented quality return; no automatic commission reversal',
            'lines' => [['kind' => 'PRODUCT', 'invoice_item_id' => $c['invoice']->items()->sole()->id, 'quantity' => '1', 'amount' => '80']],
            'responsibilities' => [['party' => 'COMPANY', 'amount' => '80']],
        ]);
        $s->move($c['actor'], $case, ['request_key' => (string) str()->uuid(), 'currency_id' => $c['currency']->id,
            'amount' => '80', 'kind' => 'COMPENSATION', 'method' => 'TRANSFER', 'funding_source' => 'Documented actual company bank transfer',
            'reference' => 'RET-TRANSFER', 'occurred_at' => now()->toIso8601String(), 'actual_confirmed' => true],
            UploadedFile::fake()->createWithContent('receipt.png', $this->sharedImage()->getContent()));
        foreach ($before as $table => $facts) {
            $this->assertSame($facts, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $this->assertThrows(fn () => app(InvoiceFinancialCorrectionService::class)->request($c['actor'], $c['invoice'], InvoiceFinancialCorrectionKind::CancelAndRefund, [], 'No bypass', 'Preserve old shared/V2 restrictions', (string) str()->uuid()), ValidationException::class);
    }

    public static function captainCounts(): array
    {
        return [[1], [2]];
    }

    #[DataProvider('captainCounts')]
    public function test_mixed_credit_collection_keeps_product_commission_earning_unchanged(int $count): void
    {
        $this->travelTo(CarbonImmutable::now()->subMinutes(2)->startOfSecond());
        $c = $this->commissionOrder($count);
        $grant = app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
            'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'reason' => 'ERROR', 'amount' => '5', 'reference' => 'UAT-COMMISSION-CREDIT', 'notes' => 'Delivery only', 'occurred_at' => now()->toIso8601String(),
        ]);
        app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, [
            'request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '5'],
            'actual_amount' => (string) BigDecimal::of($c['invoice']->grand_total)->minus('5'), 'collected_at' => now()->toIso8601String(),
        ]);
        $this->deliverCommissionOrder($c);
        $this->assertSame('8.00', DB::table('shop_commission_transactions')->sole()->amount);
        $this->assertTrue(app(ShopCommissionEligibilityService::class)->inspect($c['actor'], $c['order'])['eligible']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}
