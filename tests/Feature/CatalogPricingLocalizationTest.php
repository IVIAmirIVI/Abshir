<?php

namespace Tests\Feature;

use App\Enums\PriceObservationSource;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogPricingLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function context(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $shop = Shop::create(['name' => 'محل Original', 'area_id' => $area->id]);
        $category = Category::create(['name' => 'تصنيف Original', 'code' => 'CAT-TEST']);
        $unit = Unit::create(['name' => 'وحدة Piece', 'code' => 'PC', 'symbol' => 'pc', 'allows_decimal' => true]);
        $currency = Currency::create(['name' => 'عملة Original', 'code' => 'USD', 'symbol' => '$', 'decimal_places' => 4]);
        $product = Product::create(['name' => 'منتج Original', 'sku' => 'SKU-TEST', 'barcode' => '12345678', 'description' => 'وصف Original', 'category_id' => $category->id, 'default_unit_id' => $unit->id]);
        $group = ProductGroup::create(['name' => 'مجموعة Original', 'code' => 'GROUP-TEST']);

        return compact('admin', 'branch', 'shop', 'category', 'unit', 'currency', 'product', 'group');
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    public function test_category_unit_currency_and_group_pages_are_arabic_and_keep_stored_fields(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin']);
        foreach ([['categories', 'category', 'التصنيفات', 'إضافة تصنيف', 'تعديل التصنيف'], ['units', 'unit', 'الوحدات', 'إضافة وحدة', 'تعديل الوحدة'], ['currencies', 'currency', 'العملات', 'إضافة عملة', 'تعديل العملة'], ['product-groups', 'group', 'مجموعات المنتجات', 'إضافة مجموعة منتجات', 'تعديل مجموعة المنتجات']] as [$route, $record, $title, $create, $edit]) {
            $this->get(route($route.'.index'))->assertOk()->assertSee($title)->assertSee($c[$record]->name);
            $this->get(route($route.'.create'))->assertOk()->assertSee($create);
            $this->get(route($route.'.edit', $c[$record]))->assertOk()->assertSee($edit)->assertSee($c[$record]->name);
        }
        $index = $this->get(route('currencies.index'))->assertOk()->assertSee('USD')->assertSee('4')->assertSee('منازل عشرية');
        $this->assertSame(1, $this->xpath($index->getContent())->query('//bdi[@dir="ltr" and text()="USD"]')->length);
        $form = $this->get(route('currencies.edit', $c['currency']))->assertOk()->assertSee('عدد المنازل العشرية');
        $xpath = $this->xpath($form->getContent());
        $this->assertSame('ltr', $xpath->query('//input[@name="code"]')->item(0)->getAttribute('dir'));
        $this->assertSame('4', $xpath->query('//input[@name="decimal_places"]')->item(0)->getAttribute('value'));
        $this->put(route('currencies.update', $c['currency']), ['code' => 'USD', 'name' => $c['currency']->name, 'decimal_places' => 5])->assertSessionHasErrors('decimal_places');
        $this->assertSame(4, $c['currency']->fresh()->decimal_places);
        $this->assertTrue($c['unit']->fresh()->allows_decimal);
    }

    public function test_product_images_and_group_history_render_arabic_without_rewriting_user_data(): void
    {
        Storage::fake('public');
        $c = $this->context();
        $this->actingAs($c['admin'])->get(route('products.index'))->assertOk()->assertSee('الكتالوج')->assertSee('إضافة منتج')->assertSee($c['product']->name);
        $form = $this->get(route('products.create'))->assertOk()->assertSee('اسم المنتج')->assertSee('الوحدة الأساسية');
        $this->assertSame(0, $this->xpath($form->getContent())->query('//option//bdi')->length);
        $this->get(route('products.edit', $c['product']))->assertOk()->assertSee('تعديل المنتج')->assertSee('SKU-TEST');
        $this->post(route('products.images.store', $c['product']), ['image' => UploadedFile::fake()->createWithContent('original.png', base64_decode(self::PNG)), 'alt_text' => 'وصف Image', 'sort_order' => 0, 'is_primary' => true])->assertSessionHasNoErrors()->assertSessionHas('status', __('catalog.flash_product_image_added'));
        $this->post(route('products.groups.store', $c['product']), ['product_group_id' => $c['group']->id, 'joined_at' => now()->subDay()->toDateTimeString()])->assertSessionHasNoErrors()->assertSessionHas('status', __('catalog.flash_product_group_membership_added'));
        $show = $this->get(route('products.show', $c['product']))->assertOk()->assertSee('صور المنتج')->assertSee('رفع صورة')->assertSee('الصورة الأساسية')->assertSee('إزالة')->assertSee('سجل مجموعات المنتج')->assertSee('إزالة من المجموعة')->assertSee($c['group']->name)->assertSee('وصف Original');
        $this->assertSame(1, $this->xpath($show->getContent())->query('//bdi[@dir="ltr" and text()="SKU-TEST"]')->length);
        $membership = $c['product']->groupMemberships()->sole();
        $this->patch(route('products.groups.end', [$c['product'], $membership]))->assertSessionHasNoErrors();
        $this->get(route('products.show', $c['product']))->assertOk()->assertSee('منتهية')->assertSee($c['group']->name);
        $this->assertSame('منتج Original', $c['product']->fresh()->name);
        $this->assertSame('وصف Image', $c['product']->images()->sole()->alt_text);
    }

    public function test_pricing_history_comparison_and_estimate_preserve_exact_decimal_strings(): void
    {
        $c = $this->context();
        $this->actingAs($c['admin']);
        $form = $this->get(route('product-prices.create'))->assertOk()->assertSee('تسجيل سعر جديد')->assertSee('وقت رصد السعر');
        foreach (PriceObservationSource::cases() as $source) {
            $form->assertSee('value="'.$source->value.'"', false)->assertSee(__('pricing.sources.'.$source->value));
        }
        $this->assertSame('ltr', $this->xpath($form->getContent())->query('//input[@name="price"]')->item(0)->getAttribute('dir'));
        foreach (['12.3400', '0.0100'] as $offset => $amount) {
            $this->post(route('product-prices.store'), ['product_id' => $c['product']->id, 'shop_id' => $c['shop']->id, 'unit_id' => $c['unit']->id, 'currency_id' => $c['currency']->id, 'price' => $amount, 'observed_at' => now()->subHours(2 - $offset)->toDateTimeString(), 'source' => 'MANUAL', 'notes' => 'Price Original'])->assertSessionHasNoErrors()->assertSessionHas('status', __('pricing.flash_price_observation_recorded_historical_observations_cannot_be_edited_or_deleted'));
        }
        $history = $this->get(route('products.price-history', $c['product']))->assertOk()->assertSee('سجل الأسعار: '.$c['product']->name)->assertSee('وقت رصد السعر')->assertSee('وقت الإدخال')->assertSee('12.34')->assertSee('0.01')->assertSee('Price Original')->assertSee('إدخال يدوي');
        $this->assertSame(1, $this->xpath($history->getContent())->query('//bdi[@dir="ltr" and contains(text(), "12.34 USD")]')->length);
        $this->get(route('shops.price-history', $c['shop']))->assertOk()->assertSee('سجل الأسعار: '.$c['shop']->name)->assertSee($c['product']->name);
        $this->get(route('products.price-comparison', $c['product']))->assertOk()->assertSee('مقارنة أسعار المحلات')->assertSee($c['shop']->name)->assertSee('0.01')->assertDontSee('12.34');
        $estimate = $this->get(route('product-prices.estimate', ['preview' => 1, 'product_id' => $c['product']->id, 'unit_id' => $c['unit']->id, 'currency_id' => $c['currency']->id]))->assertOk()->assertSee('السعر التقديري المقترح')->assertSee('0.01')->assertSee($c['shop']->name)->assertSee('نافذة التقدير');
        $this->assertSame(1, $this->xpath($estimate->getContent())->query('//bdi[@dir="ltr" and text()="0.01"]')->length);
        $unused = Product::create(['name' => 'No price Product', 'default_unit_id' => $c['unit']->id]);
        $this->get(route('product-prices.estimate', ['preview' => 1, 'product_id' => $unused->id, 'unit_id' => $c['unit']->id, 'currency_id' => $c['currency']->id]))->assertOk()->assertSee('لا يتوفر سعر تقديري')->assertDontSee('السعر التقديري المقترح');
        $this->assertSame(['12.3400', '0.0100'], $c['product']->priceHistory()->orderBy('id')->pluck('price')->all());
    }

    public function test_employee_and_captain_permissions_and_pricing_branch_scope_stay_intact(): void
    {
        $c = $this->context();
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($c['branch']);
        $this->actingAs($employee)->get(route('products.show', $c['product']))->assertOk()->assertDontSee(route('products.edit', $c['product']))->assertDontSee(route('products.images.store', $c['product']))->assertDontSee(route('products.groups.store', $c['product']));
        $this->get(route('products.create'))->assertForbidden();
        $hidden = Shop::create(['name' => 'Hidden foreign Shop', 'area_id' => Area::factory()->create()->id]);
        $this->get(route('product-prices.create'))->assertOk()->assertSee($c['shop']->name)->assertDontSee($hidden->name);
        $this->get(route('shops.price-history', $hidden))->assertForbidden();
        $this->post(route('product-prices.store'), ['product_id' => $c['product']->id, 'shop_id' => $hidden->id, 'unit_id' => $c['unit']->id, 'currency_id' => $c['currency']->id, 'price' => '1.0000', 'observed_at' => now()->toDateTimeString(), 'source' => 'MANUAL'])->assertForbidden();
        $captain = User::factory()->create();
        $captain->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->actingAs($captain)->get(route('products.index'))->assertForbidden();
        $this->get(route('product-prices.index'))->assertForbidden();
    }

    public function test_translation_catalogs_match_and_english_rendering_is_available(): void
    {
        $c = $this->context();
        foreach (['catalog', 'pricing'] as $group) {
            $ar = require lang_path('ar/'.$group.'.php');
            $en = require lang_path('en/'.$group.'.php');
            $this->assertSame(array_keys(Arr::dot($ar)), array_keys(Arr::dot($en)));
            foreach (Arr::dot($ar) as $value) {
                $this->assertIsString($value);
                $this->assertNotSame('', $value);
            }
        }
        app()->setLocale('en');
        $this->actingAs($c['admin'])->get(route('products.create'))->assertOk()->assertSee('Product name');
        $this->get(route('product-prices.create'))->assertOk()->assertSee('Record price observation')->assertSee($c['shop']->name);
    }
}
