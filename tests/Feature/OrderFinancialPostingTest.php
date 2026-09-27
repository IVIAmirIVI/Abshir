<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\CorrectedOrderFinancialService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderFinancialPostingService;
use App\Services\OrderFinancialSourceSnapshotService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderFinancialPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_waits_for_delivery_then_posts_only_company_share_to_primary_custody(): void
    {
        $c = $this->context(2, '100.00');
        $invoice = $this->issue($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->markDelivered($c);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame(1, FinancialTransaction::count());
        $event = FinancialTransaction::sole();
        $this->assertSame(FinancialEventType::OrderCashCompanyDeliveryShareHeld->value, $event->event_type);
        $this->assertSame((string) $collection->id, $event->source_id);
        $deliveredAt = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->sole()->changed_at;
        $this->assertSame($deliveredAt->timestamp, $event->occurred_at->timestamp);
        $this->assertStringContainsString('"collection_method":"CASH"', $event->notes);
        $this->assertSame('3.5000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][1]->id, FinancialAccountKind::CaptainCashCustody));
        foreach ($c['captains'] as $captain) {
            $this->assertSame('0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame('0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainDeposit));
        }
        $this->assertSame(0, FinancialTransaction::whereIn('event_type', collect(FinancialEventType::orderDerived())->map->value)->count());
        $this->assertSame('100.0000', $invoice->items_subtotal);
    }

    public function test_cash_shortfall_is_company_receivable_without_fake_custody(): void
    {
        $c = $this->context();
        $c['order']->currentDeliveryPricing->allocations()->sole()->update([
            'captain_entitlement' => '7.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '2.0000',
        ]);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame('2.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
        $this->assertSame(FinancialEventType::OrderCashDeliveryShortfallDue->value, FinancialTransaction::sole()->event_type);
    }

    public function test_multi_captain_cash_assigns_company_share_to_primary_and_shortfall_to_its_captain(): void
    {
        $c = $this->context(2, '50.00');
        $allocations = $c['order']->currentDeliveryPricing->allocations()->orderBy('order_captain_id')->get();
        $allocations[1]->update([
            'captain_entitlement' => '7.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '2.0000',
        ]);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame('1.7500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][1]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('2.0000', $this->balance($c['captains'][1]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame(2, FinancialTransaction::count());
    }

    public function test_sham_cash_reimburses_equal_product_shares_and_full_entitlements(): void
    {
        $c = $this->context(2, '100.00');
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame(2, FinancialTransaction::count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashProductReimbursementDue->value)->count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashDeliveryEntitlementDue->value)->count());
        $this->assertSame(0, FinancialTransaction::where('event_type', FinancialEventType::OrderCashCompanyDeliveryShareHeld->value)->count());
        $this->assertSame($collection->id, $invoice->collection()->sole()->id);
        foreach ($c['captains'] as $captain) {
            $this->assertSame('53.2500', $this->balance($captain->id, FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame('0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainCashCustody));
            $this->assertSame('0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainDeposit));
        }
    }

    public static function splitCases(): array
    {
        return [
            'zero decimals' => [0, '501', ['251.0000', '250.0000']],
            'two decimals' => [2, '10.01', ['5.0100', '5.0000']],
            'four decimals' => [4, '0.0001', ['0.0001']],
        ];
    }

    #[DataProvider('splitCases')]
    public function test_sham_product_split_uses_exact_minor_units_and_no_zero_entries(int $decimalPlaces, string $subtotal, array $expected): void
    {
        $c = $this->context(2, $subtotal, $decimalPlaces);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total, $c['currency']->id, now());
        $entries = FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashProductReimbursementDue->value)
            ->sole()->entries()->orderBy('financial_account_id')->pluck('amount')->all();
        $this->assertSame($expected, $entries);
        $this->assertSame((string) BigDecimal::of($subtotal)->toScale(4), (string) collect($entries)->reduce(fn (BigDecimal $sum, string $amount) => $sum->plus($amount), BigDecimal::zero())->toScale(4));
    }

    public function test_backfill_waits_without_collection_then_dry_runs_and_applies_once(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertStringContainsString('WAITING_COLLECTION', Artisan::output());
        $this->assertSame(0, FinancialTransaction::count());
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $tables = ['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries', 'order_financial_source_snapshots'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        Artisan::call('financial:backfill-order-events');
        $this->assertStringContainsString('WOULD POST', Artisan::output());
        $this->assertSame($before, collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all());
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(1, FinancialTransaction::where('source_id', (string) $collection->id)->count());
        $this->assertSame('1.7500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
    }

    public function test_legacy_correction_reverses_exact_old_cash_events_then_posts_company_share(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postLegacyEvents($c, $invoice, $collection);
        $before = collect(['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries', 'order_financial_source_snapshots'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        Artisan::call('financial:correct-order-financial-semantics');
        $this->assertStringContainsString('WOULD_CORRECT', Artisan::output());
        $this->assertSame($before, collect(array_keys($before))->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all());
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertStringContainsString('LEGACY_CORRECTION_REQUIRED', Artisan::output());
        $this->assertSame(4, FinancialTransaction::count());
        $this->assertSame(0, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame(9, FinancialTransaction::count());
        $this->assertSame(4, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
        $this->assertSame('1.7500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(9, FinancialTransaction::count());
    }

    public function test_uncollected_legacy_invoice_events_are_reversed_without_replacement(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $this->postLegacyEvents($c, $invoice, null);
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(6, FinancialTransaction::count());
        $this->assertSame(3, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
        $this->assertSame(0, FinancialTransaction::whereIn('event_type', collect(FinancialEventType::correctedOrderDerived())->map->value)->count());
        $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(8, FinancialTransaction::count());
    }

    public function test_collection_before_delivery_reverses_old_full_custody_and_waits(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postLegacyCustody($c, $collection);
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(2, FinancialTransaction::count());
        $this->markDelivered($c);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame(3, FinancialTransaction::count());
        $this->assertSame('1.7500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
    }

    public function test_invoice_snapshot_is_immutable_and_financial_drift_is_rejected(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->assertDatabaseHas('order_financial_source_snapshots', ['invoice_id' => $invoice->id, 'items_subtotal' => '25.0000']);
        $invoice->update(['items_subtotal' => '26.0000', 'grand_total' => '31.0000']);
        $this->markDelivered($c);
        $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total);
        $this->assertSame('conflict', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(0, FinancialTransaction::count());
    }

    public function test_collected_invoice_remains_guarded_against_replacement(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->replace($c['admin'], $c['order'], $invoice, 'Correction');
    }

    public function test_sham_cash_legacy_events_reverse_to_product_and_full_entitlement_due(): void
    {
        $c = $this->context();
        $allocation = $c['order']->currentDeliveryPricing->allocations()->sole();
        $allocation->update([
            'captain_entitlement' => '7.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '2.0000',
        ]);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::ShamCash, $invoice->grand_total);
        $this->postExpectedProductEvent($c, $invoice);
        $receivable = app(FinancialAccountService::class)->getOrCreate(
            app(FinancialAccountService::class)->ownerForCaptain($c['captains'][0]),
            $c['currency'], FinancialAccountKind::CaptainCompanyReceivable,
        );
        $deliveredAt = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->latest('changed_at')->value('changed_at');
        $occurred = $invoice->issued_at->greaterThan($deliveredAt) ? $invoice->issued_at : $deliveredAt;
        app(FinancialPostingService::class)->postEvent(
            $c['currency'], 'CUSTOMER_INVOICE', $invoice->id, FinancialEventType::OrderDeliveryEntitlement->value,
            $invoice->version, $occurred,
            [['account' => $receivable, 'direction' => FinancialEntryDirection::Increase, 'amount' => '7.0000']],
            $c['branch'], $invoice->issuer,
        );
        Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]);
        $this->assertSame(2, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashProductReimbursementDue->value)->count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderShamCashDeliveryEntitlementDue->value)->count());
        $this->assertSame('32.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
    }

    public function test_primary_promotion_reprices_changed_participants_and_custody_follows_collector(): void
    {
        $c = $this->context(2);
        $version = $c['order']->currentDeliveryPricing()->sole()->version;
        app(OrderCaptainService::class)->switchPrimary($c['employee'], $c['order'], $c['captains'][1], 'Promote support');
        $this->assertSame($version + 1, $c['order']->currentDeliveryPricing()->sole()->version);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = app(InvoiceCollectionService::class)->record($c['employee'], $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total, $c['currency']->id, now());
        $this->assertSame($c['captains'][1]->id, $collection->captain_id);
        $this->assertSame('1.7500', $this->balance($c['captains'][1]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
    }

    public function test_conflicting_legacy_payload_causes_no_partial_correction_writes(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postLegacyEvents($c, $invoice, $collection);
        $invoice->update(['items_subtotal' => '26.0000', 'grand_total' => '31.0000']);
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics'));
        $this->assertStringContainsString('CONFLICT', Artisan::output());
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
    }

    public function test_collection_method_drift_cannot_post_the_opposite_event_matrix(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = app(InvoiceCollectionService::class)->record(
            $c['employee'], $invoice, InvoiceCollectionMethod::Cash,
            $invoice->grand_total, $c['currency']->id, now(),
        );
        DB::table('invoice_collections')->where('id', $collection->id)->update([
            'collection_method' => InvoiceCollectionMethod::ShamCash->value,
            'captain_id' => null,
            'order_captain_id' => null,
        ]);
        $this->assertSame('conflict', app(CorrectedOrderFinancialService::class)->assess($c['order'])['state']);
        $this->assertSame(1, FinancialTransaction::count());
    }

    public function test_corrected_event_preserves_authoritative_microseconds_and_latest_timestamp(): void
    {
        $c = $this->context();
        $invoice = $this->ready($c);
        $invoice->update(['status' => InvoiceStatus::Issued, 'issued_at' => now(), 'issued_by' => $c['employee']->id]);
        DB::table('invoices')->where('id', $invoice->id)->update(['issued_at' => '2026-09-27 12:34:56.123456+00']);
        $invoice = $invoice->fresh();
        app(OrderFinancialSourceSnapshotService::class)->capture($invoice);
        $this->markDelivered($c);
        $history = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->sole();
        DB::table('order_status_history')->where('id', $history->id)->update(['changed_at' => '2026-09-27 12:34:56.234567+00']);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        DB::table('invoice_collections')->where('id', $collection->id)->update(['collected_at' => '2026-09-27 12:34:56.345678+00']);

        $rawCollected = DB::selectOne("SELECT to_char(collected_at, 'YYYY-MM-DD HH24:MI:SS.US') AS collected_at FROM invoice_collections WHERE id = ?", [$collection->id])->collected_at;
        $this->assertStringContainsString('.345678', $rawCollected);

        $assessment = app(CorrectedOrderFinancialService::class)->assess($c['order']);
        $this->assertStringContainsString('.345678', $assessment['occurred_at'] ?? json_encode($assessment));
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $event = FinancialTransaction::sole();
        $stored = DB::selectOne("SELECT to_char(occurred_at, 'YYYY-MM-DD HH24:MI:SS.US') AS occurred_at FROM financial_transactions WHERE id = ?", [$event->id])->occurred_at;
        $this->assertStringContainsString('.345678', $stored);
        $this->assertStringContainsString('12:34:56.123456', $event->notes);
        $this->assertStringContainsString('12:34:56.234567', $event->notes);
        $this->assertStringContainsString('12:34:56.345678', $event->notes);
    }

    public function test_mixed_historical_dry_runs_leave_all_source_and_ledger_rows_unchanged(): void
    {
        $waiting = $this->context();
        $this->issue($waiting);
        $this->markDelivered($waiting);

        $eligible = $this->context();
        $eligibleInvoice = $this->issue($eligible);
        $this->markDelivered($eligible);
        $this->historicalCollection($eligible, $eligibleInvoice, InvoiceCollectionMethod::Cash, $eligibleInvoice->grand_total);

        $legacy = $this->context();
        $legacyInvoice = $this->issue($legacy);
        $this->markDelivered($legacy);
        $this->postLegacyEvents($legacy, $legacyInvoice, null);

        $conflict = $this->context();
        $conflictInvoice = $this->issue($conflict);
        $conflictInvoice->update(['items_subtotal' => '26.0000', 'grand_total' => '31.0000']);

        $tables = ['orders', 'invoices', 'invoice_collections', 'order_financial_source_snapshots',
            'order_financial_source_snapshot_lines', 'financial_owners', 'financial_accounts',
            'financial_transactions', 'financial_entries'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        Artisan::call('financial:backfill-order-events');
        $backfillOutput = Artisan::output();
        $this->assertStringContainsString('WAITING_COLLECTION', $backfillOutput);
        $this->assertStringContainsString('WOULD POST', $backfillOutput);
        $this->assertStringContainsString('LEGACY_CORRECTION_REQUIRED', $backfillOutput);
        Artisan::call('financial:correct-order-financial-semantics');
        $this->assertStringContainsString('WOULD_CORRECT', Artisan::output());
        $this->assertSame($before, collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all());
    }

    public function test_malformed_legacy_cash_collection_rejects_correction_without_writes(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, '1.0000');
        $this->postLegacyEvents($c, $invoice, $collection);
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();

        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics'));
        $this->assertStringContainsString('CONFLICT', Artisan::output());
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
    }

    public function test_partial_corrected_event_set_is_completed_but_wrong_version_conflicts(): void
    {
        $c = $this->context(2);
        $allocations = $c['order']->currentDeliveryPricing->allocations()->orderBy('order_captain_id')->get();
        $allocations[1]->update(['captain_entitlement' => '7.0000', 'customer_delivery_amount_allocated' => '5.0000',
            'company_collectible' => '0.0000', 'captain_shortfall' => '2.0000']);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $primary = $c['order']->captainAssignments()->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->sole();
        $collection = $invoice->collection()->create([
            'order_id' => $c['order']->id, 'captain_id' => $primary->captain_id,
            'order_captain_id' => $primary->id, 'collection_method' => InvoiceCollectionMethod::Cash,
            'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total,
            'collected_at' => now(), 'recorded_by' => $c['employee']->id,
        ]);
        $assessment = app(CorrectedOrderFinancialService::class)->assess($c['order']);
        $this->assertSame('eligible', $assessment['state']);
        $accounts = app(FinancialAccountService::class);
        $account = $accounts->getOrCreate($accounts->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainCashCustody);
        app(FinancialPostingService::class)->postEvent($c['currency'], 'INVOICE_COLLECTION', $collection->id,
            FinancialEventType::OrderCashCompanyDeliveryShareHeld->value, 1, CarbonImmutable::parse($assessment['occurred_at']),
            [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $allocations[0]->company_collectible]],
            $c['branch'], $collection->recordedBy, null, $assessment['notes']);
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame(2, FinancialTransaction::count());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::OrderCashDeliveryShortfallDue->value)->count());
        app(CorrectedOrderFinancialService::class)->postIfReady($c['order']);
        $this->assertSame(2, FinancialTransaction::count());

        $wrong = $this->context();
        $wrongInvoice = $this->issue($wrong);
        $this->markDelivered($wrong);
        $wrongCollection = $this->historicalCollection($wrong, $wrongInvoice, InvoiceCollectionMethod::Cash, $wrongInvoice->grand_total);
        $wrongAssessment = app(CorrectedOrderFinancialService::class)->assess($wrong['order']);
        $wrongAccount = $accounts->getOrCreate($accounts->ownerForCaptain($wrong['captains'][0]), $wrong['currency'], FinancialAccountKind::CaptainCashCustody);
        app(FinancialPostingService::class)->postEvent($wrong['currency'], 'INVOICE_COLLECTION', $wrongCollection->id,
            FinancialEventType::OrderCashCompanyDeliveryShareHeld->value, 2, CarbonImmutable::parse($wrongAssessment['occurred_at']),
            [['account' => $wrongAccount, 'direction' => FinancialEntryDirection::Increase, 'amount' => '1.7500']],
            $wrong['branch'], $wrongCollection->recordedBy, null, $wrongAssessment['notes']);
        $this->assertSame('conflict', app(CorrectedOrderFinancialService::class)->assess($wrong['order'])['state']);
        $this->assertSame(0, FinancialTransaction::where('source_id', (string) $wrongCollection->id)->where('source_version', 1)->count());
    }

    public function test_late_database_rejection_rolls_back_all_legacy_reversals(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        $this->postLegacyEvents($c, $invoice, $collection);
        $before = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_test_corrected_event() RETURNS trigger AS $$
BEGIN
    IF NEW.event_type = 'ORDER_CASH_COMPANY_DELIVERY_SHARE_HELD' THEN
        RAISE EXCEPTION 'Injected late ledger rejection' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER reject_test_corrected_event BEFORE INSERT ON financial_transactions
FOR EACH ROW EXECUTE FUNCTION reject_test_corrected_event();
SQL);
        $this->assertSame(1, Artisan::call('financial:correct-order-financial-semantics', ['--apply' => true]));
        $this->assertSame($before, DB::table('financial_transactions')->orderBy('id')->get()->toJson());
        $this->assertSame(0, FinancialTransaction::whereNotNull('reversal_of_transaction_id')->count());
    }

    private function postLegacyEvents(array $c, $invoice, $collection): void
    {
        $this->postExpectedProductEvent($c, $invoice);
        $accountService = app(FinancialAccountService::class);
        $receivable = $accountService->getOrCreate($accountService->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        $deposit = $accountService->getOrCreate($accountService->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainDeposit);
        $allocation = $c['order']->currentDeliveryPricing->allocations()->sole();
        $deliveredAt = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->latest('changed_at')->value('changed_at');
        $occurred = $invoice->issued_at->greaterThan($deliveredAt) ? $invoice->issued_at : $deliveredAt;
        app(FinancialPostingService::class)->postEvent($c['currency'], 'CUSTOMER_INVOICE', $invoice->id, FinancialEventType::OrderDeliveryEntitlement->value, $invoice->version, $occurred, [['account' => $receivable, 'direction' => FinancialEntryDirection::Increase, 'amount' => $allocation->captain_entitlement]], $c['branch'], $invoice->issuer);
        app(FinancialPostingService::class)->postEvent($c['currency'], 'CUSTOMER_INVOICE', $invoice->id, FinancialEventType::OrderCompanyDeliveryShare->value, $invoice->version, $occurred, [['account' => $deposit, 'direction' => FinancialEntryDirection::Decrease, 'amount' => $allocation->company_collectible]], $c['branch'], $invoice->issuer);
        if ($collection) {
            $this->postLegacyCustody($c, $collection);
        }
    }

    private function postLegacyCustody(array $c, $collection): void
    {
        $accountService = app(FinancialAccountService::class);
        $custody = $accountService->getOrCreate($accountService->ownerForCaptain($c['captains'][0]), $c['currency'], FinancialAccountKind::CaptainCashCustody);
        app(FinancialPostingService::class)->postEvent($c['currency'], 'INVOICE_COLLECTION', $collection->id, FinancialEventType::InvoiceCashCustodyReceived->value, 1, $collection->collected_at, [['account' => $custody, 'direction' => FinancialEntryDirection::Increase, 'amount' => $collection->amount]], $c['branch'], $collection->recordedBy);
    }

    private function context(int $captainCount = 1, string $itemsSubtotal = '25.00', int $decimalPlaces = 2): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['name' => 'Dollar', 'code' => strtoupper(str()->random(3)), 'decimal_places' => $decimalPlaces]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create(['name' => 'Origin']);
        $to = Area::factory()->for($branch)->create(['name' => 'Destination']);
        $customer = Customer::create(['name' => 'Financial Customer']);
        $phone = $customer->phones()->create(['phone' => str()->random(10), 'normalized_phone' => str()->random(10)]);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Street']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Shop', 'sort_order' => 1]);
        $order->update(['pricing_class' => OrderPricingClass::SingleShop]);
        OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime, 'product_name_snapshot' => 'Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1', 'current_quantity' => '1', 'actual_unit_price' => $itemsSubtotal, 'actual_price_source' => ActualPriceSource::ManualVerified, 'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $employee->id]);
        app(OrderPricingService::class)->recalculate($order);
        $captains = [];
        for ($index = 0; $index < $captainCount; $index++) {
            $user = User::factory()->create();
            $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
            app(OrderCaptainService::class)->assign($employee, $order, $captain, $index === 0 ? OrderCaptainRole::PrimaryCollection : OrderCaptainRole::Support);
            $captains[] = $captain;
        }
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id, 'currency_id' => $currency->id, 'order_pricing_class' => OrderPricingClass::SingleShop->value, 'delivery_unit_fee' => '5.00', 'effective_from' => now()->subDay(), 'effective_until' => null]);
        app(DeliveryPricingSettings::class)->update($admin, $decimalPlaces === 0 ? '100' : '65', '0');
        app(OrderDeliveryPricingService::class)->price($employee, $order, $from);

        return compact('currency', 'branch', 'from', 'to', 'admin', 'employee', 'order', 'shop', 'captains');
    }

    private function ready(array $c)
    {
        return app(InvoiceService::class)->refresh($c['employee'], $c['order'], app(InvoiceService::class)->createDraft($c['employee'], $c['order']));
    }

    private function issue(array $c)
    {
        return app(InvoiceService::class)->issue($c['employee'], $c['order'], $this->ready($c));
    }

    private function markDelivered(array $c): void
    {
        $c['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
        $c['order']->statusHistory()->create(['previous_status' => OrderOperationalStatus::HeadingToCustomer, 'new_status' => OrderOperationalStatus::Delivered, 'changed_by' => $c['employee']->id, 'changed_at' => now()]);
    }

    private function prepareDeliveryTransition(array $c): void
    {
        $c['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();
        $c['shop']->update(['picked_up_at' => now()]);
        $c['shop']->receipts()->create(['storage_path' => 'test/'.str()->uuid().'.png', 'original_filename' => 'test.png', 'mime_type' => 'image/png', 'file_size' => 1, 'uploaded_by' => $c['employee']->id, 'uploaded_at' => now()]);
    }

    private function balance(int $captainId, FinancialAccountKind $kind): string
    {
        $account = FinancialAccount::query()->whereHas('owner', fn ($query) => $query->where('captain_id', $captainId))->where('account_kind', $kind->value)->first();

        return $account ? app(FinancialBalanceQueryService::class)->balance($account) : '0.0000';
    }

    private function postExpectedProductEvent(array $c, $invoice, ?int $sourceVersion = null): void
    {
        $captain = $c['captains'][0];
        $accountService = app(FinancialAccountService::class);
        $account = $accountService->getOrCreate($accountService->ownerForCaptain($captain), $c['currency'], FinancialAccountKind::CaptainCompanyReceivable);
        $deliveredAt = $c['order']->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->latest('changed_at')->value('changed_at');
        app(FinancialPostingService::class)->postEvent(
            $c['currency'], OrderFinancialPostingService::INVOICE_SOURCE, $invoice->id,
            FinancialEventType::OrderProductReimbursement->value, $sourceVersion ?? $invoice->version,
            $invoice->issued_at->greaterThan($deliveredAt) ? $invoice->issued_at : $deliveredAt,
            [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $invoice->items_subtotal]],
            $c['branch'], $invoice->issuer,
        );
    }

    private function historicalCollection(array $c, $invoice, InvoiceCollectionMethod $method, string $amount)
    {
        $assignment = $c['order']->captainAssignments()->sole();

        return $invoice->collection()->create([
            'order_id' => $c['order']->id,
            'captain_id' => $method === InvoiceCollectionMethod::Cash ? $assignment->captain_id : null,
            'order_captain_id' => $method === InvoiceCollectionMethod::Cash ? $assignment->id : null,
            'collection_method' => $method,
            'currency_id' => $c['currency']->id,
            'amount' => $amount,
            'collected_at' => now(),
            'recorded_by' => $c['employee']->id,
        ]);
    }
}
