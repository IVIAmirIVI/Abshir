<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Services\CustomerCreditService;
use App\Services\CustomerReturnService;
use App\Services\MixedCustomerCreditService;
use App\Services\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerReturnFixtures;
use Tests\TestCase;

class LaunchCustomerCompensationTest extends TestCase
{
    use CustomerReturnFixtures, RefreshDatabase;

    #[DataProvider('compensations')]
    public function test_mixed_settlement_allows_independent_full_compensation_without_reversing_original_payment(InvoiceCollectionMethod $method, string $compensation): void
    {
        $c = $this->sold(collect: false, productAmount: '200', deliveryFee: '100');
        $grant = app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
            'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'reason' => 'OVERCHARGE', 'amount' => '30', 'reference' => (string) str()->uuid(), 'notes' => 'Independent previous overcharge', 'occurred_at' => now()->toIso8601String(),
        ]);
        app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], $method, [
            'request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '30'], 'actual_amount' => '270', 'collected_at' => now()->toIso8601String(),
        ]);
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
        $tables = ['invoices', 'invoice_collections', 'customer_credit_settlements', 'customer_credit_redemptions', 'financial_entries', 'financial_transactions'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
        $data = $this->data($c, '1', '200');
        $data['lines'][] = ['kind' => 'DELIVERY', 'quantity' => '0', 'amount' => '100'];
        $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][0]->id, 'amount' => '300']];
        $data['compensation_method'] = $compensation;
        $service = app(CustomerReturnService::class);

        $case = $service->record($c['actor'], $c['order'], $data);

        $this->assertSame('FULL', $case->invoice_refund_scope);
        $this->assertSame($case->id, $service->record($c['actor'], $c['order'], $data)->id);
        $this->assertThrows(fn () => $service->record($c['actor'], $c['order'], array_replace($data, ['request_key' => (string) str()->uuid()])), ValidationException::class);
        if ($compensation === 'CREDIT') {
            $this->assertSame('300.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
            $this->assertSame('0.0000', $service->summary($c['actor'], $c['order'])->sole()->responsibilities->sole()->funded_amount);
        } else {
            $payment = $this->movement($c, '300');
            $payment['method'] = $compensation;
            $partial = array_replace($payment, ['amount' => '80']);
            $this->assertThrows(fn () => $service->move($c['actor'], $case, $partial, $this->proof('installment forbidden')), ValidationException::class);
            $service->move($c['actor'], $case, $payment, $this->proof('actual full independent payment'));
            $this->assertSame('300.0000', $service->summary($c['actor'], $c['order'])->sole()->responsibilities->sole()->funded_amount);
            $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function compensations(): array
    {
        return [
            [InvoiceCollectionMethod::Cash, 'CREDIT'], [InvoiceCollectionMethod::ShamCash, 'CREDIT'],
            [InvoiceCollectionMethod::Cash, 'CASH'], [InvoiceCollectionMethod::ShamCash, 'TRANSFER'],
        ];
    }
}
