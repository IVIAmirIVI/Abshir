<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\ShopBusinessClassification;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainGroup;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopType;
use App\Models\Unit;
use App\Models\User;
use App\Services\CaptainBranchService;
use App\Support\UiLabels;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class CaptainShopLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $branch = Branch::factory()->create(['name' => 'فرع Original']);
        $other = Branch::factory()->create(['name' => 'فرع Other']);
        $user = User::factory()->create(['name' => 'كابتن Original', 'email' => 'captain@example.com']);
        $user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $captain = Captain::factory()->for($user)->create(['home_branch_id' => $branch->id, 'operational_status' => CaptainOperationalStatus::Available, 'employee_code' => 'CAP-TEST', 'notes' => 'ملاحظة Original']);
        $captain->employmentPeriods()->create(['started_at' => now()->subMonth()]);
        $group = CaptainGroup::factory()->create(['name' => 'مجموعة Original', 'code' => 'GROUP-TEST']);
        $root = Area::factory()->for($branch)->create(['name' => 'منطقة Root']);
        $area = Area::factory()->for($branch)->create(['name' => 'منطقة Child', 'parent_id' => $root->id]);
        $type = ShopType::create(['name' => 'نوع Original', 'code' => 'REST-TEST', 'business_classification' => ShopBusinessClassification::Restaurant]);
        $shop = Shop::create(['name' => 'محل Original', 'code' => 'SHOP-TEST', 'shop_type_id' => $type->id, 'area_id' => $area->id, 'phone' => '+963 944 123456', 'address' => 'عنوان Original', 'notes' => 'ملاحظة Shop']);

        return compact('admin', 'branch', 'other', 'captain', 'group', 'area', 'type', 'shop');
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    public function test_captain_pages_preserve_distinct_states_codes_ltr_and_user_data(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->get(route('captains.index'))->assertOk()->assertSee('الكباتن')->assertSee('متاح')->assertSee($c['captain']->user->name);
        $create = $this->get(route('captains.create'))->assertOk()->assertSee('إضافة كابتن')->assertSee('حساب المستخدم')->assertSee('تاريخ بدء العمل');
        $this->assertSame(0, $this->xpath($create->getContent())->query('//option//bdi')->length);
        foreach (CaptainOperationalStatus::cases() as $status) {
            $create->assertSee('value="'.$status->value.'"', false)->assertSee(UiLabels::label('common.captain_statuses', $status));
        }
        $show = $this->get(route('captains.show', $c['captain']))->assertOk()->assertSee('حساب فعّال')->assertSee('على رأس العمل')->assertSee('التوفر التشغيلي')->assertSee('متاح')->assertSee('تأكيد إنهاء عمل الكابتن')->assertSee('ملخص التقييمات')->assertSee('ملاحظة Original');
        $this->assertSame(1, $this->xpath($show->getContent())->query('//bdi[@dir="ltr" and text()="captain@example.com"]')->length);
        $this->assertGreaterThan(0, $this->xpath($show->getContent())->query('//bdi[@dir="ltr" and text()="CAP-TEST"]')->length);
        $this->get(route('captains.edit', $c['captain']))->assertOk()->assertSee('تعديل الكابتن')->assertSee('حفظ الكابتن');
        $this->assertSame('AVAILABLE', $c['captain']->fresh()->operational_status->value);
    }

    public function test_assignment_transfer_groups_and_employment_render_real_history_without_changing_it(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->get(route('captains.assignments.create', $c['captain']))->assertOk()->assertSee('تعيين مؤقت')->assertSee($c['branch']->name);
        $this->get(route('captains.transfer.create', $c['captain']))->assertOk()->assertSee('تأكيد نقل الكابتن')->assertSee('الأرصدة المالية السابقة تبقى منسوبة إلى فروعها الأصلية.');
        $this->get(route('captains.groups.create', $c['captain']))->assertOk()->assertSee('إضافة الكابتن إلى مجموعة')->assertSee($c['group']->name);
        $this->get(route('captain-groups.index'))->assertOk()->assertSee('مجموعات الكباتن')->assertSee($c['group']->name);
        $this->get(route('captain-groups.create'))->assertOk()->assertSee('إضافة مجموعة كباتن');
        $this->get(route('captain-groups.edit', $c['group']))->assertOk()->assertSee('تعديل مجموعة الكباتن');
        app(CaptainBranchService::class)->assignTemporary($c['admin'], $c['captain'], ['branch_id' => $c['other']->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'reason' => 'Temporary Original']);
        $this->post(route('captains.groups.store', $c['captain']), ['captain_group_id' => $c['group']->id, 'joined_at' => now()->subDay()->toDateTimeString()])->assertSessionHasNoErrors()->assertSessionHas('status', __('captains.flash_captain_group_membership_created'));
        $this->get(route('captains.show', $c['captain']))->assertOk()->assertSee('سجل التعيين والنقل بين الفروع')->assertSee('تعيين مؤقت')->assertSee('Temporary Original')->assertSee('إزالة من المجموعة')->assertSee($c['group']->name);
        $this->patch(route('captains.employment.terminate', $c['captain']), ['reason' => 'End Original'])->assertSessionHasNoErrors()->assertSessionHas('status', __('captains.flash_captain_employment_terminated'));
        $this->get(route('captains.show', $c['captain']))->assertOk()->assertSee('حساب معطّل')->assertSee('انتهى العمل')->assertSee('غير متاح')->assertSee('إعادة الكابتن إلى العمل')->assertSee('End Original');
        $this->assertSame($c['branch']->id, $c['captain']->fresh()->home_branch_id);
        $this->assertDatabaseHas('captain_group_members', ['captain_id' => $c['captain']->id, 'captain_group_id' => $c['group']->id]);
    }

    public function test_shop_types_partnerships_and_listing_ui_preserve_names_codes_and_exact_price(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->get(route('shops.index'))->assertOk()->assertSee('المحلات')->assertSee($c['shop']->name)->assertSee($c['area']->path());
        $form = $this->get(route('shops.create'))->assertOk()->assertSee('إضافة محل')->assertSee('اسم المحل')->assertSee('جاهزية استقبال الطلبات الإلكترونية');
        $this->assertSame('ltr', $this->xpath($form->getContent())->query('//input[@name="phone"]')->item(0)->getAttribute('dir'));
        $this->get(route('shops.edit', $c['shop']))->assertOk()->assertSee('تعديل المحل')->assertSee($c['shop']->name);
        $this->get(route('shop-types.index'))->assertOk()->assertSee('أنواع المحلات')->assertSee('مطعم')->assertSee($c['type']->name);
        $typeForm = $this->get(route('shop-types.create'))->assertOk()->assertSee('إضافة نوع محل')->assertSee('مطعم')->assertSee('محل')->assertSee('value="RESTAURANT"', false)->assertSee('value="SHOP"', false);
        $this->assertSame(0, $this->xpath($typeForm->getContent())->query('//option//bdi')->length);
        $this->get(route('shop-types.edit', $c['type']))->assertOk()->assertSee('تعديل نوع المحل');
        $unit = Unit::create(['name' => 'قطعة Piece', 'code' => 'PC', 'allows_decimal' => false]);
        $currency = Currency::create(['name' => 'Dollar', 'code' => 'USD', 'decimal_places' => 2]);
        $product = Product::create(['name' => 'منتج Original', 'default_unit_id' => $unit->id]);
        $listing = $c['shop']->products()->create(['product_id' => $product->id, 'unit_id' => $unit->id, 'currency_id' => $currency->id, 'listed_price' => '12.3400', 'is_available' => true, 'is_featured' => true]);
        $this->post(route('shops.partnerships.store', $c['shop']), ['starts_at' => now()->subDay()->toDateTimeString(), 'status' => 'ACTIVE', 'mobile_enabled' => false, 'online_orders_enabled' => false, 'notes' => 'Partnership Original'])->assertSessionHasNoErrors()->assertSessionHas('status', __('shops.flash_shop_partnership_added'));
        $show = $this->get(route('shops.show', $c['shop']))->assertOk()->assertSee('سجل الشراكات')->assertSee('بدء الشراكة')->assertSee('تأكيد إنهاء الشراكة')->assertSee('فعّالة')->assertSee('موقوفة')->assertSee('Partnership Original')->assertSee('منتجات المحل')->assertSee('منتج Original')->assertSee('12.3400')->assertSee('عنوان Original');
        $this->assertSame(1, $this->xpath($show->getContent())->query('//bdi[@dir="ltr" and text()="+963 944 123456"]')->length);
        $this->get(route('shops.products.create', $c['shop']))->assertOk()->assertSee('إضافة منتج للمحل')->assertSee('السعر المسجل الحالي');
        $this->get(route('shops.products.edit', [$c['shop'], $listing]))->assertOk()->assertSee('تعديل منتج المحل')->assertSee('12.3400');
        $partnership = $c['shop']->partnerships()->sole();
        $this->patch(route('shops.partnerships.end', [$c['shop'], $partnership]))->assertSessionHasNoErrors()->assertSessionHas('status', __('shops.flash_shop_partnership_ended'));
        $this->get(route('shops.show', $c['shop']))->assertOk()->assertSee('منتهية')->assertSee('Partnership Original');
        $this->assertSame('12.3400', $listing->fresh()->listed_price);
        $this->assertSame('RESTAURANT', $c['type']->fresh()->business_classification->value);
    }

    public function test_employee_controls_remain_hidden_and_foreign_records_remain_forbidden(): void
    {
        $c = $this->context();
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($c['branch']);
        $this->actingAs($employee)->get(route('captains.show', $c['captain']))->assertOk()->assertDontSee(route('captains.status', $c['captain']))->assertDontSee(route('captains.employment.terminate', $c['captain']))->assertDontSee(route('captains.transfer.create', $c['captain']))->assertDontSee(__('captains.ui_rating_summary'));
        $this->get(route('shops.show', $c['shop']))->assertOk()->assertDontSee(route('shops.edit', $c['shop']))->assertDontSee(route('shops.partnerships.store', $c['shop']))->assertDontSee(route('shops.products.create', $c['shop']));
        $foreign = Captain::factory()->create(['home_branch_id' => $c['other']->id]);
        $foreignShop = Shop::create(['name' => 'Foreign Hidden Shop', 'area_id' => Area::factory()->for($c['other'])->create()->id]);
        $this->get(route('captains.show', $foreign))->assertForbidden();
        $this->get(route('shops.show', $foreignShop))->assertForbidden();
        $this->get(route('shops.index'))->assertOk()->assertDontSee($foreignShop->name);
        $this->actingAs($c['captain']->user)->get(route('shops.index'))->assertForbidden();
        $this->get(route('captains.index'))->assertForbidden();
    }

    public function test_catalogs_have_matching_keys_and_english_rendering_remains_available(): void
    {
        $c = $this->context();
        foreach (['captains', 'shops'] as $group) {
            $ar = require lang_path('ar/'.$group.'.php');
            $en = require lang_path('en/'.$group.'.php');
            $this->assertSame(array_keys(Arr::dot($ar)), array_keys(Arr::dot($en)));
            foreach (Arr::dot($ar) as $value) {
                $this->assertIsString($value);
                $this->assertNotSame('', $value);
            }
        }
        app()->setLocale('en');
        $this->actingAs($c['admin'])->get(route('captains.show', $c['captain']))->assertOk()->assertSee('Operational status')->assertSee('Available');
        $this->get(route('shops.show', $c['shop']))->assertOk()->assertSee('Partnership history')->assertSee($c['shop']->name);
    }
}
