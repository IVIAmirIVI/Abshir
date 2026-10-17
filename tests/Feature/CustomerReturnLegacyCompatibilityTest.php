<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Services\CustomerRefundService;
use App\Services\CustomerReturnService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\MixedCustomerCreditService;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccountingV1Fixtures;
use Tests\Support\CustomerReturnFixtures;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class CustomerReturnLegacyCompatibilityTest extends TestCase
{
    use AccountingV1Fixtures, CustomerReturnFixtures;

    public function test_pre_activation_mixed_party_redemption_keeps_its_funding_history_and_retry_identity(): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        $original = config('database.connections.pgsql.search_path');
        $schema = 'mc_ret_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config(['database.connections.pgsql.search_path' => $schema]);
            DB::purge('pgsql');
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_10_16_000000'));
            Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]);
            $c = $this->sold(deliveryFee: '100');
            $time = now()->toImmutable();
            $next = $this->sold(collect: false, deliveryFee: '100', master: $c);
            $this->travelTo($time->addSecond());
            $data = $this->data($c) + ['compensation_method' => 'CREDIT'];
            $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][0]->id, 'amount' => '50'], ['party' => 'COMPANY', 'amount' => '30']];
            $case = app(CustomerReturnService::class)->record($c['actor'], $c['order'], $data);
            $grant = DB::table('customer_credit_entries')->sole();
            $payment = ['request_key' => (string) str()->uuid(), 'allocations' => [$grant->id => '80'],
                'actual_amount' => (string) BigDecimal::of($next['invoice']->grand_total)->minus('80'), 'collected_at' => now()->toIso8601String()];
            $service = app(MixedCustomerCreditService::class);
            $collection = $service->record($next['actor'], $next['invoice'], InvoiceCollectionMethod::Cash, $payment);
            $before = (array) DB::table('customer_credit_redemptions')->sole();
            $financial = DB::table('financial_entries')->orderBy('id')->get()->toJson();

            Artisan::call('migrate', ['--force' => true]);

            $after = (array) DB::table('customer_credit_redemptions')->sole();
            $this->assertSame(1, (int) $after['attribution_version']);
            $this->assertSame($before, array_intersect_key($after, $before));
            $this->assertSame($financial, DB::table('financial_entries')->orderBy('id')->get()->toJson());
            $this->assertSame('50.0000', app(CustomerReturnService::class)->summary($c['actor'], $c['order'])->sole()->responsibilities->firstWhere('party', 'CAPTAIN')->funded_amount);
            $this->assertSame($collection->id, $service->record($next['actor'], $next['invoice'], InvoiceCollectionMethod::Cash, $payment)->id);
            $this->assertDatabaseCount('customer_credit_responsibility_attributions', 0);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('customer_credit_redemptions')->update(['attribution_version' => 2])), QueryException::class);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            TestDatabaseSafety::assertSafe($this->app);
            DB::purge('pgsql');
            config(['database.connections.pgsql.search_path' => $original]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }

    public function test_existing_return_installments_remain_immutable_and_valid_after_the_forward_migrations(): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        $original = config('database.connections.pgsql.search_path');
        $schema = 'mc_ret_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config(['database.connections.pgsql.search_path' => $schema]);
            DB::purge('pgsql');
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_10_16_000000'));
            Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]);
            $c = $this->sold(productAmount: '200');
            $data = $this->data($c, '1', '200');
            $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][0]->id, 'amount' => '200']];
            $service = app(CustomerReturnService::class);
            $case = $service->record($c['actor'], $c['order'], $data);
            $service->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('historical installment'));
            $history = DB::table('customer_return_movements')->orderBy('id')->get()->toJson();
            $financial = DB::table('financial_entries')->orderBy('id')->get()->toJson();

            Artisan::call('migrate', ['--force' => true]);

            $this->assertSame(1, (int) DB::table('customer_return_cases')->where('id', $case->id)->value('compensation_contract_version'));
            $this->assertSame($history, DB::table('customer_return_movements')->orderBy('id')->get()->toJson());
            $this->assertSame($financial, DB::table('financial_entries')->orderBy('id')->get()->toJson());
            $this->assertSame('80.0000', $service->summary($c['actor'], $c['order'])->sole()->responsibilities->sole()->funded_amount);
            $service->move($c['actor'], $case, $this->movement($c, '120'), $this->proof('complete historical installment'));
            $this->assertSame('200.0000', $service->captainLiabilities($c['actor'], $c['captains'][0])->sole()->outstanding);
            $this->assertDatabaseCount('customer_return_movements', 2);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            TestDatabaseSafety::assertSafe($this->app);
            DB::purge('pgsql');
            config(['database.connections.pgsql.search_path' => $original]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }

    public function test_historical_v1_survives_activation_and_new_and_old_refund_paths_cannot_be_mixed(): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        $original = config('database.connections.pgsql.search_path');
        $schema = 'mc_ret_'.bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config(['database.connections.pgsql.search_path' => $schema]);
            DB::purge('pgsql');
            $this->migrateAccountingV1Schema();
            $c = $this->sold(enableV3: false);
            $other = $this->sold(enableV3: false, method: InvoiceCollectionMethod::Cash);
            Artisan::call('migrate', ['--force' => true]);
            $this->assertSame(1, (int) $c['order']->fresh()->accounting_contract_version);
            $proposal = fn ($ctx) => ['items' => [['invoice_item_id' => $ctx['invoice']->items()->sole()->id, 'unit_price' => '40']],
                'delivery' => [['order_captain_id' => $ctx['assignments'][0]->id, 'customer_amount' => '10']]];
            $s = app(CustomerReturnService::class);
            $data = $this->data($c);
            $data['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $c['assignments'][0]->id, 'amount' => '80']];
            $case = $s->record($c['actor'], $c['order'], $data);
            try {
                app(InvoiceFinancialCorrectionService::class)->request($c['actor'], $c['invoice'], InvoiceFinancialCorrectionKind::Reprice,
                    $proposal($c), 'Reprice', 'No mixed sources after case creation', (string) str()->uuid());
                $this->fail('Legacy correction after an unpaid RET case must fail.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
                $this->assertStringContainsString('RET case already exists', $e->getMessage());
            }
            $s->move($c['actor'], $case, $this->movement($c, '80'), $this->proof('new compensation'));
            $s->move($c['actor'], $case, $this->movement($c, '20', 'RECOVERY') + ['responsibility_id' => $case->responsibilities()->sole()->id], $this->proof('actual recovered money'));
            try {
                app(InvoiceFinancialCorrectionService::class)->request($c['actor'], $c['invoice'], InvoiceFinancialCorrectionKind::Reprice,
                    $proposal($c), 'Reprice', 'No duplicate compensation', (string) str()->uuid());
                $this->fail('Legacy correction after RET compensation must fail.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
                $this->assertStringContainsString('RET case already exists', $e->getMessage());
            }
            $correction = app(InvoiceFinancialCorrectionService::class)->request($other['actor'], $other['invoice'], InvoiceFinancialCorrectionKind::Reprice,
                $proposal($other), 'Reprice', 'Legitimate old path before new payment', (string) str()->uuid());
            $otherData = $this->data($other);
            $otherData['responsibilities'] = [['party' => 'CAPTAIN', 'order_captain_id' => $other['assignments'][0]->id, 'amount' => '80']];
            $this->assertThrows(fn () => $s->record($other['actor'], $other['order'], $otherData), ValidationException::class);
            $prepared = app(InvoiceFinancialCorrectionService::class)->prepare($other['admin'], $correction);
            app(CustomerRefundService::class)->record($other['admin'], $prepared, (string) str()->uuid(), now(), 'LEGACY-REAL-REFUND', $this->proof('legacy cash refund'));
            $this->assertThrows(fn () => $s->record($other['actor'], $other['order'], $otherData), ValidationException::class);
            $this->assertDatabaseCount('customer_return_cases', 1);
            $this->assertDatabaseCount('customer_refunds', 1);
            $this->assertDatabaseCount('customer_return_movements', 2);
            $this->assertDatabaseCount('invoice_financial_corrections', 1);
        } finally {
            TestDatabaseSafety::assertSafe($this->app);
            DB::purge('pgsql');
            config(['database.connections.pgsql.search_path' => $original]);
            DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
        }
    }
}
