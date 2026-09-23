<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Exceptions\FinancialLedgerException;
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
use App\Services\OrderPricingService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Brick\Math\BigDecimal;
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

    public function test_invoice_then_delivery_crystallizes_once_from_snapshots_and_keeps_accounts_separate(): void
    {
        $c = $this->context(2, '100.00');
        $invoice = $this->issue($c);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->prepareDeliveryTransition($c);
        app(OrderStatusService::class)->transition($c['employee'], $c['order']->fresh(), OrderOperationalStatus::Delivered, null);
        $this->assertSame(3, FinancialTransaction::where('source_type', 'CUSTOMER_INVOICE')->count());

        app(OrderFinancialPostingService::class)->postOrderDerivedIfReady($c['order']);
        $this->assertSame(3, FinancialTransaction::where('source_type', 'CUSTOMER_INVOICE')->count());
        foreach ($c['captains'] as $captain) {
            $this->assertSame('53.2500', $this->balance($captain->id, FinancialAccountKind::CaptainCompanyReceivable));
            $this->assertSame('-1.7500', $this->balance($captain->id, FinancialAccountKind::CaptainDeposit));
            $this->assertSame('0.0000', $this->balance($captain->id, FinancialAccountKind::CaptainCashCustody));
        }
        $this->assertSame('100.0000', $invoice->items_subtotal);
    }

    public function test_delivery_then_invoice_issue_posts_once_and_minor_units_split_by_order_captain_id(): void
    {
        $c = $this->context(3, '100.00');
        $ready = $this->ready($c);
        $this->markDelivered($c);
        $this->assertDatabaseCount('financial_transactions', 0);
        $invoice = app(InvoiceService::class)->issue($c['employee'], $c['order']->fresh(), $ready);
        $allocations = $invoice->sourceDeliveryPricing->allocations()->orderBy('order_captain_id')->get();
        $product = FinancialTransaction::where('event_type', FinancialEventType::OrderProductReimbursement->value)->with('entries.account.owner')->sole();
        $byCaptain = $product->entries->mapWithKeys(fn ($entry) => [$entry->account->owner->captain_id => $entry->amount]);
        $this->assertSame('33.3400', $byCaptain[$allocations[0]->captain_id]);
        $this->assertSame('33.3300', $byCaptain[$allocations[1]->captain_id]);
        $this->assertSame('33.3300', $byCaptain[$allocations[2]->captain_id]);
        $this->assertSame('100.0000', (string) $product->entries->reduce(fn (BigDecimal $sum, $entry) => $sum->plus($entry->amount), BigDecimal::zero())->toScale(4));
        $this->assertSame(3, FinancialTransaction::where('source_id', (string) $invoice->id)->count());
    }

    public function test_only_one_readiness_condition_posts_nothing(): void
    {
        $issued = $this->context();
        $this->issue($issued);
        $this->assertDatabaseCount('financial_transactions', 0);

        $delivered = $this->context();
        $this->markDelivered($delivered);
        app(OrderFinancialPostingService::class)->postOrderDerivedIfReady($delivered['order']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_saved_entitlement_is_used_without_shortfall_double_count_or_current_setting_recalculation(): void
    {
        $c = $this->context();
        $allocation = $c['order']->currentDeliveryPricing->allocations()->sole();
        $allocation->update(['captain_shortfall' => '50.0000', 'company_collectible' => '0.0000']);
        $this->issue($c);
        app(DeliveryPricingSettings::class)->update($c['admin'], '10', '0');
        $this->markDelivered($c);
        app(OrderFinancialPostingService::class)->postOrderDerivedIfReady($c['order']);

        $this->assertSame('28.2500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainDeposit));
        $this->assertSame(0, FinancialTransaction::where('event_type', FinancialEventType::OrderCompanyDeliveryShare->value)->count());
    }

    public function test_cash_posts_full_custody_only_to_primary_and_sham_cash_posts_none(): void
    {
        $cash = $this->context(2);
        $cashInvoice = $this->issue($cash);
        $collection = app(InvoiceCollectionService::class)->record($cash['employee'], $cashInvoice, InvoiceCollectionMethod::Cash, $cashInvoice->grand_total, $cash['currency']->id, now());
        $this->assertSame($cashInvoice->grand_total, $this->balance($cash['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        $this->assertSame('0.0000', $this->balance($cash['captains'][1]->id, FinancialAccountKind::CaptainCashCustody));
        app(OrderFinancialPostingService::class)->postCashCollectionCustody($collection);
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::InvoiceCashCustodyReceived->value)->count());

        $sham = $this->context();
        $shamInvoice = $this->issue($sham);
        app(InvoiceCollectionService::class)->record($sham['employee'], $shamInvoice, InvoiceCollectionMethod::ShamCash, $shamInvoice->grand_total, $sham['currency']->id, now());
        $this->assertSame(1, FinancialTransaction::where('event_type', FinancialEventType::InvoiceCashCustodyReceived->value)->count());
    }

    public function test_crystallized_invoice_is_guarded_but_uncrystallized_invoice_can_be_replaced(): void
    {
        $crystallized = $this->context();
        $invoice = $this->issue($crystallized);
        $this->markDelivered($crystallized);
        app(OrderFinancialPostingService::class)->postOrderDerivedIfReady($crystallized['order']);
        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->replace($crystallized['admin'], $crystallized['order'], $invoice, 'Correction');
    }

    public function test_uncrystallized_invoice_preserves_replacement_workflow(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $replacement = app(InvoiceService::class)->replace($c['admin'], $c['order'], $invoice, 'Correction');
        $this->assertSame(2, $replacement->version);
    }

    public function test_backfill_defaults_to_dry_run_then_applies_idempotently(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $tables = ['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries', 'orders', 'invoices', 'invoice_collections', 'order_status_history'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        $sourceBefore = [$c['order']->fresh()->updated_at?->toISOString(), $invoice->fresh()->updated_at?->toISOString()];
        $this->assertSame(0, Artisan::call('financial:backfill-order-events'));
        $this->assertStringContainsString('DRY-RUN', Artisan::output());
        $this->assertSame($before, collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());
        $this->assertSame($sourceBefore, [$c['order']->fresh()->updated_at?->toISOString(), $invoice->fresh()->updated_at?->toISOString()]);
        $this->assertSame(0, Artisan::call('financial:backfill-order-events', ['--apply' => true]));
        $this->assertSame(3, FinancialTransaction::where('source_id', (string) $invoice->id)->count());
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(3, FinancialTransaction::where('source_id', (string) $invoice->id)->count());
    }

    public function test_backfill_dry_run_is_zero_write_for_mixed_financial_states(): void
    {
        $missing = $this->context();
        $this->issue($missing);
        $this->markDelivered($missing);

        $validCash = $this->context();
        $validCashInvoice = $this->issue($validCash);
        $this->historicalCollection($validCash, $validCashInvoice, InvoiceCollectionMethod::Cash, $validCashInvoice->grand_total);

        $malformedCash = $this->context();
        $malformedInvoice = $this->issue($malformedCash);
        $this->historicalCollection($malformedCash, $malformedInvoice, InvoiceCollectionMethod::Cash, '1.0000');

        $sham = $this->context();
        $shamInvoice = $this->issue($sham);
        $this->historicalCollection($sham, $shamInvoice, InvoiceCollectionMethod::ShamCash, $shamInvoice->grand_total);

        $conflict = $this->context();
        $conflictInvoice = $this->issue($conflict);
        $this->markDelivered($conflict);
        $this->postExpectedProductEvent($conflict, $conflictInvoice);
        $conflictInvoice->update(['items_subtotal' => '26.0000', 'grand_total' => '31.0000']);

        $zero = $this->context();
        $zeroInvoice = $this->issue($zero);
        $this->markDelivered($zero);
        $zeroInvoice->update(['items_subtotal' => '0.0000', 'grand_total' => $zeroInvoice->delivery_total]);

        $invalidPrecision = $this->context();
        $this->issue($invalidPrecision);
        $this->markDelivered($invalidPrecision);
        $invalidPrecision['order']->currentDeliveryPricing->allocations()->sole()->update(['captain_entitlement' => '3.2510']);

        $tables = ['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries', 'orders', 'invoices', 'invoice_collections', 'order_status_history'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $this->assertSame(0, Artisan::call('financial:backfill-order-events'));
        $output = Artisan::output();
        $this->assertStringContainsString('WOULD POST Order', $output);
        $this->assertStringContainsString('WOULD POST CASH Collection', $output);
        $this->assertStringContainsString('UNRESOLVED CASH Collection', $output);
        $this->assertStringContainsString('SHAM_CASH_NO_CUSTODY', $output);
        $this->assertStringContainsString('CONFLICT Order', $output);
        $this->assertStringContainsString('NO_FINANCIAL_EFFECT', $output);
        $this->assertStringContainsString("UNRESOLVED Order {$invalidPrecision['order']->id}: Financial amount exceeds the Currency decimal precision.", $output);
        $this->assertSame($before, collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all());
    }

    public function test_missing_accounts_do_not_hide_invalid_financial_source_amounts(): void
    {
        foreach (['entitlement', 'product', 'company'] as $invalidSource) {
            $c = $this->context();
            $invoice = $this->issue($c);
            $this->markDelivered($c);
            if ($invalidSource === 'product') {
                $invoice->update(['items_subtotal' => '25.0010', 'grand_total' => '30.0010']);
            } else {
                $c['order']->currentDeliveryPricing->allocations()->sole()->update([
                    $invalidSource === 'entitlement' ? 'captain_entitlement' : 'company_collectible' => '3.2510',
                ]);
            }
            $this->assertSame(0, FinancialAccount::where('currency_id', $c['currency']->id)->count());
            $state = app(OrderFinancialPostingService::class)->assessOrder($c['order']);
            $this->assertSame('unresolved', $state['state'], $invalidSource);
            $this->assertStringContainsString('Currency', $state['reason']);
            Artisan::call('financial:backfill-order-events');
            $this->assertStringContainsString("UNRESOLVED Order {$c['order']->id}", Artisan::output());
            $this->assertStringNotContainsString("WOULD POST Order {$c['order']->id}", Artisan::output());
            Artisan::call('financial:backfill-order-events', ['--apply' => true]);
            $this->assertSame(0, FinancialTransaction::where('source_type', OrderFinancialPostingService::INVOICE_SOURCE)->where('source_id', (string) $invoice->id)->count());
            $this->assertSame(0, FinancialAccount::where('currency_id', $c['currency']->id)->count());
        }
    }

    public function test_valid_missing_account_remains_actionable_and_dry_run_creates_nothing(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $c['order']->currentDeliveryPricing->allocations()->sole()->update(['captain_entitlement' => '3.2500']);
        $tables = ['financial_owners', 'financial_accounts', 'financial_transactions', 'financial_entries'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
        $this->assertSame('eligible', app(OrderFinancialPostingService::class)->assessOrder($c['order'])['state']);
        Artisan::call('financial:backfill-order-events');
        $this->assertStringContainsString("WOULD POST Order {$c['order']->id}", Artisan::output());
        $this->assertSame($before, collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(3, FinancialTransaction::where('source_type', OrderFinancialPostingService::INVOICE_SOURCE)->where('source_id', (string) $invoice->id)->count());
        $this->assertSame('28.2500', $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCompanyReceivable));
    }

    public function test_historical_cash_backfill_apply_twice_has_one_exact_custody_effect(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $collection = $this->historicalCollection($c, $invoice, InvoiceCollectionMethod::Cash, $invoice->grand_total);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(1, FinancialTransaction::where('source_type', OrderFinancialPostingService::COLLECTION_SOURCE)->where('source_id', (string) $collection->id)->count());
        $this->assertSame($invoice->grand_total, $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertStringContainsString("COMPLETE CASH Collection {$collection->id}", Artisan::output());
        $this->assertSame(1, $invoice->collection()->count());
        $transaction = FinancialTransaction::where('source_type', OrderFinancialPostingService::COLLECTION_SOURCE)->where('source_id', (string) $collection->id)->sole();
        $this->assertSame(1, $transaction->entries()->count());
        $this->assertSame($invoice->grand_total, $this->balance($c['captains'][0]->id, FinancialAccountKind::CaptainCashCustody));
    }

    public function test_zero_delivery_concepts_post_no_empty_events_and_keep_invoice_guard(): void
    {
        $c = $this->context();
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        $c['order']->currentDeliveryPricing->allocations()->sole()->update(['captain_entitlement' => '0.0000', 'company_collectible' => '0.0000']);
        $service = app(OrderFinancialPostingService::class);
        $this->assertSame('eligible', $service->assessOrder($c['order'])['state']);
        $this->assertCount(2, $service->assessOrder($c['order'])['no_effect']);
        $service->postOrderDerivedIfReady($c['order']);
        $service->postOrderDerivedIfReady($c['order']);
        $this->assertSame(1, FinancialTransaction::where('source_type', OrderFinancialPostingService::INVOICE_SOURCE)->where('source_id', (string) $invoice->id)->count());
        $this->assertSame(1, DB::table('financial_entries')->count());
        Artisan::call('financial:backfill-order-events');
        $this->assertStringContainsString("COMPLETE Order {$c['order']->id}; NO_FINANCIAL_EFFECT", Artisan::output());
        $this->expectException(ValidationException::class);
        app(InvoiceService::class)->replace($c['admin'], $c['order'], $invoice, 'Correction');
    }

    public function test_backfill_handles_historical_cash_sham_cash_unknown_and_after_purchase_without_guessing(): void
    {
        $cash = $this->context();
        $cashInvoice = $this->issue($cash);
        $cashAssignment = $cash['order']->captainAssignments()->sole();
        $cashCollection = $cashInvoice->collection()->create(['order_id' => $cash['order']->id, 'captain_id' => $cashAssignment->captain_id, 'order_captain_id' => $cashAssignment->id, 'collection_method' => InvoiceCollectionMethod::Cash, 'currency_id' => $cash['currency']->id, 'amount' => $cashInvoice->grand_total, 'collected_at' => now(), 'recorded_by' => $cash['employee']->id]);

        $sham = $this->context();
        $shamInvoice = $this->issue($sham);
        $shamInvoice->collection()->create(['order_id' => $sham['order']->id, 'collection_method' => InvoiceCollectionMethod::ShamCash, 'currency_id' => $sham['currency']->id, 'amount' => $shamInvoice->grand_total, 'collected_at' => now(), 'recorded_by' => $sham['employee']->id]);

        $unknown = $this->context();
        $unknownInvoice = $this->issue($unknown);
        $unknownInvoice->collection()->create(['order_id' => $unknown['order']->id, 'collection_method' => null, 'currency_id' => $unknown['currency']->id, 'amount' => $unknownInvoice->grand_total, 'collected_at' => now(), 'recorded_by' => $unknown['employee']->id]);

        $cancelled = $this->context();
        $cancelled['order']->forceFill(['operational_status' => OrderOperationalStatus::Cancelled])->save();
        $cancelled['order']->cancellation()->create(['classification' => OrderCancellationClassification::AfterPurchase, 'reason' => OrderCancellationReason::CustomerCancelled, 'cancelled_by' => $cancelled['employee']->id, 'cancelled_at' => now()]);

        $missingInvoice = $this->context();
        $this->markDelivered($missingInvoice);

        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $output = Artisan::output();
        $this->assertStringContainsString("SHAM_CASH_NO_CUSTODY Collection {$shamInvoice->collection->id}", $output);
        $this->assertStringContainsString("UNRESOLVED Collection {$unknownInvoice->collection->id}", $output);
        $this->assertStringContainsString("UNRESOLVED AFTER_PURCHASE cancelled Order {$cancelled['order']->id}", $output);
        $this->assertStringContainsString("UNRESOLVED Order {$missingInvoice['order']->id}: DELIVERED Order has no current valid ISSUED Invoice.", $output);
        $this->assertSame(1, FinancialTransaction::where('source_type', 'INVOICE_COLLECTION')->where('source_id', (string) $cashCollection->id)->count());
        $this->assertSame(0, FinancialTransaction::where('source_type', 'INVOICE_COLLECTION')->whereIn('source_id', [(string) $shamInvoice->collection->id, (string) $unknownInvoice->collection->id])->count());
        $this->assertSame(0, FinancialTransaction::where('source_type', 'CUSTOMER_INVOICE')->count());
    }

    public function test_backfill_validates_exact_payload_version_partial_sets_and_zero_effects(): void
    {
        $partial = $this->context();
        $partialInvoice = $this->issue($partial);
        $this->markDelivered($partial);
        $this->postExpectedProductEvent($partial, $partialInvoice);
        $assessment = app(OrderFinancialPostingService::class)->assessOrder($partial['order']);
        $this->assertSame('eligible', $assessment['state']);
        $this->assertSame([
            FinancialEventType::OrderDeliveryEntitlement->value,
            FinancialEventType::OrderCompanyDeliveryShare->value,
        ], $assessment['missing']);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertSame(3, FinancialTransaction::where('source_id', (string) $partialInvoice->id)->count());
        $this->assertSame(0, Artisan::call('financial:backfill-order-events'));
        $this->assertStringContainsString("COMPLETE Order {$partial['order']->id}", Artisan::output());

        $conflict = $this->context();
        $conflictInvoice = $this->issue($conflict);
        $this->markDelivered($conflict);
        $this->postExpectedProductEvent($conflict, $conflictInvoice);
        $conflictInvoice->update(['items_subtotal' => '26.0000', 'grand_total' => '31.0000']);
        Artisan::call('financial:backfill-order-events', ['--apply' => true]);
        $this->assertStringContainsString("CONFLICT Order {$conflict['order']->id}", Artisan::output());
        $this->assertSame(1, FinancialTransaction::where('source_id', (string) $conflictInvoice->id)->count());

        $wrongVersion = $this->context();
        $wrongInvoice = $this->issue($wrongVersion);
        $this->markDelivered($wrongVersion);
        $this->postExpectedProductEvent($wrongVersion, $wrongInvoice, $wrongInvoice->version + 1);
        $this->assertSame('conflict', app(OrderFinancialPostingService::class)->assessOrder($wrongVersion['order'])['state']);

        $zero = $this->context();
        $zeroInvoice = $this->issue($zero);
        $this->markDelivered($zero);
        $zeroInvoice->update(['items_subtotal' => '0.0000', 'grand_total' => $zeroInvoice->delivery_total]);
        $zeroAssessment = app(OrderFinancialPostingService::class)->assessOrder($zero['order']);
        $this->assertContains(FinancialEventType::OrderProductReimbursement->value, $zeroAssessment['no_effect']);

        $zeroConflict = $this->context();
        $zeroConflictInvoice = $this->issue($zeroConflict);
        $this->markDelivered($zeroConflict);
        $this->postExpectedProductEvent($zeroConflict, $zeroConflictInvoice);
        $zeroConflictInvoice->update(['items_subtotal' => '0.0000', 'grand_total' => $zeroConflictInvoice->delivery_total]);
        $this->assertSame('conflict', app(OrderFinancialPostingService::class)->assessOrder($zeroConflict['order'])['state']);
    }

    public function test_malformed_historical_cash_is_unresolved_and_never_posts(): void
    {
        foreach (['amount', 'currency', 'captain'] as $fault) {
            $c = $this->context(2);
            $invoice = $this->issue($c);
            $assignment = $c['order']->captainAssignments()->where('assignment_role', OrderCaptainRole::PrimaryCollection->value)->sole();
            $values = ['order_id' => $c['order']->id, 'captain_id' => $assignment->captain_id, 'order_captain_id' => $assignment->id, 'collection_method' => InvoiceCollectionMethod::Cash, 'currency_id' => $c['currency']->id, 'amount' => $invoice->grand_total, 'collected_at' => now(), 'recorded_by' => $c['employee']->id];
            if ($fault === 'amount') {
                $values['amount'] = '1.0000';
            } elseif ($fault === 'currency') {
                $values['currency_id'] = Currency::create(['name' => 'Other', 'code' => strtoupper(str()->random(3)), 'decimal_places' => 2])->id;
            } else {
                $values['captain_id'] = $c['captains'][1]->id;
            }
            $collection = $invoice->collection()->create($values);
            $before = FinancialTransaction::count();
            Artisan::call('financial:backfill-order-events');
            $this->assertStringContainsString("UNRESOLVED CASH Collection {$collection->id}", Artisan::output());
            Artisan::call('financial:backfill-order-events', ['--apply' => true]);
            $this->assertSame($before, FinancialTransaction::count());
        }
    }

    public function test_late_financial_failure_rolls_back_delivery_and_invoice_issue(): void
    {
        $delivery = $this->context();
        $this->issue($delivery);
        $delivery['order']->currentDeliveryPricing->allocations()->sole()->update(['captain_entitlement' => '3.2510']);
        $this->prepareDeliveryTransition($delivery);
        try {
            app(OrderStatusService::class)->transition($delivery['employee'], $delivery['order']->fresh(), OrderOperationalStatus::Delivered, null);
            $this->fail('A precision-invalid later financial event should fail.');
        } catch (FinancialLedgerException) {
            $this->assertSame(OrderOperationalStatus::HeadingToCustomer, $delivery['order']->fresh()->operational_status);
            $this->assertSame(0, FinancialTransaction::count());
        }

        $issuance = $this->context();
        $ready = $this->ready($issuance);
        $this->markDelivered($issuance);
        $issuance['order']->currentDeliveryPricing->allocations()->sole()->update(['captain_entitlement' => '3.2510']);
        try {
            app(InvoiceService::class)->issue($issuance['employee'], $issuance['order']->fresh(), $ready);
            $this->fail('A precision-invalid later financial event should fail.');
        } catch (FinancialLedgerException) {
            $this->assertSame('READY', $ready->fresh()->status->value);
            $this->assertSame(0, FinancialTransaction::count());
        }
    }

    public static function splitCases(): array
    {
        return [
            'zero decimals' => [0, '100', 3, ['34.0000', '33.0000', '33.0000']],
            'two decimals' => [2, '100.00', 3, ['33.3400', '33.3300', '33.3300']],
            'three decimals' => [3, '1.000', 3, ['0.3340', '0.3330', '0.3330']],
            'four decimals' => [4, '1.0000', 3, ['0.3334', '0.3333', '0.3333']],
            'smaller than captains' => [2, '0.01', 3, ['0.0100']],
        ];
    }

    #[DataProvider('splitCases')]
    public function test_product_split_respects_currency_precision_without_zero_entries(int $decimalPlaces, string $subtotal, int $captains, array $expected): void
    {
        $c = $this->context($captains, $subtotal, $decimalPlaces);
        $invoice = $this->issue($c);
        $this->markDelivered($c);
        app(OrderFinancialPostingService::class)->postOrderDerivedIfReady($c['order']);
        $entries = FinancialTransaction::where('source_id', (string) $invoice->id)->where('event_type', FinancialEventType::OrderProductReimbursement->value)->sole()->entries()->orderBy('financial_account_id')->pluck('amount')->all();
        $this->assertSame($expected, $entries);
        $this->assertSame((string) BigDecimal::of($subtotal)->toScale(4), (string) collect($entries)->reduce(fn (BigDecimal $sum, string $amount) => $sum->plus($amount), BigDecimal::zero())->toScale(4));
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
        app(DeliveryPricingSettings::class)->update($admin, '65', '0');
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
