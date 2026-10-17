<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Livewire\Reports\ManagementReports;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainReceivablePayoutService;
use App\Services\InvoiceCollectionService;
use App\Services\ManagementReportQuery;
use App\Services\OrderCancellationService;
use App\Services\OrderService;
use App\Services\ShopCommissionAccountingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CaptainBenefitFixtures;
use Tests\Support\ShopCommissionFixtures;
use Tests\TestCase;

class ManagementReportsTest extends TestCase
{
    use CaptainBenefitFixtures, RefreshDatabase, ShopCommissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
    }

    private function report(User $actor, array $filters = []): array
    {
        return app(ManagementReportQuery::class)->snapshot($actor, array_replace([
            'from' => '2026-10-08', 'to' => '2026-10-08', 'branch_id' => '', 'currency_id' => '',
        ], $filters));
    }

    #[DataProvider('scenarios')]
    public function test_actual_single_and_shared_v2_sources_are_separate_and_never_multiplied(int $captains, string $method, string $invoiceAmount, string $receivable, string $custody): void
    {
        $c = $this->commissionOrder($captains);
        app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::from($method),
            $c['invoice']->grand_total, $c['currency']->id, now(), null, $method === 'SHAM_CASH' ? 'Recorded transfer' : null);
        $this->deliverCommissionOrder($c);
        $account = DB::table('shop_commission_accounts')->sole();
        app(ShopCommissionAccountingService::class)->recordPayment($c['admin'], $c['master'], $account->id, [
            'amount' => '3', 'payment_method' => 'CASH', 'occurred_at' => now()->toIso8601String(), 'request_key' => (string) str()->uuid(),
        ]);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();

        $result = $this->report($c['admin'], ['branch_id' => $c['branch']->id]);

        $this->assertSame(1, $result['operations']['created']);
        $this->assertSame(1, $result['operations']['delivered']);
        $this->assertSame($captains === 2 ? 1 : 0, $result['operations']['shared']);
        $this->assertSame($captains === 1 ? 1 : 0, $result['operations']['single']);
        $this->assertSame($captains, $result['operations']['captains']->total());
        foreach ($result['operations']['captains'] as $row) {
            $this->assertSame(1, $row->total);
            $this->assertSame(1, $row->delivered);
        }
        $this->assertSame(1, $result['operations']['shops']->sole()->total);
        $this->assertSame($invoiceAmount, $result['financial']['invoices']['rows'][0]['issued']);
        $collections = $result['financial']['collections']['rows'][0];
        $this->assertSame($invoiceAmount, $collections[$method === 'CASH' ? 'cash' : 'sham_cash']);
        $this->assertSame(2, $result['financial']['collections']['versions'][0]['version']);
        $balances = $result['financial']['balances']['rows'][0];
        $this->assertSame($receivable, $balances['CAPTAIN_COMPANY_RECEIVABLE']);
        $this->assertSame($custody, $balances['CAPTAIN_CASH_CUSTODY']);
        $this->assertSame('0', $balances['CAPTAIN_DEPOSIT']);
        $commission = $result['financial']['commissions']['rows'][0];
        $this->assertSame('8.00', $commission['earned']);
        $this->assertSame('3.00', $commission['paid']);
        $this->assertSame('5.00', $commission['outstanding']);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
    }

    public static function scenarios(): array
    {
        return [
            [1, 'CASH', '90.0000', '5.0000', '10.0000'],
            [2, 'CASH', '100.0000', '10.0000', '20.0000'],
            [1, 'SHAM_CASH', '90.0000', '85.0000', '0'],
            [2, 'SHAM_CASH', '100.0000', '90.0000', '0'],
        ];
    }

    public function test_currency_separation_branch_scope_and_sources_require_their_original_permissions(): void
    {
        $first = $this->commissionOrder();
        $second = $this->commissionOrder();
        $global = $this->report($first['admin']);
        $this->assertCount(2, $global['financial']['invoices']['rows']);
        $this->assertEqualsCanonicalizing([$first['currency']->id, $second['currency']->id],
            array_column($global['financial']['invoices']['rows'], 'currency_id'));
        $local = $this->report($first['actor']);
        $this->assertSame(1, $local['operations']['created']);
        $this->assertSame([$first['currency']->id], array_column($local['financial']['invoices']['rows'], 'currency_id'));
        $this->assertArrayNotHasKey('commissions', $local['financial']);
        $filtered = $this->report($first['admin'], ['currency_id' => $second['currency']->id]);
        $this->assertSame([$second['currency']->id], array_column($filtered['financial']['invoices']['rows'], 'currency_id'));
        $this->assertSame(2, $filtered['operations']['created']);

        $this->actingAs($first['actor']);
        Livewire::test(ManagementReports::class)->set('branchId', (string) $second['branch']->id)->assertForbidden();
        $captainUser = $first['captains'][0]->user;
        $captainUser->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->actingAs($captainUser)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($first['actor'])->get(route('shops.commission-account', $first['master']))->assertForbidden();
        $this->get(route('orders.show', $second['order']))->assertForbidden();
    }

    public function test_damascus_bounds_and_first_final_transition_are_session_timezone_independent(): void
    {
        $c = $this->sharedContext(2, 1);
        foreach (['2026-10-07 20:59:59', '2026-10-07 21:00:00', '2026-10-08 20:59:59', '2026-10-08 21:00:00'] as $date) {
            $order = $this->plainOrder($c);
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $date]);
        }
        DB::table('orders')->where('id', $c['order']->id)->update(['created_at' => '2026-10-07 20:00:00']);
        $cancelled = $this->plainOrder($c);
        app(OrderCancellationService::class)->cancel($c['admin'], $cancelled, OrderCancellationClassification::BeforePurchase, OrderCancellationReason::Other, 'Fixture cancellation');
        DB::table('orders')->where('id', $cancelled->id)->update(['created_at' => '2026-10-07 20:00:00']);
        $cancelled->statusHistory()->create(['new_status' => 'CANCELLED', 'changed_at' => now()->addMinute(), 'changed_by' => $c['admin']->id]);
        $utc = $this->report($c['admin']);
        $this->assertSame(2, $utc['operations']['created']);
        $this->assertSame(1, $utc['operations']['cancelled']);
        $this->assertSame(1, $utc['operations']['trend'][0]['cancelled']);
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $shifted = $this->report($c['admin']);
        $this->assertSame($utc['operations']['created'], $shifted['operations']['created']);
        $this->assertSame($utc['operations']['trend'], $shifted['operations']['trend']);
    }

    public function test_entitlements_payouts_and_period_end_balances_are_different_sources(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');
        $review = DB::table('captain_benefit_reviews')->where('action', 'APPROVE')->sole();
        $entry = DB::table('financial_entries')->sole();
        app(CaptainReceivablePayoutService::class)->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '7', 'occurred_at' => now()->toIso8601String(),
                'payment_method' => 'CASH', 'received_by_user_id' => $c['captain']->user_id],
            [['financial_entry_id' => $entry->id, 'amount' => '7']], $this->benefitImage());

        $report = $this->report($c['reviewer']);

        $this->assertSame('20.0000', $report['financial']['benefits']['rows'][0]['bonus']);
        $this->assertSame('7.0000', $report['financial']['payouts']['rows'][0]['paid']);
        $this->assertSame('13.0000', $report['financial']['balances']['rows'][0]['CAPTAIN_COMPANY_RECEIVABLE']);
        $later = $this->report($c['reviewer'], ['from' => '2026-10-09', 'to' => '2026-10-09']);
        $this->assertSame([], $later['financial']['payouts']['rows']);
        $this->assertSame('13.0000', $later['financial']['balances']['rows'][0]['CAPTAIN_COMPANY_RECEIVABLE']);
        $this->assertSame($review->id, DB::table('captain_benefit_reviews')->where('action', 'APPROVE')->sole()->id);
    }

    public function test_unavailable_optional_sources_are_explicit_instead_of_sql_errors_or_fake_zero(): void
    {
        $c = $this->sharedContext();
        DB::statement('ALTER TABLE shop_commission_accounts RENAME TO held_shop_commission_accounts');
        DB::statement('ALTER TABLE captain_benefit_reviews RENAME TO held_captain_benefit_reviews');
        try {
            $result = $this->report($c['admin']);
            $this->assertFalse($result['financial']['commissions']['available']);
            $this->assertFalse($result['financial']['benefits']['available']);
            $this->assertTrue($result['financial']['invoices']['available']);
            $this->assertTrue($result['operations']['available']);
        } finally {
            DB::statement('ALTER TABLE held_captain_benefit_reviews RENAME TO captain_benefit_reviews');
            DB::statement('ALTER TABLE held_shop_commission_accounts RENAME TO shop_commission_accounts');
        }
    }

    public function test_livewire_filters_render_localized_reports_and_execute_no_writes(): void
    {
        $c = $this->commissionOrder();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->actingAs($c['admin'])->get(route('reports.index'))->assertSee(__('reports.title'));
        Livewire::test(ManagementReports::class)->set('from', '2026-10-08')->set('to', '2026-10-08')
            ->set('branchId', (string) $c['branch']->id)->set('currencyId', (string) $c['currency']->id)
            ->call('applyFilters')->assertHasNoErrors()->assertSee(__('reports.boundaries'))->assertSee($c['currency']->code);
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $sql) === 1)));
        $this->assertSame(array_keys(Arr::dot(require lang_path('ar/reports.php'))), array_keys(Arr::dot(require lang_path('en/reports.php'))));
        Livewire::test(ManagementReports::class)->set('to', '2026-01-01')->call('applyFilters')->assertHasErrors('to');
        app()->setLocale('en');
        $this->get(route('reports.index'))->assertSee('Management reports');
    }

    private function plainOrder(array $c): Order
    {
        $area = Area::factory()->for($c['branch'])->create();
        $customer = Customer::factory()->create();
        $phone = $customer->phones()->create(['phone' => '0991112223', 'normalized_phone' => '0991112223']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Fixture']);

        return app(OrderService::class)->create($c['admin'], ['branch_id' => $c['branch']->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
    }
}
