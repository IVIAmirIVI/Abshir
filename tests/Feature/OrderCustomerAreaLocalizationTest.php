<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\OrderCancellationReason;
use App\Enums\OrderCaptainRole;
use App\Enums\OrderOperationalStatus;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderCancellationIntentService;
use App\Services\OrderCaptainService;
use App\Services\OrderItemService;
use App\Services\OrderService;
use App\Services\OrderShopPurchaseConfirmationService;
use App\Services\OrderShopReturnService;
use App\Services\OrderShopService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCustomerAreaLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function context(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['name' => 'فرع Center', 'default_currency_id' => $currency->id]);
        $root = Area::factory()->for($branch)->create(['name' => 'منطقة Root']);
        $area = Area::factory()->for($branch)->create(['name' => 'منطقة Child', 'parent_id' => $root->id, 'code' => 'AREA-01']);
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($branch);
        $customer = Customer::create(['name' => 'زبون English', 'notes' => 'ملاحظة Original', 'created_by_user_id' => $actor->id]);
        $phone = $customer->phones()->create(['phone' => '+963 944 123456', 'normalized_phone' => '963944123456', 'is_primary' => true]);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'شارع Original', 'is_primary' => true]);
        $order = app(OrderService::class)->create($actor, [
            'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id,
            'source' => 'MANUAL', 'entry_mode' => 'LIVE',
        ]);
        $shop = app(OrderShopService::class)->add($actor, $order, [
            'shop_type' => 'ONE_TIME', 'business_classification' => 'SHOP', 'shop_name' => 'محل Original',
        ]);
        $unit = Unit::create(['name' => 'قطعة Piece', 'code' => 'PC', 'allows_decimal' => false]);
        $item = app(OrderItemService::class)->add($actor, $order, $shop, [
            'item_type' => 'ONE_TIME_ITEM', 'item_name' => 'منتج Original', 'unit_id' => $unit->id,
            'quantity' => '1', 'estimated_currency_id' => $currency->id, 'manual_estimated_unit_price' => '12.3400',
        ]);

        return compact('currency', 'branch', 'root', 'area', 'actor', 'customer', 'phone', 'address', 'order', 'shop', 'item');
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    public function test_order_pages_localize_labels_and_preserve_payload_codes_and_exact_values(): void
    {
        $c = $this->context();
        $this->actingAs($c['actor'])->get(route('orders.index'))->assertOk()
            ->assertSee('الطلبات')->assertSee('جديد')->assertSee('تقديري')->assertSee($c['order']->order_number)
            ->assertSee($c['customer']->name)->assertSee($c['phone']->phone);
        $create = $this->get(route('orders.create'))->assertOk()->assertSee('إضافة طلب')
            ->assertSee('رقم هاتف الزبون')->assertSee('عنوان التوصيل')->assertSee('مصدر الطلب')
            ->assertSee('name="customer_phone_id"', false)->assertSee('value="MANUAL"', false)
            ->assertSee('value="HISTORICAL_MANUAL"', false);
        $this->assertSame(0, $this->xpath($create->getContent())->query('//option//bdi')->length);
        $show = $this->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee('محل لمرة واحدة')->assertSee('الكباتن المعيّنون')->assertSee('سجل حالات الطلب')
            ->assertSee('تم التواصل مع المحل')->assertSee('أكد المحل الطلب')->assertSee('الطلب جاهز لدى المحل')
            ->assertSee('تم الاستلام من المحل')->assertSee('لم يُسجل بعد')
            ->assertSee('رفع فاتورة المحل')->assertSee('تأكيد إلغاء الطلب قبل الشراء')
            ->assertSee('إلغاء بطلب الزبون')->assertSee('value="CUSTOMER_CANCELLED"', false)
            ->assertSee('value="BEFORE_PURCHASE"', false)->assertSee('value="PRIMARY_COLLECTION"', false)
            ->assertSee('منتج Original')->assertSee('12.3400');
        $xpath = $this->xpath($show->getContent());
        $this->assertSame(1, $xpath->query('//bdi[@dir="ltr" and text()="'.$c['order']->order_number.'"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//bdi[@dir="ltr" and text()="+963 944 123456"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//bdi[@dir="ltr" and text()="12.3400"]')->length);
        $this->assertSame('NEW', $c['order']->fresh()->operational_status->value);
        $this->assertSame('MANUAL', $c['order']->fresh()->source->value);
        $this->assertSame('12.3400', $c['item']->fresh()->estimated_unit_price);
    }

    public function test_customer_area_forms_and_hierarchy_preserve_user_entered_data(): void
    {
        $c = $this->context();
        $this->actingAs($c['actor'])->get(route('customers.index'))->assertOk()
            ->assertSee('بحث بالاسم أو رقم الهاتف')->assertSee('إضافة زبون')->assertSee($c['customer']->name);
        $this->get(route('customers.create'))->assertOk()->assertSee('حفظ الزبون');
        $this->get(route('customers.edit', $c['customer']))->assertOk()->assertSee('تعديل الزبون')->assertSee('ملاحظة Original');
        $show = $this->get(route('customers.show', $c['customer']))->assertOk()->assertSee('أرقام الهاتف')
            ->assertSee('العناوين')->assertSee('إضافة رقم هاتف')->assertSee('إضافة عنوان')
            ->assertSee($c['area']->path())->assertSee('شارع Original');
        $this->assertGreaterThan(0, $this->xpath($show->getContent())->query('//bdi[@dir="ltr" and text()="+963 944 123456"]')->length);
        $phone = $this->get(route('customers.phones.create', $c['customer']))->assertOk()->assertSee('رقم الهاتف الأساسي');
        $this->assertSame('ltr', $this->xpath($phone->getContent())->query('//input[@name="phone"]')->item(0)->getAttribute('dir'));
        $this->get(route('customers.phones.edit', [$c['customer'], $c['phone']]))->assertOk()->assertSee('تعديل رقم الهاتف');
        foreach (['customers.addresses.create', 'customers.addresses.edit'] as $route) {
            $args = str_ends_with($route, 'edit') ? [$c['customer'], $c['address']] : $c['customer'];
            $this->get(route($route, $args))->assertOk()->assertSee('تفاصيل العنوان')->assertSee('علامة مميزة')
                ->assertSee('العنوان الأساسي')->assertSee($c['area']->path());
        }
        $this->get(route('areas.index', ['branch_id' => $c['branch']->id]))->assertOk()
            ->assertSee('المناطق')->assertSee('المنطقة الرئيسية:')->assertSee('AREA-01');
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $this->actingAs($admin)->get(route('areas.create', ['branch_id' => $c['branch']->id]))->assertOk()
            ->assertSee('إضافة منطقة')->assertSee('المنطقة الرئيسية')->assertSee($c['root']->path());
        $this->get(route('areas.edit', $c['area']))->assertOk()->assertSee('تعديل المنطقة')->assertSee('AREA-01');
        $this->assertSame('زبون English', $c['customer']->fresh()->name);
        $this->assertSame('منطقة Child', $c['area']->fresh()->name);
    }

    public function test_order_create_preserves_customer_visibility_and_eager_loaded_relationship_constraints(): void
    {
        $c = $this->context();
        $foreignBranch = Branch::factory()->create();
        $foreignArea = Area::factory()->for($foreignBranch)->create();
        $hidden = Customer::factory()->create(['name' => 'Hidden foreign customer']);
        $hidden->addresses()->create(['area_id' => $foreignArea->id, 'address_line_1' => 'Hidden address']);
        $hidden->phones()->create(['phone' => '999111222', 'normalized_phone' => '999111222']);
        $inactive = Customer::factory()->create(['name' => 'Inactive accessible customer', 'is_active' => false]);
        $inactive->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'Inactive customer address']);
        $inactiveAddress = $c['customer']->addresses()->create(['area_id' => $c['area']->id, 'address_line_1' => 'Inactive address']);
        $inactiveAddress->forceFill(['is_active' => false])->save();
        $inactiveArea = Area::factory()->for($c['branch'])->create(['is_active' => false]);
        $c['customer']->addresses()->create(['area_id' => $inactiveArea->id, 'address_line_1' => 'Inactive area address']);

        $response = $this->actingAs($c['actor'])->get(route('orders.create'))->assertOk()
            ->assertSee($c['customer']->name)->assertSee($c['address']->address_line_1)
            ->assertSee('إضافة طلب')->assertSee('رقم هاتف الزبون')->assertSee('عنوان التوصيل')
            ->assertSee('dir="rtl"', false)
            ->assertSee("\u{2066}".$c['phone']->phone."\u{2069}")
            ->assertDontSee($hidden->name)->assertDontSee('Hidden address')->assertDontSee('999111222')
            ->assertDontSee($inactive->name)->assertDontSee('Inactive address')->assertDontSee('Inactive area address');
        $customers = $response->viewData('customers');
        $this->assertSame([$c['customer']->id], $customers->modelKeys());
        $visible = $customers->sole();
        $this->assertTrue($visible->relationLoaded('phones'));
        $this->assertTrue($visible->relationLoaded('addresses'));
        $this->assertSame([$c['phone']->id], $visible->phones->modelKeys());
        $this->assertSame([$c['address']->id], $visible->addresses->modelKeys());
        $this->assertSame($c['branch']->id, $visible->addresses->sole()->area->branch->id);
    }

    public function test_read_only_user_cannot_gain_mutation_controls_or_cross_branch_access(): void
    {
        $c = $this->context();
        $role = Role::create(['code' => 'LOCALIZED_VIEWER', 'name' => 'Viewer']);
        $role->permissions()->sync(Permission::whereIn('code', ['orders.view', 'customers.view', 'areas.view'])->pluck('id'));
        $viewer = User::factory()->create();
        $viewer->roles()->attach($role);
        $viewer->branches()->attach($c['branch']);
        $this->actingAs($viewer)->get(route('orders.show', $c['order']))->assertOk()
            ->assertDontSee(route('orders.shops.store', $c['order']))
            ->assertDontSee(route('orders.captains.store', $c['order']))
            ->assertDontSee(route('orders.cancel', $c['order']))
            ->assertDontSee(route('orders.shops.receipts.store', [$c['order'], $c['shop']]));
        $this->get(route('customers.show', $c['customer']))->assertOk()
            ->assertDontSee(route('customers.edit', $c['customer']))
            ->assertDontSee(route('customers.phones.create', $c['customer']));
        $this->get(route('areas.index', ['branch_id' => $c['branch']->id]))->assertOk()->assertDontSee(route('areas.edit', $c['area']));
        $outsider = User::factory()->create();
        $outsider->roles()->attach($role);
        $this->actingAs($outsider)->get(route('orders.show', $c['order']))->assertForbidden();
        $this->get(route('customers.show', $c['customer']))->assertForbidden();
    }

    public function test_phone_match_prefix_is_safe_json_and_matching_stays_authorized(): void
    {
        $c = $this->context();
        $match = Customer::create(['name' => '<script>match()</script>', 'created_by_user_id' => $c['actor']->id]);
        $match->forceFill(['created_by_user_id' => $c['actor']->id])->save();
        $match->phones()->create(['phone' => '963944123456', 'normalized_phone' => '963944123456']);
        $this->actingAs($c['actor'])->get(route('customers.phone-matches', ['customer' => $c['customer'], 'phone' => '+963 944 123456']))
            ->assertOk()->assertJsonPath('matches.0.name', $match->name);
        __('customers.match_prefix');
        Lang::addLines(['customers.match_prefix' => '</script><script>injected()</script>'], 'ar');
        $form = $this->get(route('customers.phones.create', $c['customer']))->assertOk()
            ->assertSee('const matchPrefix =', false)->assertSee('row.append(matchPrefix, link)', false)
            ->assertSee('link.textContent = match.name', false)
            ->assertDontSee('</script><script>injected()</script>', false);
        $this->assertStringContainsString('u003C', $form->getContent());
        $this->post(route('customers.phones.store', $c['customer']), ['phone' => '963944123456', 'duplicate_acknowledged' => true])
            ->assertSessionHasErrors('normalized_phone');
        $other = Customer::create(['name' => 'Intentional duplicate', 'created_by_user_id' => $c['actor']->id]);
        $other->forceFill(['created_by_user_id' => $c['actor']->id])->save();
        $this->post(route('customers.phones.store', $other), ['phone' => '963944123456', 'duplicate_acknowledged' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('customers.flash.phone_added'));
        $this->assertDatabaseHas('customer_phones', ['customer_id' => $other->id, 'normalized_phone' => '963944123456']);
    }

    public function test_flash_translation_keeps_redirect_session_and_request_fields(): void
    {
        $c = $this->context();
        $this->actingAs($c['actor'])->post(route('customers.store'), ['name' => 'زبون New'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('customers.flash.created'));
        $new = Customer::where('name', 'زبون New')->sole();
        $this->put(route('customers.update', $new), ['name' => 'زبون Updated'])
            ->assertRedirect(route('customers.show', $new))->assertSessionHas('status', __('customers.flash.updated'));
        $this->post(route('orders.store'), [
            'branch_id' => $c['branch']->id, 'customer_id' => $c['customer']->id,
            'customer_phone_id' => $c['phone']->id, 'customer_address_id' => $c['address']->id,
            'source' => 'MANUAL', 'entry_mode' => 'LIVE',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', __('orders.flash.created'));
    }

    public function test_purchase_intent_return_and_final_cancellation_ui_use_canonical_wording(): void
    {
        Storage::fake('order_returns');
        $c = $this->context();
        $captain = Captain::create(['user_id' => User::factory()->create()->id, 'home_branch_id' => $c['branch']->id, 'operational_status' => CaptainOperationalStatus::Available]);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $assignment = app(OrderCaptainService::class)->assign($c['actor'], $c['order'], $captain, OrderCaptainRole::PrimaryCollection);
        $image = fn () => UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG));
        app(OrderShopPurchaseConfirmationService::class)->record($c['actor'], $c['order'], $c['shop'], [
            'order_captain_id' => $assignment->id, 'currency_id' => $c['currency']->id, 'amount' => '12.34',
            'purchased_at' => now()->subMinutes(5)->toIso8601String(), 'request_key' => (string) Str::uuid(),
        ], $image());
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee('الشراء المثبت:')->assertSee('عرض إثبات الشراء الخاص')->assertSee('بدء إلغاء الطلب وإرجاع المشتريات');
        app(OrderCancellationIntentService::class)->create($c['actor'], $c['order'], OrderCancellationReason::CustomerCancelled, null, (string) Str::uuid());
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee('الكابتن الذي أعاد المشتريات')
            ->assertSee('المبلغ المسترد من المحل')->assertSee('إثبات مشترك للإرجاع ورد المبلغ')
            ->assertSee('تسجيل اكتمال إرجاع المشتريات')->assertDontSee(route('orders.status', $c['order']));
        app(OrderShopReturnService::class)->record($c['actor'], $c['order'], $c['shop'], [
            'returning_order_captain_id' => $assignment->id, 'refund_amount' => '12.34',
            'returned_at' => now()->subMinutes(2)->toIso8601String(), 'refunded_at' => now()->subMinute()->toIso8601String(),
            'refund_method' => 'CASH', 'request_key' => (string) Str::uuid(),
        ], ['RETURN_AND_REFUND_PROOF' => $image()]);
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee('إثبات إرجاع المشتريات ورد المبلغ')
            ->assertSee('تأكيد الإلغاء النهائي للطلب');
        $this->post(route('orders.cancellation-finalize', $c['order']))->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('orders.flash.cancelled_after'));
        $this->get(route('orders.show', $c['order']))->assertOk()->assertSee('إلغاء بعد الشراء')->assertSee('إلغاء بطلب الزبون');
        $this->assertSame(OrderOperationalStatus::Cancelled, $c['order']->fresh()->operational_status);
    }

    public function test_new_catalogs_match_and_english_fallback_remains_usable(): void
    {
        foreach (['common', 'orders', 'customers', 'areas'] as $group) {
            $ar = Arr::dot(require lang_path('ar/'.$group.'.php'));
            $en = Arr::dot(require lang_path('en/'.$group.'.php'));
            $this->assertEqualsCanonicalizing(array_keys($ar), array_keys($en), $group);
            foreach ($ar as $key => $value) {
                $this->assertNotSame('', $value, $key);
                $this->assertNotSame('', $en[$key], $key);
            }
        }
        $c = $this->context();
        app()->setLocale('en');
        $this->actingAs($c['actor'])->get(route('orders.show', $c['order']))->assertOk()
            ->assertSee('dir="ltr"', false)->assertSee('Shop progress and private receipt evidence')->assertSee('Cancel before purchase');
    }
}
