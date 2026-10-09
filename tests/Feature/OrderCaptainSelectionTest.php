<?php

namespace Tests\Feature;

use App\Enums\OrderCaptainRole;
use App\Models\OrderCaptainSelection;
use App\Models\User;
use App\Services\OrderCaptainSelectionService;
use App\Services\OrderCaptainService;
use App\Services\SharedOrderShopPurchaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class OrderCaptainSelectionTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_explicit_pair_and_collector_are_audited_without_rewriting_matching_assignments(): void
    {
        $c = $this->sharedContext();
        $before = $c['order']->captainAssignments()->get()->map->getAttributes()->all();
        $key = (string) str()->uuid();
        $source = $this->select($c, $key);
        $this->assertSame('SHARED_COMPANY_V1', $source->contract);
        $this->assertSame($c['assignments'][0]->id, $source->collecting_order_captain_id);
        $this->assertSame($c['actor']->id, $source->selected_by);
        $this->assertSame($c['branch']->id, $source->branch_id);
        $this->assertSame($before, $c['order']->captainAssignments()->get()->map->getAttributes()->all());
        $this->assertSame($source->id, $this->select($c, $key)->id);
        $this->assertSame(1, OrderCaptainSelection::count());
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_switching_collector_preserves_two_participants_and_previous_history(): void
    {
        $c = $this->sharedContext();
        $old = $this->select($c)->fresh();
        $new = app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
            array_column($c['captains'], 'id'), $c['captains'][1]->id, (string) str()->uuid(), 'Explicit collector change');
        $active = $c['order']->captainAssignments()->whereNull('removed_at')->get();
        $this->assertCount(2, $active);
        $this->assertSame($c['captains'][1]->id, $active->firstWhere('assignment_role', OrderCaptainRole::PrimaryCollection)->captain_id);
        $this->assertSame($c['captains'][0]->id, $active->firstWhere('assignment_role', OrderCaptainRole::Support)->captain_id);
        $this->assertSame(4, $c['order']->captainAssignments()->count());
        $this->assertSame($old->getAttributes(), $old->fresh()->getAttributes());
        $this->assertSame(2, OrderCaptainSelection::count());
        $this->assertNotSame($old->collecting_order_captain_id, $new->collecting_order_captain_id);
        $this->assertSame(2, $c['order']->currentDeliveryPricing->captain_count_snapshot);
        $this->assertSame($new->assignment_ids, $c['order']->currentDeliveryPricing()->first()->allocations()->orderBy('order_captain_id')->pluck('order_captain_id')->all());
    }

    public function test_one_captain_selection_preserves_the_single_delivery_unit(): void
    {
        $c = $this->sharedContext(2, 1);
        $source = $this->select($c);
        $this->assertSame('SINGLE_V1', $source->contract);
        $this->assertSame(1, $c['order']->currentDeliveryPricing->captain_count_snapshot);
    }

    public function test_duplicates_more_than_two_and_nonparticipant_collector_are_rejected_without_changes(): void
    {
        $c = $this->sharedContext();
        $before = $c['order']->captainAssignments()->get()->map->getAttributes()->all();
        foreach ([[[$c['captains'][0]->id, $c['captains'][0]->id], $c['captains'][0]->id],
            [[$c['captains'][0]->id, $c['captains'][1]->id, 999999], $c['captains'][0]->id],
            [[$c['captains'][0]->id, $c['captains'][1]->id], 999999]] as [$ids, $collector]) {
            $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
                $ids, $collector, (string) str()->uuid()), ValidationException::class);
        }
        $this->assertSame($before, $c['order']->captainAssignments()->get()->map->getAttributes()->all());
        $this->assertSame(0, OrderCaptainSelection::count());
    }

    public function test_cross_branch_and_unauthorized_actors_cannot_select_captains(): void
    {
        $c = $this->sharedContext();
        $other = $this->sharedContext();
        $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select($c['actor'], $other['order'],
            array_column($other['captains'], 'id'), $other['captains'][0]->id, (string) str()->uuid()), HttpException::class);
        $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select(User::factory()->create(), $c['order'],
            array_column($c['captains'], 'id'), $c['captains'][0]->id, (string) str()->uuid()), AuthorizationException::class);
        $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
            [$c['captains'][0]->id, $other['captains'][1]->id], $c['captains'][0]->id, (string) str()->uuid(), 'Changed'), ValidationException::class);
        $this->assertSame(0, OrderCaptainSelection::count());
    }

    public function test_request_key_cannot_be_reused_with_different_intent(): void
    {
        $c = $this->sharedContext();
        $key = (string) str()->uuid();
        $this->select($c, $key);
        $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
            array_column($c['captains'], 'id'), $c['captains'][1]->id, $key), ValidationException::class);
        $this->assertSame(1, OrderCaptainSelection::count());
    }

    public function test_reselection_requires_reason_and_purchase_freezes_collector(): void
    {
        Storage::fake('order_returns');
        $c = $this->sharedContext();
        $this->select($c);
        $this->assertThrows(fn () => $this->select($c), ValidationException::class);
        app(SharedOrderShopPurchaseService::class)->record($c['actor'], $c['order'], $c['shop'],
            $this->sharedData($c, '80'), $this->sharedImage());
        $this->assertThrows(fn () => app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
            array_column($c['captains'], 'id'), $c['captains'][1]->id, (string) str()->uuid(), 'Changed'), ValidationException::class);
        $this->assertSame(1, OrderCaptainSelection::count());
    }

    public function test_database_enforces_audit_immutability_and_active_selection_consistency(): void
    {
        $c = $this->sharedContext();
        $source = $this->select($c);
        foreach ([fn () => DB::table('order_captain_selections')->where('id', $source->id)->update(['selected_by' => $c['admin']->id]),
            fn () => DB::table('order_captain_selections')->where('id', $source->id)->delete(),
            fn () => DB::table('order_captains')->where('id', $source->collecting_order_captain_id)->update(['removed_at' => now()])] as $attempt) {
            $this->assertThrows(fn () => DB::transaction(function () use ($attempt) {
                $attempt();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }), QueryException::class);
        }
        $this->assertSame($source->getAttributes(), $source->fresh()->getAttributes());
        $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
    }

    public function test_selected_participants_cannot_be_reparented_to_another_branch_without_a_new_valid_selection(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        $other = $this->sharedContext();
        $this->assertThrows(fn () => DB::transaction(function () use ($c, $other) {
            DB::table('orders')->where('id', $c['order']->id)->update(['branch_id' => $other['branch']->id]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }), QueryException::class);
        $this->assertSame($c['branch']->id, $c['order']->fresh()->branch_id);
    }

    public function test_direct_sql_cannot_forge_contract_collector_or_participant_order(): void
    {
        $c = $this->sharedContext();
        $ids = array_column($c['assignments'], 'id');
        $base = ['order_id' => $c['order']->id, 'branch_id' => $c['branch']->id,
            'collecting_order_captain_id' => $ids[0], 'selected_by' => $c['actor']->id,
            'assignment_ids' => json_encode($ids), 'contract' => 'SHARED_COMPANY_V1', 'selected_at' => now()];
        foreach ([['contract' => 'SINGLE_V1'], ['contract' => 'UNKNOWN'],
            ['assignment_ids' => json_encode(array_reverse($ids))], ['collecting_order_captain_id' => $ids[1]],
            ['assignment_ids' => json_encode([$ids[0]])]] as $bad) {
            $this->assertThrows(fn () => DB::transaction(function () use ($base, $bad) {
                DB::table('order_captain_selections')->insert(array_replace($base, $bad, ['request_key' => (string) str()->uuid()]));
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }), QueryException::class);
        }
        $this->assertSame(0, OrderCaptainSelection::count());
        $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
    }

    public function test_legacy_assignment_actions_cannot_bypass_an_explicit_selection(): void
    {
        $c = $this->sharedContext();
        $this->select($c);
        $service = app(OrderCaptainService::class);
        foreach ([fn () => $service->assign($c['actor'], $c['order'], $c['captains'][1], OrderCaptainRole::Support),
            fn () => $service->remove($c['actor'], $c['order'], $c['assignments'][1], 'Remove support'),
            fn () => $service->switchPrimary($c['actor'], $c['order'], $c['captains'][1], 'Switch primary')] as $action) {
            $this->assertThrows($action, ValidationException::class);
        }
        $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
        $this->assertSame(1, OrderCaptainSelection::count());
    }

    public function test_audited_assignment_identity_and_closed_participation_cannot_be_rewritten(): void
    {
        $c = $this->sharedContext();
        $source = $this->select($c);
        $foreign = $this->sharedContext();
        foreach ([['captain_id' => $foreign['captains'][0]->id], ['assigned_by' => $c['admin']->id],
            ['assigned_at' => now()->subDays(2)], ['assignment_role' => 'SUPPORT']] as $changes) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_captains')
                ->where('id', $source->collecting_order_captain_id)->update($changes)), QueryException::class);
        }
        app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'], array_column($c['captains'], 'id'),
            $c['captains'][1]->id, (string) str()->uuid(), 'New collector');
        $old = $c['order']->captainAssignments()->findOrFail($source->collecting_order_captain_id);
        $this->assertNotNull($old->removed_at);
        $before = $old->getAttributes();
        foreach ([['removed_at' => null], ['removal_reason' => 'Rewritten history'], ['removed_by' => $c['admin']->id]] as $changes) {
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('order_captains')->where('id', $old->id)->update($changes)), QueryException::class);
        }
        $this->assertSame($before, $old->fresh()->getAttributes());
        $this->assertSame(2, $c['order']->captainAssignments()->whereNull('removed_at')->count());
    }

    private function select(array $c, ?string $key = null): OrderCaptainSelection
    {
        return app(OrderCaptainSelectionService::class)->select($c['actor'], $c['order'],
            array_column($c['captains'], 'id'), $c['captains'][0]->id, $key ?? (string) str()->uuid());
    }
}
