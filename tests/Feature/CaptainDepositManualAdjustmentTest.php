<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Exceptions\FinancialIdempotencyConflictException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainDepositManualAdjustment;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBranchService;
use App\Services\CaptainDepositManualAdjustmentEvidenceService;
use App\Services\CaptainDepositManualAdjustmentService;
use App\Services\FinancialAccountService;
use App\Services\FinancialPostingService;
use App\Support\UiLabels;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ManualDepositFixtures;
use Tests\TestCase;

class CaptainDepositManualAdjustmentTest extends TestCase
{
    use ManualDepositFixtures, RefreshDatabase;

    public static function directions(): array
    {
        return [['INCREASE', '35.0000'], ['DECREASE', '-15.0000']];
    }

    #[DataProvider('directions')]
    public function test_exact_deposit_math_can_be_negative_and_preserves_physical_receipt(string $direction, string $balance): void
    {
        $f = $this->manualFixture();
        $receipt = $this->deposit($f, '10');
        $history = DB::table('captain_deposit_receipts')->get()->all();
        $a = $this->adjust($f, $this->manualData('25', $direction));
        $this->assertSame($balance, $this->depositBalance($f));
        $this->assertEquals($history, DB::table('captain_deposit_receipts')->get()->all());
        $t = FinancialTransaction::where('source_type', 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT')->sole();
        $this->assertSame('CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_'.$direction, $t->event_type);
        $this->assertSame((string) $a->id, $t->source_id);
        $this->assertCount(1, $t->entries);
        $this->assertSame(FinancialAccountKind::CaptainDeposit, $t->entries->sole()->account->account_kind);
        $this->assertSame('25.0000', $t->entries->sole()->amount);
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertDatabaseCount('captain_receivable_recoveries', 0);
        $this->assertDatabaseCount('customer_refunds', 0);
        $this->assertDatabaseMissing('financial_accounts', ['account_kind' => 'CAPTAIN_CASH_CUSTODY']);
        $this->assertDatabaseMissing('financial_accounts', ['account_kind' => 'CAPTAIN_COMPANY_RECEIVABLE']);
        $this->actingAs($f['admin'])->get(route('captains.financials.show', $f['captain']))
            ->assertOk()->assertSee(__('financial.terms.manual_deposit_adjustment'))->assertSee(UiLabels::label('financial.directions', $direction))->assertSee($a->reason)->assertSee($balance);
    }

    public function test_branches_and_currencies_stay_independent_after_real_transfer(): void
    {
        $f = $this->manualFixture();
        $b = Branch::factory()->create(['default_currency_id' => $f['currency']->id]);
        $this->deposit($f, '100');
        app(CaptainBranchService::class)->transfer($f['admin'], $f['captain'], ['branch_id' => $b->id, 'starts_at' => now(), 'reason' => 'Transfer']);
        $other = array_replace($f, ['branch' => $b]);
        $f['employee']->branches()->attach($b);
        $this->deposit($other, '50');
        $this->adjust($f, $this->manualData('20', 'DECREASE'));
        $this->assertSame('80.0000', $this->depositBalance($f));
        $this->assertSame('50.0000', $this->depositBalance($other));
        $currency = Currency::create(['name' => 'JPY', 'code' => 'JPY', 'decimal_places' => 0]);
        $j = array_replace($f, ['currency' => $currency]);
        $this->adjust($j, $this->manualData('7'));
        $this->assertSame('7.0000', $this->depositBalance($j));
        $this->assertSame('80.0000', $this->depositBalance($f));
    }

    public static function invalidInputs(): array
    {
        return array_map(fn ($amount) => [['amount' => $amount]], ['0', '-1', 'NaN', 'Infinity', '-Infinity', '1.001']);
    }

    #[DataProvider('invalidInputs')]
    public function test_service_rejects_invalid_money_without_sources_or_evidence(array $invalid): void
    {
        $f = $this->manualFixture();
        $this->reject(fn () => $this->adjust($f, array_replace($this->manualData(), $invalid)), ValidationException::class);
        $this->assertDatabaseCount('captain_deposit_manual_adjustments', 0);
        $this->assertSame([], Storage::disk(CaptainDepositManualAdjustmentEvidenceService::DISK)->allFiles());
    }

    public function test_reason_branch_and_evidence_are_required_by_service_and_http(): void
    {
        $f = $this->manualFixture();
        foreach (['', '   ', "\t\n"] as $reason) {
            $this->reject(fn () => $this->adjust($f, array_replace($this->manualData(), ['reason' => $reason])), ValidationException::class);
        }
        $unrelated = array_replace($f, ['branch' => Branch::factory()->create()]);
        $this->reject(fn () => $this->adjust($unrelated), ValidationException::class);
        $route = route('captains.manual-deposit-adjustments.store', $f['captain']);
        $this->actingAs($f['admin'])->post($route, $this->manualData() + ['branch_id' => $f['branch']->id, 'currency_id' => $f['currency']->id])->assertSessionHasErrors('evidence');
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"></svg>', '<?php echo 1;', '<html>Bad</html>', str_repeat('x', 5242881)] as $bytes) {
            $this->reject(fn () => app(CaptainDepositManualAdjustmentService::class)->record($f['admin'], $f['captain'], $f['branch'], $f['currency'], $this->manualData(), UploadedFile::fake()->createWithContent('proof.png', $bytes)), ValidationException::class);
        }
        $this->assertDatabaseCount('captain_deposit_manual_adjustments', 0);
    }

    public function test_permission_grants_cannot_allow_employee_captain_or_arbitrary_user(): void
    {
        $f = $this->manualFixture();
        $captainUser = $f['captain']->user;
        $captainUser->roles()->sync([Role::where('code', 'CAPTAIN')->sole()->id]);
        // Even mistakenly granting the permission cannot bypass the explicit role contract.
        Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->attach(Permission::where('code', CaptainDepositManualAdjustmentService::PERMISSION)->sole());
        foreach ([$f['employee'], $captainUser, User::factory()->create()] as $actor) {
            $this->actingAs($actor)->post(route('captains.manual-deposit-adjustments.store', $f['captain']), [])->assertForbidden();
            $this->reject(fn () => app(CaptainDepositManualAdjustmentService::class)->record($actor, $f['captain'], $f['branch'], $f['currency'], $this->manualData(), $this->manualProof()), $actor->is($f['employee']) ? HttpException::class : AuthorizationException::class);
        }
        auth()->logout();
        $this->post(route('captains.manual-deposit-adjustments.store', $f['captain']), [])->assertRedirect(route('login'));
    }

    public function test_private_evidence_nested_authorization_integrity_and_safe_retry(): void
    {
        $f = $this->manualFixture();
        $data = $this->manualData();
        $a = $this->adjust($f, $data);
        $this->assertSame($a->id, $this->adjust($f, $data)->id);
        $this->reject(fn () => $this->adjust($f, array_replace($data, ['reason' => 'Different'])), FinancialIdempotencyConflictException::class);
        $this->assertCount(1, Storage::disk(CaptainDepositManualAdjustmentEvidenceService::DISK)->allFiles());
        $this->assertDatabaseCount('financial_transactions', 1);
        $route = route('captains.manual-deposit-adjustments.evidence', [$f['captain'], $a]);
        $this->actingAs($f['admin'])->get($route)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($f['employee'])->get($route)->assertForbidden();
        $this->actingAs($f['captain']->user)->get($route)->assertForbidden();
        $this->actingAs($f['admin'])->get(route('captains.manual-deposit-adjustments.evidence', [Captain::factory()->create(), $a]))->assertNotFound();
        $root = config('filesystems.disks.'.CaptainDepositManualAdjustmentEvidenceService::DISK.'.root');
        foreach (['local', 'public'] as $name) {
            $this->assertFalse(str_starts_with(strtolower($root), strtolower(config('filesystems.disks.'.$name.'.root')).DIRECTORY_SEPARATOR));
        }
        $this->assertFalse(config('filesystems.disks.'.CaptainDepositManualAdjustmentEvidenceService::DISK.'.serve'));
        $this->reject(fn () => Storage::disk(CaptainDepositManualAdjustmentEvidenceService::DISK)->temporaryUrl($a->evidence_path, now()->addMinute()), \RuntimeException::class);
        Storage::disk(CaptainDepositManualAdjustmentEvidenceService::DISK)->put($a->evidence_path, 'tampered');
        $this->actingAs($f['admin'])->get($route)->assertStatus(409);
    }

    public function test_direct_sql_source_mutation_and_generic_reversal_are_rejected(): void
    {
        $f = $this->manualFixture();
        $a = $this->adjust($f);
        foreach (['amount' => '1', 'reason' => 'Rewritten', 'branch_id' => Branch::factory()->create()->id, 'captain_id' => Captain::factory()->create()->id,
            'currency_id' => Currency::create(['name' => 'EUR', 'code' => 'EUR', 'decimal_places' => 2])->id, 'direction' => 'DECREASE',
            'adjusted_by_user_id' => $f['employee']->id, 'occurred_at' => now()->subDay(), 'request_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'evidence_sha256' => str_repeat('a', 64), 'evidence_path' => 'proof/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.png'] as $key => $value) {
            $this->reject(fn () => DB::table('captain_deposit_manual_adjustments')->where('id', $a->id)->update([$key => $value]), QueryException::class);
        }
        $this->reject(fn () => DB::table('captain_deposit_manual_adjustments')->where('id', $a->id)->delete(), QueryException::class);
        $t = FinancialTransaction::sole();
        $this->reject(fn () => app(FinancialPostingService::class)->reverse($t, 'TEST', '1', 'UNDO', 1, now(), 'Wrong', null, $f['admin']), FinancialIdempotencyConflictException::class);
        $raw = (array) DB::table('financial_transactions')->where('id', $t->id)->first();
        unset($raw['id']);
        $raw['source_type'] = 'TEST';
        $raw['source_id'] = '1';
        $raw['event_type'] = 'UNDO';
        $raw['transaction_kind'] = 'REVERSAL';
        $raw['status'] = 'DRAFT';
        $raw['posted_at'] = null;
        $raw['reversal_of_transaction_id'] = $t->id;
        $this->reject(fn () => DB::table('financial_transactions')->insert($raw), QueryException::class);
    }

    public static function badSqlAmounts(): array
    {
        return array_map(fn ($a) => [$a], ['0', '-1', 'NaN', 'Infinity', '-Infinity', '1.001']);
    }

    #[DataProvider('badSqlAmounts')]
    public function test_postgresql_rejects_invalid_money_without_typmod_rounding(string $amount): void
    {
        $f = $this->manualFixture();
        $a = $this->adjust($f);
        $raw = (array) DB::table('captain_deposit_manual_adjustments')->where('id', $a->id)->first();
        unset($raw['id']);
        $raw['amount'] = $amount;
        $raw['request_key'] = (string) Str::uuid();
        $this->reject(function () use ($raw) {
            $raw['payload_hash'] = DB::scalar('SELECT manual_deposit_hash(?::jsonb)', [json_encode($raw)]);
            DB::table('captain_deposit_manual_adjustments')->insert($raw);
        }, QueryException::class);
        $this->assertSame(-1, (int) DB::scalar("SELECT atttypmod FROM pg_attribute WHERE attrelid='captain_deposit_manual_adjustments'::regclass AND attname='amount'"));
    }

    public function test_exact_complete_posting_blocks_missing_extra_and_wrong_financial_facts(): void
    {
        $f = $this->manualFixture();
        $a = $this->adjust($f);
        $t = FinancialTransaction::sole();
        $raw = (array) DB::table('financial_transactions')->where('id', $t->id)->first();
        unset($raw['id']);
        foreach (['event_type' => 'CAPTAIN_DEPOSIT_MANUAL_ADJUSTMENT_DECREASE', 'source_type' => 'ARBITRARY', 'source_version' => 2,
            'currency_id' => Currency::create(['name' => 'EUR', 'code' => 'EUR', 'decimal_places' => 2])->id, 'branch_id' => Branch::factory()->create()->id,
            'recorded_by_user_id' => $f['employee']->id, 'reason' => 'Changed', 'source_id' => '999999'] as $key => $value) {
            $this->reject(fn () => DB::table('financial_transactions')->insert(array_replace($raw, [$key => $value])), QueryException::class);
        }
        $this->reject(fn () => DB::table('financial_transactions')->insert($raw), QueryException::class);
        $source = (array) DB::table('captain_deposit_manual_adjustments')->where('id', $a->id)->first();
        unset($source['id']);
        $source['request_key'] = (string) Str::uuid();
        $this->reject(function () use ($source) {
            $source['payload_hash'] = DB::scalar('SELECT manual_deposit_hash(?::jsonb)', [json_encode($source)]);
            DB::table('captain_deposit_manual_adjustments')->insert($source);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, QueryException::class);
        $accounts = app(FinancialAccountService::class);
        $owner = $accounts->ownerForCaptain($f['captain']);
        foreach ([FinancialAccountKind::CaptainCompanyReceivable, FinancialAccountKind::CaptainCashCustody] as $kind) {
            $account = $accounts->getOrCreate($owner, $f['currency'], $kind);
            $this->reject(function () use ($t, $account, $f) {
                DB::table('financial_entries')->insert(['financial_transaction_id' => $t->id, 'financial_account_id' => $account->id, 'currency_id' => $f['currency']->id, 'direction' => 'INCREASE', 'amount' => '1', 'created_at' => now()]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }, QueryException::class);
        }
    }

    public function test_real_http_creation_keeps_intentional_reason_and_denies_source_substitution(): void
    {
        $f = $this->manualFixture();
        $route = route('captains.manual-deposit-adjustments.store', $f['captain']);
        $data = $this->manualData() + ['branch_id' => $f['branch']->id, 'currency_id' => $f['currency']->id, 'evidence' => $this->manualProof()];
        $this->actingAs($f['admin'])->post($route, $data + ['account_kind' => 'CAPTAIN_COMPANY_RECEIVABLE'])->assertSessionHasErrors('account_kind');
        $this->post($route, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('25.0000', $this->depositBalance($f));
        $this->assertSame($data['reason'], CaptainDepositManualAdjustment::sole()->reason);
    }

    public function test_evidence_is_cleaned_when_posting_fails_and_no_financial_source_survives(): void
    {
        $f = $this->manualFixture();
        $posting = \Mockery::mock(FinancialPostingService::class)->makePartial();
        $posting->shouldReceive('postEvent')->once()->andThrow(new \RuntimeException('Injected posting failure'));
        $this->app->instance(FinancialPostingService::class, $posting);
        $this->reject(fn () => $this->adjust($f), \RuntimeException::class);
        $this->assertDatabaseCount('captain_deposit_manual_adjustments', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame([], Storage::disk(CaptainDepositManualAdjustmentEvidenceService::DISK)->allFiles());
    }

    public static function malformedSets(): array
    {
        return [['wrong_amount'], ['wrong_direction'], ['wrong_captain'], ['wrong_currency'], ['receivable'], ['custody'], ['extra_entry'], ['missing_entry']];
    }

    #[DataProvider('malformedSets')]
    public function test_postgresql_rejects_fresh_source_with_inexact_required_entry_set(string $fault): void
    {
        $f = $this->manualFixture();
        $a = $this->adjust($f);
        $t = FinancialTransaction::sole();
        $source = (array) DB::table('captain_deposit_manual_adjustments')->where('id', $a->id)->first();
        unset($source['id']);
        $transaction = (array) DB::table('financial_transactions')->where('id', $t->id)->first();
        unset($transaction['id']);
        $transaction['status'] = 'DRAFT';
        $transaction['posted_at'] = null;
        $entry = (array) DB::table('financial_entries')->where('financial_transaction_id', $t->id)->sole();
        unset($entry['id']);
        $accounts = app(FinancialAccountService::class);
        $owner = $accounts->ownerForCaptain($f['captain']);
        $extra = $accounts->getOrCreate($owner, $f['currency'], FinancialAccountKind::CaptainCashCustody);
        if ($fault === 'wrong_amount') {
            $entry['amount'] = '24';
        }
        if ($fault === 'wrong_direction') {
            $entry['direction'] = 'DECREASE';
        }
        if (in_array($fault, ['receivable', 'custody'])) {
            $entry['financial_account_id'] = $accounts->getOrCreate($owner, $f['currency'], $fault === 'custody' ? FinancialAccountKind::CaptainCashCustody : FinancialAccountKind::CaptainCompanyReceivable)->id;
        }
        if ($fault === 'wrong_captain') {
            $entry['financial_account_id'] = $accounts->getOrCreate($accounts->ownerForCaptain(Captain::factory()->create()), $f['currency'], FinancialAccountKind::CaptainDeposit)->id;
        }
        if ($fault === 'wrong_currency') {
            $c = Currency::create(['name' => 'EUR', 'code' => 'EUR', 'decimal_places' => 2]);
            $entry['financial_account_id'] = $accounts->getOrCreate($owner, $c, FinancialAccountKind::CaptainDeposit)->id;
            $entry['currency_id'] = $c->id;
        }
        $this->reject(function () use ($source, $transaction, $entry, $fault, $extra) {
            $source['request_key'] = (string) Str::uuid();
            $source['payload_hash'] = DB::scalar('SELECT manual_deposit_hash(?::jsonb)', [json_encode($source)]);
            $id = DB::table('captain_deposit_manual_adjustments')->insertGetId($source);
            $transaction['source_id'] = (string) $id;
            $transaction['notes'] = $source['payload_hash'];
            $tid = DB::table('financial_transactions')->insertGetId($transaction);
            $entry['financial_transaction_id'] = $tid;
            if ($fault !== 'missing_entry') {
                DB::table('financial_entries')->insert($entry);
            }
            if ($fault === 'extra_entry') {
                $entry['financial_account_id'] = $extra->id;
                DB::table('financial_entries')->insert($entry);
            }
            DB::table('financial_transactions')->where('id', $tid)->update(['status' => 'POSTED', 'posted_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, QueryException::class);
        $this->assertDatabaseCount('captain_deposit_manual_adjustments', 1);
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_database_actor_and_source_hash_cannot_be_forged(): void
    {
        $f = $this->manualFixture();
        $a = $this->adjust($f);
        $raw = (array) DB::table('captain_deposit_manual_adjustments')->where('id', $a->id)->first();
        unset($raw['id']);
        foreach (['adjusted_by_user_id' => $f['employee']->id, 'branch_id' => Branch::factory()->create()->id, 'reason' => '   ', 'occurred_at' => now()->addDay()->toIso8601String()] as $key => $value) {
            $this->reject(function () use ($raw, $key, $value) {
                $raw[$key] = $value;
                $raw['request_key'] = (string) Str::uuid();
                $raw['payload_hash'] = DB::scalar('SELECT manual_deposit_hash(?::jsonb)', [json_encode($raw)]);
                DB::table('captain_deposit_manual_adjustments')->insert($raw);
            }, QueryException::class);
        }
        $raw['request_key'] = (string) Str::uuid();
        $raw['payload_hash'] = str_repeat('0', 64);
        $this->reject(fn () => DB::table('captain_deposit_manual_adjustments')->insert($raw), QueryException::class);
    }

    private function reject(callable $action, string $class): void
    {
        try {
            DB::transaction($action);
            $this->fail('Invalid operation succeeded.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());
        }
    }
}
