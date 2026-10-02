<?php

namespace Tests\Feature;

use App\Enums\FinancialEventType;
use App\Support\UiLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Support\ManualDepositFixtures;
use Tests\TestCase;

class FinancialLocalizationTest extends TestCase
{
    use ManualDepositFixtures, RefreshDatabase;

    public function test_financial_catalogs_match_and_every_event_has_a_distinct_display_label(): void
    {
        $ar = Arr::dot(require lang_path('ar/financial.php'));
        $en = Arr::dot(require lang_path('en/financial.php'));
        $this->assertEqualsCanonicalizing(array_keys($ar), array_keys($en));
        foreach ($ar as $key => $label) {
            $this->assertNotSame('', $label, $key);
            $this->assertNotSame('', $en[$key], $key);
        }
        foreach (FinancialEventType::cases() as $event) {
            $this->assertArrayHasKey('events.'.$event->value, $ar);
            $this->assertSame($ar['events.'.$event->value], UiLabels::label('financial.events', $event));
            $this->assertNotSame($event->value, UiLabels::label('financial.events', $event));
        }
        $labels = array_map(fn ($event) => $ar['events.'.$event->value], FinancialEventType::cases());
        $this->assertCount(count($labels), array_unique($labels));
        foreach (['REPRICE', 'CANCEL_AND_REFUND'] as $kind) {
            $this->assertSame($ar['correction_kinds.'.$kind], UiLabels::label('financial.correction_kinds', $kind));
        }
        foreach (['REQUESTED', 'PREPARED', 'READY_TO_FINALIZE', 'COMPLETED', 'ABANDONED'] as $state) {
            $this->assertSame($ar['correction_states.'.$state], UiLabels::label('financial.correction_states', $state));
        }
    }

    public function test_captain_financial_pages_keep_exact_money_and_distinct_authorized_operations(): void
    {
        $f = $this->manualFixture();
        $f['currency']->update(['decimal_places' => 4]);
        $this->deposit($f, '12.3400');
        $this->deposit($f, '0.0100');
        $adjustment = $this->adjust($f, $this->manualData('27.3500', 'DECREASE'));
        $response = $this->actingAs($f['admin'])->get(route('captains.financials.show', $f['captain']))
            ->assertOk()->assertSee('رصيد تشغيل الكابتن لدى أبشر')->assertSee('مستحقات الكابتن لدى أبشر')
            ->assertSee('نقد أبشر بعهدة الكابتن')->assertSee('إيصال استلام مبلغ تشغيل من الكابتن')
            ->assertSee('صرف مستحقات الكابتن')->assertSee('تعديل إداري لرصيد التشغيل')
            ->assertSee('تخفيض')->assertSee('12.3400')->assertSee('0.0100')->assertSee('-15.0000')
            ->assertSee($f['branch']->name)->assertSee('USD')->assertSee($adjustment->reason)
            ->assertDontSee('financial.ui.')->assertSee('dir="rtl"', false);
        $this->assertStringContainsString('<bdi dir="ltr"', $response->getContent());
        $this->assertSame('-15.0000', $this->depositBalance($f));
        $this->assertSame('27.3500', $adjustment->fresh()->amount);
        $this->get(route('invoices.index'))->assertOk()->assertSee('لا توجد فواتير.');
        $this->get(route('invoice-collections.index'))->assertOk()->assertSee('لا توجد عمليات تحصيل.');
        $this->actingAs($f['employee'])->get(route('captains.financials.show', $f['captain']))
            ->assertOk()->assertDontSee(route('captains.manual-deposit-adjustments.store', $f['captain']));
        $this->get(route('captain-financials.index'))->assertOk()->assertSee('السجل المالي للكباتن');
    }
}
