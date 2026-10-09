<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\OrderCancellationReason;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainReceivableRecovery;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBranchService;
use App\Services\CaptainReceivableRecoveryEvidenceService;
use App\Services\CaptainReceivableRecoveryService;
use App\Services\FinancialBalanceQueryService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\OrderCancellationFinalizationService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderShopReturnService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
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
use Tests\Support\ReceivableDispositionFixtures;
use Tests\TestCase;

class CaptainReceivableRecoveryTest extends TestCase
{
    use ReceivableDispositionFixtures,RefreshDatabase;

    public static function overpayments(): array
    {
        return [['70', '10', '30'], ['100', '40', '0']];
    }

    #[DataProvider('overpayments')]
    public function test_exact_cash_recovery_then_finalization_preserves_all_historical_money(string $p, string $k, string $u): void
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), $p);
        $history = $this->history();
        $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
        $dBefore = DB::table('invoice_correction_receivable_dispositions')->get()->all();
        $this->blocked(fn () => app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c));
        $transactions = DB::table('financial_transactions')->count();
        $r = $this->record($f, $c);
        $this->assertTrue(BigDecimal::of($r->amount)->isEqualTo($k));
        $this->assertSame($f['employee']->id, $r->received_by_user_id);
        $this->assertSame('CASH', $r->method);
        $this->assertSame($transactions, DB::table('financial_transactions')->count());
        $this->assertSame('READY_TO_FINALIZE', $c->fresh()->state->value);
        $this->assertEquals($dBefore, DB::table('invoice_correction_receivable_dispositions')->get()->all());
        $c = app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->assertSame('COMPLETED', $c->state->value);
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame($u === '0' ? 0 : 1, DB::table('financial_transactions')->where('source_type', 'INVOICE_CORRECTION_RECEIVABLE_DISPOSITION')->count());
        $this->assertEquals($history, $this->history());
        $this->assertDatabaseCount('customer_refunds', 1);
        $this->assertDatabaseCount('captain_deposit_receipts', 0);
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCashCustody));
        $this->blocked(fn () => $this->record($f, $c));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($s) => [$s], ['10', '39.99', '41', '0', '-1', 'NaN', 'Infinity', '40.0001']);
    }

    #[DataProvider('invalidAmounts')]
    public function test_partial_excess_and_invalid_money_are_rejected(string $amount): void
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '100');
        $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
        $this->blocked(fn () => $this->record($f, $c, ['amount' => $amount]));
        $this->assertDatabaseCount('captain_receivable_recoveries', 0);
        $this->assertSame([], Storage::disk(CaptainReceivableRecoveryEvidenceService::DISK)->allFiles());
    }

    public function test_retries_duplicate_submissions_and_receiver_source_tampering(): void
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '100');
        $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
        foreach ([['method' => 'SHAM_CASH'], ['branch_id' => 999], ['currency_id' => 999], ['received_by_user_id' => $f['admin']->id]] as $data) {
            $this->blocked(fn () => $this->record($f, $c, $data));
        }
        $this->assertForbidden(fn () => app(CaptainReceivableRecoveryService::class)->record($f['admin'], $c, $f['captain'], $this->data(), $this->proof()));
        $outsider = User::factory()->create();
        $outsider->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->assertForbidden(fn () => app(CaptainReceivableRecoveryService::class)->record($outsider, $c, $f['captain'], $this->data(), $this->proof()));
        $this->assertForbidden(fn () => app(CaptainReceivableRecoveryService::class)->record($f['captain']->user, $c, $f['captain'], $this->data(), $this->proof()));
        $data = $this->data();
        $r = $this->record($f, $c, $data);
        $this->assertSame($r->id, $this->record($f, $c, $data)->id);
        $this->blocked(fn () => $this->record($f, $c));
        $this->blocked(fn () => $this->record($f, $c, array_replace($data, ['reference' => 'CHANGED'])));
        $this->assertDatabaseCount('captain_receivable_recoveries', 1);
        $this->assertCount(1, Storage::disk(CaptainReceivableRecoveryEvidenceService::DISK)->allFiles());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_evidence_endpoint_is_private_nested_and_reauthorized_and_upload_is_mandatory(): void
    {
        $this->assertFalse(config('filesystems.disks.captain_receivable_recoveries.serve'));
        $root = str_replace('\\', '/', config('filesystems.disks.captain_receivable_recoveries.root'));
        foreach (config('filesystems.disks') as $disk) {
            if (($disk['serve'] ?? false) || isset($disk['url'])) {
                if (isset($disk['root'])) {
                    $this->assertFalse(str_starts_with($root, rtrim(str_replace('\\', '/', $disk['root']), '/').'/'));
                }
            }
        }
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '100');
        $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
        $url = route('captain-recoveries.store', [$c, $f['captain']]);
        $this->actingAs($f['employee'])->get(route('orders.invoices.show', [$f['order'], $f['invoice']]))->assertOk()->assertSee(__('financial.ui.record_repayment'))->assertSee(__('financial.ui.required'));
        $this->actingAs($f['admin'])->get(route('orders.invoices.show', [$f['order'], $f['invoice']]))->assertOk()->assertSee(__('financial.ui.finalization_awaits'))->assertDontSee(__('financial.ui.record_repayment'));
        $this->actingAs($f['employee'])->post($url, $this->data())->assertSessionHasErrors('evidence');
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"></svg>', '<?php echo 1;', '<html>proof</html>'] as $bad) {
            $this->blocked(fn () => app(CaptainReceivableRecoveryService::class)->record($f['employee'], $c, $f['captain'], $this->data(), UploadedFile::fake()->createWithContent('image.png', $bad)));
        }
        $this->blocked(fn () => app(CaptainReceivableRecoveryService::class)->record($f['employee'], $c, $f['captain'], $this->data(), UploadedFile::fake()->createWithContent('large.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=').str_repeat('a', 5 * 1024 * 1024))));
        $this->actingAs($f['employee'])->post($url, $this->data() + ['evidence' => $this->proof(), 'amount' => '40'])->assertSessionHasErrors('amount');
        $this->actingAs($f['employee'])->post($url, $this->data() + ['evidence' => $this->proof()])->assertRedirect();
        $r = CaptainReceivableRecovery::sole();
        $this->get(route('orders.invoices.show', [$f['order'], $f['invoice']]))->assertOk()->assertSee(__('financial.ui.received'))->assertSee($f['employee']->name);
        $this->get(route('captain-recoveries.evidence', [$c, $r]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->fixture('USD', $f);
        $otherC = $this->correction($other, '70');
        $this->get(route('captain-recoveries.evidence', [$otherC, $r]))->assertNotFound();
        $f['employee']->branches()->detach($f['branch']);
        $this->get(route('captain-recoveries.evidence', [$c, $r]))->assertForbidden();
        $this->actingAs($f['admin'])->get(route('captain-recoveries.evidence', [$c, $r]))->assertOk();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_no_recovery_for_zero_requirement_or_other_captain(): void
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '40');
        $c = $this->prepareAndRefund($f, $this->correction($f, '70'));
        $this->blocked(fn () => $this->record($f, $c));
        $this->blocked(fn () => app(CaptainReceivableRecoveryService::class)->record($f['employee'], $c, Captain::factory()->create(), $this->data(), $this->proof()));
        $this->assertDatabaseCount('captain_receivable_recoveries', 0);
    }

    public function test_two_captains_require_separate_receipts_and_never_offset_each_other(): void
    {
        $f = $this->fixture('USD', captainCount: 2);
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '80');
        $this->payout($f, $this->productEntry($f, $f['captains'][1]), '85', $f['captains'][1]);
        $c = $this->prepareAndRefund($f, $this->correction($f, '140'));
        $this->record($f, $c);
        $this->blocked(fn () => app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c));
        $r = app(CaptainReceivableRecoveryService::class)->record($f['employee'], $c, $f['captains'][1], $this->data(), $this->proof());
        $this->assertTrue(BigDecimal::of($r->amount)->isEqualTo('15'));
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->assertDatabaseCount('captain_receivable_recoveries', 2);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_recovery_and_allocation_sql_immutability_and_complete_set_guards(): void
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '100');
        $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
        $r = $this->record($f, $c);
        foreach (['amount' => '41', 'method' => 'SHAM_CASH', 'captain_id' => $f['captain']->id, 'branch_id' => $f['branch']->id,
            'currency_id' => $f['currency']->id, 'received_by_user_id' => $f['admin']->id, 'invoice_financial_correction_id' => $c->id,
            'evidence_sha256' => str_repeat('0', 64), 'payload_hash' => str_repeat('0', 64), 'request_key' => (string) Str::uuid(),
            'received_at' => now()->addMinute(), 'evidence_path' => 'proof/'.Str::uuid().'.png'] as $column => $value) {
            $this->invalidSQL(fn () => DB::table('captain_receivable_recoveries')->where('id', $r->id)->update([$column => $value]));
        }
        $this->invalidSQL(fn () => DB::table('captain_receivable_recoveries')->where('id', $r->id)->delete());
        $this->invalidSQL(fn () => DB::table('captain_receivable_recovery_allocations')->where('captain_receivable_recovery_id', $r->id)->update(['amount' => '39']));
        $this->invalidSQL(fn () => DB::table('captain_receivable_recovery_allocations')->where('captain_receivable_recovery_id', $r->id)->delete());
        $this->invalidSQL(fn () => DB::table('captain_receivable_recovery_allocations')->insert(['captain_receivable_recovery_id' => $r->id, 'disposition_id' => $c->receivableDispositions()->sole()->id, 'amount' => '1']));
        $copy = (array) DB::table('captain_receivable_recoveries')->sole();
        unset($copy['id']);
        $copy['request_key'] = (string) Str::uuid();
        $this->invalidSQL(fn () => DB::table('captain_receivable_recoveries')->insert($copy));
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->invalidSQL(fn () => DB::table('captain_receivable_recoveries')->insert($copy));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_one_receipt_covers_multiple_exact_excess_sources_and_unlocks_real_return_cancellation(): void
    {
        $f = $this->fixture('USD', captainPercent: '100', purchase: true);
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '70');
        $delivery = DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')->where('t.event_type', 'ORDER_SHAM_CASH_DELIVERY_ENTITLEMENT_DUE')->select('e.*')->sole();
        $this->payout($f, $delivery, '40');
        $history = $this->history();
        $c = app(InvoiceFinancialCorrectionService::class)->request($f['employee'], $f['invoice'], InvoiceFinancialCorrectionKind::CancelAndRefund, [], 'Cancel after paid purchase', 'Return goods', (string) Str::uuid());
        $c = $this->prepareAndRefund($f, $c);
        $this->actingAs($f['employee'])->get(route('orders.workflow', $f['order']))->assertOk()->assertSee(__('orders.ui_start_return'));
        app(OrderCancellationIntentService::class)->create($f['employee'], $f['order']->fresh(), OrderCancellationReason::CustomerCancelled, 'Return goods', (string) Str::uuid());
        $shop = $f['order']->shops()->sole();
        // PostgreSQL now() is fixed at the outer test transaction's start.
        $transactionTime = CarbonImmutable::parse(DB::scalar('SELECT CURRENT_TIMESTAMP'));
        app(OrderShopReturnService::class)->record($f['employee'], $f['order']->fresh(), $shop, [
            'returning_order_captain_id' => $f['order']->captainAssignments()->sole()->id,
            'refund_amount' => '100', 'returned_at' => $transactionTime->subSeconds(20)->format('Y-m-d H:i:s.uP'), 'refunded_at' => $transactionTime->subSeconds(10)->format('Y-m-d H:i:s.uP'),
            'refund_method' => 'CASH', 'request_key' => (string) Str::uuid()], ['RETURN_AND_REFUND_PROOF' => $this->proof()]);
        $this->blocked(fn () => app(OrderCancellationFinalizationService::class)->finalize($f['admin'], $f['order']->fresh()));
        $this->blocked(fn () => app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c));
        $receipt = $this->record($f, $c);
        $this->assertTrue(BigDecimal::of($receipt->amount)->isEqualTo('110'));
        $this->assertEqualsCanonicalizing(['70', '40'], $receipt->allocations()->pluck('amount')->map(fn ($a) => (string) BigDecimal::of($a)->strippedOfTrailingZeros())->all());
        $this->assertSame('DELIVERED', $f['order']->fresh()->operational_status->value);
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->assertSame('CANCELLED', $f['order']->fresh()->operational_status->value);
        $this->assertSame('COMPLETED', $c->fresh()->state->value);
        $this->assertDatabaseCount('customer_refunds', 1);
        $this->assertEquals($history, $this->history());
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance($f['captain'], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    protected function record(array $f, object $c, array $data = []): CaptainReceivableRecovery
    {
        return app(CaptainReceivableRecoveryService::class)->record($f['employee'], $c, $f['captain'], $data + $this->data(), $this->proof());
    }

    public function test_recovery_does_not_net_another_captains_residual_and_historical_branch_survives_transfer(): void
    {
        $f = $this->fixture('USD', captainCount: 2);
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '80');
        $this->payout($f, $this->productEntry($f, $f['captains'][1]), '50', $f['captains'][1]);
        $c = $this->prepareAndRefund($f, $this->correction($f, '140'));
        $newBranch = Branch::factory()->create(['default_currency_id' => $f['currency']->id]);
        // A real permanent transfer changes the current home Branch, never the financial source.
        app(CaptainBranchService::class)->transfer($f['admin'], $f['captain'], ['branch_id' => $newBranch->id, 'starts_at' => now(), 'reason' => 'Move after historical Order']);
        $r = $this->record($f, $c, ['amount' => '10.00']);
        $this->assertSame($f['branch']->id, $r->branch_id);
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->assertDatabaseCount('captain_receivable_recoveries', 1);
        $this->assertSame('20.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance($f['captains'][1], $f['branch'], $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', app(FinancialBalanceQueryService::class)->captainBranchBalance($f['captain'], $newBranch, $f['currency'], FinancialAccountKind::CaptainCompanyReceivable));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_post_storage_database_failure_cleans_evidence_and_successful_retry_is_durable(): void
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '100');
        $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
        $data = $this->data();
        DB::unprepared("CREATE FUNCTION recovery_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected post-evidence SQL failure' USING ERRCODE='23514'; END; $$; CREATE TRIGGER zz_test_failure BEFORE INSERT ON captain_receivable_recoveries FOR EACH ROW EXECUTE FUNCTION recovery_test_fail()");
        $this->invalidSQL(fn () => $this->record($f, $c, $data));
        $this->assertDatabaseCount('captain_receivable_recoveries', 0);
        $this->assertSame([], Storage::disk(CaptainReceivableRecoveryEvidenceService::DISK)->allFiles());
        DB::statement('DROP TRIGGER zz_test_failure ON captain_receivable_recoveries');
        DB::statement('DROP FUNCTION recovery_test_fail()');
        $r = $this->record($f, $c, $data);
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->assertSame($r->id, $this->record($f, $c, $data)->id);
        $this->assertCount(1, Storage::disk(CaptainReceivableRecoveryEvidenceService::DISK)->allFiles());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_hash_is_timezone_independent_and_receiver_history_is_not_reassigned(): void
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '100');
        $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
        $data = $this->data();
        $r = $this->record($f, $c, $data);
        DB::statement("SET LOCAL TIME ZONE 'Asia/Beirut'");
        DB::select('SELECT validate_captain_recovery(?)', [$r->id]);
        $this->assertSame($r->id, $this->record($f, $c, $data)->id);
        $f['employee']->branches()->detach();
        $f['employee']->roles()->detach();
        app(InvoiceFinancialCorrectionService::class)->finalize($f['admin'], $c);
        $this->assertSame($f['employee']->id, $r->fresh()->received_by_user_id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_private_root_is_physically_non_overlapping_and_has_no_automatic_temporary_url(): void
    {
        $disk = Storage::disk(CaptainReceivableRecoveryEvidenceService::DISK);
        $disk->makeDirectory('proof');
        $root = realpath($disk->path(''));
        $this->assertNotFalse($root);
        foreach (config('filesystems.disks') as $name => $configuration) {
            if ($name === CaptainReceivableRecoveryEvidenceService::DISK || ! isset($configuration['root']) || ! (($configuration['serve'] ?? false) || isset($configuration['url']))) {
                continue;
            }
            $other = Storage::disk($name);
            $other->makeDirectory('');
            $servedRoot = realpath($other->path(''));
            $this->assertNotFalse($servedRoot);
            $this->assertFalse(str_starts_with(strtolower($root.DIRECTORY_SEPARATOR), strtolower(rtrim($servedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)));
        }
        try {
            $disk->temporaryUrl('proof/'.Str::uuid().'.png', now()->addMinute());
            $this->fail('Private recovery disk generated a serving URL.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('temporary URLs', $e->getMessage());
        }
    }

    public function test_direct_sql_cannot_commit_missing_wrong_or_hidden_recovery_allocations(): void
    {
        $f = $this->fixture('USD');
        Storage::fake(CaptainReceivableRecoveryEvidenceService::DISK);
        $this->payout($f, $this->productEntry($f), '100');
        $c = $this->prepareAndRefund($f, $this->correction($f, '60'));
        $this->invalidSQL(fn () => DB::select('SELECT validate_correction_recoveries(?,true)', [$c->id]));
        $this->invalidSQL(fn () => DB::table('invoice_financial_corrections')->where('id', $c->id)->update(['state' => 'COMPLETED', 'finalized_by' => $f['admin']->id, 'finalized_at' => now()]));
        $r = $this->record($f, $c);
        $header = (array) DB::table('captain_receivable_recoveries')->where('id', $r->id)->first();
        unset($header['id']);
        $other = $this->fixture('USD', $f);
        $this->payout($other, $this->productEntry($other), '100');
        $otherC = $this->prepareAndRefund($other, $this->correction($other, '60'));
        $header['invoice_financial_correction_id'] = $otherC->id;
        $header['request_key'] = (string) Str::uuid();
        $header['received_at'] = now()->format('Y-m-d H:i:s.uP');
        foreach ([['amount' => '39.99'], ['amount' => '41'], ['received_by_user_id' => $f['admin']->id], ['method' => 'SHAM_CASH'], ['branch_id' => Branch::factory()->create()->id], ['amount' => 'NaN'], ['amount' => 'Infinity'], ['amount' => '0'], ['amount' => '-1'], ['amount' => '40.001']] as $bad) {
            $this->invalidSQL(fn () => DB::table('captain_receivable_recoveries')->insert($bad + $header));
        }
        $this->invalidSQL(fn () => DB::table('captain_receivable_recovery_allocations')->insert(['captain_receivable_recovery_id' => $r->id, 'disposition_id' => $otherC->receivableDispositions()->sole()->id, 'amount' => '40']));
        // A header with an exact valid hash still cannot commit an incomplete child set.
        $header['payload_hash'] = DB::scalar("SELECT encode(sha256(convert_to(captain_recovery_payload(jsonb_populate_record(NULL::captain_receivable_recoveries,?::jsonb))::text,'UTF8')),'hex')", [json_encode($header)]);
        $this->invalidSQL(fn () => DB::table('captain_receivable_recoveries')->insert($header));
        $this->assertDatabaseCount('captain_receivable_recoveries', 1);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    protected function data(): array
    {
        return ['request_key' => (string) Str::uuid(), 'received_at' => now()->format('Y-m-d H:i:s.uP'), 'reference' => 'CASH-RECOVERY'];
    }

    private function blocked(callable $action): void
    {
        try {
            $action();
            $this->fail('Unsafe recovery operation accepted.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function assertForbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('Unauthorized physical receiver accepted.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } catch (AuthorizationException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function invalidSQL(callable $action): void
    {
        try {
            DB::transaction(function () use ($action) {
                $action();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Invalid recovery SQL accepted.');
        } catch (QueryException|\PDOException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function history(): array
    {
        return [DB::table('captain_receivable_payouts')->get()->all(), DB::table('captain_receivable_payout_allocations')->get()->all(), DB::table('financial_transactions')->where('event_type', 'CAPTAIN_RECEIVABLE_PAID')->get()->all()];
    }
}
