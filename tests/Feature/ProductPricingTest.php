<?php

namespace Tests\Feature;

use App\Data\PriceEstimateResult;
use App\Enums\PriceObservationSource;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\Role;
use App\Models\Shop;
use App\Models\SystemSetting;
use App\Models\Unit;
use App\Models\User;
use App\Services\PriceEstimationSettings;
use App\Services\ProductPriceService;
use App\Support\UiDate;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'SUPER_ADMIN', array $branches = []): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $role)->sole());
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    private function context(?Branch $branch = null): array
    {
        $branch ??= Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $shop = Shop::create(['name' => 'Shop '.fake()->unique()->word(), 'area_id' => $area->id]);
        $unit = Unit::create(['name' => 'Piece '.fake()->unique()->word(), 'code' => fake()->unique()->lexify('U???'), 'allows_decimal' => false]);
        $currency = Currency::create(['name' => 'Currency '.fake()->unique()->word(), 'code' => fake()->unique()->lexify('C??'), 'decimal_places' => 2]);
        $product = Product::create(['name' => 'Product '.fake()->unique()->word(), 'default_unit_id' => $unit->id]);

        return compact('branch', 'area', 'shop', 'unit', 'currency', 'product');
    }

    private function observation(array $context, string $price, string $observedAt, array $overrides = []): ProductPriceHistory
    {
        return ProductPriceHistory::query()->create(array_merge([
            'product_id' => $context['product']->id,
            'shop_id' => $context['shop']->id,
            'unit_id' => $context['unit']->id,
            'currency_id' => $context['currency']->id,
            'price' => $price,
            'observed_at' => Carbon::parse($observedAt),
            'source' => PriceObservationSource::Manual,
        ], $overrides));
    }

    public function test_authorized_user_records_exact_append_only_observation_with_distinct_observed_time(): void
    {
        $context = $this->context();
        $employee = $this->account('ORDER_EMPLOYEE', [$context['branch']]);
        $observedAt = now()->subDay()->startOfSecond();

        $this->actingAs($employee)->post(route('product-prices.store'), [
            'product_id' => $context['product']->id,
            'shop_id' => $context['shop']->id,
            'unit_id' => $context['unit']->id,
            'currency_id' => $context['currency']->id,
            'price' => '123456789.1234',
            'observed_at' => $observedAt->format('Y-m-d H:i:s'),
            'source' => PriceObservationSource::PurchaseReceipt->value,
            'notes' => 'Receipt entered one day later.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $observation = ProductPriceHistory::sole();
        $this->assertSame('123456789.1234', $observation->price);
        $this->assertTrue($observation->observed_at->equalTo($observedAt));
        $this->assertFalse($observation->observed_at->equalTo($observation->created_at));
        $this->assertTrue($observation->recordedBy->is($employee));
        $this->assertSame(PriceObservationSource::PurchaseReceipt, $observation->source);

        $this->expectException(LogicException::class);
        $observation->update(['price' => '1.0000']);
    }

    public function test_observation_validation_requires_dimensions_positive_price_source_and_active_references(): void
    {
        $context = $this->context();
        $admin = $this->account();
        $this->actingAs($admin)->post(route('product-prices.store'), [])->assertSessionHasErrors([
            'product_id', 'shop_id', 'unit_id', 'currency_id', 'price', 'observed_at', 'source',
        ]);
        $this->post(route('product-prices.store'), [
            'product_id' => $context['product']->id,
            'shop_id' => $context['shop']->id,
            'unit_id' => $context['unit']->id,
            'currency_id' => $context['currency']->id,
            'price' => '0',
            'observed_at' => now()->format('Y-m-d H:i:s'),
            'source' => 'SPECULATIVE',
        ])->assertSessionHasErrors(['price', 'source']);

        $context['unit']->forceFill(['is_active' => false])->save();
        $this->post(route('product-prices.store'), [
            'product_id' => $context['product']->id,
            'shop_id' => $context['shop']->id,
            'unit_id' => $context['unit']->id,
            'currency_id' => $context['currency']->id,
            'price' => '10.0000',
            'observed_at' => now()->format('Y-m-d H:i:s'),
            'source' => PriceObservationSource::Manual->value,
        ])->assertSessionHasErrors('unit_id');
    }

    public static function observationInstants(): array
    {
        return [
            'UTC session' => ['UTC', '2026-10-08T23:59:59+00:00', false],
            'Damascus session UTC input' => ['Asia/Damascus', '2026-10-08T23:59:59+00:00', false],
            'Damascus offset crossing midnight' => ['Asia/Damascus', '2026-10-09T02:59:59+03:00', false],
            'negative offset' => ['America/New_York', '2026-10-08T19:59:59-04:00', false],
            'positive offset date object' => ['Asia/Damascus', '2026-10-09T13:59:59+14:00', true],
            'offset-free application UTC input' => ['Asia/Damascus', '2026-10-08 23:59:59', false],
        ];
    }

    #[DataProvider('observationInstants')]
    public function test_observation_preserves_absolute_instant_across_postgres_session_timezones(string $sessionTimezone, string $input, bool $asObject): void
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->assertSame('UTC', config('app.timezone'));
        DB::select("SELECT set_config('TimeZone', ?, true)", [$sessionTimezone]);
        $context = $this->context();
        $expected = Carbon::parse($input);
        $observation = app(ProductPriceService::class)->record($this->account(), [
            'product_id' => $context['product']->id, 'shop_id' => $context['shop']->id,
            'unit_id' => $context['unit']->id, 'currency_id' => $context['currency']->id,
            'price' => '10.0000', 'observed_at' => $asObject ? $expected->toDateTimeImmutable() : $input,
            'source' => PriceObservationSource::Manual,
        ])->fresh();

        $this->assertTrue($observation->observed_at->equalTo($expected));
        $this->assertSame($expected->getTimestamp(), (int) DB::scalar('SELECT EXTRACT(EPOCH FROM observed_at)::bigint FROM product_price_history WHERE id = ?', [$observation->id]));
        $this->assertSame('09/10/2026 02:59:59', UiDate::datetime($observation->observed_at, true));
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_estimation_boundaries_are_absolute_instants_despite_damascus_session_timezone(): void
    {
        DB::select("SELECT set_config('TimeZone', 'Asia/Damascus', true)");
        $context = $this->context();
        $boundary = $this->observation($context, '15.0000', '2026-10-06T00:00:00+00:00');
        foreach (['2026-10-05T23:59:59+00:00', '2026-10-09T00:00:01+00:00'] as $excludedAt) {
            $other = $this->context();
            foreach (['product', 'unit', 'currency'] as $dimension) {
                $other[$dimension] = $context[$dimension];
            }
            $this->observation($other, '1.0000', $excludedAt);
        }
        $result = app(ProductPriceService::class)->estimateUnknownShopPrice($context['product'], $context['unit'], $context['currency'], Carbon::parse('2026-10-09T00:00:00+00:00'));

        $this->assertSame($boundary->id, $result->sourceObservationId);
        $this->assertSame('15.0000', $result->price);
    }

    public function test_latest_known_shop_price_uses_observed_at_and_deterministic_id_tie_break(): void
    {
        $context = $this->context();
        $service = app(ProductPriceService::class);
        $this->observation($context, '10.0000', '2026-09-14 10:00:00');
        $firstAtLatestTime = $this->observation($context, '30.0000', '2026-09-15 10:00:00');
        $lastAtLatestTime = $this->observation($context, '28.0000', '2026-09-15 10:00:00');
        $olderButLastCreated = $this->observation($context, '1.0000', '2026-09-14 23:59:59');
        $this->assertGreaterThan($firstAtLatestTime->id, $lastAtLatestTime->id);
        $this->assertGreaterThan($lastAtLatestTime->id, $olderButLastCreated->id);

        $latest = $service->getLatestShopPrice($context['product'], $context['shop'], $context['unit'], $context['currency']);

        $this->assertTrue($latest->is($lastAtLatestTime));
        $this->assertSame('28.0000', $latest->price);
        $this->assertTrue($latest->relationLoaded('shop'));
        $this->assertTrue($latest->relationLoaded('product'));
    }

    public function test_known_shop_lookup_excludes_other_units_and_currencies(): void
    {
        $context = $this->context();
        $otherUnit = Unit::create(['name' => 'Box', 'code' => 'BOX', 'allows_decimal' => false]);
        $otherCurrency = Currency::create(['name' => 'Other', 'code' => 'OTH', 'decimal_places' => 2]);
        $expected = $this->observation($context, '20.0000', '2026-09-13 10:00:00');
        $this->observation($context, '1.0000', '2026-09-15 10:00:00', ['unit_id' => $otherUnit->id]);
        $this->observation($context, '2.0000', '2026-09-15 11:00:00', ['currency_id' => $otherCurrency->id]);

        $latest = app(ProductPriceService::class)->getLatestShopPrice($context['product'], $context['shop'], $context['unit'], $context['currency']);

        $this->assertTrue($latest->is($expected));
    }

    public function test_unknown_shop_estimate_matches_latest_per_shop_then_lowest_rule(): void
    {
        $reference = Carbon::parse('2026-09-15 12:00:00');
        $a = $this->context();
        $b = $this->context();
        $c = $this->context();
        foreach (['product', 'unit', 'currency'] as $dimension) {
            $b[$dimension] = $a[$dimension];
            $c[$dimension] = $a[$dimension];
        }
        $this->observation($a, '20.0000', '2026-09-13 09:00:00');
        $this->observation($a, '28.0000', '2026-09-15 10:00:00');
        $shopBLatest = $this->observation($b, '24.0000', '2026-09-14 12:00:00');
        $this->observation($c, '26.0000', '2026-09-15 08:00:00');

        $result = app(ProductPriceService::class)->estimateUnknownShopPrice($a['product'], $a['unit'], $a['currency'], $reference);

        $this->assertTrue($result->available());
        $this->assertSame('24.0000', $result->price);
        $this->assertSame($b['shop']->id, $result->sourceShopId);
        $this->assertSame($shopBLatest->id, $result->sourceObservationId);
        $this->assertSame(3, $result->estimationWindowDays);
        $this->assertSame(PriceEstimateResult::METHOD, $result->estimationMethod);
    }

    public function test_estimation_window_is_inclusive_excludes_future_and_configuration_changes_result(): void
    {
        $context = $this->context();
        $reference = Carbon::parse('2026-09-15 12:00:00');
        $boundary = $this->observation($context, '15.0000', '2026-09-12 12:00:00');
        $this->observation($context, '1.0000', '2026-09-15 12:00:01');
        $service = app(ProductPriceService::class);

        $result = $service->estimateUnknownShopPrice($context['product'], $context['unit'], $context['currency'], $reference);
        $this->assertSame($boundary->id, $result->sourceObservationId);

        $olderContext = $this->context();
        $this->observation($olderContext, '17.0000', '2026-09-11 12:00:00');
        $this->assertFalse($service->estimateUnknownShopPrice($olderContext['product'], $olderContext['unit'], $olderContext['currency'], $reference)->available());

        SystemSetting::create(['key' => PriceEstimationSettings::KEY, 'value' => '5']);
        $this->assertTrue($service->estimateUnknownShopPrice($olderContext['product'], $olderContext['unit'], $olderContext['currency'], $reference)->available());
    }

    public function test_equal_estimate_prices_use_observed_time_then_id_tie_break(): void
    {
        $reference = Carbon::parse('2026-09-15 12:00:00');
        $base = $this->context();
        $second = $this->context();
        $third = $this->context();
        foreach (['product', 'unit', 'currency'] as $dimension) {
            $second[$dimension] = $base[$dimension];
            $third[$dimension] = $base[$dimension];
        }
        $this->observation($base, '10.0000', '2026-09-14 09:00:00');
        $firstTie = $this->observation($second, '10.0000', '2026-09-15 09:00:00');
        $lastTie = $this->observation($third, '10.0000', '2026-09-15 09:00:00');

        $result = app(ProductPriceService::class)->estimateUnknownShopPrice($base['product'], $base['unit'], $base['currency'], $reference);

        $this->assertGreaterThan($firstTie->id, $lastTie->id);
        $this->assertSame($lastTie->id, $result->sourceObservationId);
    }

    public function test_no_estimate_never_falls_back_to_shop_product_or_other_dimensions(): void
    {
        $context = $this->context();
        $otherUnit = Unit::create(['name' => 'Case', 'code' => 'CASE', 'allows_decimal' => false]);
        $otherCurrency = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'decimal_places' => 2]);
        $this->observation($context, '4.0000', now()->toDateTimeString(), ['unit_id' => $otherUnit->id]);
        $this->observation($context, '3.0000', now()->toDateTimeString(), ['currency_id' => $otherCurrency->id]);
        $context['shop']->products()->create([
            'product_id' => $context['product']->id,
            'unit_id' => $context['unit']->id,
            'currency_id' => $context['currency']->id,
            'listed_price' => '2.0000',
        ]);

        $result = app(ProductPriceService::class)->estimateUnknownShopPrice($context['product'], $context['unit'], $context['currency']);

        $this->assertFalse($result->available());
        $this->assertNull($result->price);
        $this->assertNull($result->sourceShop);
    }

    public function test_product_comparison_returns_one_latest_row_per_shop_unit_currency_without_averaging(): void
    {
        $context = $this->context();
        $admin = $this->account();
        $this->observation($context, '8.0000', '2026-09-13 10:00:00');
        $latest = $this->observation($context, '12.0000', '2026-09-15 10:00:00');

        $rows = app(ProductPriceService::class)->comparisonQuery($context['product'], $admin)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($latest->id, $rows->sole()->id);
        $this->assertEquals('12.0000', $rows->sole()->price);
        $this->actingAs($admin)->get(route('products.price-comparison', $context['product']))
            ->assertOk()->assertSee(UiDate::datetime($latest->observed_at, true))->assertSee('12')->assertDontSee('8.0000');
    }

    public function test_append_only_model_rejects_deletion(): void
    {
        $context = $this->context();
        $observation = $this->observation($context, '9.0000', now()->toDateTimeString());

        $this->expectException(LogicException::class);
        $observation->delete();
    }

    public function test_history_routes_are_paginated_and_offer_no_edit_or_delete_endpoint(): void
    {
        $context = $this->context();
        $admin = $this->account();
        $observation = $this->observation($context, '9.0000', now()->subHour()->toDateTimeString());

        $this->actingAs($admin)->get(route('product-prices.index'))->assertOk()->assertSee(__('pricing.observed_at'))->assertSee(__('pricing.entered_at'));
        $this->get(route('products.price-history', $context['product']))->assertOk()->assertSee($context['shop']->name);
        $this->get(route('shops.price-history', $context['shop']))->assertOk()->assertSee($context['product']->name);
        $this->put('/price-observations/'.$observation->id, ['price' => '1.0000'])->assertNotFound();
        $this->delete('/price-observations/'.$observation->id)->assertNotFound();
        $this->assertModelExists($observation);
    }
}
