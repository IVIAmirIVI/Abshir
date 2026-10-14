<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainReceivablePayout;
use App\Models\FinancialTransaction;
use App\Services\CaptainBenefitRequestService;
use App\Services\CaptainBenefitReviewService;
use App\Services\CaptainDepositReceiptService;
use App\Services\FinancialBalanceQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CaptainBenefitFixtures;
use Tests\TestCase;

class CaptainFinancialUxTest extends TestCase
{
    use CaptainBenefitFixtures, RefreshDatabase;

    public function test_source_cards_are_in_the_payout_form_and_full_http_payout_is_exact_and_idempotent(): void
    {
        $c = $this->approvedBonus('200');
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $source = FinancialTransaction::sole()->entries()->sole();
        app(CaptainDepositReceiptService::class)->record($c['reviewer'], $c['captain'], $c['branch'], $c['currency'], [
            'request_key' => (string) Str::uuid(), 'amount' => '100', 'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP'),
        ], $this->benefitImage());
        $url = route('captains.financials.show', [$c['captain'], 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id]);
        $response = $this->actingAs($c['reviewer'])->get($url)->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//form[@data-captain-payout]//input[@name="allocations['.$source->id.'][amount]"]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-captain-payout]//button[@data-remaining="200.0000"]')->length);
        $this->assertSame(0, $xpath->query('//form[@data-captain-payout]//table')->length);
        $this->assertSame(1, $xpath->query('//details[@name="captain-financial-tasks" and @open]')->length);
        $this->assertSame('captain-payout', $xpath->query('//details[@open]')->item(0)->getAttribute('id'));
        $this->get(route('captains.requests.show', [$c['captain'], $c['proposal']]))->assertSee($url.'#captain-payout');
        $data = $this->payout($c, [$source->id => '200'], '200');
        $endpoint = route('captains.financials.payouts.store', $c['captain']);

        $this->post($endpoint, $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($endpoint, $data)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseCount('captain_receivable_payouts', 1);
        $this->assertDatabaseHas('captain_receivable_payout_allocations', ['financial_entry_id' => $source->id, 'amount' => '200.0000']);
        $this->assertSame('0.0000', $this->balance($c, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('100.0000', $this->balance($c, FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $this->balance($c, FinancialAccountKind::CaptainCashCustody));
    }

    public function test_multiple_sources_support_partial_then_full_transfer_payout_without_operating_changes(): void
    {
        $c = $this->approvedBonus('200');
        $first = FinancialTransaction::sole()->entries()->sole();
        $c['proposal'] = app(CaptainBenefitRequestService::class)->save($c['creator'], $c['captain'], [
            'request_key' => (string) Str::uuid(), 'kind' => 'BONUS', 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'amount' => '100', 'description' => 'Second approved bonus',
        ]);
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');
        $second = FinancialTransaction::where('event_type', 'CAPTAIN_BONUS_DUE')->latest('id')->firstOrFail()->entries()->sole();
        $endpoint = route('captains.financials.payouts.store', $c['captain']);
        $this->actingAs($c['reviewer'])->post($endpoint, $this->payout($c, [$first->id => '50', $second->id => '100'], '150'))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('captain_receivable_payout_allocations', 2);
        $remaining = app(FinancialBalanceQueryService::class)->payableReceivableEntries($c['captain'], $c['branch'], $c['currency']);
        $this->assertCount(1, $remaining);
        $this->assertSame('150.0000', $remaining->sole()->remaining_amount);
        $this->assertSame($first->id, (int) $remaining->sole()->financial_entry_id);
        $data = $this->payout($c, [$first->id => '150'], '150');
        $data['payment_method'] = 'TRANSFER';
        $data['reference'] = 'TRANSFER-PROOF';
        $this->post($endpoint, $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('TRANSFER', CaptainReceivablePayout::latest('id')->firstOrFail()->payment_method);
        $this->assertSame('0.0000', $this->balance($c, FinancialAccountKind::CaptainCompanyReceivable));
        $this->assertSame('0.0000', $this->balance($c, FinancialAccountKind::CaptainDeposit));
        $this->assertSame('0.0000', $this->balance($c, FinancialAccountKind::CaptainCashCustody));
    }

    public function test_empty_invalid_and_excessive_source_inputs_are_rejected_without_a_payout(): void
    {
        $c = $this->approvedBonus('200');
        $source = FinancialTransaction::sole()->entries()->sole();
        $endpoint = route('captains.financials.payouts.store', $c['captain']);
        $this->actingAs($c['reviewer']);
        foreach (['', '-1', 'NaN', '200.001', '201'] as $invalid) {
            $data = $this->payout($c, [$source->id => $invalid], '200');
            $this->post($endpoint, $data)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertDatabaseCount('captain_receivable_payout_allocations', 0);
        $this->assertSame('200.0000', $this->balance($c, FinancialAccountKind::CaptainCompanyReceivable));
    }

    public function test_unauthorized_and_cross_branch_payout_requests_cannot_post(): void
    {
        $c = $this->approvedBonus('200');
        $source = FinancialTransaction::sole()->entries()->sole();
        $endpoint = route('captains.financials.payouts.store', $c['captain']);
        $this->actingAs($c['captain']->user)->post($endpoint, $this->payout($c, [$source->id => '200'], '200'))->assertForbidden();
        $this->get(route('captains.financials.show', $c['captain']))->assertForbidden();
        $data = $this->payout($c, [$source->id => '200'], '200');
        $data['branch_id'] = Branch::factory()->create()->id;
        $this->actingAs($c['employee'])->post($endpoint, $data)->assertForbidden();
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertSame('200.0000', $this->balance($c, FinancialAccountKind::CaptainCompanyReceivable));
    }

    public function test_empty_payables_show_history_link_without_a_payment_form_for_each_captain(): void
    {
        $c = $this->benefitContext();
        foreach ([$c['reviewer'], $c['employee']] as $actor) {
            $this->actingAs($actor)->get(route('captains.financials.show', [$c['captain'], 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id]))
                ->assertOk()->assertSee(__('financial.ui.no_payable_entitlements'))
                ->assertSee('href="#captain-payout-history"', false)->assertDontSee('data-captain-payout', false);
        }
        $other = Captain::factory()->create(['home_branch_id' => $c['branch']->id]);
        $this->actingAs($c['reviewer'])->get(route('captains.financials.show', [$other, 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id]))
            ->assertOk()->assertSee($other->user->name)->assertSee(__('financial.ui.no_payable_entitlements'))
            ->assertDontSee('data-captain-payout', false)->assertDontSee($c['captain']->user->name);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_unpaid_approved_request_has_contextual_cancellation_but_partial_and_full_payouts_do_not(): void
    {
        $c = $this->approvedBonus('200');
        $entry = FinancialTransaction::sole()->entries()->sole();
        $url = route('captains.requests.show', [$c['captain'], $c['proposal']]);
        $this->actingAs($c['reviewer'])->get($url)->assertOk()
            ->assertSee(__('captain_requests.actions.REVERSE'))->assertSee(__('captain_requests.reverse_help'))
            ->assertSee('name="reason" required', false);
        $this->actingAs($c['employee'])->get($url)->assertOk()->assertDontSee('value="REVERSE"', false);
        $this->actingAs($c['reviewer'])->post(route('captains.requests.review', [$c['captain'], $c['proposal']]), [
            'request_key' => (string) Str::uuid(), 'action' => 'REVERSE',
        ])->assertSessionHasErrors('reason');
        $endpoint = route('captains.financials.payouts.store', $c['captain']);
        $this->post($endpoint, $this->payout($c, [$entry->id => '50'], '50'))->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee(__('captain_requests.paid_reversal_blocked'))->assertDontSee('value="REVERSE"', false);
        $this->post($endpoint, $this->payout($c, [$entry->id => '150'], '150'))->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee(__('captain_requests.states.FULLY_PAID'))->assertDontSee('value="REVERSE"', false);
        $this->assertSame('FULLY_PAID', app(CaptainBenefitReviewService::class)->summary($c['proposal'])['state']);
    }

    public function test_expense_and_bonus_payout_history_use_actual_source_types_and_keep_ids_in_details(): void
    {
        $c = $this->benefitContext('EXPENSE', evidence: true);
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');
        $entry = FinancialTransaction::sole()->entries()->sole();
        $url = route('captains.financials.show', [$c['captain'], 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id]);
        $response = $this->actingAs($c['reviewer'])->get($url)->assertOk()->assertSee(__('financial.ui.approved_expense'));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//article[@data-payout-source]//details')->length);
        $this->assertStringNotContainsString('#'.$entry->id, $xpath->query('//article[@data-payout-source]/h4')->item(0)->textContent);
        $this->post(route('captains.financials.payouts.store', $c['captain']), $this->payout($c, [$entry->id => '20'], '20'))->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertSee(__('financial.ui.expense_payout'))->assertDontSee(__('financial.ui.bonus_payout'))
            ->assertSee(__('financial.ui.no_payable_entitlements'))->assertDontSee('data-captain-payout', false);
    }

    private function approvedBonus(string $amount): array
    {
        $c = $this->benefitContext();
        $c['proposal'] = app(CaptainBenefitRequestService::class)->save($c['creator'], $c['captain'], [
            'request_key' => (string) Str::uuid(), 'kind' => 'BONUS', 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'amount' => $amount, 'description' => 'Approved UAT bonus',
        ]);
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');

        return $c;
    }

    private function payout(array $c, array $allocations, string $total): array
    {
        return [
            'request_key' => (string) Str::uuid(), 'branch_id' => $c['branch']->id, 'currency_id' => $c['currency']->id,
            'amount' => $total, 'occurred_at' => now()->subMinute()->format('Y-m-d H:i:s.uP'),
            'payment_method' => 'CASH', 'received_by_user_id' => $c['captain']->user_id, 'evidence' => $this->benefitImage(),
            'allocations' => collect($allocations)->map(fn ($amount, $id) => ['financial_entry_id' => $id, 'amount' => $amount])->all(),
        ];
    }

    private function balance(array $c, FinancialAccountKind $kind): string
    {
        return app(FinancialBalanceQueryService::class)->captainBranchBalance($c['captain'], $c['branch'], $c['currency'], $kind);
    }
}
