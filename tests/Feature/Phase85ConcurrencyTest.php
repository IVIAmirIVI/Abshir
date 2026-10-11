<?php

namespace Tests\Feature;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderPricingStatus;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\OrderShopPurchaseConfirmation;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerRefundService;
use App\Services\FinancialBalanceQueryService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\OrderCancellationFinalizationService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCaptainService;
use App\Services\OrderService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderShopReturnService;
use App\Services\OrderStatusService;
use Brick\Math\BigDecimal;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AccountingV1Fixtures;
use Tests\TestCase;

class Phase85ConcurrencyTest extends TestCase
{
    use AccountingV1Fixtures;

    public function test_phase_9c3a_refunded_cancellation_and_legacy_finalizer_cannot_both_create_cancellation(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            Storage::fake('order_purchases');
            Storage::fake('order_returns');
            $fixture = $this->fixture();
            $ready = $this->readyInvoice($fixture);
            $assignment = $fixture['order']->captainAssignments()->sole();
            app(OrderShopPurchaseConfirmationService::class)->record($fixture['actor'], $fixture['order'], $ready['shop'], [
                'order_captain_id' => $assignment->id, 'currency_id' => $ready['currency']->id,
                'amount' => '10.00', 'purchased_at' => now()->subMinutes(5)->toIso8601String(),
                'request_key' => (string) Str::uuid(),
            ], UploadedFile::fake()->createWithContent('purchase.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')));
            $invoice = app(InvoiceService::class)->issue($fixture['actor'], $fixture['order'], $ready['invoice']);
            app(InvoiceCollectionService::class)->record($fixture['actor'], $invoice,
                InvoiceCollectionMethod::Cash, $invoice->grand_total, $ready['currency']->id, now());
            $admin = User::factory()->create();
            $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
            $source = app(InvoiceFinancialCorrectionService::class)->request($fixture['actor'], $invoice,
                InvoiceFinancialCorrectionKind::CancelAndRefund, [], 'Customer cancellation',
                'Full return and customer refund', (string) Str::uuid());
            app(OrderCancellationIntentService::class)->create($fixture['actor'], $fixture['order'],
                OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
            app(InvoiceFinancialCorrectionService::class)->prepare($admin, $source);
            app(OrderShopReturnService::class)->record($fixture['actor'], $fixture['order'], $ready['shop'], [
                'returning_order_captain_id' => $assignment->id, 'refund_amount' => '10.00',
                'returned_at' => now()->subMinutes(2)->toIso8601String(),
                'refunded_at' => now()->subMinute()->toIso8601String(),
                'refund_method' => 'CASH', 'request_key' => (string) Str::uuid(),
            ], ['RETURN_AND_REFUND_PROOF' => UploadedFile::fake()->createWithContent('return.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))]);
            app(CustomerRefundService::class)->record($admin, $source, (string) Str::uuid(), now(), 'FINAL-CANCEL-REFUND',
                UploadedFile::fake()->createWithContent('refund.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')));
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'correction_finalize', 'actor_id' => $admin->id,
                    'order_id' => $fixture['order']->id, 'correction_id' => $source->id],
                ['operation' => 'finalize', 'actor_id' => $fixture['actor']->id,
                    'order_id' => $fixture['order']->id],
            ]);
            $this->assertOneSuccessAndOneValidation($results);
            $this->assertSame('COMPLETED', $source->fresh()->state->value);
            $this->assertSame('CANCELLED', $fixture['order']->fresh()->operational_status->value);
            $this->assertSame(1, DB::table('order_cancellations')->where('order_id', $fixture['order']->id)->count());
            foreach (DB::table('customer_refunds')->where('invoice_financial_correction_id', $source->id)->pluck('evidence_path') as $path) {
                Storage::disk('customer_refunds')->delete($path);
            }
        });
    }

    public function test_phase_9c3a_collected_invoice_replace_cannot_race_past_correction_request(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $ready = $this->readyInvoice($fixture);
            $invoice = app(InvoiceService::class)->issue($fixture['actor'], $fixture['order'], $ready['invoice']);
            app(InvoiceCollectionService::class)->record($fixture['actor'], $invoice,
                InvoiceCollectionMethod::Cash, $invoice->grand_total, $ready['currency']->id, now());
            $admin = User::factory()->create();
            $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'correction_request', 'actor_id' => $fixture['actor']->id,
                    'order_id' => $fixture['order']->id, 'invoice_id' => $invoice->id,
                    'kind' => InvoiceFinancialCorrectionKind::Reprice->value,
                    'proposal' => ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                        'delivery' => [['order_captain_id' => $fixture['order']->captainAssignments()->sole()->id,
                            'customer_amount' => '5.00']]],
                    'reason' => 'Correct price', 'explanation' => 'Verified customer amount',
                    'request_key' => (string) Str::uuid()],
                ['operation' => 'invoice_replace', 'actor_id' => $admin->id,
                    'order_id' => $fixture['order']->id, 'invoice_id' => $invoice->id,
                    'reason' => 'Racing replacement'],
            ]);
            $this->assertSame(['success' => 1, 'validation' => 1],
                collect($results)->countBy('status')->sortKeys()->all(), json_encode($results));
            $this->assertSame('ISSUED', $invoice->fresh()->status->value);
            $this->assertDatabaseCount('invoice_financial_corrections', 1);
            $this->assertDatabaseCount('invoice_collections', 1);
        });
    }

    public function test_phase_9c3a_prepare_vs_receivable_payout_rechecks_paid_dependency(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            Storage::fake('order_receipts');
            $fixture = $this->fixture();
            $ready = $this->readyInvoice($fixture);
            $invoice = app(InvoiceService::class)->issue($fixture['actor'], $fixture['order'], $ready['invoice']);
            app(InvoiceCollectionService::class)->record($fixture['actor'], $invoice,
                InvoiceCollectionMethod::ShamCash, $invoice->grand_total, $ready['currency']->id, now());
            $admin = User::factory()->create();
            $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
            foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
                OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
                OrderOperationalStatus::HeadingToCustomer] as $status) {
                app(OrderStatusService::class)->transition($fixture['actor'], $fixture['order']->fresh(), $status, null);
            }
            app(OrderShopProgressService::class)->record($fixture['actor'], $fixture['order']->fresh(), $ready['shop'], 'picked_up_at');
            app(OrderShopReceiptService::class)->upload($fixture['actor'], $fixture['order']->fresh(), $ready['shop'],
                UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')), null);
            app(OrderStatusService::class)->transition($fixture['actor'], $fixture['order']->fresh(), OrderOperationalStatus::Delivered, null);
            $due = app(FinancialBalanceQueryService::class)
                ->payableReceivableEntries($ready['captain'], $fixture['branch'], $ready['currency'])
                ->firstWhere('event_type', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE');
            $this->assertNotNull($due);
            $source = app(InvoiceFinancialCorrectionService::class)->request($fixture['actor'], $invoice,
                InvoiceFinancialCorrectionKind::Reprice,
                ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                    'delivery' => [['order_captain_id' => $fixture['order']->captainAssignments()->sole()->id,
                        'customer_amount' => '5.00']]],
                'Concurrent payout', 'Product subtotal must decrease', (string) Str::uuid());
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'correction_prepare', 'actor_id' => $admin->id,
                    'order_id' => $fixture['order']->id, 'correction_id' => $source->id],
                ['operation' => 'receivable_payout', 'actor_id' => $admin->id,
                    'order_id' => $fixture['order']->id, 'captain_id' => $ready['captain']->id,
                    'branch_id' => $fixture['branch']->id, 'currency_id' => $ready['currency']->id,
                    'request_key' => (string) Str::uuid(), 'amount' => (string) $due->amount,
                    'occurred_at' => now()->subSecond()->toIso8601String(), 'reference' => 'RACE-PREPARE-PAYOUT',
                    'financial_entry_id' => $due->financial_entry_id],
            ], $ready['currency']);
            // Either worker may acquire the Currency lock first; PREPARE must capture
            // exactly the committed payout, then reserve the original source in both cases.
            $this->assertSame('success', $results[0]['status'], json_encode($results));
            $this->assertContains($results[1]['status'], ['success', 'validation'], json_encode($results));
            $source = $source->fresh();
            $this->assertSame('PREPARED', $source->state->value);
            $this->assertTrue($source->receivable_dispositions_captured);
            $disposition = $source->receivableDispositions()
                ->where('original_receivable_financial_entry_id', $due->financial_entry_id)->sole();
            $this->assertSame($ready['captain']->id, $disposition->captain_id);
            $this->assertSame($fixture['branch']->id, $disposition->branch_id);
            $this->assertSame($ready['currency']->id, $disposition->currency_id);
            $paidFirst = $results[1]['status'] === 'success';
            $expected = [
                'original_entitlement' => '10.0000', 'corrected_entitlement' => '8.0000',
                'historical_paid' => $paidFirst ? '10.0000' : '0.0000',
                'original_unpaid' => $paidFirst ? '0.0000' : '10.0000',
                'residual_payable' => $paidFirst ? '0.0000' : '8.0000',
                'recovery_required' => $paidFirst ? '2.0000' : '0.0000',
            ];
            foreach ($expected as $field => $amount) {
                $this->assertSame($amount, (string) BigDecimal::of($disposition->$field)->toScale(4), $field);
            }
            $this->assertSame($paidFirst ? 2 : 1, $source->receivable_contract_version);
            $this->assertDatabaseCount('captain_receivable_payouts', $paidFirst ? 1 : 0);
            $this->assertDatabaseCount('captain_receivable_payout_allocations', $paidFirst ? 1 : 0);
            $this->assertCount($paidFirst ? 1 : 0, $disposition->allocations);
            if ($paidFirst) {
                $allocation = DB::table('captain_receivable_payout_allocations')->sole();
                $this->assertSame($due->financial_entry_id, $allocation->financial_entry_id);
                $this->assertSame('10.0000', (string) BigDecimal::of($allocation->amount)->toScale(4));
                $frozen = $disposition->allocations->sole();
                $this->assertSame($allocation->id, $frozen->captain_receivable_payout_allocation_id);
                $this->assertSame($allocation->captain_receivable_payout_id, $frozen->captain_receivable_payout_id);
                $this->assertSame('10.0000', (string) BigDecimal::of($frozen->amount)->toScale(4));
            } else {
                $this->assertSame(['amount' => ['Payout exceeds the payable Receivable in this Branch and Currency.']],
                    $results[1]['errors']);
            }
            $this->assertNull(app(FinancialBalanceQueryService::class)
                ->payableReceivableEntries($ready['captain'], $fixture['branch'], $ready['currency'])
                ->firstWhere('financial_entry_id', $due->financial_entry_id));
            $this->assertSame(0, DB::table('financial_transactions')->whereIn('event_type', [
                'RECEIVABLE_UNPAID_REMAINDER_RETIRED', 'RECEIVABLE_CORRECTED_RESIDUAL_DUE',
            ])->count());
            $this->assertDatabaseCount('invoice_correction_reversals', 0);
            foreach (DB::table('captain_receivable_payouts')->pluck('evidence_path') as $path) {
                Storage::disk('captain_financial_evidence')->delete($path);
            }
        });
    }

    public function test_phase_9c3a_finalize_vs_original_receivable_payout_has_one_financial_winner(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            Storage::fake('order_receipts');
            $fixture = $this->fixture();
            $ready = $this->readyInvoice($fixture);
            $invoice = app(InvoiceService::class)->issue($fixture['actor'], $fixture['order'], $ready['invoice']);
            app(InvoiceCollectionService::class)->record($fixture['actor'], $invoice,
                InvoiceCollectionMethod::ShamCash, $invoice->grand_total, $ready['currency']->id, now());
            $admin = User::factory()->create();
            $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
            foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
                OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
                OrderOperationalStatus::HeadingToCustomer] as $status) {
                app(OrderStatusService::class)->transition($fixture['actor'], $fixture['order']->fresh(), $status, null);
            }
            app(OrderShopProgressService::class)->record($fixture['actor'], $fixture['order']->fresh(), $ready['shop'], 'picked_up_at');
            app(OrderShopReceiptService::class)->upload($fixture['actor'], $fixture['order']->fresh(), $ready['shop'],
                UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')), null);
            app(OrderStatusService::class)->transition($fixture['actor'], $fixture['order']->fresh(), OrderOperationalStatus::Delivered, null);
            $due = app(FinancialBalanceQueryService::class)
                ->payableReceivableEntries($ready['captain'], $fixture['branch'], $ready['currency'])
                ->firstWhere('event_type', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE');
            $this->assertNotNull($due);
            $source = app(InvoiceFinancialCorrectionService::class)->request($fixture['actor'], $invoice,
                InvoiceFinancialCorrectionKind::Reprice,
                ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                    'delivery' => [['order_captain_id' => $fixture['order']->captainAssignments()->sole()->id,
                        'customer_amount' => '5.00']]],
                'Concurrent payout', 'Product subtotal must decrease', (string) Str::uuid());
            app(InvoiceFinancialCorrectionService::class)->prepare($admin, $source);
            app(CustomerRefundService::class)->record($admin, $source, (string) Str::uuid(), now(), 'RACE-REFUND',
                UploadedFile::fake()->createWithContent('refund.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')));
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'correction_finalize', 'actor_id' => $admin->id,
                    'order_id' => $fixture['order']->id, 'correction_id' => $source->id],
                ['operation' => 'receivable_payout', 'actor_id' => $admin->id,
                    'order_id' => $fixture['order']->id, 'captain_id' => $ready['captain']->id,
                    'branch_id' => $fixture['branch']->id, 'currency_id' => $ready['currency']->id,
                    'request_key' => (string) Str::uuid(), 'amount' => (string) $due->amount,
                    'occurred_at' => now()->subSecond()->toIso8601String(), 'reference' => 'RACE-PAYOUT',
                    'financial_entry_id' => $due->financial_entry_id],
            ], $ready['currency']);
            $this->assertOneSuccessAndOneValidation($results);
            $completed = $source->fresh()->state->value === 'COMPLETED';
            $paid = DB::table('captain_receivable_payout_allocations')
                ->where('financial_entry_id', $due->financial_entry_id)->exists();
            $this->assertNotSame($completed, $paid);
            $this->assertSame($completed ? 1 : 0,
                DB::table('invoice_correction_reversals')->where('invoice_financial_correction_id', $source->id)->count() > 0 ? 1 : 0);
            foreach (DB::table('customer_refunds')->where('invoice_financial_correction_id', $source->id)->pluck('evidence_path') as $path) {
                Storage::disk('customer_refunds')->delete($path);
            }
            foreach (DB::table('captain_receivable_payouts')->pluck('evidence_path') as $path) {
                Storage::disk('captain_financial_evidence')->delete($path);
            }
        });
    }

    public function test_phase_9c3a_correction_prepare_and_delivery_never_leave_unposted_delivered_finances(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $ready = $this->readyInvoice($fixture);
            $invoice = app(InvoiceService::class)->issue($fixture['actor'], $fixture['order'], $ready['invoice']);
            app(InvoiceCollectionService::class)->record($fixture['actor'], $invoice,
                InvoiceCollectionMethod::Cash, $invoice->grand_total, $ready['currency']->id, now());
            $admin = User::factory()->create();
            $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
            foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned,
                OrderOperationalStatus::HeadingToShops, OrderOperationalStatus::Shopping,
                OrderOperationalStatus::HeadingToCustomer] as $status) {
                app(OrderStatusService::class)->transition($fixture['actor'], $fixture['order']->fresh(), $status, null);
            }
            app(OrderShopProgressService::class)->record($fixture['actor'], $fixture['order']->fresh(), $ready['shop'], 'picked_up_at');
            app(OrderShopReceiptService::class)->upload($fixture['actor'], $fixture['order']->fresh(), $ready['shop'],
                UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')), null);
            $source = app(InvoiceFinancialCorrectionService::class)->request($fixture['actor'], $invoice,
                InvoiceFinancialCorrectionKind::Reprice,
                ['items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                    'delivery' => [['order_captain_id' => $fixture['order']->captainAssignments()->sole()->id,
                        'customer_amount' => '5.00']]],
                'Concurrent delivery', 'Correct historical price', (string) Str::uuid());
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'correction_prepare', 'actor_id' => $admin->id,
                    'order_id' => $fixture['order']->id, 'correction_id' => $source->id],
                ['operation' => 'deliver', 'actor_id' => $fixture['actor']->id,
                    'order_id' => $fixture['order']->id],
            ]);
            $this->assertSame('success', $results[0]['status'], json_encode($results));
            $this->assertContains($results[1]['status'], ['success', 'validation'], json_encode($results));
            $delivered = $fixture['order']->fresh()->operational_status === OrderOperationalStatus::Delivered;
            $originalEvents = DB::table('financial_transactions')->where('source_type', 'INVOICE_COLLECTION')->count();
            $this->assertSame($delivered, $originalEvents > 0);
            $this->assertSame('PREPARED', $source->fresh()->state->value);
        });
    }

    public function test_phase_9c3a_real_request_refund_and_finalization_workers_keep_one_canonical_history(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $ready = $this->readyInvoice($fixture);
            $invoice = app(InvoiceService::class)->issue($fixture['actor'], $fixture['order'], $ready['invoice']);
            app(InvoiceCollectionService::class)->record($fixture['actor'], $invoice,
                InvoiceCollectionMethod::Cash, $invoice->grand_total, $ready['currency']->id, now());
            $admin = User::factory()->create();
            $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
            $proposal = [
                'items' => [['invoice_item_id' => $invoice->items()->sole()->id, 'unit_price' => '8.00']],
                'delivery' => [['order_captain_id' => $fixture['order']->captainAssignments()->sole()->id,
                    'customer_amount' => '5.00']],
            ];
            $job = fn (): array => [
                'operation' => 'correction_request', 'actor_id' => $fixture['actor']->id,
                'order_id' => $fixture['order']->id, 'invoice_id' => $invoice->id,
                'kind' => InvoiceFinancialCorrectionKind::Reprice->value, 'proposal' => $proposal,
                'reason' => 'Concurrent correction', 'explanation' => 'Verified customer amount',
                'request_key' => (string) Str::uuid(),
            ];
            $results = $this->raceServices($fixture['order'], [$job(), $job()]);
            $this->assertOneSuccessAndOneValidation($results);
            $correction = InvoiceFinancialCorrection::query()->sole();
            app(InvoiceFinancialCorrectionService::class)->prepare($admin, $correction);

            $refundAt = now()->format('Y-m-d H:i:s.uP');
            $refundJob = fn (): array => [
                'operation' => 'correction_refund', 'actor_id' => $admin->id,
                'order_id' => $fixture['order']->id, 'correction_id' => $correction->id,
                'request_key' => (string) Str::uuid(), 'reference' => 'ONE-REAL-REFUND',
                'refunded_at' => $refundAt,
            ];
            $results = $this->raceServices($fixture['order'], [$refundJob(), $refundJob()]);
            $this->assertOneSuccessAndOneValidation($results);
            $this->assertSame(1, DB::table('customer_refunds')->where('invoice_financial_correction_id', $correction->id)->count());

            $finalize = ['operation' => 'correction_finalize', 'actor_id' => $admin->id,
                'order_id' => $fixture['order']->id, 'correction_id' => $correction->id];
            $recordedRefund = DB::table('customer_refunds')->where('invoice_financial_correction_id', $correction->id)->sole();
            $retry = ['operation' => 'correction_refund', 'actor_id' => $admin->id,
                'order_id' => $fixture['order']->id, 'correction_id' => $correction->id,
                'request_key' => $recordedRefund->request_key, 'reference' => $recordedRefund->reference,
                'refunded_at' => $refundAt];
            $results = $this->raceServices($fixture['order'], [$retry, $finalize]);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $this->assertSame(1, DB::table('customer_refunds')->where('invoice_financial_correction_id', $correction->id)->count());
            $results = $this->raceServices($fixture['order'], [$finalize, $finalize]);
            $this->assertSame(['success' => 2], collect($results)->countBy('status')->all(), json_encode($results));
            $this->assertSame(1, DB::table('invoices')->where('order_id', $fixture['order']->id)->where('version', 2)->count());
            $this->assertSame('COMPLETED', DB::table('invoice_financial_corrections')->where('id', $correction->id)->value('state'));

            foreach (DB::table('customer_refunds')->where('invoice_financial_correction_id', $correction->id)->pluck('evidence_path') as $path) {
                Storage::disk('customer_refunds')->delete($path);
            }
        });
    }

    public function test_two_real_captain_rating_services_serialize_and_return_a_controlled_conflict(): void
    {
        $this->requirePostgresConcurrency();
        $fixture = $this->fixture();
        $captain = $this->captain($fixture['branch']);
        app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
        DB::transaction(function () use ($fixture): void {
            $previous = $fixture['order']->fresh()->operational_status;
            $fixture['order']->forceFill(['operational_status' => OrderOperationalStatus::Delivered])->save();
            $fixture['order']->statusHistory()->create(['previous_status' => $previous, 'new_status' => OrderOperationalStatus::Delivered,
                'changed_by' => $fixture['actor']->id, 'changed_at' => now()]);
        });

        try {
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'rating', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'captain_id' => $captain->id, 'rating' => 5],
                ['operation' => 'rating', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'captain_id' => $captain->id, 'rating' => 4],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $this->assertSame(1, DB::table('captain_ratings')->where(['order_id' => $fixture['order']->id, 'captain_id' => $captain->id])->count());
            $conflict = collect($results)->firstWhere('status', 'validation');
            $this->assertSame('يوجد تقييم لهذا الكابتن على الطلب بالفعل.', $conflict['errors']['captain_id'][0] ?? null);
        } finally {
            DB::table('captain_ratings')->where('order_id', $fixture['order']->id)->delete();
            DB::table('order_captains')->where('order_id', $fixture['order']->id)->delete();
            $this->deleteCaptain($captain);
            $this->cleanup($fixture);
        }
    }

    public function test_real_cancellation_and_delivery_services_cannot_both_finalize_the_order(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Purchased', 'sort_order' => 1, 'picked_up_at' => now()]);
            DB::table('order_shop_receipts')->insert(['order_shop_id' => $shop->id, 'storage_path' => 'concurrency/'.$fixture['order']->id.'/receipt.png', 'original_filename' => 'receipt.png', 'mime_type' => 'image/png', 'file_size' => 68, 'uploaded_by' => $fixture['actor']->id, 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $currency = Currency::create(['name' => 'Purchase currency', 'code' => 'PCR', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            OrderShopPurchaseConfirmation::create(['order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id,
                'order_captain_id' => $assignment->id, 'branch_id' => $fixture['branch']->id, 'currency_id' => $currency->id,
                'amount' => '10.00', 'purchased_at' => now()->subMinute(), 'recorded_by' => $fixture['actor']->id,
                'request_key' => (string) Str::uuid(), 'evidence_path' => 'test/purchase.png', 'evidence_name' => 'purchase.png',
                'evidence_mime' => 'image/png', 'evidence_size' => 68, 'evidence_sha256' => str_repeat('a', 64)]);
            $fixture['order']->forceFill(['operational_status' => OrderOperationalStatus::HeadingToCustomer])->save();

            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_after', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'request_key' => (string) Str::uuid()],
                ['operation' => 'deliver', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $status = $fixture['order']->fresh()->operational_status;
            $intent = DB::table('order_cancellation_intents')->where('order_id', $fixture['order']->id)->exists();
            $this->assertContains($status, [OrderOperationalStatus::HeadingToCustomer, OrderOperationalStatus::Delivered]);
            $this->assertSame($status !== OrderOperationalStatus::Delivered, $intent);
        });
    }

    public function test_real_cancellation_and_invoice_issue_services_cannot_both_succeed(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $invoiceFixture = $this->readyInvoice($fixture);
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_before', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
                ['operation' => 'issue', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'invoice_id' => $invoiceFixture['invoice']->id],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $cancelled = $fixture['order']->fresh()->operational_status === OrderOperationalStatus::Cancelled;
            $issued = $invoiceFixture['invoice']->fresh()->status === InvoiceStatus::Issued;
            $this->assertNotSame($cancelled, $issued);
        });
    }

    public function test_real_before_purchase_cancellation_and_receipt_services_cannot_both_succeed(): void
    {
        $this->requirePostgresConcurrency();
        $fixture = $this->fixture();
        $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Not purchased', 'sort_order' => 1]);

        try {
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_before', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
                ['operation' => 'receipt', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id],
            ]);

            $this->assertOneSuccessAndOneValidation($results);
            $beforePurchase = DB::table('order_cancellations')->where(['order_id' => $fixture['order']->id, 'classification' => 'BEFORE_PURCHASE'])->exists();
            $receiptExists = DB::table('order_shop_receipts')->where('order_shop_id', $shop->id)->exists();
            $this->assertNotSame($beforePurchase, $receiptExists);
        } finally {
            foreach (DB::table('order_shop_receipts')->where('order_shop_id', $shop->id)->pluck('storage_path') as $path) {
                Storage::disk('order_receipts')->delete($path);
            }
            DB::table('order_cancellations')->where('order_id', $fixture['order']->id)->delete();
            DB::table('order_shop_receipts')->where('order_shop_id', $shop->id)->delete();
            DB::table('order_shops')->where('id', $shop->id)->delete();
            $this->cleanup($fixture);
        }
    }

    public function test_purchase_vs_intent_freezes_the_exact_committed_purchase_set(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $currency = Currency::create(['name' => 'Return currency', 'code' => 'RTC', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            $first = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'First', 'sort_order' => 1]);
            $second = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Second', 'sort_order' => 2]);
            $this->confirmedPurchase($fixture, $first, $assignment->id, $currency->id);

            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'purchase', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'order_shop_id' => $second->id, 'order_captain_id' => $assignment->id, 'currency_id' => $currency->id,
                    'request_key' => (string) Str::uuid()],
                ['operation' => 'cancel_after', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'request_key' => (string) Str::uuid()],
            ]);
            $this->assertNotContains('error', array_column($results, 'status'), json_encode($results));
            $this->assertSame('success', $results[1]['status']);
            $intent = DB::table('order_cancellation_intents')->where('order_id', $fixture['order']->id)->first();
            $this->assertNotNull($intent);
            $purchases = DB::table('order_shop_purchase_confirmations')->where('order_id', $fixture['order']->id)->pluck('id')->sort()->values()->all();
            $required = DB::table('order_cancellation_intent_shops')->where('order_cancellation_intent_id', $intent->id)
                ->pluck('purchase_confirmation_id')->sort()->values()->all();
            $this->assertSame($purchases, $required);
            $this->assertSame($results[0]['status'] === 'success' ? 2 : 1, count($purchases));
            foreach (DB::table('order_shop_purchase_confirmations')->where('order_shop_id', $second->id)->pluck('evidence_path') as $path) {
                Storage::disk('order_returns')->delete($path);
            }
        });
    }

    public function test_duplicate_purchase_confirmation_uses_one_authoritative_source(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $currency = Currency::create(['name' => 'Purchase currency', 'code' => 'PCD', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'One purchase', 'sort_order' => 1]);
            $job = fn (): array => ['operation' => 'purchase', 'actor_id' => $fixture['actor']->id,
                'order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id,
                'order_captain_id' => $assignment->id, 'currency_id' => $currency->id,
                'request_key' => (string) Str::uuid()];
            $results = $this->raceServices($fixture['order'], [$job(), $job()]);
            $this->assertOneSuccessAndOneValidation($results);
            $this->assertSame(1, DB::table('order_shop_purchase_confirmations')->where('order_shop_id', $shop->id)->count());
            foreach (DB::table('order_shop_purchase_confirmations')->where('order_shop_id', $shop->id)->pluck('evidence_path') as $path) {
                Storage::disk('order_returns')->delete($path);
            }
        });
    }

    public function test_real_after_purchase_intent_and_invoice_issue_cannot_both_succeed(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $ready = $this->readyInvoice($fixture);
            $assignmentId = $fixture['order']->captainAssignments()->value('id');
            $this->confirmedPurchase($fixture, $ready['shop'], $assignmentId, $ready['currency']->id);
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'cancel_after', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'request_key' => (string) Str::uuid()],
                ['operation' => 'issue', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'invoice_id' => $ready['invoice']->id],
            ]);
            $this->assertOneSuccessAndOneValidation($results);
            $intent = DB::table('order_cancellation_intents')->where('order_id', $fixture['order']->id)->exists();
            $issued = $ready['invoice']->fresh()->status === InvoiceStatus::Issued;
            $this->assertNotSame($intent, $issued);
        });
    }

    public function test_duplicate_return_and_last_return_vs_finalization_use_real_services(): void
    {
        $this->requirePostgresConcurrency();
        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $currency = Currency::create(['name' => 'Return currency', 'code' => 'RTD', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Purchased', 'sort_order' => 1]);
            $this->confirmedPurchase($fixture, $shop, $assignment->id, $currency->id);
            app(OrderCancellationIntentService::class)->create($fixture['actor'], $fixture['order'],
                OrderCancellationReason::CustomerCancelled, 'Concurrent cancellation', (string) Str::uuid());

            $returnJob = fn (): array => ['operation' => 'return', 'actor_id' => $fixture['actor']->id,
                'order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id,
                'order_captain_id' => $assignment->id, 'request_key' => (string) Str::uuid()];
            $results = $this->raceServices($fixture['order'], [$returnJob(), $returnJob()]);
            $this->assertOneSuccessAndOneValidation($results);
            $this->assertSame(1, DB::table('order_shop_returns')->where('order_id', $fixture['order']->id)->count());
            app(OrderCancellationFinalizationService::class)->finalize($fixture['actor'], $fixture['order']);
            $this->assertSame(OrderOperationalStatus::Cancelled, $fixture['order']->fresh()->operational_status);
            $this->deleteReturnEvidence($fixture['order']);
        });

        $this->withIsolatedSchema(function (): void {
            $fixture = $this->fixture();
            $currency = Currency::create(['name' => 'Return currency', 'code' => 'RTL', 'decimal_places' => 2]);
            $captain = $this->captain($fixture['branch']);
            $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
            $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime,
                'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Purchased', 'sort_order' => 1]);
            $this->confirmedPurchase($fixture, $shop, $assignment->id, $currency->id);
            app(OrderCancellationIntentService::class)->create($fixture['actor'], $fixture['order'],
                OrderCancellationReason::CustomerCancelled, 'Concurrent cancellation', (string) Str::uuid());
            $results = $this->raceServices($fixture['order'], [
                ['operation' => 'return', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id,
                    'order_shop_id' => $shop->id, 'order_captain_id' => $assignment->id, 'request_key' => (string) Str::uuid()],
                ['operation' => 'finalize', 'actor_id' => $fixture['actor']->id, 'order_id' => $fixture['order']->id],
            ]);
            $this->assertSame('success', $results[0]['status'], json_encode($results));
            $this->assertContains($results[1]['status'], ['success', 'validation'], json_encode($results));
            $this->assertSame(1, DB::table('order_shop_returns')->where('order_id', $fixture['order']->id)->count());
            if ($results[1]['status'] === 'validation') {
                app(OrderCancellationFinalizationService::class)->finalize($fixture['actor'], $fixture['order']);
            }
            $this->assertSame(OrderOperationalStatus::Cancelled, $fixture['order']->fresh()->operational_status);
            $this->deleteReturnEvidence($fixture['order']);
        });
    }

    private function deleteReturnEvidence(Order $order): void
    {
        foreach (DB::table('order_shop_return_evidence as e')->join('order_shop_returns as r', 'r.id', '=', 'e.order_shop_return_id')
            ->where('r.order_id', $order->id)->pluck('e.storage_path') as $path) {
            Storage::disk('order_returns')->delete($path);
        }
    }

    private function confirmedPurchase(array $fixture, OrderShop $shop, int $assignmentId, int $currencyId): void
    {
        DB::table('order_shop_purchase_confirmations')->insert([
            'order_id' => $fixture['order']->id, 'order_shop_id' => $shop->id, 'order_captain_id' => $assignmentId,
            'branch_id' => $fixture['branch']->id, 'currency_id' => $currencyId, 'amount' => '10.00',
            'purchased_at' => DB::raw("clock_timestamp() - interval '5 minutes'"), 'recorded_by' => $fixture['actor']->id,
            'request_key' => (string) Str::uuid(), 'evidence_path' => 'test/'.Str::uuid().'.png',
            'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png', 'evidence_size' => 68,
            'evidence_sha256' => str_repeat('a', 64), 'created_at' => DB::raw('clock_timestamp()'),
            'updated_at' => DB::raw('clock_timestamp()'),
        ]);
    }

    private function raceServices(Order $order, array $jobs, ?Currency $lockedCurrency = null): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'abshir-phase85-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $goPath = $directory.DIRECTORY_SEPARATOR.'go';
        $processes = [];

        DB::beginTransaction();

        try {
            if ($lockedCurrency) {
                DB::table('currencies')->where('id', $lockedCurrency->id)->lockForUpdate()->firstOrFail();
            } else {
                DB::table('orders')->where('id', $order->id)->lockForUpdate()->firstOrFail();
            }

            foreach ($jobs as $index => $job) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR."payload-{$index}.json";
                $readyPath = $directory.DIRECTORY_SEPARATOR."ready-{$index}";
                $resultPath = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                file_put_contents($payloadPath, json_encode($job, JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/phase85_service_worker.php'), $payloadPath, $readyPath, $goPath, $resultPath], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $processes[] = compact('process', 'readyPath', 'resultPath');
            }

            $this->waitForWorkersReady($processes);
            touch($goPath);
            usleep(500_000);
            foreach ($processes as $worker) {
                $this->assertTrue($worker['process']->isRunning(), 'A service worker did not wait for the locked Order or Currency. '.$worker['process']->getErrorOutput());
            }

            DB::commit();
            $results = [];
            foreach ($processes as $worker) {
                $worker['process']->wait();
                $this->assertTrue($worker['process']->isSuccessful(), $worker['process']->getErrorOutput().$worker['process']->getOutput());
                $this->assertFileExists($worker['resultPath']);
                $results[] = json_decode(file_get_contents($worker['resultPath']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop();
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function waitForWorkersReady(array $workers): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (collect($workers)->every(fn (array $worker): bool => file_exists($worker['readyPath']))) {
                return;
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isTerminated()) {
                    $this->fail('A service worker exited before the start barrier. '.$worker['process']->getErrorOutput().$worker['process']->getOutput());
                }
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);

        $this->fail('Timed out waiting for real Laravel service workers.');
    }

    private function assertOneSuccessAndOneValidation(array $results): void
    {
        $this->assertSame(['success' => 1, 'validation' => 1], collect($results)->countBy('status')->sortKeys()->all(), json_encode($results, JSON_PRETTY_PRINT));
    }

    private function workerEnvironment(): array
    {
        $database = config('database.connections.'.config('database.default'));

        return [
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'DB_SCHEMA' => (string) $database['search_path'],
        ];
    }

    private function readyInvoice(array $fixture): array
    {
        $currency = Currency::create(['name' => 'Race Currency', 'code' => 'R'.random_int(10, 99), 'decimal_places' => 2]);
        $fixture['branch']->update(['default_currency_id' => $currency->id]);
        $origin = Area::factory()->for($fixture['branch'])->create(['name' => 'Race Origin']);
        $shop = OrderShop::create(['order_id' => $fixture['order']->id, 'shop_type' => OrderShopType::OneTime, 'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Invoice Race Shop', 'sort_order' => 1]);
        $item = OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime, 'product_name_snapshot' => 'Race Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1', 'current_quantity' => '1', 'actual_unit_price' => '10.00', 'actual_price_source' => ActualPriceSource::ManualVerified, 'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $fixture['actor']->id]);
        $fixture['order']->forceFill(['pricing_class' => OrderPricingClass::SingleShop, 'pricing_status' => OrderPricingStatus::Confirmed])->save();
        $captain = $this->captain($fixture['branch']);
        $assignment = app(OrderCaptainService::class)->assign($fixture['actor'], $fixture['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $pricing = $fixture['order']->deliveryPricings()->create(['version' => 1, 'from_area_id' => $origin->id, 'to_area_id' => $fixture['area']->id, 'currency_id' => $currency->id, 'order_pricing_class_snapshot' => OrderPricingClass::SingleShop, 'suggested_unit_fee' => '5.00', 'applied_unit_fee' => '5.00', 'captain_count_snapshot' => 1, 'captain_share_percent_snapshot' => '65.00', 'gross_delivery_total' => '5.00', 'discount_amount' => '0', 'customer_delivery_total' => '5.00', 'status' => 'PRICED', 'priced_at' => now(), 'created_by' => $fixture['actor']->id]);
        $pricing->allocations()->create(['order_captain_id' => $assignment->id, 'captain_id' => $captain->id,
            'currency_id' => $currency->id, 'applied_unit_fee' => '5.00', 'captain_share_percent_snapshot' => '65.00',
            'captain_entitlement' => '3.25', 'discount_allocated' => '0', 'customer_delivery_amount_allocated' => '5.00',
            'company_collectible' => '1.75', 'captain_shortfall' => '0']);
        $invoice = app(InvoiceService::class)->createDraft($fixture['actor'], $fixture['order']);
        $invoice = app(InvoiceService::class)->refresh($fixture['actor'], $fixture['order'], $invoice);

        return compact('currency', 'origin', 'shop', 'item', 'captain', 'pricing', 'invoice');
    }

    private function cleanupInvoiceFixture(array $fixture, array $invoiceFixture): void
    {
        DB::table('order_cancellations')->where('order_id', $fixture['order']->id)->delete();
        DB::table('invoice_items')->where('invoice_id', $invoiceFixture['invoice']->id)->delete();
        Invoice::query()->whereKey($invoiceFixture['invoice']->id)->delete();
        DB::table('order_delivery_pricings')->where('order_id', $fixture['order']->id)->delete();
        DB::table('order_items')->where('order_shop_id', $invoiceFixture['shop']->id)->delete();
        DB::table('order_shops')->where('id', $invoiceFixture['shop']->id)->delete();
        DB::table('order_captains')->where('order_id', $fixture['order']->id)->delete();
        $this->deleteCaptain($invoiceFixture['captain']);
        $fixture['branch']->update(['default_currency_id' => null]);
        DB::table('areas')->where('id', $invoiceFixture['origin']->id)->delete();
        DB::table('currencies')->where('id', $invoiceFixture['currency']->id)->delete();
    }

    private function fixture(): array
    {
        if (str_contains($this->name(), 'phase_9c3a_')) {
            $this->migrateAccountingV1Schema();
        } else {
            Artisan::call('migrate', ['--force' => true]);
        }
        $this->seed(AuthorizationSeeder::class);
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Phase 8.5 concurrency']);
        $phone = $customer->phones()->create(['phone' => uniqid('96'), 'normalized_phone' => uniqid('96')]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Concurrency street']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $order = app(OrderService::class)->create($actor, ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);

        return compact('branch', 'area', 'customer', 'phone', 'address', 'actor', 'order');
    }

    private function captain(Branch $branch): Captain
    {
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $captain;
    }

    private function deleteCaptain(Captain $captain): void
    {
        $userId = $captain->user_id;
        DB::table('captain_employment_periods')->where('captain_id', $captain->id)->delete();
        DB::table('captains')->where('id', $captain->id)->delete();
        DB::table('users')->where('id', $userId)->delete();
    }

    private function cleanup(array $fixture): void
    {
        DB::table('order_status_history')->where('order_id', $fixture['order']->id)->delete();
        DB::table('orders')->where('id', $fixture['order']->id)->delete();
        DB::table('user_roles')->where('user_id', $fixture['actor']->id)->delete();
        DB::table('user_branches')->where('user_id', $fixture['actor']->id)->delete();
        DB::table('users')->where('id', $fixture['actor']->id)->delete();
        DB::table('customer_addresses')->where('customer_id', $fixture['customer']->id)->delete();
        DB::table('customer_phones')->where('customer_id', $fixture['customer']->id)->delete();
        DB::table('customers')->where('id', $fixture['customer']->id)->delete();
        DB::table('areas')->where('id', $fixture['area']->id)->delete();
        DB::table('branches')->where('id', $fixture['branch']->id)->delete();
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! class_exists(Process::class)) {
            $this->markTestSkipped('This locking regression test requires PostgreSQL and independent PHP worker processes.');
        }
    }

    private function withIsolatedSchema(callable $callback): void
    {
        $connection = (string) config('database.default');
        $key = "database.connections.{$connection}.search_path";
        $original = config($key);
        $schema = 'phase85_finance_'.str()->lower(str()->random(12));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            config([$key => $schema]);
            DB::purge($connection);
            DB::reconnect($connection);
            $callback();
        } finally {
            DB::purge($connection);
            config([$key => $original]);
            DB::reconnect($connection);
            DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }
    }
}
