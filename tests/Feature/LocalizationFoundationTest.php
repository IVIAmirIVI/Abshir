<?php

namespace Tests\Feature;

use App\Enums\CaptainOperationalStatus;
use App\Enums\FinancialEventType;
use App\Enums\InvoiceFinancialCorrectionKind;
use App\Enums\InvoiceFinancialCorrectionState;
use App\Enums\InvoiceStatus;
use App\Enums\OrderOperationalStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\UiLabels;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\ViewException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalizationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_locale_and_guest_pages_are_arabic_without_registration(): void
    {
        $this->assertSame('ar', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
        foreach (['/', '/login', '/forgot-password', '/reset-password/test-token'] as $url) {
            $this->get($url)->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false)->assertSee('أبشر')->assertSee('طلبك علينا');
        }
        $this->get('/login')->assertSee('البريد الإلكتروني')->assertSee('كلمة المرور')->assertSee('تسجيل الدخول')->assertSee('تذكرني')->assertSee('dir="ltr"', false);
        $this->get('/register')->assertNotFound();
        $this->assertFalse(Route::has('register'));
    }

    public function test_authenticated_shell_profile_and_confirmation_are_arabic(): void
    {
        $user = User::factory()->create(['email' => 'technical@example.test']);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false)->assertSee('مرحبًا بك في أبشر')->assertDontSee('Phase 1');
        $this->get('/profile')->assertOk()->assertSee('معلومات الحساب')->assertSee('تغيير كلمة المرور')->assertSee('حفظ')->assertSee('name="email"', false)->assertSee('technical@example.test');
        $this->get('/confirm-password')->assertOk()->assertSee('يرجى تأكيد كلمة المرور');
        $this->assertStringContainsString('يرجى تأكيد بريدك الإلكتروني', view('auth.verify-email')->render());
    }

    public function test_english_rendering_uses_ltr_and_matching_catalogs(): void
    {
        app()->setLocale('en');
        $this->get('/login')->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)->assertSee('Log in');
        $this->assertSame('rtl', UiLabels::direction('ar-SY'));
        $this->assertSame('ltr', UiLabels::direction('en'));
        foreach (['common', 'auth', 'admin', 'orders', 'financial', 'messages', 'passwords', 'pagination'] as $group) {
            $ar = Arr::dot(require lang_path('ar/'.$group.'.php'));
            $en = Arr::dot(require lang_path('en/'.$group.'.php'));
            $this->assertEqualsCanonicalizing(array_keys($ar), array_keys($en), $group);
            foreach ($ar as $key => $value) {
                $this->assertNotSame('', $value, $group.'.'.$key);
                $this->assertNotSame('', $en[$key], $group.'.'.$key);
            }
        }
        // Exercise real fallback lookup without introducing a locale preference.
        app()->setLocale('fr');
        $this->assertSame('Log in', __('common.actions.login'));
    }

    public function test_canonical_roles_and_custom_permissions_do_not_mutate_codes(): void
    {
        foreach (['SUPER_ADMIN' => 'مدير النظام', 'ORDER_EMPLOYEE' => 'المشرف', 'CAPTAIN' => 'الكابتن'] as $code => $label) {
            $this->assertSame($label, UiLabels::role($code));
        }
        $this->assertSame('دور خاص', UiLabels::role('CUSTOM', 'دور خاص'));
        $this->assertSame('عرض — المستخدمين', UiLabels::permission('users.view'));
        $this->assertSame('custom.permission', UiLabels::permission('custom.permission'));
        $this->seed(AuthorizationSeeder::class);
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseMissing('roles', ['code' => 'SUPERVISOR']);
        foreach (Permission::all() as $permission) {
            $this->assertNotSame($permission->code, UiLabels::permission($permission->code));
        }
    }

    public function test_status_and_event_labels_cover_actual_identifiers_without_changing_them(): void
    {
        foreach ([
            [OrderOperationalStatus::class, 'orders.statuses'],
            [InvoiceStatus::class, 'financial.invoice_statuses'],
            [CaptainOperationalStatus::class, 'common.captain_statuses'],
            [InvoiceFinancialCorrectionState::class, 'financial.correction_states'],
            [InvoiceFinancialCorrectionKind::class, 'financial.correction_kinds'],
            [FinancialEventType::class, 'financial.events'],
        ] as [$enum, $group]) {
            foreach ($enum::cases() as $case) {
                $code = $case->value;
                $this->assertNotSame($code, UiLabels::label($group, $case));
                $this->assertSame($code, $case->value);
            }
        }
        $this->assertSame('تم التسليم', UiLabels::label('orders.statuses', 'DELIVERED'));
        $this->assertSame('صادرة', UiLabels::label('financial.invoice_statuses', 'ISSUED'));
        $this->assertSame('متاح', UiLabels::label('common.captain_statuses', 'AVAILABLE'));
        $this->assertSame('تم التجهيز', UiLabels::label('financial.correction_states', 'PREPARED'));
        $this->assertSame('UNKNOWN_FUTURE', UiLabels::label('orders.statuses', 'UNKNOWN_FUTURE'));
        $this->assertArrayNotHasKey('COLLECTED', __('financial.invoice_statuses'));
        $this->assertSame('مُحصّلة', __('financial.collection_states.collected'));
        $this->assertSame('رصيد تشغيل الكابتن لدى أبشر', __('financial.accounts.CAPTAIN_DEPOSIT'));
        $this->assertSame('مستحقات الكابتن لدى أبشر', __('financial.accounts.CAPTAIN_COMPANY_RECEIVABLE'));
        $this->assertSame('نقد أبشر بعهدة الكابتن', __('financial.accounts.CAPTAIN_CASH_CUSTODY'));
        $this->assertNotSame(__('financial.terms.deposit_receipt'), __('financial.terms.manual_deposit_adjustment'));
    }

    public function test_validation_and_auth_failures_are_arabic_and_preserve_error_keys(): void
    {
        $validator = Validator::make(['email' => 'invalid', 'amount' => 'text'], ['name' => 'required', 'email' => 'email', 'amount' => 'numeric']);
        $this->assertSame('حقل الاسم مطلوب.', $validator->errors()->first('name'));
        $this->assertSame('يجب أن يكون البريد الإلكتروني بريدًا إلكترونيًا صالحًا.', $validator->errors()->first('email'));
        $this->assertSame('يجب أن يكون المبلغ رقمًا.', $validator->errors()->first('amount'));
        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertSessionHasErrors(['email' => __('auth.failed')]);
        $ar = require lang_path('ar/validation.php');
        $en = require lang_path('en/validation.php');
        $this->assertEqualsCanonicalizing(array_keys(Arr::except($en, ['custom', 'attributes'])), array_keys(Arr::except($ar, ['custom', 'attributes'])));
    }

    public function test_technical_values_are_escaped_and_money_is_not_rounded(): void
    {
        $technical = 'users.view / 2026-10-01 / 012345 / <script>alert(1)</script>';
        $html = Blade::render('<x-ltr dir="rtl">{{ $technical }}</x-ltr>', compact('technical'));
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringNotContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('users.view / 2026-10-01 / 012345', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        foreach (['1.2345' => '1.2345', '-0.0001' => '-0.0001', '0.0000' => '0', '12345678901234567890.0000' => '12345678901234567890'] as $amount => $display) {
            $html = Blade::render('<x-money :amount="$amount" currency="USD" />', compact('amount'));
            $this->assertStringContainsString($display.' USD', $html);
            $this->assertStringContainsString('dir="ltr"', $html);
        }
    }

    public function test_money_component_rejects_float_input(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Money display requires an exact decimal string.');
        Blade::render('<x-money :amount="$amount" />', ['amount' => 1.2345]);
    }

    public static function navigationRoles(): array
    {
        return [['SUPER_ADMIN'], ['ORDER_EMPLOYEE'], ['CAPTAIN'], ['CUSTOM']];
    }

    #[DataProvider('navigationRoles')]
    public function test_desktop_and_mobile_preserve_original_link_visibility(string $code): void
    {
        $this->seed(AuthorizationSeeder::class);
        $role = Role::where('code', $code)->first() ?? Role::create(['code' => $code, 'name' => 'Custom']);
        if ($code === 'CUSTOM') {
            $role->permissions()->sync(Permission::whereIn('code', ['users.view', 'roles.view', 'invoice_collections.sham_cash.view'])->pluck('id'));
        }
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        // The pre-localization contract, independent of the new grouped menu.
        $links = [
            'branches.view' => ['admin.branches.index'],
            'users.view' => ['admin.users.index'],
            'roles.view' => ['admin.roles.index'],
            'permissions.view' => ['admin.permissions.index'],
            'customers.view' => ['customers.index'],
            'areas.view' => ['areas.index'],
            'captains.view' => ['captains.index'],
            'captain_financials.view' => ['captain-financials.index'],
            'captain_groups.view' => ['captain-groups.index'],
            'products.view' => ['products.index'],
            'shops.view' => ['shops.index'],
            'product_prices.view' => ['product-prices.index'],
            'orders.view' => ['orders.index'],
            'invoices.view' => ['invoices.index'],
            'invoice_collections.view' => ['invoice-collections.index'],
            'invoice_collections.sham_cash.view' => ['invoice-collections.sham-cash'],
            'system_settings.view' => ['admin.pricing-settings.edit', 'admin.delivery-pricing-settings.edit'],
            'delivery_pricing_rules.view' => ['delivery-pricing-rules.index'],
            'delivery_pricing_overrides.approve' => ['delivery-overrides.index'],
            'bill_payment_fees.manage' => ['admin.bill-payment-fees.index'],
        ];
        $expected = [route('dashboard'), route('profile.edit')];
        foreach ($links as $permission => $routes) {
            $visible = $code === 'SUPER_ADMIN' || $role->permissions->contains('code', $permission);
            if ($permission === 'roles.view' && $code !== 'SUPER_ADMIN') {
                $visible = false;
            }
            if ($visible) {
                foreach ($routes as $route) {
                    $expected[] = route($route);
                }
            }
        }
        if (in_array($code, ['SUPER_ADMIN', 'ORDER_EMPLOYEE'], true)) {
            $expected[] = route('notifications.index');
        }
        if ($code === 'SUPER_ADMIN') {
            $expected[] = route('admin.security-audit.index');
        }
        foreach (['desktop', 'mobile'] as $surface) {
            $actual = [];
            foreach ($xpath->query('//*[@data-navigation="'.$surface.'"]//a[@href]') as $anchor) {
                $actual[] = $anchor->getAttribute('href');
                $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $anchor->textContent);
            }
            $this->assertEqualsCanonicalizing($expected, $actual, $surface.' '.$code);
        }
        $response->assertSee('aria-controls="mobile-navigation"', false);
    }
}
