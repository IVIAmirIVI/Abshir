<?php

namespace Tests\Feature;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\OrderOperationalStatus;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Services\FinancialBalanceQueryService;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use App\Services\SharedOrderShopPurchaseService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class OrderAccountingRoutingTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
    }

    #[DataProvider('routing')]
    public function test_server_validates_and_binds_contract_at_initial_insert(bool $enabled, int $count, int $expected): void
    {
        $c = $this->sharedContext();
        config(['order_accounting.enable_v3_single_captain' => $enabled]);
        $ids = array_slice(array_column($c['captains'], 'id'), 0, $count);
        $observed = [];
        Order::creating(function (Order $order) use (&$observed): void {
            $observed[] = (int) ($order->accounting_contract_version ?? 2);
        });
        $order = app(OrderService::class)->create($c['actor'], $this->data($c) + ['accounting_contract_version' => 1, 'participation_contract' => 'SINGLE'],
            $ids, $ids[0] ?? null);
        $this->assertSame([$expected], $observed);
        $this->assertSame($expected, (int) $order->fresh()->accounting_contract_version);
        $this->assertSame($count, $order->captainAssignments()->whereNull('removed_at')->count());
        $this->assertSame($count === 0 ? 0 : 1, $order->captainSelections()->count());
        if ($count) {
            $selection = $order->captainSelections()->sole();
            $this->assertSame($count === 1 ? 'SINGLE_V1' : 'SHARED_COMPANY_V1', $selection->contract);
            $this->assertSame($ids[0], $order->captainAssignments()->findOrFail($selection->collecting_order_captain_id)->captain_id);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function routing(): array
    {
        return [
            'enabled single' => [true, 1, 3], 'enabled shared' => [true, 2, 2], 'enabled unassigned' => [true, 0, 2],
            'disabled single' => [false, 1, 2], 'disabled shared' => [false, 2, 2], 'disabled unassigned' => [false, 0, 2],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_participation_is_rejected_before_any_order_insert(string $defect): void
    {
        $c = $this->sharedContext();
        config(['order_accounting.enable_v3_single_captain' => true]);
        $ids = [$c['captains'][0]->id];
        $collector = $ids[0];
        if ($defect === 'duplicate') {
            $ids[] = $ids[0];
        } elseif ($defect === 'nonparticipant collector') {
            $collector = $c['captains'][1]->id;
        } elseif ($defect === 'foreign branch') {
            $captain = Captain::factory()->create(['home_branch_id' => Branch::factory()->create()->id, 'operational_status' => 'AVAILABLE']);
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
            $ids = [$collector = $captain->id];
        } elseif ($defect === 'ineligible') {
            $c['captains'][0]->user->forceFill(['is_active' => false])->save();
        } elseif ($defect === 'no permission') {
            Role::where('code', 'ORDER_EMPLOYEE')->sole()->permissions()->detach(Permission::where('code', 'orders.captains.assign')->sole()->id);
        }
        $before = Order::count();
        $inserted = false;
        Order::creating(function () use (&$inserted): void {
            $inserted = true;
        });
        $this->assertThrows(fn () => app(OrderService::class)->create($c['actor'], $this->data($c), $ids, $collector),
            $defect === 'no permission' ? AuthorizationException::class : ValidationException::class);
        $this->assertFalse($inserted);
        $this->assertSame($before, Order::count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function invalidSelections(): array
    {
        return array_map(fn ($defect) => [$defect], ['duplicate', 'nonparticipant collector', 'foreign branch', 'ineligible', 'no permission']);
    }

    public function test_unknown_participation_retains_v2_and_later_shared_assignment_never_rebinds_it(): void
    {
        $c = $this->sharedContext();
        config(['order_accounting.enable_v3_single_captain' => true]);
        $order = app(OrderService::class)->create($c['actor'], $this->data($c));
        $this->assertSame(2, (int) $order->fresh()->accounting_contract_version);
        app(OrderCaptainSelectionService::class)->select($c['actor'], $order, array_column($c['captains'], 'id'), $c['captains'][1]->id, (string) str()->uuid());
        $this->assertSame(2, (int) $order->fresh()->accounting_contract_version);
        $this->assertSame(2, $order->captainAssignments()->whereNull('removed_at')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_selection_key_conflict_rolls_back_new_order_and_initial_assignments(): void
    {
        $c = $this->sharedContext();
        config(['order_accounting.enable_v3_single_captain' => true]);
        $key = (string) str()->uuid();
        app(OrderService::class)->create($c['actor'], $this->data($c), [$c['captains'][0]->id], $c['captains'][0]->id, $key);
        $before = ['orders' => Order::count(), 'assignments' => DB::table('order_captains')->count(), 'selections' => DB::table('order_captain_selections')->count()];
        $this->assertThrows(fn () => app(OrderService::class)->create($c['actor'], $this->data($c), [$c['captains'][0]->id], $c['captains'][0]->id, $key), ValidationException::class);
        $this->assertSame($before, ['orders' => Order::count(), 'assignments' => DB::table('order_captains')->count(), 'selections' => DB::table('order_captain_selections')->count()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('collectionMethods')]
    public function test_enabled_v3_preserves_real_shared_cash_and_sham_cash_workflows(string $method, string $expectedReceivable): void
    {
        config(['order_accounting.enable_v3_single_captain' => true]);
        $c = $this->sharedContext(0, 2, true);
        Storage::fake('order_receipts');
        Storage::fake('order_returns');
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'], $this->sharedData($c), $this->sharedImage());
        app(OrderShopReceiptService::class)->upload($c['actor'], $c['order'], $c['shop'], $this->sharedImage(), null);
        app(OrderShopProgressService::class)->record($c['actor'], $c['order'], $c['shop'], 'picked_up_at');
        $service = app(InvoiceService::class);
        $invoice = $service->issue($c['actor'], $c['order'], $service->refresh($c['actor'], $c['order'], $service->createDraft($c['actor'], $c['order'])));
        app(InvoiceCollectionService::class)->record($c['actor'], $invoice, InvoiceCollectionMethod::from($method), $invoice->grand_total, $c['currency']->id, now(), null, $method === 'SHAM_CASH' ? 'Company payment' : null);
        foreach (['CONFIRMED', 'CAPTAIN_ASSIGNED', 'HEADING_TO_SHOPS', 'SHOPPING', 'HEADING_TO_CUSTOMER', 'DELIVERED'] as $status) {
            app(OrderStatusService::class)->transition($c['actor'], $c['order'], OrderOperationalStatus::from($status), null);
        }
        $this->assertSame(2, (int) $c['order']->fresh()->accounting_contract_version);
        $this->assertSame('100.0000', $invoice->grand_total);
        $balances = app(FinancialBalanceQueryService::class);
        foreach ($c['captains'] as $index => $captain) {
            $actual = $balances->captainBalances($captain, $c['currency']);
            $this->assertSame('0.0000', $actual['CAPTAIN_DEPOSIT']);
            $this->assertSame($expectedReceivable, $actual['CAPTAIN_COMPANY_RECEIVABLE']);
            $this->assertSame($method === 'CASH' && $index === 0 ? '20.0000' : '0.0000', $actual['CAPTAIN_CASH_CUSTODY']);
        }
        $this->assertSame([2], FinancialTransaction::distinct()->pluck('source_version')->all());
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_collections', 1);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function collectionMethods(): array
    {
        return [['CASH', '5.0000'], ['SHAM_CASH', '45.0000']];
    }

    private function data(array $c): array
    {
        return $c['order']->only(['branch_id', 'customer_id', 'customer_phone_id', 'customer_address_id']) + ['source' => 'MANUAL', 'entry_mode' => 'LIVE'];
    }
}
