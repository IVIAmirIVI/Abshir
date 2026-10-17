<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCancellationClassification;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Livewire\Orders\AddShop;
use App\Livewire\Orders\CaptainAssignments;
use App\Livewire\Orders\CreateOrder;
use App\Livewire\Orders\OrderShopItems;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopProduct;
use App\Models\ShopType;
use App\Models\Unit;
use App\Models\User;
use App\Services\DeliveryPricingRuleService;
use App\Services\DeliveryPricingSettings;
use App\Services\OrderCancellationService;
use App\Services\OrderCaptainService;
use App\Services\OrderDeliveryPricingService;
use App\Services\OrderItemService;
use App\Services\OrderService;
use App\Services\OrderShopService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderSearchSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'UXS', 'name' => 'عملة الاختبار', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $area = Area::factory()->for($branch)->create(['name' => 'منطقة الاختيار']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $customer = $this->customer($area, 'زبون مختار', '963955111111');
        $order = app(OrderService::class)->create($actor, [
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $customer->phones()->sole()->id,
            'customer_address_id' => $customer->addresses()->sole()->id,
            'source' => 'MANUAL', 'entry_mode' => 'LIVE',
        ]);
        $type = ShopType::create(['name' => 'نوع المحل', 'business_classification' => 'SHOP']);
        $shop = Shop::create(['business_classification' => 'SHOP', 'name' => 'محل مختار', 'area_id' => $area->id, 'shop_type_id' => $type->id]);

        return compact('currency', 'branch', 'area', 'actor', 'customer', 'order', 'type', 'shop');
    }

    private function customer(Area $area, string $name, string $phone): Customer
    {
        $customer = Customer::create(['name' => $name]);
        $customer->phones()->create(['phone' => $phone, 'normalized_phone' => $phone, 'is_primary' => true]);
        $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'عنوان '.$name, 'is_primary' => true]);

        return $customer;
    }

    public function test_customer_zero_and_ambiguous_enter_preserve_selection_contacts_and_order_draft(): void
    {
        $c = $this->context();
        $first = $this->customer($c['area'], 'أمير الأول', '963944111111');
        $second = $this->customer($c['area'], 'أمير الثاني', '963944222222');
        $foreignArea = Area::factory()->for(Branch::factory()->create())->create(['name' => 'منطقة مخفية']);
        $hidden = $this->customer($foreignArea, 'أمير مخفي', '963944333333');
        $c['customer']->addresses()->create(['area_id' => $foreignArea->id, 'address_line_1' => 'عنوان مخفي للزبون المختار']);
        $phoneId = (string) $c['customer']->phones()->sole()->id;
        $addressId = (string) $c['customer']->addresses()->where('area_id', $c['area']->id)->sole()->id;
        $component = Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->set('customerId', (string) $c['customer']->id)->set('source', 'PHONE')->set('notes', 'مسودة محفوظة')
            ->set('customerSearch', 'لا يوجد')->call('selectSoleCustomer', 'لا يوجد')
            ->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerPhoneId', $phoneId)->assertSet('customerAddressId', $addressId)
            ->assertSee('role="combobox"', false)
            ->assertDontSee('عنوان مخفي للزبون المختار')
            ->set('customerSearch', 'أمير')->call('selectSoleCustomer', 'أمير')
            ->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerPhoneId', $phoneId)->assertSet('customerAddressId', $addressId)
            ->assertSet('source', 'PHONE')->assertSet('notes', 'مسودة محفوظة')
            ->assertSee($first->name)->assertSee($second->name)->assertSee('963944222222')
            ->assertSee($c['area']->name)->assertDontSee($hidden->name)
            ->call('selectCustomer', $second->id)->assertSet('customerId', (string) $second->id);
        $unit = Unit::create(['name' => 'قطعة إنشاء', 'code' => 'SEARCH_WIZARD', 'allows_decimal' => false]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        app(DeliveryPricingRuleService::class)->create($admin, [
            'from_area_id' => $c['area']->id, 'to_area_id' => $c['area']->id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '5', 'effective_from' => now()->subDay(),
        ]);
        $component->call('nextStep')->call('selectShop', $c['shop']->id)->call('addDraftShop')
            ->set('itemType', 'ONE_TIME_ITEM')->set('itemName', 'منتج مسودة')->set('unitId', (string) $unit->id)->call('addDraftItem')
            ->call('goToStep', 4)->call('submit')->assertHasNoErrors()->assertRedirect();
        $created = Order::query()->latest('id')->first();
        $this->assertSame($second->id, $created->customer_id);
        $this->assertSame('مسودة محفوظة', $created->notes);
    }

    public function test_customer_sole_match_phone_search_and_current_input_override_stale_debounce(): void
    {
        $c = $this->context();
        $first = $this->customer($c['area'], 'أمير الأول', '963944111111');
        $second = $this->customer($c['area'], 'أمير الثاني', '963944222222');
        $component = Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->call('selectSoleCustomer', 'لا توجد نتيجة')->assertSet('customerId', '')
            ->set('customerSearch', 'أمير الأول')->call('selectSoleCustomer', 'أمير')
            ->assertSet('customerId', '')
            ->call('selectSoleCustomer', 'أمير الأول')->assertSet('customerId', (string) $first->id)
            ->call('selectSoleCustomer', '944222')->assertSet('customerId', (string) $second->id)
            ->assertSet('customerPhoneId', (string) $second->phones()->sole()->id);
        $extraAddress = $second->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'عنوان اختاره المشغل']);
        $component->set('customerAddressId', (string) $extraAddress->id)
            ->call('selectSoleCustomer', '944222')->assertSet('customerAddressId', (string) $extraAddress->id)
            ->call('selectSoleCustomer', 'لا توجد نتيجة')->assertSet('customerId', (string) $second->id)
            ->assertSet('customerAddressId', (string) $extraAddress->id);
    }

    public function test_select2_customer_search_is_scoped_and_does_not_change_the_order_draft(): void
    {
        $c = $this->context();
        $visible = $this->customer($c['area'], 'أمير ظاهر', '963944555555');
        $foreignArea = Area::factory()->for(Branch::factory()->create())->create();
        $this->customer($foreignArea, 'أمير مخفي', '963944555555');
        $visible->addresses()->create(['area_id' => $foreignArea->id, 'address_line_1' => 'عنوان سري']);

        Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->set('customerId', (string) $c['customer']->id)->set('notes', 'مسودة محفوظة')->set('source', 'PHONE')
            ->call('searchCustomers', 'أمير')->assertReturned(function (array $results) use ($visible): bool {
                return array_column($results, 'id') === [(string) $visible->id]
                    && str_contains($results[0]['text'], '963944555555')
                    && ! str_contains($results[0]['text'], 'عنوان سري');
            })
            ->call('searchCustomers', '944555')->assertReturned(fn (array $results) => array_column($results, 'id') === [(string) $visible->id])
            ->call('searchCustomers', 'غير موجود')->assertReturned([])
            ->assertSet('customerId', (string) $c['customer']->id)
            ->assertSet('customerPhoneId', (string) $c['customer']->phones()->sole()->id)
            ->assertSet('customerAddressId', (string) $c['customer']->addresses()->sole()->id)
            ->assertSet('notes', 'مسودة محفوظة')->assertSet('source', 'PHONE');
    }

    public function test_select2_customer_beyond_initial_results_remains_selected_after_rerender(): void
    {
        $c = $this->context();
        for ($i = 0; $i < 50; $i++) {
            $this->customer($c['area'], 'نتيجة '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '963955'.str_pad((string) $i, 6, '0', STR_PAD_LEFT));
        }
        $selected = $this->customer($c['area'], 'نتيجة 99', '963944999999');
        Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->assertDontSee($selected->name)
            ->call('searchCustomers', $selected->name)->assertReturned(fn (array $results) => array_column($results, 'id') === [(string) $selected->id])
            ->set('customerId', (string) $selected->id)->set('notes', 'إعادة عرض')
            ->assertSee($selected->name)->assertSet('customerId', (string) $selected->id)
            ->assertSet('customerPhoneId', (string) $selected->phones()->sole()->id)
            ->assertSet('customerAddressId', (string) $selected->addresses()->sole()->id);
    }

    public function test_customer_modal_closes_without_creating_customer_or_losing_order_draft(): void
    {
        $c = $this->context();
        $count = Customer::count();
        Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->set('customerId', (string) $c['customer']->id)->set('notes', 'مسودة الطلب')
            ->call('toggleCustomerCreate')->assertSet('showCustomerCreate', true)->assertSee('role="dialog"', false)
            ->set('newCustomerName', 'مسودة زبون')->call('createCustomer')->assertHasErrors('newCustomerPhone')
            ->call('closeCustomerCreate')->assertSet('showCustomerCreate', false)->assertDontSee('role="dialog"', false)
            ->assertHasNoErrors('newCustomerPhone')->assertSet('newCustomerName', 'مسودة زبون')
            ->assertSet('customerId', (string) $c['customer']->id)->assertSet('notes', 'مسودة الطلب');
        $this->assertSame($count, Customer::count());
    }

    public function test_shop_enter_selects_only_a_sole_match_and_explicit_choice_is_scoped(): void
    {
        $c = $this->context();
        $first = Shop::create(['business_classification' => 'SHOP', 'name' => 'متجر الأول', 'area_id' => $c['area']->id, 'shop_type_id' => $c['type']->id]);
        $second = Shop::create(['business_classification' => 'SHOP', 'name' => 'متجر الثاني', 'area_id' => $c['area']->id, 'shop_type_id' => $c['type']->id]);
        $foreignArea = Area::factory()->for(Branch::factory()->create())->create();
        $hidden = Shop::create(['business_classification' => 'SHOP', 'name' => 'متجر مخفي', 'area_id' => $foreignArea->id, 'shop_type_id' => $c['type']->id]);
        $component = Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'REGISTERED')->set('notes', 'ملاحظة محل')
            ->call('selectSoleShop', 'متجر')->assertSet('shopId', '')
            ->assertSee($first->name)->assertSee($second->name)->assertDontSee($hidden->name)
            ->assertSee($c['area']->name)->assertSee($c['type']->name)
            ->call('selectShop', $second->id)->assertSet('shopId', (string) $second->id)
            ->call('selectSoleShop', 'متجر')->assertSet('shopId', (string) $second->id)
            ->call('selectSoleShop', 'لا توجد نتيجة')->assertSet('shopId', (string) $second->id)
            ->assertSet('notes', 'ملاحظة محل')
            ->set('shopSearch', 'متجر الأول')->call('selectSoleShop', 'متجر')->assertSet('shopId', (string) $second->id)
            ->call('selectSoleShop', 'متجر الأول')->assertSet('shopId', (string) $first->id);
        $component->call('add')->assertHasNoErrors()->assertRedirect();
        $this->assertSame($first->id, $c['order']->shops()->sole()->shop_id);
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'REGISTERED')->set('shopSearch', 'متجر')->call('selectShop', $hidden->id)->assertNotFound();
    }

    public function test_product_enter_never_adds_or_selects_an_ambiguous_item_and_preserves_draft(): void
    {
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $unit = Unit::create(['name' => 'قطعة', 'code' => 'SEARCH_UNIT', 'symbol' => 'ق', 'allows_decimal' => false]);
        $first = Product::create(['name' => 'منتج الأول', 'sku' => 'SEL-A', 'barcode' => '777111', 'default_unit_id' => $unit->id]);
        $second = Product::create(['name' => 'منتج الثاني', 'sku' => 'SEL-B', 'barcode' => '777222', 'default_unit_id' => $unit->id]);
        Product::create(['name' => 'منتج غير نشط', 'default_unit_id' => $unit->id])->forceFill(['is_active' => false])->save();
        $beforeOrder = $c['order']->fresh()->getRawOriginal();
        $component = Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $shop])
            ->set('quantity', '3')->set('manualEstimatedUnitPrice', '12.50')
            ->call('selectSoleProduct', 'منتج')->assertSet('productId', '')
            ->assertSee('SEL-A')->assertDontSee('777222')->assertSee($unit->name)->assertDontSee('منتج غير نشط')
            ->call('selectProduct', $second->id)->assertSet('productId', (string) $second->id)
            ->call('selectSoleProduct', 'منتج')->assertSet('productId', (string) $second->id)
            ->call('selectSoleProduct', 'لا توجد نتيجة')->assertSet('productId', (string) $second->id)
            ->assertSet('quantity', '3')->assertSet('manualEstimatedUnitPrice', '12.50')
            ->set('productSearch', 'SEL-A')->call('selectSoleProduct', 'منتج')->assertSet('productId', (string) $second->id)
            ->call('selectSoleProduct', 'SEL-A')->assertSet('productId', (string) $first->id);
        $this->assertSame(0, $shop->items()->count());
        $this->assertSame($beforeOrder, $c['order']->fresh()->getRawOriginal());
        $component->set('unitId', '')->call('add')->assertHasErrors('unitId')->assertSet('quantity', '3')->assertSet('productId', (string) $first->id)
            ->set('unitId', (string) $unit->id)->call('add')->assertHasNoErrors();
        $this->assertSame($first->id, $shop->items()->sole()->product_id);
        $this->assertSame('3.0000', $shop->items()->sole()->current_quantity);
    }

    public function test_sole_authoritative_captain_still_auto_selects_without_search_fallbacks(): void
    {
        $c = $this->context();
        $user = User::factory()->create();
        $captain = Captain::create(['user_id' => $user->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subDay()]);
        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])->assertSet('captainId', (string) $captain->id);
        $otherUser = User::factory()->create();
        $other = Captain::create(['user_id' => $otherUser->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $other->employmentPeriods()->create(['started_at' => now()->subDay()]);
        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])->assertSet('captainId', '');
    }

    private function allowCreation(User $actor, array $permissions): void
    {
        $actor->roles()->sole()->permissions()->syncWithoutDetaching(Permission::whereIn('code', $permissions)->pluck('id'));
    }

    public function test_inline_shop_area_is_required_scoped_and_multiple_options_are_not_guessed(): void
    {
        $c = $this->context();
        $this->allowCreation($c['actor'], ['shops.create']);
        Area::factory()->for($c['branch'])->create(['name' => 'منطقة ثانية']);
        $hidden = Area::factory()->for(Branch::factory()->create())->create(['name' => 'منطقة ممنوعة']);
        $inactive = Area::factory()->for($c['branch'])->create(['name' => 'منطقة متوقفة', 'is_active' => false]);
        $before = $c['order']->fresh()->getRawOriginal();
        $component = Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('notes', 'ملاحظات محفوظة')->set('shopType', 'CREATE_PERMANENT')
            ->assertSet('newShopAreaId', '')->assertSee('wire:model="newShopAreaId"', false)
            ->assertDontSee($hidden->name)->assertDontSee($inactive->name)
            ->set('newShopClassification', 'SHOP')->set('newShopName', 'محل جديد')->call('createPermanentShop')->assertHasErrors('newShopAreaId')
            ->assertSet('notes', 'ملاحظات محفوظة')->assertSet('newShopName', 'محل جديد')
            ->set('newShopAreaId', (string) $c['area']->id)->call('createPermanentShop')->assertHasNoErrors()
            ->assertSet('shopType', 'REGISTERED')->assertSee('محل جديد')->assertSet('notes', 'ملاحظات محفوظة');
        $newShop = Shop::where('name', 'محل جديد')->sole();
        $component->assertSet('shopId', (string) $newShop->id);
        $this->assertSame($c['area']->id, $newShop->area_id);
        $this->assertSame($before, $c['order']->fresh()->getRawOriginal());
        $component->call('add')->assertHasNoErrors();
        $this->assertSame($c['area']->id, $c['order']->shops()->sole()->origin_area_id);
    }

    public function test_inline_shop_zero_areas_and_missing_permission_cannot_create(): void
    {
        $c = $this->context();
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->assertDontSee('value="CREATE_PERMANENT"', false)->call('createPermanentShop')->assertForbidden();
        $this->allowCreation($c['actor'], ['shops.create']);
        $c['area']->is_active = false;
        $c['area']->save();
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'CREATE_PERMANENT')->assertSee(__('orders.ui_no_inline_shop_areas'))
            ->set('newShopClassification', 'SHOP')->set('newShopName', 'محل غير صالح')->call('createPermanentShop')->assertHasErrors('newShopAreaId');
        $this->assertFalse(Shop::where('name', 'محل غير صالح')->exists());
    }

    public function test_inline_product_creation_uses_catalog_and_listing_services_without_changing_order_facts(): void
    {
        $c = $this->context();
        $this->allowCreation($c['actor'], ['products.create', 'shop_products.create']);
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $unit = Unit::create(['name' => 'وحدة جديدة', 'code' => 'INLINE_UNIT', 'symbol' => 'ق', 'allows_decimal' => false]);
        $category = Category::create(['name' => 'صنف وحيد']);
        $existingItem = app(OrderItemService::class)->add($c['actor'], $c['order'], $shop, [
            'item_type' => 'ONE_TIME_ITEM', 'item_name' => 'صنف محفوظ', 'unit_id' => $unit->id, 'quantity' => '2',
        ]);
        $captain = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subDay()]);
        $assignment = app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        app(DeliveryPricingRuleService::class)->create($admin, [
            'from_area_id' => $c['area']->id, 'to_area_id' => $c['area']->id, 'currency_id' => $c['currency']->id,
            'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '5', 'effective_from' => now()->subDay(),
        ]);
        app(DeliveryPricingSettings::class)->update($admin, '65', '0');
        app(OrderDeliveryPricingService::class)->price($c['actor'], $c['order'], $c['area']);
        $beforeOrder = $c['order']->fresh()->getRawOriginal();
        $beforeShop = $shop->fresh()->getRawOriginal();
        $beforeItem = $existingItem->fresh()->getRawOriginal();
        $beforeCaptain = $assignment->fresh()->getRawOriginal();
        $beforePricing = $c['order']->deliveryPricings()->get()->map->getRawOriginal()->all();
        $component = Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $shop])
            ->assertSee(__('orders.ui_add_new_product'))->set('quantity', '3')->set('manualEstimatedUnitPrice', '12.50')
            ->set('productSearch', 'منتج جديد')->call('toggleProductCreation')
            ->assertSet('newProductName', 'منتج جديد')->assertSet('newProductUnitId', (string) $unit->id)
            ->assertSet('newProductCategoryId', (string) $category->id)->assertDontSee('barcode')
            ->set('newProductName', '')->call('createProduct')->assertHasErrors('newProductName')
            ->assertSet('quantity', '3')->assertSet('manualEstimatedUnitPrice', '12.50')
            ->set('newProductName', 'منتج جديد')->set('newProductSku', 'INLINE-SKU')->call('createProduct')->assertHasNoErrors()
            ->assertSet('creatingProduct', false)->assertSee('منتج جديد')
            ->assertSet('quantity', '3')->assertSet('manualEstimatedUnitPrice', '12.50');
        $product = Product::where('sku', 'INLINE-SKU')->sole();
        $component->assertSet('productId', (string) $product->id);
        $this->assertNull($product->barcode);
        $this->assertSame($beforeOrder, $c['order']->fresh()->getRawOriginal());
        $this->assertSame($beforeShop, $shop->fresh()->getRawOriginal());
        $this->assertSame($beforeItem, $existingItem->fresh()->getRawOriginal());
        $this->assertSame($beforeCaptain, $assignment->fresh()->getRawOriginal());
        $this->assertSame($beforePricing, $c['order']->deliveryPricings()->get()->map->getRawOriginal()->all());
        $this->assertSame(1, $shop->items()->count());
        $listing = ShopProduct::where('product_id', $product->id)->sole();
        $this->assertSame($c['shop']->id, $listing->shop_id);
        $this->assertSame($unit->id, $listing->unit_id);
        $this->assertNull($listing->listed_price);
        $this->assertNull($listing->currency_id);
        $component->call('add')->assertHasNoErrors();
        $newItem = $shop->items()->where('product_id', $product->id)->sole();
        $this->assertSame('3.0000', $newItem->current_quantity);
        $this->assertSame($beforeItem, $existingItem->fresh()->getRawOriginal());
    }

    public function test_inline_product_requires_permission_but_existing_search_remains_available(): void
    {
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $shop])
            ->assertSee('productSearch')->assertDontSee('wire:click="toggleProductCreation"', false)
            ->call('createProduct')->assertForbidden();
        $this->allowCreation($c['actor'], ['products.create']);
        $unit = Unit::create(['name' => 'وحدة', 'code' => 'NO_LIST', 'symbol' => 'ق', 'allows_decimal' => false]);
        Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $shop])
            ->set('newProductName', 'منتج بلا ربط')->set('newProductUnitId', (string) $unit->id)
            ->call('createProduct')->assertHasNoErrors()->assertSee('منتج بلا ربط');
        $this->assertSame(0, ShopProduct::count());
    }

    public function test_inline_product_duplicates_inactive_units_and_multiple_options_require_explicit_choice(): void
    {
        $c = $this->context();
        $this->allowCreation($c['actor'], ['products.create']);
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $unit = Unit::create(['name' => 'وحدة أولى', 'code' => 'FIRST', 'symbol' => 'ق', 'allows_decimal' => false]);
        Unit::create(['name' => 'وحدة ثانية', 'code' => 'SECOND', 'symbol' => 'ق', 'allows_decimal' => false]);
        $inactive = Unit::create(['name' => 'وحدة متوقفة', 'code' => 'OFF', 'symbol' => 'ق', 'allows_decimal' => false]);
        $inactive->is_active = false;
        $inactive->save();
        Category::create(['name' => 'صنف أول']);
        Category::create(['name' => 'صنف ثان']);
        $existing = Product::create(['name' => 'منتج موجود', 'sku' => 'EXISTING', 'default_unit_id' => $unit->id]);
        $component = Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $shop])
            ->call('toggleProductCreation')->assertSet('newProductUnitId', '')->assertSet('newProductCategoryId', '')
            ->set('newProductName', 'منتج آخر')->call('createProduct')->assertHasErrors('newProductUnitId')
            ->set('newProductUnitId', (string) $inactive->id)->call('createProduct')->assertHasErrors('newProductUnitId')
            ->set('newProductUnitId', (string) $unit->id)->set('newProductName', 'منتج موجود')
            ->call('createProduct')->assertHasErrors('newProductName')
            ->set('newProductName', 'منتج آخر')->set('newProductSku', 'EXISTING')->call('createProduct')->assertHasErrors('newProductSku');
        $this->assertSame(1, Product::count());
        $component->call('toggleProductCreation')->call('selectSoleProduct', 'EXISTING')->assertSet('productId', (string) $existing->id);
    }

    public function test_barcode_is_not_an_order_search_field_and_existing_data_is_preserved(): void
    {
        $c = $this->context();
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $unit = Unit::create(['name' => 'وحدة', 'code' => 'BARCODE_TEST', 'symbol' => 'ق', 'allows_decimal' => false]);
        $product = Product::create(['name' => 'منتج محفوظ', 'sku' => 'ORDER-SKU', 'barcode' => '9988776655', 'default_unit_id' => $unit->id]);
        Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $shop])
            ->assertDontSee('9988776655')->call('selectSoleProduct', '9988776655')->assertSet('productId', '')
            ->call('selectSoleProduct', 'ORDER-SKU')->assertSet('productId', (string) $product->id);
        $this->assertSame('9988776655', $product->fresh()->barcode);
    }

    public function test_final_order_blocks_inline_catalog_creation_even_by_direct_action(): void
    {
        $c = $this->context();
        $this->allowCreation($c['actor'], ['products.create', 'shops.create']);
        $shop = app(OrderShopService::class)->add($c['actor'], $c['order'], ['shop_type' => 'REGISTERED', 'shop_id' => $c['shop']->id]);
        $unit = Unit::create(['name' => 'وحدة', 'code' => 'FROZEN', 'symbol' => 'ق', 'allows_decimal' => false]);
        app(OrderCancellationService::class)->cancel($c['actor'], $c['order'], OrderCancellationClassification::BeforePurchase, OrderCancellationReason::CustomerCancelled, null);
        Livewire::actingAs($c['actor'])->test(OrderShopItems::class, ['order' => $c['order'], 'orderShop' => $shop])
            ->assertDontSee('wire:click="toggleProductCreation"', false)
            ->set('newProductName', 'منتج ممنوع')->set('newProductUnitId', (string) $unit->id)
            ->call('createProduct')->assertHasErrors('order');
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('newShopClassification', 'SHOP')->set('newShopName', 'محل ممنوع')->set('newShopAreaId', (string) $c['area']->id)
            ->call('createPermanentShop')->assertHasErrors('order');
        $this->assertFalse(Product::where('name', 'منتج ممنوع')->exists());
        $this->assertFalse(Shop::where('name', 'محل ممنوع')->exists());
    }

    public function test_uat_shop_area_labels_separate_branch_from_hierarchy_and_type_empty_state_is_explicit(): void
    {
        $c = $this->context();
        $this->allowCreation($c['actor'], ['shops.create', 'shop_types.create']);
        $c['branch']->update(['name' => 'كفر بطنا']);
        $c['area']->update(['name' => 'كفر بطنا']);
        Area::factory()->for($c['branch'])->create(['name' => 'سقبا']);
        $child = Area::factory()->for($c['branch'])->create(['name' => 'حي', 'parent_id' => $c['area']->id]);
        $this->actingAs($c['actor'])->get(route('shops.create'))->assertOk()
            ->assertSee('label="'.__('common.terms.branch').': كفر بطنا"', false)
            ->assertSee('>كفر بطنا</option>', false)->assertSee('>سقبا</option>', false)
            ->assertSee('كفر بطنا › حي')->assertDontSee('كفر بطنا › سقبا')
            ->assertDontSee('كفر بطنا › كفر بطنا')->assertSee($c['type']->name)
            ->assertDontSee(__('shops.ui_no_area_super_admin_only'));
        $this->assertSame('كفر بطنا › حي', $child->path());
        $c['type']->is_active = false;
        $c['type']->save();
        $this->get(route('shops.create'))->assertOk()->assertSee(__('shops.ui_no_active_types'))->assertSee(route('shop-types.create'), false);
        Livewire::actingAs($c['actor'])->test(AddShop::class, ['order' => $c['order']])
            ->set('shopType', 'CREATE_PERMANENT')->assertSet('newShopTypeId', '')
            ->assertSee(__('shops.ui_no_active_types'))->assertSee(route('shop-types.create'), false);
    }
}
