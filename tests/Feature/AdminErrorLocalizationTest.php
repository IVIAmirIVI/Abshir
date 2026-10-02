<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Services\OrderService;
use App\Support\UiLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\Support\ManualDepositFixtures;
use Tests\TestCase;

class AdminErrorLocalizationTest extends TestCase
{
    use ManualDepositFixtures, RefreshDatabase;

    public function test_admin_pages_translate_system_roles_permissions_and_preserve_custom_data(): void
    {
        $f = $this->manualFixture();
        $custom = Role::create(['code' => 'CUSTOM_DISPLAY', 'name' => 'Custom stored name']);
        $f['employee']->roles()->attach($custom);
        foreach (['admin.branches.index', 'admin.branches.create', 'admin.users.index', 'admin.users.create', 'admin.roles.index', 'admin.roles.create', 'admin.permissions.index', 'admin.pricing-settings.edit', 'admin.delivery-pricing-settings.edit', 'admin.bill-payment-fees.index', 'delivery-pricing-rules.index', 'delivery-pricing-rules.create', 'delivery-overrides.index'] as $route) {
            $this->actingAs($f['admin'])->get(route($route))->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false)->assertDontSee('admin.ui.')->assertDontSee('admin.permission_groups.');
        }
        $this->get(route('admin.users.index'))->assertSee('المستخدمون')->assertSee('مدير النظام')->assertSee('المشرف')->assertSee('Custom stored name');
        $this->get(route('admin.permissions.index'))->assertSee(UiLabels::permission('orders.view'))->assertSee('orders.view')->assertSee('dir="ltr"', false);
        $this->get(route('delivery-pricing-rules.create'))->assertSee('منطقة الانطلاق')->assertSee('منطقة الوصول')->assertSee('مطعم واحد')->assertSee('محل واحد')->assertSee('عدة محلات');
        foreach (Permission::all() as $permission) {
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', UiLabels::permission($permission->code));
            $this->assertSame($permission->code, $permission->fresh()->code);
        }
        $this->assertSame('Custom stored name', $custom->fresh()->name);
        $this->assertDatabaseMissing('roles', ['code' => 'SUPERVISOR']);
    }

    public function test_order_and_financial_errors_keep_their_fields_http_behavior_and_state(): void
    {
        $f = $this->manualFixture();
        $area = Area::factory()->for($f['branch'])->create();
        $customer = Customer::create(['name' => 'Untranslated customer']);
        $phone = $customer->phones()->create(['phone' => '111', 'normalized_phone' => '111']);
        $address = $customer->addresses()->create(['area_id' => $area->id, 'address_line_1' => 'Stored address']);
        $order = app(OrderService::class)->create($f['admin'], ['branch_id' => $f['branch']->id, 'customer_id' => $customer->id, 'customer_phone_id' => $phone->id, 'customer_address_id' => $address->id, 'source' => 'MANUAL', 'entry_mode' => 'LIVE']);
        $this->actingAs($f['admin'])->patchJson(route('orders.status', $order), ['operational_status' => 'DELIVERED'])
            ->assertUnprocessable()->assertJsonValidationErrors(['operational_status'])
            ->assertJsonPath('errors.operational_status.0', 'هذا الانتقال بين الحالات التشغيلية غير مسموح.');
        $this->assertSame('NEW', $order->fresh()->operational_status->value);
        $this->post(route('captains.financials.payouts.store', $f['captain']), [
            'branch_id' => $f['branch']->id, 'currency_id' => $f['currency']->id,
            'request_key' => (string) Str::uuid(), 'amount' => '1.00', 'occurred_at' => now()->subSecond()->toIso8601String(),
            'evidence' => $this->manualProof(), 'allocations' => [['financial_entry_id' => 1, 'amount' => '0']],
        ])->assertRedirect()->assertSessionHasErrors(['allocations' => 'حدد قيود مستحقات الكابتن التي تم صرفها بدقة.']);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
    }

    public function test_admin_and_pricing_validation_are_arabic_without_granting_access(): void
    {
        $f = $this->manualFixture();
        $role = Role::where('code', 'SUPER_ADMIN')->sole();
        $this->actingAs($f['admin'])->putJson(route('admin.roles.update', $role), ['name' => $role->name, 'code' => $role->code])
            ->assertUnprocessable()->assertJsonValidationErrors(['role'])
            ->assertJsonPath('errors.role.0', 'أدوار النظام الأساسية محمية. أنشئ دورًا مخصصًا لتغيير صلاحيات الوصول.');
        $from = Area::factory()->for($f['branch'])->create();
        $to = Area::factory()->for($f['branch'])->create();
        $this->postJson(route('delivery-pricing-rules.store'), ['from_area_id' => $from->id, 'to_area_id' => $to->id, 'currency_id' => $f['currency']->id, 'order_pricing_class' => 'SINGLE_SHOP', 'delivery_unit_fee' => '1.234', 'effective_from' => now()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['delivery_unit_fee'])
            ->assertJsonPath('errors.delivery_unit_fee.0', 'تتجاوز الأجرة دقة العملة USD.');
        $this->assertDatabaseCount('delivery_pricing_rules', 0);
        foreach ([$f['employee'], $f['captain']->user] as $user) {
            foreach (['admin.users.index', 'admin.roles.index', 'admin.permissions.index', 'admin.delivery-pricing-settings.edit'] as $route) {
                $this->actingAs($user)->get(route($route))->assertForbidden()->assertSee('الوصول غير مسموح');
            }
        }
    }

    public function test_all_catalogs_and_static_translation_references_resolve_in_both_languages(): void
    {
        foreach (glob(lang_path('ar/*.php')) as $file) {
            $group = basename($file, '.php');
            $ar = Arr::dot(require $file);
            $en = Arr::dot(require lang_path('en/'.$group.'.php'));
            $this->assertEqualsCanonicalizing(array_keys($ar), array_keys($en), $group);
            foreach ($ar as $key => $value) {
                if (is_string($value)) {
                    $this->assertNotSame('', $value, $group.'.'.$key);
                    $this->assertNotSame('', $en[$key], $group.'.'.$key);
                }
            }
        }
        foreach (['resources/views', 'app/Http', 'app/Services'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory))) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all('/__\(\s*[\'"]([a-z_]+\.[a-zA-Z0-9_.]+)[\'"]\s*(?=[,)])/', file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $key) {
                    foreach (['ar', 'en'] as $locale) {
                        $this->assertTrue(Lang::hasForLocale($key, $locale), $key.' '.$file->getPathname());
                    }
                }
            }
        }
    }

    public function test_validation_grammar_and_known_money_errors_remain_distinct(): void
    {
        $this->assertSame('يجب ألا يقل المبلغ عن 2.', Validator::make(['amount' => 1], ['amount' => 'numeric|min:2'])->errors()->first('amount'));
        $this->assertSame('يجب ألا يتجاوز المبلغ 2.', Validator::make(['amount' => 3], ['amount' => 'numeric|max:2'])->errors()->first('amount'));
        $this->assertSame('يجب ألا يقل عدد أحرف الاسم عن 2.', Validator::make(['name' => 'a'], ['name' => 'string|min:2'])->errors()->first('name'));
        $this->assertSame('يتجاوز المبلغ المالي دقة المنازل العشرية للعملة.', UiLabels::error('Financial amount exceeds the Currency decimal precision.'));
        $this->assertSame('يجب أن تكون مبالغ الحركات المالية أكبر من صفر.', UiLabels::error('Financial movement amounts must be greater than zero.'));
        $this->assertSame('INTERNAL_UNEXPECTED', UiLabels::error('INTERNAL_UNEXPECTED'));
        app()->setLocale('en');
        $this->assertSame('Financial amount exceeds the Currency decimal precision.', UiLabels::error('Financial amount exceeds the Currency decimal precision.'));
        $this->get('/login')->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);
    }
}
