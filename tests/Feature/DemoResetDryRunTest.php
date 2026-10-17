<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\CaptainDepositReceiptService;
use App\Support\DemoOrderReset\ResetInventory;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoResetDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_assessment_is_read_only_repeatable_and_keeps_independent_deposit_and_configuration(): void
    {
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->assertMatchesRegularExpression('/^mc_testing_[a-f0-9]{16}$/', DB::scalar('SELECT current_schema()'));
        $this->seed(AuthorizationSeeder::class);
        Storage::fake('captain_financial_evidence');
        $currency = Currency::create(['code' => 'SYP', 'name' => 'Syrian Pound', 'decimal_places' => 0]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        Customer::factory()->create();
        Shop::create(['name' => 'Demo shop', 'area_id' => Area::factory()->for($branch)->create()->id, 'business_classification' => 'SHOP']);
        app(CaptainDepositReceiptService::class)->record($actor, $captain, $branch, $currency,
            ['request_key' => (string) str()->uuid(), 'amount' => '5000', 'occurred_at' => now()->subMinute()->toIso8601String()],
            UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9XkAAAAASUVORK5CYII=')));
        $before = $this->state();
        $files = Storage::disk('captain_financial_evidence')->allFiles();
        $inspector = new ResetInventory;
        try {
            DB::transaction(fn () => DB::table('financial_entries')->delete());
            $this->fail('Financial immutability must remain active.');
        } catch (QueryException $exception) {
            $this->assertSame('55000', $exception->getCode());
        }
        DB::statement('SET LOCAL transaction_read_only=on');
        $report = $inspector->inspect(DB::connection()->getPdo(), true);
        $this->assertSame('on', $report['identity']['read_only']);
        $this->assertFalse($report['executed']);
        $this->assertSame([ResetInventory::FULL_BLOCKER], $report['blockers']);
        $this->assertSame(1, $report['candidate_table_counts']['captain_deposit_receipts']);
        $this->assertSame('5000.0000', collect($report['balances_before'])->firstWhere('account_kind', 'CAPTAIN_DEPOSIT')['balance']);
        $this->assertArrayHasKey('users', $report['preserved_table_counts']);
        $this->assertArrayHasKey('bill_payment_fee_rules', $report['preserved_table_counts']);
        $this->assertSame($before, $this->state());
        $this->assertSame($report, $inspector->inspect(DB::connection()->getPdo(), true));
        $this->assertSame($files, Storage::disk('captain_financial_evidence')->allFiles());
        $orderOnly = $inspector->inspect(DB::connection()->getPdo(), false);
        $this->assertArrayHasKey('captain_deposit_receipts', $orderOnly['preserved_table_counts']);
        $this->assertArrayHasKey('customers', $orderOnly['preserved_table_counts']);
        $this->assertSame([], $orderOnly['blockers']);
        $this->assertSame($before, $this->state());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction(), 'Caller retains its rollback-only fixture transaction.');
    }

    private function state(): array
    {
        $result = [];
        foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname=current_schema() ORDER BY tablename') as $table) {
            $name = '"'.str_replace('"', '""', $table->tablename).'"';
            $result[$table->tablename] = DB::scalar("SELECT md5(COALESCE(string_agg(h,'' ORDER BY h),'')) FROM (SELECT md5(to_jsonb(r)::text) AS h FROM {$name} r) hashes");
        }
        $result['guards'] = DB::scalar("SELECT md5(string_agg(pg_get_functiondef(t.tgfoid),'|' ORDER BY t.oid)) FROM pg_trigger t
            JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname=current_schema() AND NOT t.tgisinternal");

        return $result;
    }
}
