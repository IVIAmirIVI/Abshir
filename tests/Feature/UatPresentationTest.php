<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UatPresentationTest extends TestCase
{
    public function test_compact_decimal_output_keeps_exact_input_values_and_western_digits(): void
    {
        $amount = '50.0000';
        $quantity = '1.2500';

        $html = Blade::render('<x-money :amount="$amount" currency="SYP" /><x-decimal :value="$quantity" /><x-text-input value="50.0000" wire:model="amount" inputmode="decimal" dir="ltr" />', compact('amount', 'quantity'));

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $values = $dom->getElementsByTagName('bdi');
        $this->assertSame(['50 SYP', '1.25'], array_map(fn ($node): string => trim($node->textContent), iterator_to_array($values)));
        foreach ($values as $value) {
            $this->assertSame('ltr', $value->getAttribute('dir'));
        }
        $input = $dom->getElementsByTagName('input')->item(0);
        $this->assertSame('50.0000', $input->getAttribute('value'));
        $this->assertSame('amount', $input->getAttribute('wire:model'));
        $this->assertSame(['50.0000', '1.2500'], [$amount, $quantity]);
    }

    public function test_shared_action_keeps_disabled_navigation_and_loading_contract(): void
    {
        $html = Blade::render('<x-orders.action type="button" variant="secondary" wire:click="nextStep" wire:loading.attr="disabled" disabled><span wire:loading.remove wire:target="nextStep">التالي</span><span wire:loading wire:target="nextStep">جارٍ الحفظ</span></x-orders.action>');

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $button = $dom->getElementsByTagName('button')->item(0);
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertSame('nextStep', $button->getAttribute('wire:click'));
        $this->assertSame('disabled', $button->getAttribute('wire:loading.attr'));
        $this->assertTrue($button->hasAttribute('disabled'));
        $this->assertStringContainsString('btn-outline', $button->getAttribute('class'));
        $this->assertSame('nextStep', $button->getElementsByTagName('span')->item(0)->getAttribute('wire:target'));
    }
}
