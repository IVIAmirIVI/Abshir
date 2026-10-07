<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Livewire\Orders\AddShop;
use App\Livewire\Orders\CaptainAssignments;
use App\Livewire\Orders\CreateOrder;
use App\Livewire\Orders\NextAction;
use App\Livewire\Orders\OrderShopItems;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\Unit;
use App\Models\User;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\InvoiceService;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCancellationService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryOriginService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use App\Services\OrderService;
use App\Services\OrderShopService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OrderLivewireUxTest extends TestCase
{
    use RefreshDatabase;

    private static int $contextSequence = 0;

    public function test_next_action_uses_current_state_and_permission_before_suggesting_mutations(): void
    {
        $c = $this->context();
        $component = Livewire::actingAs($c['actor'])->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'shop')->assertSee(__('orders.next_steps.shop'))
            ->assertViewHas('canTransition', true)->assertSee('name="operational_status"', false);
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $component->dispatch('order-updated')->assertViewHas('step', 'products');
        $viewerRole = Role::create(['code' => 'NEXT_VIEWER', 'name' => 'Read only']);
        $viewerRole->permissions()->attach(Permission::where('code', 'orders.view')->sole());
        $viewer = User::factory()->create();
        $viewer->roles()->attach($viewerRole);
        $viewer->branches()->attach($c['branch']);
        Livewire::actingAs($viewer)->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'waiting')->assertDontSee(__('orders.next_steps.products'));

        app(OrderCancellationService::class)->cancel($c['actor'], $c['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);
        Livewire::actingAs($c['actor'])->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'cancelled')->assertDontSee('name="operational_status"', false);
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertDontSee('wire:submit="add"', false)->assertDontSee('wire:submit="assign"', false)
            ->assertDontSee(route('orders.shops.progress', [$c['order'], $shop]), false)
            ->assertDontSee(route('orders.invoices.store', $c['order']), false);
        $this->assertSame('CANCELLED', $c['order']->statusHistory()->reorder()->latest('id')->first()->new_status->value);
    }

    public function test_cancellation_intent_prioritizes_returns_and_hides_normal_mutation_controls(): void
    {
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $captain = $this->captain($c['branch'], 'كابتن الإلغاء');
        $assignment = app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $shop->purchaseConfirmation()->create([
            'order_id' => $c['order']->id, 'order_captain_id' => $assignment->id, 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'recorded_by' => $c['actor']->id, 'purchased_at' => now(),
            'amount' => '10', 'request_key' => (string) Str::uuid(), 'evidence_path' => 'test/'.Str::uuid().'.png',
            'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png', 'evidence_size' => 1, 'evidence_sha256' => str_repeat('a', 64),
        ]);
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()->assertDontSee(__('orders.ui_returns'))->assertSee(__('orders.ui_purchase_progress'));
        app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'], OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        Livewire::actingAs($c['actor'])->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'cancellation')->assertDontSee('name="operational_status"', false);
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee(__('orders.ui_returns'));
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertDontSee('wire:submit="add"', false)->assertDontSee('wire:submit="assign"', false)
            ->assertDontSee('wire:click="remove(', false)
            ->assertDontSee(route('orders.shops.progress', [$c['order'], $shop]), false);
    }

    public function test_next_action_skips_completed_prerequisites_and_keeps_issued_evidence_accessible(): void
    {
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $unit = Unit::create(['name' => 'قطعة متابعة', 'code' => 'NEXT_UNIT', 'symbol' => 'ق', 'allows_decimal' => false]);
        $item = app(OrderItemService::class)->add($c['actor'], $c['order'], $shop, ['item_type' => 'ONE_TIME_ITEM', 'item_name' => 'منتج متابعة', 'unit_id' => $unit->id, 'quantity' => '1']);
        $component = Livewire::actingAs($c['actor'])->test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'captain_unavailable');
        $captain = $this->captain($c['branch'], 'كابتن متابعة');
        $component->dispatch('order-updated')->assertViewHas('step', 'captain');
        $assignment = app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $component->dispatch('order-updated')->assertViewHas('step', 'purchase');
        $shop->purchaseConfirmation()->create([
            'order_id' => $c['order']->id, 'order_captain_id' => $assignment->id, 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'recorded_by' => $c['actor']->id, 'purchased_at' => now(),
            'amount' => '10', 'request_key' => (string) Str::uuid(), 'evidence_path' => 'test/'.Str::uuid().'.png',
            'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png', 'evidence_size' => 1, 'evidence_sha256' => str_repeat('a', 64),
        ]);
        $component->dispatch('order-updated')->assertViewHas('step', 'pricing_unavailable');
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        app(DeliveryPricingRuleService::class)->create($admin, [
            'from_area_id' => $c['area']->id, 'to_area_id' => $c['area']->id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '5', 'effective_from' => now()->subDay(),
        ]);
        app(DeliveryPricingSettings::class)->update($admin, '65', '0');
        $component->dispatch('order-updated')->assertViewHas('step', 'pricing');
        $pricing = app(OrderDeliveryPricingService::class);
        $pricing->price($c['actor'], $c['order'], $c['area']);
        $component->dispatch('order-updated')->assertViewHas('step', 'actual_prices');
        app(OrderItemService::class)->confirmActual($c['actor'], $c['order'], $shop, $item, [
            'actual_unit_price' => '10', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED',
        ]);
        $component->dispatch('order-updated')->assertViewHas('step', 'invoice');
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->createDraft($c['actor'], $c['order']);
        $component->dispatch('order-updated')->assertViewHas('step', 'invoice_refresh');
        $invoice = $invoices->refresh($c['actor'], $c['order'], $invoice);
        $component->dispatch('order-updated')->assertViewHas('step', 'invoice_issue');
        $pricing->price($c['actor'], $c['order'], $c['area']);
        $component->dispatch('order-updated')->assertViewHas('step', 'invoice_refresh');
        $invoice = $invoices->refresh($c['actor'], $c['order'], $invoice);
        $invoices->issue($c['actor'], $c['order'], $invoice);
        $component->dispatch('order-updated')->assertViewHas('step', 'evidence')
            ->assertDontSee(__('orders.next_steps.purchase'))->assertDontSee(__('orders.next_steps.actual_prices'));
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee($item->product_name_snapshot)
            ->assertSee(route('orders.shops.progress', [$c['order'], $shop]), false)
            ->assertDontSee('wire:submit="add"', false)->assertDontSee('wire:submit="assign"', false)
            ->assertDontSee('wire:click="removeItem(', false)->assertDontSee('wire:click="removeShop"', false)
            ->assertDontSee('wire:click="remove(', false)->assertDontSee('wire:submit="switchPrimary"', false)
            ->assertDontSee(route('orders.items.actual', [$c['order'], $shop, $item]), false)
            ->assertDontSee(route('orders.items.change', [$c['order'], $shop, $item]), false);
    }

    private function context(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currencyCode = 'U'.str_pad((string) ++self::$contextSequence, 2, '0', STR_PAD_LEFT);
        $currency = Currency::create(['code' => $currencyCode, 'name' => 'UX Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['name' => 'فرع الاختبار', 'default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create(['name' => 'منطقة الاختبار']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $customer = Customer::create(['name' => 'زبون تفاعلي', 'created_by_user_id' => $actor->id]);
        $phone = $customer->phones()->create(['phone' => '+963 955 000 111', 'normalized_phone' => '963955000111', 'is_primary' => true]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'شارع تفاعلي', 'is_primary' => true]);
        $shopType = ShopType::create(['name' => 'متجر', 'business_classification' => 'SHOP']);
        $shop = Shop::create(['name' => 'محل مسجل تفاعلي', 'area_id' => $area->id, 'shop_type_id' => $shopType->id]);
        $order = app(OrderService::class)->create($actor, [
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id,
            'source' => 'MANUAL', 'entry_mode' => 'LIVE',
        ]);

        return compact('currency', 'branch', 'area', 'actor', 'customer', 'phone', 'address', 'shop', 'order');
    }

    public function test_create_order_reactively_filters_customer_contact_data_and_uses_arabic_inline_errors(): void
    {
        $c = $this->context();
        $otherBranch = Branch::factory()->create();
        $otherArea = Area::factory()->for($otherBranch)->create();
        $foreignAddress = $c['customer']->addresses()->create(['area_id' => $otherArea->id, 'address_line_1' => 'عنوان غير متاح']);

        Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->call('submit')
            ->assertHasErrors(['customerId', 'customerPhoneId', 'customerAddressId', 'source'])
            ->assertSee('الفرع')
            ->set('branchId', (string) $c['branch']->id)
            ->set('customerId', (string) $c['customer']->id)
            ->assertSee($c['phone']->phone)
            ->assertSee($c['address']->address_line_1)
            ->assertDontSee($foreignAddress->address_line_1)
            ->set('customerPhoneId', (string) $c['phone']->id)
            ->set('customerAddressId', (string) $c['address']->id)
            ->set('source', 'MANUAL')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, Order::query()->count());
    }

    public function test_shop_mode_clears_stale_values_and_server_rejects_contradictory_payloads(): void
    {
        $c = $this->context();

        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'ONE_TIME')
            ->set('shopName', 'محل مؤقت')
            ->set('shopPhone', '0999000000')
            ->set('shopAddress', 'عنوان مؤقت')
            ->set('shopType', 'REGISTERED')
            ->assertSet('shopName', '')
            ->assertSet('shopPhone', '')
            ->assertSet('shopAddress', '')
            ->assertDontSeeHtml('name="shop_name"')
            ->set('shopId', (string) $c['shop']->id)
            ->set('shopType', 'ONE_TIME')
            ->assertSet('shopId', '')
            ->assertDontSeeHtml('name="shop_id"')
            ->set('businessClassification', 'SHOP')
            ->call('add')
            ->assertHasErrors(['shopName']);

        $this->actingAs($c['actor'])->post(route('orders.shops.store', $c['order']), [
            'shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id, 'shop_name' => 'بيانات متناقضة',
        ])->assertSessionHasErrors('shop_name');
        try {
            app(OrderShopService::class)->add($c['actor'], $c['order'], [
                'shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id, 'shop_name' => 'بيانات متناقضة',
            ]);
            $this->fail('The domain service accepted contradictory Shop identity data.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shop_type', $exception->errors());
        }
        $foreignBranch = Branch::factory()->create();
        $foreignArea = Area::factory()->for($foreignBranch)->create();
        $foreignShop = Shop::create([
            'name' => 'محل خارج الفرع', 'area_id' => $foreignArea->id, 'shop_type_id' => $c['shop']->shop_type_id,
        ]);
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'REGISTERED')
            ->set('shopId', (string) $foreignShop->id)
            ->call('add')
            ->assertForbidden();
        $this->assertSame(0, $c['order']->shops()->count());
    }

    public function test_captain_choices_are_branch_eligible_and_livewire_tampering_is_denied(): void
    {
        $c = $this->context();
        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])
            ->assertSee(__('orders.ui_no_eligible_captains'));
        $eligible = $this->captain($c['branch'], 'كابتن مؤهل');
        $foreign = $this->captain(Branch::factory()->create(), 'كابتن من فرع آخر');

        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])
            ->assertSee($eligible->user->name)
            ->assertDontSee($foreign->user->name)
            ->set('captainId', (string) $eligible->id)
            ->set('assignmentRole', 'PRIMARY_COLLECTION')
            ->call('assign')
            ->assertHasNoErrors()
            ->assertRedirect(route('orders.show', $c['order']));
        $this->assertDatabaseHas('order_captains', ['order_id' => $c['order']->id, 'captain_id' => $eligible->id]);

        $viewerRole = Role::create(['code' => 'UX_VIEWER', 'name' => 'UX Viewer']);
        $viewerRole->permissions()->sync(Permission::where('code', 'orders.view')->pluck('id'));
        $viewer = User::factory()->create();
        $viewer->roles()->attach($viewerRole);
        $viewer->branches()->attach($c['branch']);
        Livewire::actingAs($viewer)->test(AddShop::class, ['order' => $c['order']])->assertForbidden();
    }

    public function test_purchase_payer_lists_only_order_assignment_history_and_markup_is_mobile_safe(): void
    {
        $c = $this->context();
        app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $historical = $this->captain($c['branch'], 'كابتن شارك بالطلب');
        $assignment = app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $historical, OrderCaptainRole::PrimaryCollection);
        app(OrderCaptainService::class)->remove($c['actor'], $c['order'], $assignment, 'استبدال تشغيلي');
        $unrelated = $this->captain($c['branch'], 'كابتن غير مرتبط بالطلب');

        $response = $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee($historical->user->name)
            ->assertSee(__('orders.ui_historical_assignment'))
            ->assertSee('wire:loading.attr="disabled"', false)
            ->assertSee('sm:grid-cols-2', false)
            ->assertDontSee('min-w-[', false);

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $payer = $xpath->query('//input[@type="hidden" and @name="order_captain_id"]')->item(0);
        $this->assertNotNull($payer);
        $purchaseForm = $xpath->query('ancestor::form', $payer)->item(0);
        $this->assertStringNotContainsString($unrelated->user->name, $purchaseForm->textContent);
        $this->assertSame((string) $assignment->id, $payer->getAttribute('value'));
        $this->assertNotNull($xpath->query('//input[@type="hidden" and @name="currency_id"]')->item(0));
        $cancelForm = $xpath->query('//details[.//form[contains(@action,"/cancel")]]')->item(0);
        $this->assertNotNull($cancelForm);
        $this->assertFalse($cancelForm->hasAttribute('open'));

        app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $unrelated, OrderCaptainRole::PrimaryCollection);
        $multiple = $this->actingAs($c['actor'])->get(route('orders.show', $c['order']));
        $multipleDom = new \DOMDocument;
        @$multipleDom->loadHTML('<?xml encoding="UTF-8">'.$multiple->getContent());
        $multipleXPath = new \DOMXPath($multipleDom);
        $this->assertSame(1, $multipleXPath->query('//select[@name="order_captain_id"]')->length);
        $this->assertSame(2, $multipleXPath->query('//select[@name="order_captain_id"]/option')->length);
    }

    public function test_shop_modes_save_through_domain_service_and_show_required_classification(): void
    {
        $c = $this->context();
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'REGISTERED')->set('shopId', (string) $c['shop']->id)
            ->call('add')->assertHasNoErrors()->assertRedirect();
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'ONE_TIME')->set('shopName', 'محل زيارة واحدة')
            ->call('add')->assertHasErrors('businessClassification')
            ->assertSet('shopName', 'محل زيارة واحدة')
            ->set('businessClassification', 'SHOP')->call('add')->assertHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('order_shops', ['order_id' => $c['order']->id, 'shop_id' => $c['shop']->id]);
        $this->assertDatabaseHas('order_shops', ['order_id' => $c['order']->id, 'shop_id' => null, 'shop_name_snapshot' => 'محل زيارة واحدة']);
    }

    public function test_duplicate_assignment_is_a_visible_field_error_and_support_captain_can_be_promoted(): void
    {
        $c = $this->context();
        $primary = $this->captain($c['branch'], 'رئيسي');
        $support = $this->captain($c['branch'], 'مساعد');
        $service = app(OrderCaptainService::class);
        $service->assign($c['actor'], $c['order'], $primary, OrderCaptainRole::PrimaryCollection);
        $service->assign($c['actor'], $c['order'], $support, OrderCaptainRole::Support);

        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])
            ->set('captainId', (string) $primary->id)->call('assign')
            ->assertHasErrors('captainId')->assertSee(__('domain.captain_is_already_assigned'));
        $this->assertSame(2, $c['order']->captainAssignments()->count());

        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])
            ->assertSee(__('orders.ui_switch_primary'))
            ->set('switchCaptainId', (string) $support->id)->set('switchReason', 'تبديل المسؤول')
            ->call('switchPrimary')->assertHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('order_captains', ['order_id' => $c['order']->id, 'captain_id' => $support->id, 'assignment_role' => 'PRIMARY_COLLECTION', 'removed_at' => null]);
    }

    public function test_livewire_rechecks_branch_access_on_subsequent_actions(): void
    {
        $c = $this->context();
        $component = Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'REGISTERED')->set('shopId', (string) $c['shop']->id);
        $c['actor']->branches()->detach();
        $component->call('add')->assertForbidden();
        $this->assertSame(0, $c['order']->shops()->count());
    }

    public function test_one_registered_shop_holds_three_products_without_triggering_multi_shop(): void
    {
        $c = $this->context();
        $orderShop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $unit = Unit::create(['name' => 'قطعة سريعة', 'code' => 'FAST_UNIT', 'symbol' => 'ق', 'allows_decimal' => false]);
        $products = collect(['أ', 'ب', 'ج'])->map(fn (string $suffix) => Product::create(['name' => 'منتج '.$suffix, 'default_unit_id' => $unit->id, 'is_active' => true]));

        $component = Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $orderShop]);
        foreach ($products as $product) {
            $component->set('productId', (string) $product->id)->set('unitId', (string) $unit->id)
                ->set('quantity', '1')->call('add')->assertHasNoErrors();
        }

        $this->assertSame(1, $c['order']->shops()->count());
        $this->assertSame(3, $orderShop->items()->count());
        $this->assertSame('SINGLE_SHOP', $c['order']->fresh()->pricing_class->value);
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'REGISTERED')->set('shopId', (string) $c['shop']->id)->call('add')
            ->assertHasErrors('shopId');
        $this->assertSame(1, $c['order']->shops()->count());
    }

    public function test_different_registered_shops_remain_multi_shop_and_route_areas_are_inferred(): void
    {
        $c = $this->context();
        app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $second = Shop::create(['name' => 'محل ثان', 'area_id' => $c['area']->id, 'shop_type_id' => $c['shop']->shop_type_id]);

        $single = $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))
            ->assertSee(__('orders.ui_route_from_to', ['from' => $c['area']->path(), 'to' => $c['area']->path()]))
            ->assertDontSee('name="preview_from_area_id"', false);
        $this->assertStringContainsString($c['area']->name, $single->getContent());
        $this->assertSame($c['area']->id, $c['order']->destination_area_id);

        app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $second->id]);
        $this->assertSame(2, $c['order']->shops()->count());
        $this->assertSame('MULTI_SHOP', $c['order']->fresh()->pricing_class->value);
    }

    public function test_single_contact_options_and_inline_permanent_shop_are_selected_automatically(): void
    {
        $c = $this->context();
        Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->set('branchId', (string) $c['branch']->id)->set('customerId', (string) $c['customer']->id)
            ->assertSet('customerPhoneId', (string) $c['phone']->id)
            ->assertSet('customerAddressId', (string) $c['address']->id);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());

        Livewire::actingAs($admin)->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'CREATE_PERMANENT')->set('newShopName', 'محل أنشئ داخل الطلب')
            ->set('newShopTypeId', (string) $c['shop']->shop_type_id)->set('newShopAreaId', (string) $c['area']->id)
            ->call('createPermanentShop')->assertHasNoErrors()
            ->assertSet('shopType', 'REGISTERED')->assertSet('shopSearch', 'محل أنشئ داخل الطلب');
        $created = Shop::where('name', 'محل أنشئ داخل الطلب')->sole();
        $this->assertDatabaseHas('shops', ['id' => $created->id, 'area_id' => $c['area']->id]);
    }

    public function test_inline_shop_creation_rejects_an_area_outside_the_order_branch(): void
    {
        $c = $this->context();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $outside = Area::factory()->for(Branch::factory()->create())->create();

        Livewire::actingAs($admin)->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'CREATE_PERMANENT')->set('newShopName', 'محل خارج الفرع')
            ->set('newShopTypeId', (string) $c['shop']->shop_type_id)->set('newShopAreaId', (string) $outside->id)
            ->call('createPermanentShop')->assertNotFound();
        $this->assertDatabaseMissing('shops', ['name' => 'محل خارج الفرع']);
    }

    public function test_customer_search_finds_accessible_arabic_name_and_phone_without_exposing_foreign_customer(): void
    {
        $c = $this->context();
        $c['customer']->update(['name' => 'أمير الحموي']);
        $foreignBranch = Branch::factory()->create();
        $foreignArea = Area::factory()->for($foreignBranch)->create();
        $hidden = Customer::create(['name' => 'أمير مخفي']);
        $hidden->phones()->create(['phone' => '0999555333', 'normalized_phone' => '0999555333']);
        $hidden->addresses()->create(['area_id' => $foreignArea->id, 'address_line_1' => 'خارج الفرع']);

        Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->set('customerSearch', 'أمير')
            ->assertSee('أمير الحموي')->assertDontSee('أمير مخفي')
            ->call('selectSoleCustomer', 'أمير')->assertSet('customerId', (string) $c['customer']->id)
            ->set('customerSearch', '955000')
            ->assertSee('أمير الحموي')->assertDontSee('أمير مخفي')
            ->call('selectSoleCustomer', '955000')->assertSet('customerId', (string) $c['customer']->id)
            ->assertDontSee('wire:model.live.debounce.250ms="customerSearch"', false);
    }

    public function test_inline_customer_creation_uses_existing_services_preserves_state_and_requires_permission(): void
    {
        $c = $this->context();
        $initialCount = Customer::query()->count();
        $component = Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->set('source', 'PHONE')->call('toggleCustomerCreate')
            ->assertSee('role="dialog"', false)->assertSee('aria-modal="true"', false)
            ->set('newCustomerName', 'زبون من الطلب')->set('newCustomerPhone', $c['phone']->phone)
            ->set('newCustomerAddress', 'عنوان الزبون الجديد')->set('newCustomerAreaId', (string) $c['area']->id)
            ->call('createCustomer')->assertHasErrors('newCustomerPhone');
        $this->assertSame($initialCount, Customer::query()->count());

        $component->set('newCustomerDuplicateAcknowledged', true)->call('createCustomer')->assertHasNoErrors()
            ->assertSet('source', 'PHONE')->assertSet('showCustomerCreate', false)->assertDontSee('role="dialog"', false);
        $created = Customer::where('name', 'زبون من الطلب')->sole();
        $component->assertSet('customerId', (string) $created->id)
            ->assertSet('customerPhoneId', (string) $created->phones()->sole()->id)
            ->assertSet('customerAddressId', (string) $created->addresses()->sole()->id);

        $role = Role::create(['code' => 'ORDER_CREATOR_ONLY', 'name' => 'Order creator only']);
        $role->permissions()->sync(Permission::whereIn('code', ['orders.view', 'orders.create'])->pluck('id'));
        $restricted = User::factory()->create();
        $restricted->roles()->attach($role);
        $restricted->branches()->attach($c['branch']);
        Livewire::actingAs($restricted)->test(CreateOrder::class)
            ->assertDontSee(__('orders.ui_add_new_customer'))
            ->call('createCustomer')->assertForbidden();
    }

    public function test_mutable_items_and_empty_shop_can_be_removed_but_history_and_tampering_are_blocked(): void
    {
        $c = $this->context();
        $orderShop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $unit = Unit::create(['name' => 'قطعة حذف', 'code' => 'REMOVE_UNIT', 'symbol' => 'ح', 'allows_decimal' => false]);
        $first = Product::create(['name' => 'منتج أول', 'default_unit_id' => $unit->id]);
        $second = Product::create(['name' => 'منتج ثان', 'default_unit_id' => $unit->id]);
        $component = Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $orderShop]);
        foreach ([$first, $second] as $product) {
            $component->set('productId', (string) $product->id)->set('unitId', (string) $unit->id)->call('add')->assertHasNoErrors();
        }
        $items = $orderShop->items()->orderBy('id')->get();
        $component->call('removeItem', $items->first()->id)->assertHasNoErrors();
        $this->assertSame(1, $orderShop->items()->count());

        $remaining = $orderShop->items()->sole();
        $remaining->update(['actual_unit_price' => '5', 'actual_currency_id' => $c['currency']->id, 'actual_price_source' => 'MANUAL_VERIFIED', 'actual_price_confirmed_at' => now()]);
        $component->call('removeItem', $remaining->id)->assertHasErrors('remove');
        $remaining->update(['actual_unit_price' => null, 'actual_currency_id' => null, 'actual_price_source' => null, 'actual_price_confirmed_at' => null]);
        $component->call('removeItem', $remaining->id)->assertHasNoErrors();
        $this->assertSame(0, $orderShop->items()->count());

        $other = $this->context();
        $otherShop = app(OrderShopService::class)->add($other['actor'], $other['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $other['shop']->id]);
        Livewire::actingAs($other['actor'])->test(OrderShopItems::class, ['order' => $other['order'], 'orderShop' => $otherShop])
            ->set('productId', (string) $first->id)->set('unitId', (string) $unit->id)->call('add')->assertHasNoErrors();
        $component->call('removeItem', $otherShop->items()->sole()->id)->assertForbidden();
        Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $orderShop])
            ->call('removeShop')->assertRedirect(route('orders.show', $c['order']));
        $this->assertDatabaseMissing('order_shops', ['id' => $orderShop->id]);
    }

    public function test_shop_with_purchase_history_cannot_be_removed(): void
    {
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $captain = $this->captain($c['branch'], 'مشتري');
        $assignment = app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $shop->purchaseConfirmation()->create([
            'order_id' => $c['order']->id, 'order_captain_id' => $assignment->id, 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'recorded_by' => $c['actor']->id, 'purchased_at' => now(),
            'amount' => '10', 'request_key' => (string) Str::uuid(), 'evidence_path' => 'test/'.Str::uuid().'.png',
            'evidence_name' => 'proof.png', 'evidence_mime' => 'image/png', 'evidence_size' => 1, 'evidence_sha256' => str_repeat('a', 64),
        ]);

        Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $shop])
            ->call('removeShop')->assertHasErrors('removeShop');
        $this->assertDatabaseHas('order_shops', ['id' => $shop->id]);
    }

    public function test_one_time_and_registered_shop_origins_drive_directional_pricing_without_technical_controls(): void
    {
        $c = $this->context();
        $origin = Area::factory()->for($c['branch'])->create(['name' => 'كفر بطنا']);
        $c['area']->update(['name' => 'سقبا']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $forward = app(DeliveryPricingRuleService::class)->create($admin, [
            'from_area_id' => $origin->id, 'to_area_id' => $c['area']->id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '120', 'effective_from' => now()->subDay(),
        ]);
        $reverse = app(DeliveryPricingRuleService::class)->create($admin, [
            'from_area_id' => $c['area']->id, 'to_area_id' => $origin->id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '140', 'effective_from' => now()->subDay(),
        ]);

        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'ONE_TIME')->set('shopName', 'محل مؤقت')->set('businessClassification', 'SHOP')
            ->call('add')->assertHasErrors('originAreaId')
            ->set('originAreaId', (string) $origin->id)->call('add')->assertHasNoErrors()->assertRedirect();
        $orderShop = $c['order']->shops()->sole();
        $this->assertSame($origin->id, $orderShop->origin_area_id);
        $this->assertSame($origin->id, app(OrderDeliveryOriginService::class)->resolve($c['order']->fresh())->id);
        $this->assertSame($forward->id, app(DeliveryPricingRuleService::class)->applicable($origin->id, $c['area']->id, $c['currency']->id, $c['order']->fresh()->pricing_class, $c['order']->ordered_at)->id);
        $this->assertSame($reverse->id, app(DeliveryPricingRuleService::class)->applicable($c['area']->id, $origin->id, $c['currency']->id, $c['order']->fresh()->pricing_class, $c['order']->ordered_at)->id);

        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee(__('orders.ui_route_from_to', ['from' => 'كفر بطنا', 'to' => 'سقبا']))
            ->assertSee(__('orders.ui_suggested_delivery_fee'))->assertSee('120')
            ->assertDontSee('preview_from_area_id', false)->assertDontSee(__('orders.ui_preview_suggested'));

        $registeredOrder = app(OrderService::class)->create($c['actor'], [
            'branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id, 'customer_phone_id' => $c['phone']->id,
            'customer_address_id' => $c['address']->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE',
        ]);
        $c['shop']->update(['area_id' => $origin->id]);
        $registered = app(OrderShopService::class)->add($c['actor'], $registeredOrder, ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $this->assertSame($origin->id, $registered->origin_area_id);
    }

    public function test_operational_transition_is_available_before_preparation_but_respects_real_prerequisites(): void
    {
        $c = $this->context();
        Livewire::actingAs($c['actor'])->test(NextAction::class, ['order' => $c['order']])
            ->assertViewHas('step', 'shop')->assertViewHas('canTransition', true)
            ->assertSee(route('orders.status', $c['order']), false)->assertSee('value="CONFIRMED"', false);
        $this->actingAs($c['actor'])->patch(route('orders.status', $c['order']), ['operational_status' => 'HEADING_TO_SHOPS'])
            ->assertSessionHasErrors('operational_status');
        $this->patch(route('orders.status', $c['order']), ['operational_status' => 'CONFIRMED'])->assertSessionHasNoErrors();
        Livewire::test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'shop')
            ->assertViewHas('canTransition', false)->assertDontSee('name="operational_status"', false);
        $this->patch(route('orders.status', $c['order']), ['operational_status' => 'CAPTAIN_ASSIGNED'])->assertSessionHasErrors('operational_status');
        app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $this->captain($c['branch'], 'كابتن الانتقال'), OrderCaptainRole::PrimaryCollection);
        Livewire::test(NextAction::class, ['order' => $c['order']])->assertViewHas('step', 'shop')
            ->assertViewHas('canTransition', true)->assertSee('value="CAPTAIN_ASSIGNED"', false);
        $this->patch(route('orders.status', $c['order']), ['operational_status' => 'CAPTAIN_ASSIGNED'])->assertSessionHasNoErrors();
        $this->assertSame('CAPTAIN_ASSIGNED', $c['order']->fresh()->operational_status->value);
    }

    private function captain(Branch $branch, string $name): Captain
    {
        $user = User::factory()->create(['name' => $name]);
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);

        return $captain;
    }
}
