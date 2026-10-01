<?php

namespace Tests\Support;

use App\Enums\ActualPriceSource;
use App\Enums\CaptainOperationalStatus;
use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderItemType;
use App\Enums\OrderOperationalStatus;
use App\Enums\OrderPricingClass;
use App\Enums\OrderShopType;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainReceivablePayout;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\InvoiceFinancialCorrection;
use App\Models\OrderItem;
use App\Models\OrderShop;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainReceivablePayoutService;
use App\Services\CustomerRefundService;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\InvoiceCollectionService;
use App\Services\InvoiceFinancialCorrectionService;
use App\Services\InvoiceService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderPricingService;
use App\Services\OrderService;
use App\Services\OrderShopProgressService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReceiptService;
use App\Services\OrderStatusService;
use Brick\Math\BigDecimal;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait ReceivableDispositionFixtures
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function fixture(string $code, ?array $shared = null, string $captainPercent = '0', int $captainCount = 1, InvoiceCollectionMethod $method = InvoiceCollectionMethod::ShamCash, bool $deliver = true, bool $purchase = false): array
    {
        $this->seed(AuthorizationSeeder::class);
        foreach (['customer_refunds', 'order_receipts', 'captain_financial_evidence'] as $disk) {
            Storage::fake($disk);
        }
        $currency = $shared['currency'] ?? Currency::create(['name' => $code, 'code' => $code, 'decimal_places' => 2]);
        $branch = $shared['branch'] ?? Branch::factory()->create(['default_currency_id' => $currency->id]);
        $from = Area::factory()->for($branch)->create();
        $to = Area::factory()->for($branch)->create();
        $customer = Customer::create(['name' => 'Migration fixture']);
        $phone = $customer->phones()->create(['phone' => '123', 'normalized_phone' => '123']);
        $address = $customer->addresses()->create(['area_id' => $to->id, 'address_line_1' => 'Fixture street']);
        $admin = $shared['admin'] ?? User::factory()->create();
        $employee = $shared['employee'] ?? User::factory()->create();
        if (! $shared) {
            $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
            $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
            $employee->branches()->attach($branch);
        }
        $order = app(OrderService::class)->create($employee, ['branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL',
            'entry_mode' => 'HISTORICAL_MANUAL', 'ordered_at' => now()->subDay()]);
        $shop = OrderShop::create(['order_id' => $order->id, 'shop_type' => OrderShopType::OneTime,
            'business_classification_snapshot' => ShopBusinessClassification::Shop, 'shop_name_snapshot' => 'Fixture Shop', 'sort_order' => 1]);
        $order->update(['pricing_class' => OrderPricingClass::SingleShop]);
        OrderItem::create(['order_shop_id' => $shop->id, 'item_type' => OrderItemType::OneTime,
            'product_name_snapshot' => 'Fixture Item', 'unit_name_snapshot' => 'Piece', 'requested_quantity' => '1',
            'current_quantity' => '1', 'actual_unit_price' => (string) (100 * $captainCount), 'actual_price_source' => ActualPriceSource::ManualVerified,
            'actual_currency_id' => $currency->id, 'actual_price_confirmed_at' => now(), 'actual_price_confirmed_by' => $employee->id]);
        app(OrderPricingService::class)->recalculate($order);
        $captain = $shared['captain'] ?? Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $branch->id,
            'operational_status' => CaptainOperationalStatus::Available]);
        if (! $shared) {
            $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        }
        app(OrderCaptainService::class)->assign($employee, $order, $captain, OrderCaptainRole::PrimaryCollection);
        $captains = [$captain];
        for ($index = 1; $index < $captainCount; $index++) {
            $support = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
            $support->employmentPeriods()->create(['started_at' => now()->subMonth()]);
            app(OrderCaptainService::class)->assign($employee, $order, $support, OrderCaptainRole::Support);
            $captains[] = $support;
        }
        app(DeliveryPricingRuleService::class)->create($admin, ['from_area_id' => $from->id, 'to_area_id' => $to->id,
            'currency_id' => $currency->id, 'order_pricing_class' => OrderPricingClass::SingleShop->value,
            'delivery_unit_fee' => '100.00', 'effective_from' => now()->subDays(2), 'effective_until' => null]);
        app(DeliveryPricingSettings::class)->update($admin, $captainPercent, '0');
        app(OrderDeliveryPricingService::class)->price($employee, $order, $from);
        $invoices = app(InvoiceService::class);
        if ($purchase) {
            Storage::fake('order_returns');
            app(OrderShopPurchaseConfirmationService::class)->record($employee, $order->fresh(), $shop, [
                'order_captain_id' => $order->captainAssignments()->orderBy('id')->first()->id, 'currency_id' => $currency->id,
                'amount' => '100', 'purchased_at' => now()->subMinute()->toIso8601String(), 'request_key' => (string) Str::uuid()], $this->proof());
        }
        $invoice = $invoices->issue($employee, $order, $invoices->refresh($employee, $order, $invoices->createDraft($employee, $order)));
        $collection = app(InvoiceCollectionService::class)->record($employee, $invoice, $method, (string) (200 * $captainCount), $currency->id, now());
        foreach ([OrderOperationalStatus::Confirmed, OrderOperationalStatus::CaptainAssigned, OrderOperationalStatus::HeadingToShops,
            OrderOperationalStatus::Shopping, OrderOperationalStatus::HeadingToCustomer] as $status) {
            app(OrderStatusService::class)->transition($employee, $order->fresh(), $status, null);
        }
        app(OrderShopProgressService::class)->record($employee, $order->fresh(), $shop, 'picked_up_at');
        app(OrderShopReceiptService::class)->upload($employee, $order->fresh(), $shop, $this->proof(), null);
        if ($deliver) {
            app(OrderStatusService::class)->transition($employee, $order->fresh(), OrderOperationalStatus::Delivered, null);
        }

        return compact('currency', 'branch', 'employee', 'admin', 'captain', 'captains', 'order', 'invoice', 'collection');
    }

    private function proposal(array $f): array
    {
        return ['items' => [['invoice_item_id' => $f['invoice']->items()->sole()->id, 'unit_price' => '70.00']],
            'delivery' => [['order_captain_id' => $f['order']->captainAssignments()->sole()->id, 'customer_amount' => '5.00']]];
    }

    private function completeCorrection(array $f): InvoiceFinancialCorrection
    {
        $service = app(InvoiceFinancialCorrectionService::class);
        $correction = $service->request($f['employee'], $f['invoice'], InvoiceFinancialCorrectionKind::Reprice,
            $this->proposal($f), 'Correct price', 'Migration fixture', (string) Str::uuid());
        $service->prepare($f['admin'], $correction);
        app(CustomerRefundService::class)->record($f['admin'], $correction, (string) Str::uuid(), now(), 'MIGRATION-REFUND', $this->proof());

        return $service->finalize($f['admin'], $correction);
    }

    protected function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG));
    }

    protected function productEntry(array $f, ?Captain $captain = null): object
    {
        $captain ??= $f['captain'];

        return DB::table('financial_entries as e')->join('financial_transactions as t', 't.id', '=', 'e.financial_transaction_id')
            ->join('financial_accounts as a', 'a.id', '=', 'e.financial_account_id')->join('financial_owners as o', 'o.id', '=', 'a.financial_owner_id')
            ->where('t.source_type', 'INVOICE_COLLECTION')->where('t.source_id', (string) $f['collection']->id)
            ->where('t.event_type', 'ORDER_SHAM_CASH_PRODUCT_REIMBURSEMENT_DUE')->where('o.captain_id', $captain->id)->select('e.*')->sole();
    }

    protected function payout(array $f, object $entry, string $amount, ?Captain $captain = null): CaptainReceivablePayout
    {
        return app(CaptainReceivablePayoutService::class)->record($f['admin'], $captain ?? $f['captain'], $f['branch'], $f['currency'],
            ['request_key' => (string) Str::uuid(), 'amount' => $amount, 'occurred_at' => now()->subSecond()->toIso8601String(), 'reference' => 'DISPOSITION-PAYOUT'],
            [['financial_entry_id' => $entry->id, 'amount' => $amount]], $this->proof());
    }

    protected function correction(array $f, string $product, string $delivery = '100'): InvoiceFinancialCorrection
    {
        $proposal = ['items' => [['invoice_item_id' => $f['invoice']->items()->sole()->id, 'unit_price' => $product]],
            'delivery' => $f['order']->captainAssignments()->orderBy('id')->get()->map(fn ($a) => ['order_captain_id' => $a->id, 'customer_amount' => $delivery])->all()];

        return app(InvoiceFinancialCorrectionService::class)->request($f['employee'], $f['invoice'], InvoiceFinancialCorrectionKind::Reprice,
            $proposal, 'Correct actual price', 'Paid-source disposition regression', (string) Str::uuid());
    }

    protected function prepareAndRefund(array $f, InvoiceFinancialCorrection $c): InvoiceFinancialCorrection
    {
        $service = app(InvoiceFinancialCorrectionService::class);
        $c = $service->prepare($f['admin'], $c);
        if (BigDecimal::of($c->customer_delta)->isLessThan(0)) {
            app(CustomerRefundService::class)->record($f['admin'], $c, (string) Str::uuid(), now(), 'CUSTOMER-REFUND', $this->proof());
        }

        return $c->fresh();
    }
}
