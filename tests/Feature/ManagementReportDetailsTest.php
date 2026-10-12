<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Models\Role;
use App\Services\CaptainReceivablePayoutService;
use App\Services\InvoiceCollectionService;
use App\Services\ManagementReportCsv;
use App\Services\ManagementReportDetailQuery;
use App\Services\ManagementReportQuery;
use App\Services\OrderCancellationService;
use App\Services\OrderService;
use App\Services\ShopCommissionAccountingService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\CaptainBenefitFixtures;
use Tests\Support\ShopCommissionFixtures;
use Tests\TestCase;

class ManagementReportDetailsTest extends TestCase
{
    use CaptainBenefitFixtures, RefreshDatabase, ShopCommissionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
    }

    private function filters(array $extra = []): array
    {
        return array_replace(['from' => '2026-10-08', 'to' => '2026-10-08', 'branch_id' => '', 'currency_id' => ''], $extra);
    }

    private function detail(array $c, string $type, array $filters = []): array
    {
        return app(ManagementReportDetailQuery::class)->report($c['admin'], $type, $this->filters($filters));
    }

    private function csv(string $type, array $filters = []): array
    {
        $response = $this->get(route('reports.export', ['type' => $type] + $this->filters($filters)))->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public function test_shared_source_details_csv_and_summary_agree_without_report_writes(): void
    {
        $c = $this->commissionOrder(2);
        app(InvoiceCollectionService::class)->record($c['actor'], $c['invoice'], InvoiceCollectionMethod::Cash,
            $c['invoice']->grand_total, $c['currency']->id, now(), null, null);
        $this->deliverCommissionOrder($c);
        $account = DB::table('shop_commission_accounts')->sole();
        app(ShopCommissionAccountingService::class)->recordPayment($c['admin'], $c['master'], $account->id,
            ['amount' => '3', 'payment_method' => 'CASH', 'occurred_at' => now()->toIso8601String(), 'request_key' => (string) str()->uuid()]);
        $filters = $this->filters(['branch_id' => (string) $c['branch']->id, 'currency_id' => (string) $c['currency']->id]);
        $summary = app(ManagementReportQuery::class)->snapshot($c['admin'], $filters);
        $before = $this->financialContents();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->actingAs($c['admin']);
        foreach (ManagementReportDetailQuery::TYPES as $type) {
            $detail = $this->detail($c, $type, $filters);
            $rows = $detail['query']->get();
            $this->get(route('reports.detail', ['type' => $type] + $filters))
                ->assertOk()->assertSee(__('reports.detail_titles.'.$type))->assertSee(__('reports.definitions.'.$detail['definition']));
            $csv = $this->csv($type, $filters);
            $this->assertSame(count($rows) + 1, count($csv), $type);
            $this->assertSame(array_map(fn ($field) => __('reports.columns.'.$field), array_keys($detail['columns'])), $csv[0]);
            foreach ($rows as $index => $row) {
                foreach (array_keys($detail['columns']) as $column => $field) {
                    $this->assertSame(app(ManagementReportDetailQuery::class)->cell($row, $field), $csv[$index + 1][$column], "$type.$field");
                }
            }
            if (in_array($type, ['created', 'delivered', 'cancelled', 'single', 'shared', 'unassigned'], true)) {
                $this->assertSame($summary['operations'][$type], count($rows));
            }
        }
        $this->assertCount(2, $this->detail($c, 'captains', $filters)['query']->get());
        $this->assertSame(1, $this->detail($c, 'shops', $filters)['query']->sole()->total);
        $this->assertSame('100.0000', $this->detail($c, 'invoices', $filters)['query']->sole()->amount);
        $this->assertSame('100.0000', $this->detail($c, 'collections', $filters)['query']->sole()->amount);
        $balances = $this->detail($c, 'balances', $filters)['query']->get();
        foreach (['CAPTAIN_COMPANY_RECEIVABLE', 'CAPTAIN_CASH_CUSTODY'] as $kind) {
            $total = $balances->where('account_kind', $kind)->reduce(fn ($sum, $r) => $sum->plus($r->amount), BigDecimal::zero());
            $this->assertTrue($total->isEqualTo($summary['financial']['balances']['rows'][0][$kind]));
        }
        $commission = $this->detail($c, 'commission_balances', $filters)['query']->sole();
        $this->assertSame($summary['financial']['commissions']['rows'][0]['outstanding'], $commission->outstanding);
        $this->assertSame('5.00', $commission->outstanding);
        $this->assertSame($before, $this->financialContents());
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $sql) === 1)));
    }

    private function financialContents(): array
    {
        $result = [];
        foreach (['orders', 'invoices', 'invoice_collections', 'financial_transactions', 'financial_entries', 'shop_commission_transactions', 'captain_benefit_requests', 'captain_benefit_reviews', 'captain_receivable_payouts'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    public function test_branch_currency_filters_and_original_permissions_apply_to_every_download(): void
    {
        $first = $this->commissionOrder();
        $other = $this->commissionOrder();
        $this->actingAs($first['actor']);
        $local = $this->csv('invoices');
        $this->assertCount(2, $local);
        $this->assertSame((string) $first['invoice']->id, $local[1][0]);
        foreach (['invoices', 'created', 'balances'] as $type) {
            $params = ['type' => $type] + $this->filters(['branch_id' => $other['branch']->id]);
            $this->get(route('reports.detail', $params))->assertForbidden();
            $this->get(route('reports.export', $params))->assertForbidden();
        }
        $this->get(route('reports.export', ['type' => 'commissions'] + $this->filters()))->assertForbidden();
        $captainUser = $first['captains'][0]->user;
        $captainUser->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->actingAs($captainUser)->get(route('reports.export', ['type' => 'balances'] + $this->filters()))->assertForbidden();
        $this->actingAs($first['admin']);
        $filtered = $this->csv('invoices', ['currency_id' => $other['currency']->id]);
        $this->assertCount(2, $filtered);
        $this->assertSame((string) $other['invoice']->id, $filtered[1][0]);
        $this->assertSame($other['currency']->code, $filtered[1][5]);
        $this->assertCount(3, $this->csv('invoices'));
        $this->get(route('reports.export', ['type' => 'private-evidence'] + $this->filters()))->assertNotFound();
    }

    public function test_csv_neutralizes_text_formulas_but_preserves_exact_signed_decimal_amounts(): void
    {
        $c = $this->commissionOrder();
        $c['master']->update(['name' => '=HYPERLINK("https://example.invalid")']);
        $this->actingAs($c['admin']);
        $csv = $this->csv('shops');
        $this->assertSame("'".$c['master']->name, $csv[1][1]);
        $guard = app(ManagementReportCsv::class);
        foreach (['=1+1', '+cmd', '-cmd', '@SUM(1)', "\tvalue", "\rvalue", "\nvalue", '  =1', " \t@SUM(1)"] as $value) {
            $this->assertSame("'".$value, $guard->safeCell($value));
        }
        foreach (['-15.0000', '12345678901234567890.1234', '0.0000', '12.3400'] as $amount) {
            $this->assertSame($amount, $guard->safeCell($amount, true));
        }
        $this->assertSame("'-15", $guard->safeCell('-15'));
        $this->assertSame("'=1", $guard->safeCell('=1', true));
        app()->setLocale('en');
        $this->assertSame('Name', $this->csv('shops')[0][1]);
        $this->assertSame(array_keys(Arr::dot(require lang_path('ar/reports.php'))), array_keys(Arr::dot(require lang_path('en/reports.php'))));
    }

    public function test_approved_benefits_actual_payouts_and_as_of_balances_stay_distinct(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');
        $entry = DB::table('financial_entries')->sole();
        app(CaptainReceivablePayoutService::class)->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'],
            ['request_key' => (string) str()->uuid(), 'amount' => '7', 'occurred_at' => now()->toIso8601String(), 'payment_method' => 'CASH', 'received_by_user_id' => $c['captain']->user_id],
            [['financial_entry_id' => $entry->id, 'amount' => '7']], $this->benefitImage());
        $reportContext = ['admin' => $c['reviewer']];
        $benefit = $this->detail($reportContext, 'benefits')['query']->sole();
        $this->assertSame('20.0000', $benefit->amount);
        $this->assertSame('CAPTAIN_BONUS_DUE', $benefit->event_type);
        $this->assertSame($c['proposal']->id, $benefit->proposal_id);
        $this->assertSame('7.0000', $this->detail($reportContext, 'payouts')['query']->sole()->amount);
        $this->assertSame('13.0000', $this->detail($reportContext, 'balances')['query']->sole()->amount);
        $later = ['from' => '2026-10-09', 'to' => '2026-10-09'];
        $this->assertSame(0, $this->detail($reportContext, 'benefits', $later)['query']->count());
        $this->assertSame(0, $this->detail($reportContext, 'payouts', $later)['query']->count());
        $this->assertSame('13.0000', $this->detail($reportContext, 'balances', $later)['query']->sole()->amount);
        $this->actingAs($c['reviewer'])->get(app(ManagementReportDetailQuery::class)->sourceLink('benefits', $benefit))->assertOk();
        $this->get(route('reports.detail', ['type' => 'benefits'] + $this->filters()))->assertOk()->assertSee(__('financial.events.CAPTAIN_BONUS_DUE'));
        $expense = $this->benefitContext('EXPENSE', true);
        $this->benefitAction($expense, 'SUBMIT');
        $this->benefitAction($expense, 'APPROVE');
        $this->assertSame('CAPTAIN_EXPENSE_REIMBURSEMENT_DUE', $this->detail($reportContext, 'benefits', ['branch_id' => $expense['branch']->id])['query']->sole()->event_type);
    }

    public function test_creation_and_finalization_use_damascus_boundaries_and_never_count_duplicate_history(): void
    {
        $c = $this->sharedContext(2, 1);
        $before = $this->sharedContext(2, 1);
        $after = $this->sharedContext(2, 1);
        DB::table('orders')->where('id', $c['order']->id)->update(['created_at' => '2026-10-07 21:00:00']);
        DB::table('orders')->where('id', $before['order']->id)->update(['created_at' => '2026-10-07 20:59:59']);
        DB::table('orders')->where('id', $after['order']->id)->update(['created_at' => '2026-10-08 21:00:00']);
        app(OrderCancellationService::class)->cancel($c['admin'], $c['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::Other, 'Fixture');
        $c['order']->statusHistory()->create(['new_status' => 'CANCELLED', 'changed_at' => now()->addMinute(), 'changed_by' => $c['admin']->id]);
        $row = $this->detail($c, 'created')['query']->sole();
        $this->assertSame($c['order']->id, $row->id);
        $this->assertSame('2026-10-08 00:00:00+03:00', app(ManagementReportDetailQuery::class)->cell($row, 'created_at'));
        $this->assertSame(1, $this->detail($c, 'cancelled')['query']->count());
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $this->assertSame($row->id, $this->detail($c, 'created')['query']->sole()->id);
        $this->actingAs($c['admin']);
        $this->assertCount(2, $this->csv('cancelled'));
        $this->getJson(route('reports.export', ['type' => 'created'] + $this->filters(['from' => '2026-01-01', 'to' => '2027-10-01'])))->assertUnprocessable();
    }

    public function test_uninstalled_financial_source_is_unavailable_and_not_a_zero_or_empty_download(): void
    {
        $c = $this->sharedContext();
        DB::statement('ALTER TABLE shop_commission_accounts RENAME TO held_shop_commission_accounts');
        DB::statement('ALTER TABLE captain_benefit_reviews RENAME TO held_captain_benefit_reviews');
        try {
            $this->actingAs($c['admin']);
            foreach (['commissions', 'commission_balances', 'benefits'] as $type) {
                $this->assertNull($this->detail($c, $type)['query']);
                $this->get(route('reports.detail', ['type' => $type] + $this->filters()))->assertOk()->assertSee(__('reports.unavailable'))->assertDontSee(__('reports.export_csv'));
                $this->get(route('reports.export', ['type' => $type] + $this->filters()))->assertStatus(503);
            }
        } finally {
            DB::statement('ALTER TABLE held_captain_benefit_reviews RENAME TO captain_benefit_reviews');
            DB::statement('ALTER TABLE held_shop_commission_accounts RENAME TO shop_commission_accounts');
        }
    }

    public function test_detail_is_paginated_export_has_all_matching_rows_and_preserves_filters(): void
    {
        $c = $this->sharedContext();
        $filters = $this->filters(['branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id]);
        // Cheap valid Order fixtures; no additional accounting facts or Captain assignments needed.
        for ($index = 0; $index < 26; $index++) {
            app(OrderService::class)->create($c['admin'], ['branch_id' => $c['branch']->id,
                'customer_id' => $c['order']->customer_id, 'customer_phone_id' => $c['order']->customer_phone_id,
                'customer_address_id' => $c['order']->customer_address_id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        }
        $query = $this->detail($c, 'created', $filters)['query'];
        $page = $query->paginate(25);
        $this->assertSame(27, $page->total());
        $this->assertCount(25, $page->items());
        $this->actingAs($c['admin']);
        $response = $this->get(route('reports.detail', ['type' => 'created', 'page' => 2] + $filters))->assertOk();
        $response->assertSee(route('reports.export', ['type' => 'created'] + $filters))
            ->assertSee(route('reports.index', $filters));
        $this->assertCount(28, $this->csv('created', $filters));
        // Force multiple chunks without manufacturing 500 business records; stable ordering is still exercised.
        $this->assertSame($query->get()->pluck('id')->all(), $query->lazy(2)->pluck('id')->all());
    }
}
