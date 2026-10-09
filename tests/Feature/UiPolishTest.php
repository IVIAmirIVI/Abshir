<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\UiDate;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_route_does_not_expand_any_desktop_or_mobile_group(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        foreach (['dashboard', 'product-prices.index'] as $route) {
            $response = $this->actingAs($user)->get(route($route))->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($dom);
            $groups = $xpath->query('//details[contains(@class,"app-nav-group")]');
            $this->assertGreaterThan(0, $groups->length);
            foreach ($groups as $group) {
                $this->assertFalse($group->hasAttribute('open'));
                $this->assertSame('{ expanded: false }', $group->getAttribute('x-data'));
                $this->assertTrue($group->hasAttribute('wire:ignore.self'));
                $summary = $group->getElementsByTagName('summary')->item(0);
                $this->assertSame('false', $summary->getAttribute('aria-expanded'));
                // Native details/summary supplies Enter/Space and click toggling; Alpine observes, not overrides it.
                $this->assertStringContainsString('@toggle="expanded = $el.open"', $response->getContent());
                $this->assertStringContainsString(':aria-expanded="expanded.toString()"', $response->getContent());
            }
            foreach (['desktop', 'mobile'] as $surface) {
                $this->assertSame(1, $xpath->query('//*[@data-navigation="'.$surface.'"]//a[@aria-current="page"]')->length);
            }
        }
        $this->assertStringContainsString('mobileNavigation.showModal()', $response->getContent());
        $this->assertStringContainsString('mobileNavigation.close()', $response->getContent());
    }

    public function test_shared_button_variants_preserve_submit_click_disabled_and_loading_attributes(): void
    {
        $html = Blade::render('<x-primary-button wire:click="save" wire:loading.attr="disabled">حفظ</x-primary-button><x-secondary-button disabled>عودة</x-secondary-button><x-danger-button name="decision" value="reject">رفض</x-danger-button>');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $buttons = $dom->getElementsByTagName('button');
        $this->assertSame(3, $buttons->length);
        foreach ($buttons as $button) {
            $this->assertStringContainsString('app-button', $button->getAttribute('class'));
            $this->assertNotSame('', trim($button->textContent));
        }
        $this->assertSame('submit', $buttons->item(0)->getAttribute('type'));
        $this->assertSame('save', $buttons->item(0)->getAttribute('wire:click'));
        $this->assertSame('disabled', $buttons->item(0)->getAttribute('wire:loading.attr'));
        $this->assertSame('button', $buttons->item(1)->getAttribute('type'));
        $this->assertTrue($buttons->item(1)->hasAttribute('disabled'));
        $this->assertStringContainsString('bg-red-600', $buttons->item(2)->getAttribute('class'));
        $this->assertSame('reject', $buttons->item(2)->getAttribute('value'));
        $mary = Blade::render('<div class="mary-surface"><x-orders.action type="button" variant="danger" wire:click="remove" disabled>حذف</x-orders.action></div>');
        $this->assertStringContainsString('btn-error btn-outline', $mary);
        $this->assertStringContainsString('wire:click="remove"', $mary);
        $this->assertStringContainsString('type="button"', $mary);
    }

    public function test_arabic_months_weekdays_and_western_gregorian_digits(): void
    {
        app()->setLocale('ar');
        $this->assertSame('9 تشرين الأول 2026', UiDate::longDate('2026-10-09'));
        $this->assertSame('الجمعة', UiDate::weekday('2026-10-09'));
        foreach (range(1, 12) as $month) {
            $date = sprintf('2026-%02d-09', $month);
            $this->assertSame('9 '.__('dates.months.'.$month).' 2026', UiDate::longDate($date));
            $this->assertDoesNotMatchRegularExpression('/[٠-٩۰-۹]/u', UiDate::longDate($date));
        }
        $this->assertEqualsCanonicalizing(array_keys(Arr::dot(require lang_path('ar/dates.php'))), array_keys(Arr::dot(require lang_path('en/dates.php'))));
        app()->setLocale('en');
        $this->assertSame('9 October 2026', UiDate::longDate('2026-10-09'));
    }

    public function test_datetime_display_converts_damascus_without_mutating_the_source(): void
    {
        $value = CarbonImmutable::parse('2026-12-31T22:05:06Z');
        $this->assertSame('01/01/2027 01:05', UiDate::datetime($value));
        $this->assertSame('01/01/2027 01:05:06', UiDate::datetime($value, true));
        $this->assertSame('01/01/2027', UiDate::datetimeDate($value));
        $this->assertSame('2026-12-31T22:05:06+00:00', $value->toIso8601String());
        $mutable = Carbon::parse('2026-12-31T22:05:06Z');
        $this->assertSame('01/01/2027 01:05', UiDate::datetime($mutable));
        $this->assertSame('2026-12-31T22:05:06+00:00', $mutable->toIso8601String());
        $this->assertSame('01/03/2026 00:30', UiDate::datetime('2026-02-28T21:30:00Z'));
    }

    public function test_date_only_values_never_shift_and_numeric_rendering_is_isolated(): void
    {
        $value = CarbonImmutable::parse('2026-01-01T00:00:00+14:00');
        $this->assertSame('01/01/2026', UiDate::date($value));
        $this->assertSame('29/02/2028', UiDate::date('2028-02-29'));
        $this->assertSame('31/12/2026', UiDate::date('2026-12-31'));
        $this->assertSame('—', UiDate::date(null));
        $html = Blade::render('<x-ui-date value="2026-10-09" date-only />');
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('09/10/2026', $html);
        $long = Blade::render('<x-ui-date value="2026-10-09" long />');
        $this->assertStringContainsString('dir="rtl"', $long);
        $this->assertStringContainsString('9 تشرين الأول 2026', $long);
    }
}
