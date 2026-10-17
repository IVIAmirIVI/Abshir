<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\OrderCaptain;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainService;
use App\Services\CorrectedOrderFinancialService;
use App\Services\CustomerCreditService;
use App\Services\DeliveryAllocationService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialBalanceQueryService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\MixedCustomerCreditService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialSourceSnapshotService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderShopPurchaseService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerReturnFixtures;
use Tests\TestCase;

class CaptainFuelAllowanceTest extends TestCase
{
    use CustomerReturnFixtures, RefreshDatabase;

    #[DataProvider('splits')]
    public function test_percentage_points_and_currency_rounding_preserve_the_customer_fee(string $base, string $fuel, string $fee, int $places, string $entitlement, string $company, string $fuelAmount): void
    {
        $currency = new Currency(['decimal_places' => $places]);
        $assignment = new OrderCaptain(['captain_id' => 1]);
        $assignment->id = 1;
        $row = app(DeliveryAllocationService::class)->calculate($fee, $base, '0', $currency, [$assignment], [1 => $fuel])[0];
        $this->assertSame($entitlement, $row['captain_entitlement']);
        $this->assertSame($company, $row['company_collectible']);
        $this->assertSame($fuelAmount, $row['fuel_allowance_amount']);
        $this->assertSame((string) BigDecimal::of($base)->plus($fuel)->toScale(4), $row['captain_share_percent_snapshot']);
        $this->assertSame((string) BigDecimal::of($fee)->toScale(4), $row['customer_delivery_amount_allocated']);
        $this->assertSame((string) BigDecimal::of($fee)->toScale(4), (string) BigDecimal::of($row['captain_entitlement'])->plus($row['company_collectible'])->toScale(4));
    }

    public static function splits(): array
    {
        return [
            'zero preserves base' => ['50', '0', '10000', 2, '5000.0000', '5000.0000', '0.0000'],
            '50 plus 10' => ['50', '10', '10000', 2, '6000.0000', '4000.0000', '1000.0000'],
            '70 plus 10' => ['70', '10', '10000', 2, '8000.0000', '2000.0000', '1000.0000'],
            'company zero' => ['95', '5', '10000', 2, '10000.0000', '0.0000', '500.0000'],
            'fractional percentage' => ['50.125', '1.2345', '10000', 2, '5135.9500', '4864.0500', '123.4500'],
            'one cent is not allocated twice' => ['50', '50', '0.01', 2, '0.0100', '0.0000', '0.0000'],
            'zero place currency' => ['50', '10', '125', 0, '75.0000', '50.0000', '12.0000'],
            'four place currency' => ['50', '10', '0.0125', 4, '0.0075', '0.0050', '0.0012'],
        ];
    }

    public function test_admin_create_edit_and_default_zero_are_visible_without_new_permissions(): void
    {
        $c = $this->sharedContext(2, 1, true);
        $this->assertSame('0.0000', $c['captains'][0]->fresh()->fuel_allowance_percentage);
        $this->actingAs($c['admin'])->get(route('captains.create'))->assertOk()->assertSee(__('captains.fuel_label'));
        $user = User::factory()->create();
        $this->post(route('captains.store'), ['user_id' => $user->id, 'home_branch_id' => $c['branch']->id,
            'hired_at' => now()->toDateString(), 'fuel_allowance_percentage' => '10.1250'])->assertSessionHasNoErrors();
        $captain = Captain::where('user_id', $user->id)->sole();
        $this->assertSame('10.1250', $captain->fuel_allowance_percentage);
        $this->get(route('captains.edit', $captain))->assertOk()->assertSee('name="fuel_allowance_percentage"', false);
        $this->put(route('captains.update', $captain), ['fuel_allowance_percentage' => '5'])->assertSessionHasNoErrors();
        $this->get(route('captains.show', $captain))->assertOk()->assertSee(__('captains.fuel_label'));
        $this->assertSame('5.0000', $captain->fresh()->fuel_allowance_percentage);
    }

    #[DataProvider('invalidPercentages')]
    public function test_http_and_authoritative_service_reject_invalid_configuration(mixed $value): void
    {
        $c = $this->sharedContext(2, 1, true);
        $captain = $c['captains'][0];
        $this->actingAs($c['admin'])->put(route('captains.update', $captain), ['fuel_allowance_percentage' => $value])
            ->assertSessionHasErrors('fuel_allowance_percentage');
        $this->assertThrows(fn () => app(CaptainService::class)->save($c['admin'], $captain, ['fuel_allowance_percentage' => $value]), ValidationException::class);
        $this->assertSame('0.0000', $captain->fresh()->fuel_allowance_percentage);
    }

    public static function invalidPercentages(): array
    {
        return array_map(fn (mixed $value): array => [$value], ['-1', '51', '101', 'NaN', 'Infinity', '10%', '1e1', '0.00001', '١٠', '', null]);
    }

    public function test_both_configuration_directions_and_the_actual_allocation_reject_over_100(): void
    {
        $c = $this->sharedContext(2, 1, true);
        app(DeliveryPricingSettings::class)->update($c['admin'], '95', '0');
        $this->assertThrows(fn () => app(CaptainService::class)->save($c['admin'], $c['captains'][0], ['fuel_allowance_percentage' => '10']), ValidationException::class);
        app(CaptainService::class)->save($c['admin'], $c['captains'][0], ['fuel_allowance_percentage' => '5']);
        $this->assertThrows(fn () => app(DeliveryPricingSettings::class)->update($c['admin'], '96', '0'), ValidationException::class);
        $this->assertSame('95', (string) app(DeliveryPricingSettings::class)->captainSharePercent());
        $this->assertThrows(fn () => app(DeliveryAllocationService::class)->calculate('100', '96', '0', $c['currency'], $c['assignments'], [$c['captains'][0]->id => '5']), ValidationException::class);
    }

    public function test_employee_and_cross_branch_editor_cannot_change_the_allowance(): void
    {
        $c = $this->sharedContext(2, 1, true);
        $captain = $c['captains'][0];
        $this->actingAs($c['actor'])->put(route('captains.update', $captain), ['fuel_allowance_percentage' => '10'])->assertForbidden();
        $editor = User::factory()->create();
        $role = Role::create(['code' => 'FUEL_EDITOR', 'name' => 'Scoped Captain editor']);
        $role->permissions()->attach(Permission::where('code', 'captains.update')->sole());
        $editor->roles()->attach($role);
        $editor->branches()->attach(Branch::factory()->create());
        $this->actingAs($editor)->put(route('captains.update', $captain), ['fuel_allowance_percentage' => '10'])->assertForbidden();
        $this->actingAs($captain->user)->put(route('captains.update', $captain), ['fuel_allowance_percentage' => '10'])->assertForbidden();
        $this->assertSame('0.0000', $captain->fresh()->fuel_allowance_percentage);
    }

    #[DataProvider('contracts')]
    public function test_existing_financial_contracts_post_the_frozen_entitlement_exactly_once(int $count, InvoiceCollectionMethod $method, bool $v3): void
    {
        $c = $this->prepared($count, $v3);
        $this->assertSame($count === 1 && $v3 ? 3 : 2, $c['order']->fresh()->accounting_contract_version);
        $expectedTotal = $count === 1 ? '180.0000' : '280.0000';
        $this->assertSame($expectedTotal, $c['invoice']->grand_total);
        $collection = app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], $method, $expectedTotal,
            $c['currency']->id, now(), null, 'Fuel allowance regression payment');
        $this->deliver($c);
        foreach ($c['captains'] as $index => $captain) {
            $entitlement = $index === 0 ? '60' : '55';
            $v3Single = $count === 1 && $v3;
            $expectedReceivable = $v3Single && $method === InvoiceCollectionMethod::Cash ? '0'
                : (string) BigDecimal::of($entitlement)->plus($method === InvoiceCollectionMethod::ShamCash ? ($count === 1 ? '80' : '40') : '0');
            $this->assertBalances($c, $index, $v3Single && $method === InvoiceCollectionMethod::Cash ? '-40' : '0', $expectedReceivable,
                ! $v3Single && $index === 0 && $method === InvoiceCollectionMethod::Cash ? (string) ($count * 100) : '0');
        }
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $this->assertSame($expectedTotal, $collection->amount);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function contracts(): array
    {
        return [[1, InvoiceCollectionMethod::Cash, true], [1, InvoiceCollectionMethod::ShamCash, true],
            [2, InvoiceCollectionMethod::Cash, true], [2, InvoiceCollectionMethod::ShamCash, true],
            [1, InvoiceCollectionMethod::Cash, false], [1, InvoiceCollectionMethod::ShamCash, false]];
    }

    public function test_profile_changes_do_not_rewrite_issued_sources_and_future_pricing_uses_the_new_allowance(): void
    {
        $c = $this->prepared();
        $snapshot = app(OrderFinancialSourceSnapshotService::class)->read($c['invoice']);
        $rows = $c['order']->currentDeliveryPricing->allocations->toJson();
        app(CaptainService::class)->save($c['admin'], $c['captains'][0], ['fuel_allowance_percentage' => '5']);
        $this->assertSame($snapshot, app(OrderFinancialSourceSnapshotService::class)->read($c['invoice']));
        $this->assertSame($rows, $c['order']->fresh()->currentDeliveryPricing->allocations->toJson());
        $future = $this->sharedContext(2, 1, true, '100', $c);
        $captainId = $c['captains'][0]->id;
        app(OrderCaptainSelectionService::class)->select($c['actor'], $future['order'], [$captainId], $captainId, (string) str()->uuid(), 'Future delivery pricing');
        $row = $future['order']->fresh()->currentDeliveryPricing->allocations->sole();
        $this->assertSame('5.0000', $row->fuel_allowance_percent_snapshot);
        $this->assertSame('55.0000', $row->captain_entitlement);
        $html = Blade::render('<x-captains.delivery-share :allocation="$allocation" />', ['allocation' => $c['order']->fresh()->currentDeliveryPricing->allocations->sole()]);
        $this->assertStringContainsString(__('captains.fuel_applied'), $html);
        $this->assertStringContainsString('>10</bdi>', $html);
    }

    public function test_discount_keeps_customer_amount_and_company_funded_shortfall_policy(): void
    {
        $c = $this->sharedContext(2, 1, true, '100');
        app(CaptainService::class)->save($c['admin'], $c['captains'][0], ['fuel_allowance_percentage' => '10']);
        $pricing = app(OrderDeliveryPricingService::class)->price($c['admin'], $c['order'], $c['shop']->originArea, null, '70', 'Existing company-funded discount');
        $row = $pricing->allocations()->sole();
        $this->assertSame('30.0000', $pricing->customer_delivery_total);
        $this->assertSame('60.0000', $row->captain_entitlement);
        $this->assertSame('0.0000', $row->company_collectible);
        $this->assertSame('30.0000', $row->captain_shortfall);
    }

    public function test_mixed_credit_keeps_actual_cash_and_company_funded_shortfall_separate(): void
    {
        $c = $this->prepared();
        $grant = app(CustomerCreditService::class)->grant($c['actor'], $c['order']->customer, [
            'request_key' => (string) str()->uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'reason' => 'OVERCHARGE', 'amount' => '80', 'reference' => 'FUEL-CREDIT', 'notes' => 'Previous overcharge', 'occurred_at' => now()->toIso8601String(),
        ]);
        $data = ['request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '80'], 'actual_amount' => '100',
            'collected_at' => now()->toIso8601String(), 'notes' => 'Actual mixed payment', 'payment_reference' => 'FUEL-MIXED'];
        $collection = app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $data);
        $this->deliver($c);
        $this->assertSame('180.0000', $c['invoice']->fresh()->grand_total);
        $this->assertSame('100.0000', $collection->amount);
        $this->assertSame('0.0000', app(CustomerCreditService::class)->balances($c['actor'], $c['order']->customer)->sole()->available);
        $this->assertBalances($c, 0, '0', '40', '0');
        $this->assertSame($collection->id, app(MixedCustomerCreditService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash, $data)->id);
        $this->assertDatabaseCount('customer_credit_redemptions', 1);
    }

    public function test_postgresql_rejects_invalid_configuration_and_snapshot_tampering(): void
    {
        $c = $this->prepared();
        $row = $c['order']->currentDeliveryPricing->allocations->sole();
        foreach (['-1', '100.0001', 'NaN', 'Infinity'] as $value) {
            $this->assertSqlRejected(fn () => DB::table('captains')->where('id', $c['captains'][0]->id)->update(['fuel_allowance_percentage' => $value]), $value === 'Infinity' ? '22003' : '23514');
        }
        $this->assertSqlRejected(fn () => DB::table('order_delivery_allocations')->where('id', $row->id)->update(['fuel_allowance_percent_snapshot' => '0']), '55000');
        $copy = Arr::except($row->getAttributes(), ['id', 'created_at', 'updated_at']);
        $copy['captain_share_percent_snapshot'] = '105';
        $this->assertSqlRejected(fn () => DB::table('order_delivery_allocations')->insert($copy));
        $this->assertSame('60.0000', $row->fresh()->captain_entitlement);
    }

    public function test_arabic_and_english_translation_keys_remain_equal(): void
    {
        $this->assertSame(array_keys(Arr::dot(require lang_path('ar/captains.php'))), array_keys(Arr::dot(require lang_path('en/captains.php'))));
    }

    private function prepared(int $count = 1, bool $v3 = true): array
    {
        // PostgreSQL now() is fixed at the outer test transaction start; keep purchases in its past.
        $this->travelTo(CarbonImmutable::now()->subMinutes(2)->startOfSecond());
        config(['order_accounting.enable_v3_single_captain' => $v3]);
        Storage::fake('order_returns');
        Storage::fake('order_receipts');
        $c = $this->sharedContext(2, $count, true, '100');
        foreach ($c['captains'] as $index => $captain) {
            app(CaptainService::class)->save($c['admin'], $captain, ['fuel_allowance_percentage' => $index === 0 ? '10' : '5']);
        }
        app(OrderDeliveryPricingService::class)->price($c['admin'], $c['order'], $c['shop']->originArea);
        if ($count === 2) {
            app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        } else {
            app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c) + ['order_captain_id' => $c['assignments'][0]->id], $this->sharedImage());
        }
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $service = app(InvoiceService::class);
        $c['invoice'] = $service->issue($c['actor'], $c['order'], $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order'])));

        return $c;
    }

    private function deliver(array $c): void
    {
        foreach (OrderOperationalStatus::cases() as $status) {
            if (in_array($status->value, ['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'], true)) {
                app(OrderStatusService::class)->transition($c['actor'], $c['order'], $status, null);
            }
        }
    }

    private function assertBalances(array $c, int $index, string $deposit, string $receivable, string $custody): void
    {
        $query = app(FinancialBalanceQueryService::class);
        foreach ([FinancialAccountKind::CaptainDeposit->value => $deposit, FinancialAccountKind::CaptainCompanyReceivable->value => $receivable, FinancialAccountKind::CaptainCashCustody->value => $custody] as $kind => $expected) {
            $this->assertSame((string) BigDecimal::of($expected)->toScale(4), $query->captainBranchBalance($c['captains'][$index], $c['branch'], $c['currency'], FinancialAccountKind::from($kind)));
        }
    }

    private function assertSqlRejected(callable $operation, string $state = '23514'): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL accepted an invalid fuel allocation.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
