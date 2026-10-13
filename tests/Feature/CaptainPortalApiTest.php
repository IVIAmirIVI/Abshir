<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class CaptainPortalApiTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->travelTo(CarbonImmutable::parse('2026-10-13T10:00:00Z'));
    }

    private function user(array $c): User
    {
        $user = $c['captains'][0]->user;
        $user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());

        return $user;
    }

    public function test_session_api_is_off_by_default_and_does_not_create_token_or_write_routes(): void
    {
        $c = $this->sharedContext(2, 1);
        $this->actingAs($this->user($c))->getJson(route('api.v1.me'))->assertNotFound();
        $this->getJson(route('api.v1.captain.orders.index'))->assertNotFound();
        $this->getJson(route('api.v1.captain.orders.show', $c['order']->id))->assertNotFound();
        $this->postJson(route('api.v1.captain.orders.index'), ['status' => 'DELIVERED'])->assertStatus(405);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_enabled_api_reauthorizes_session_role_self_identity_and_foreign_order_on_every_request(): void
    {
        config(['captain_portal.session_api_enabled' => true]);
        $this->getJson(route('api.v1.me'))->assertUnauthorized();
        $c = $this->sharedContext(2, 1);
        $foreign = $this->sharedContext(2, 1);
        $this->actingAs($c['actor'])->getJson(route('api.v1.me'))->assertForbidden();
        $user = $this->user($c);
        $this->actingAs($user)->getJson(route('api.v1.me', ['captain_id' => $foreign['captains'][0]->id]))
            ->assertOk()->assertJsonPath('data.captain_id', $c['captains'][0]->id)->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.password');
        $this->getJson(route('api.v1.captain.orders.show', $foreign['order']->id))->assertNotFound();
        $this->getJson(route('api.v1.captain.orders.show', 999999))->assertNotFound();
        $this->getJson(route('api.v1.captain.orders.index', ['branch_id' => $foreign['branch']->id]))->assertForbidden();
        $user->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $this->getJson(route('api.v1.captain.orders.show', $foreign['order']->id))->assertNotFound();
        $user->roles()->detach();
        $this->getJson(route('api.v1.me'))->assertForbidden();
    }

    public function test_same_authorized_list_filters_are_used_without_contact_financial_or_private_payloads(): void
    {
        config(['captain_portal.session_api_enabled' => true]);
        $c = $this->sharedContext();
        $user = $this->user($c);
        $this->actingAs($user);
        $params = ['orders_kind' => 'shared', 'orders_role' => 'collector', 'branch_id' => $c['branch']->id, 'view' => 'active'];
        $html = $this->get(route('captain.portal', $params))->assertOk();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->getJson(route('api.v1.captain.orders.index', $params + ['captain_id' => 999, 'per_page' => 5000, 'secret' => 'must-not-return']))
            ->assertOk()->assertJsonCount($html->viewData('page')->total(), 'data')
            ->assertJsonPath('meta.per_page', 20)->assertJsonPath('data.0.id', $c['order']->id)
            ->assertJsonPath('data.0.shared_participation', true)->assertJsonPath('data.0.assignment_role', 'PRIMARY_COLLECTION')
            ->assertJsonMissingPath('data.0.customer_phone_snapshot')->assertJsonMissingPath('data.0.customer_id')
            ->assertJsonMissingPath('data.0.notes')->assertJsonMissingPath('data.0.invoice')->assertJsonMissingPath('links');
        $this->assertStringNotContainsString('must-not-return', $response->getContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->getJson(route('api.v1.captain.orders.show', $c['order']->id))->assertOk()->assertJsonPath('data.id', $c['order']->id)
            ->assertJsonMissingPath('data.contact')->assertJsonMissingPath('data.evidence')->assertJsonMissingPath('data.grand_total');
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|alter|truncate|create|drop)\b/i', $sql))));
        $this->getJson(route('api.v1.captain.orders.index', ['orders_status' => 'forged']))->assertUnprocessable()->assertJsonValidationErrors(['orders_status']);
    }

    public function test_session_api_has_a_real_rate_limit_without_authorization_expansion(): void
    {
        config(['captain_portal.session_api_enabled' => true]);
        $c = $this->sharedContext(2, 1);
        $this->actingAs($this->user($c));
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->getJson(route('api.v1.me'))->assertOk();
        }
        $this->getJson(route('api.v1.me'))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, Role::where('code', 'CAPTAIN')->sole()->permissions()->count());
    }
}
