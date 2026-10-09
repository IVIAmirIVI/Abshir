<?php

namespace Tests\Feature;

use App\Livewire\Orders\CaptainAssignments;
use App\Livewire\Orders\CreateOrder;
use App\Models\DeliveryFeeOverrideRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SharedOrderWizardTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_two_captain_wizard_preserves_draft_prices_explicit_collector_and_replay(): void
    {
        $c = $this->wizardContext();
        $component = $this->stepThree($c)->set('wizardCaptainIds', array_map('strval', array_column($c['captains'], 'id')))
            ->set('wizardCollectorId', (string) $c['captains'][1]->id)->call('nextStep')->assertHasNoErrors()->assertSet('step', 4);
        $groups = $component->get('groups');
        $ids = $component->get('wizardCaptainIds');
        foreach ([2, 1, 3, 4] as $step) {
            $component->call('goToStep', $step)->assertHasNoErrors()->assertSet('groups', $groups)
                ->assertSet('wizardCaptainIds', $ids)->assertSet('wizardCollectorId', (string) $c['captains'][1]->id);
        }
        $retry = clone $component;
        $component->call('submit')->assertHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $this->assertNotSame($c['order']->id, $order->id);
        $this->assertSame(2, $order->captainAssignments()->whereNull('removed_at')->count());
        $selection = $order->captainSelections()->sole();
        $this->assertSame($c['captains'][1]->id, $order->captainAssignments()->findOrFail($selection->collecting_order_captain_id)->captain_id);
        $this->assertSame('SHARED_COMPANY_V1', $selection->contract);
        $this->assertSame(2, $order->currentDeliveryPricing->captain_count_snapshot);
        $this->assertSame('20.0000', $order->currentDeliveryPricing->customer_delivery_total);
        $this->assertSame(1, $order->shops()->count());
        $this->assertSame(1, $order->shops()->sole()->items()->count());
        $before = Order::count();
        $retry->call('submit')->assertHasNoErrors()->assertRedirect(route('orders.workflow', $order));
        $this->assertSame($before, Order::count());
        $this->assertSame(1, $order->captainSelections()->count());
        $this->assertSame(0, DB::table('financial_transactions')->count());
        $this->assertThrows(fn () => app(InvoiceService::class)->createDraft($c['actor'], $order), ValidationException::class);
    }

    public function test_unchanged_branch_preserves_selection_genuine_change_invalidates_it(): void
    {
        $c = $this->wizardContext();
        $ids = array_map('strval', array_column($c['captains'], 'id'));
        $component = $this->stepThree($c)->set('wizardCaptainIds', $ids)->set('wizardCollectorId', $ids[0]);
        $component->set('branchId', (string) $c['branch']->id)->assertSet('wizardCaptainIds', $ids)->assertSet('wizardCollectorId', $ids[0]);
        $other = $this->sharedContext();
        $c['actor']->branches()->attach($other['branch']);
        $component->set('branchId', (string) $other['branch']->id)->assertSet('wizardCaptainIds', [])->assertSet('wizardCollectorId', '');
    }

    public function test_missing_or_forged_collector_stays_on_step_three_without_clearing_groups(): void
    {
        $c = $this->wizardContext();
        $component = $this->stepThree($c)->set('wizardCaptainIds', array_map('strval', array_column($c['captains'], 'id')));
        $groups = $component->get('groups');
        $component->call('nextStep')->assertHasErrors('wizardCollectorId')->assertSet('step', 3)->assertSet('groups', $groups);
        $component->set('wizardCollectorId', '999999')->call('nextStep')->assertHasErrors('wizardCollectorId')->assertSet('step', 3)->assertSet('groups', $groups);
        $this->assertSame(1, Order::count());
    }

    public function test_changed_participants_after_review_roll_back_order_and_selection(): void
    {
        $c = $this->wizardContext();
        $ids = array_map('strval', array_column($c['captains'], 'id'));
        $component = $this->stepThree($c)->set('wizardCaptainIds', $ids)->set('wizardCollectorId', $ids[0])->call('nextStep')->assertHasNoErrors();
        $component->set('wizardCaptainIds', [$ids[0]])->call('submit')->assertHasErrors('delivery')->assertSet('step', 3);
        $this->assertSame(1, Order::count());
        $this->assertSame(0, DB::table('order_captain_selections')->count());
    }

    public function test_workflow_collector_change_keeps_both_captains_and_updates_allocation_references(): void
    {
        $c = $this->wizardContext();
        Livewire::actingAs($c['actor'])->test(CaptainAssignments::class, ['order' => $c['order']])
            ->set('collectorId', (string) $c['captains'][1]->id)->set('selectionReason', 'Supervisor chose collector')
            ->call('saveSelection')->assertHasNoErrors()->assertRedirect(route('orders.workflow', $c['order']));
        $selection = $c['order']->captainSelections()->sole();
        $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
        $this->assertSame($selection->assignment_ids, $c['order']->currentDeliveryPricing()->firstOrFail()->allocations()->orderBy('order_captain_id')->pluck('order_captain_id')->all());
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_shared_wizard_override_reuses_explicit_collector_and_retains_real_audit(): void
    {
        $c = $this->wizardContext();
        $c['actor'] = $c['admin'];
        $ids = array_map('strval', array_column($c['captains'], 'id'));
        $component = $this->stepThree($c)->set('wizardCaptainIds', $ids)->set('wizardCollectorId', $ids[0])
            ->call('beginDeliveryOverride')->assertSet('deliveryOverrideCaptainId', $ids[0])
            ->set('wizardCollectorId', $ids[1])->assertSet('deliveryOverrideCaptainId', $ids[1])
            ->set('deliveryOverrideAmount', '12')->set('deliveryOverrideReason', 'Approved exception for both delivery units')
            ->call('nextStep')->assertHasNoErrors()->assertSet('step', 4);
        $component->call('goToStep', 3)->call('nextStep')->assertHasNoErrors()->call('submit')->assertHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $selection = $order->captainSelections()->sole();
        $this->assertSame($c['captains'][1]->id, $order->captainAssignments()->findOrFail($selection->collecting_order_captain_id)->captain_id);
        $this->assertSame('24.0000', $order->currentDeliveryPricing->customer_delivery_total);
        $this->assertSame('12.0000', $order->currentDeliveryPricing->applied_unit_fee);
        $audit = DeliveryFeeOverrideRequest::where('order_id', $order->id)->sole();
        $this->assertSame('Approved exception for both delivery units', $audit->reason);
        $this->assertSame($c['admin']->id, $audit->requested_by);
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_pending_shared_override_keeps_automatic_combined_fee_and_does_not_pretend_approval(): void
    {
        $c = $this->wizardContext();
        $ids = array_map('strval', array_column($c['captains'], 'id'));
        $component = $this->stepThree($c)->set('wizardCaptainIds', $ids)->set('wizardCollectorId', $ids[1])
            ->call('beginDeliveryOverride')->set('deliveryOverrideAmount', '12')->set('deliveryOverrideReason', 'Requires existing approval')
            ->call('nextStep')->assertHasNoErrors();
        $this->assertFalse($component->get('reviewedDelivery')['immediate']);
        $component->call('submit')->assertHasNoErrors();
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('20.0000', $order->currentDeliveryPricing->customer_delivery_total);
        $this->assertSame('PENDING', DeliveryFeeOverrideRequest::where('order_id', $order->id)->sole()->status->value);
        $this->assertSame(2, $order->captainAssignments()->whereNull('removed_at')->count());
    }

    private function wizardContext(): array
    {
        $c = $this->sharedContext();
        $c['unit'] = Unit::create(['code' => 'MC2', 'name' => 'Piece', 'allows_decimal' => false]);
        $c['product'] = Product::create(['name' => 'MC2 product', 'default_unit_id' => $c['unit']->id]);
        $c['registeredShop'] = Shop::create(['name' => 'MC2 shop', 'business_classification' => 'SHOP', 'area_id' => $c['shop']->origin_area_id]);

        return $c;
    }

    private function stepThree(array $c)
    {
        return Livewire::actingAs($c['actor'])->test(CreateOrder::class)
            ->set('branchId', (string) $c['branch']->id)->set('customerId', (string) $c['order']->customer_id)
            ->set('source', 'MANUAL')->call('nextStep')->assertHasNoErrors()
            ->call('selectShop', $c['registeredShop']->id)->call('addDraftShop')->assertHasNoErrors()
            ->call('selectProduct', $c['product']->id)->call('addDraftItem')->assertHasNoErrors()
            ->call('nextStep')->assertHasNoErrors()->assertSet('step', 3);
    }
}
