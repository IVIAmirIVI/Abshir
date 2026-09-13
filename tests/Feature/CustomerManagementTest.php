<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerPhone;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'SUPER_ADMIN', array $branches = []): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $role)->sole());
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    public function test_order_employee_can_create_and_update_own_addressless_customer(): void
    {
        $employee = $this->account('ORDER_EMPLOYEE');
        $this->actingAs($employee)->post(route('customers.store'), ['name' => 'Maya Hassan', 'notes' => 'Call first'])->assertSessionHasNoErrors();
        $customer = Customer::where('name', 'Maya Hassan')->sole();
        $this->assertTrue($customer->creator->is($employee));
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Call first');
        $this->put(route('customers.update', $customer), ['name' => 'Maya Updated'])->assertSessionHasNoErrors();
        $this->assertSame('Maya Updated', $customer->fresh()->name);
    }

    public function test_captain_and_guest_cannot_access_customer_management(): void
    {
        $captain = $this->account('CAPTAIN');
        $this->actingAs($captain)->get(route('customers.index'))->assertForbidden();
        $this->post(route('customers.store'), ['name' => 'Blocked'])->assertForbidden();
        auth()->logout();
        $this->get(route('customers.index'))->assertRedirect('/login');
    }

    public function test_customer_visibility_uses_accessible_address_branches_and_creator_fallback(): void
    {
        [$assigned, $other] = Branch::factory()->count(2)->create();
        $employee = $this->account('ORDER_EMPLOYEE', [$assigned]);
        $visible = Customer::factory()->create();
        CustomerAddress::factory()->for($visible)->for(Area::factory()->for($assigned))->create();
        $hidden = Customer::factory()->create();
        CustomerAddress::factory()->for($hidden)->for(Area::factory()->for($other))->create();
        $own = Customer::factory()->create(['created_by_user_id' => $employee->id]);
        $otherAddressless = Customer::factory()->create();

        $response = $this->actingAs($employee)->get(route('customers.index'));
        $response->assertOk()->assertSee($visible->name)->assertSee($own->name)->assertDontSee($hidden->name)->assertDontSee($otherAddressless->name);
        $this->get(route('customers.show', $hidden))->assertForbidden();
    }

    public function test_customer_detail_hides_addresses_from_inaccessible_branches(): void
    {
        [$assigned, $other] = Branch::factory()->count(2)->create();
        $employee = $this->account('ORDER_EMPLOYEE', [$assigned]);
        $customer = Customer::factory()->create();
        CustomerAddress::factory()->for($customer)->for(Area::factory()->for($assigned))->create(['address_line_1' => 'Visible address']);
        CustomerAddress::factory()->for($customer)->for(Area::factory()->for($other))->create(['address_line_1' => 'Hidden address']);

        $this->actingAs($employee)->get(route('customers.show', $customer))->assertOk()->assertSee('Visible address')->assertDontSee('Hidden address');
    }

    public function test_phone_normalization_blocks_duplicates_per_customer_but_allows_other_customers(): void
    {
        $admin = $this->account();
        [$first, $second] = Customer::factory()->count(2)->create();
        $this->actingAs($admin)->post(route('customers.phones.store', $first), ['phone' => '+963 944 123 456', 'label' => 'Mobile'])->assertSessionHasNoErrors();
        $this->post(route('customers.phones.store', $first), ['phone' => '963-944-123-456'])->assertSessionHasErrors('normalized_phone');
        $this->post(route('customers.phones.store', $second), ['phone' => '963-944-123-456'])->assertSessionHasNoErrors();
        $this->assertSame('963944123456', $first->phones()->sole()->normalized_phone);
    }

    public function test_phone_primary_switching_and_delete_promotion_are_atomic(): void
    {
        $customer = Customer::factory()->create();
        $admin = $this->account();
        $this->actingAs($admin)->post(route('customers.phones.store', $customer), ['phone' => '111111'])->assertSessionHasNoErrors();
        $this->post(route('customers.phones.store', $customer), ['phone' => '222222'])->assertSessionHasNoErrors();
        [$first, $second] = $customer->phones()->orderBy('id')->get();
        $this->assertTrue($first->is_primary);
        $this->patch(route('customers.phones.primary', [$customer, $second]))->assertSessionHasNoErrors();
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->delete(route('customers.phones.destroy', [$customer, $second]))->assertSessionHasNoErrors();
        $this->assertTrue($first->fresh()->is_primary);
    }

    public function test_addresses_require_accessible_active_area_and_support_multiple_branches(): void
    {
        [$one, $two, $foreign] = Branch::factory()->count(3)->create();
        $employee = $this->account('ORDER_EMPLOYEE', [$one, $two]);
        $customer = Customer::factory()->create(['created_by_user_id' => $employee->id]);
        $oneArea = Area::factory()->for($one)->create();
        $twoArea = Area::factory()->for($two)->create();
        $foreignArea = Area::factory()->for($foreign)->create();

        $this->actingAs($employee)->post(route('customers.addresses.store', $customer), ['address_line_1' => 'Missing area'])->assertSessionHasErrors('area_id');
        $this->post(route('customers.addresses.store', $customer), ['area_id' => $oneArea->id, 'address_line_1' => 'One'])->assertSessionHasNoErrors();
        $this->post(route('customers.addresses.store', $customer), ['area_id' => $twoArea->id, 'address_line_1' => 'Two'])->assertSessionHasNoErrors();
        $this->post(route('customers.addresses.store', $customer), ['area_id' => $foreignArea->id, 'address_line_1' => 'Blocked'])->assertForbidden();
        $this->assertCount(2, $customer->addresses);
    }

    public function test_address_primary_switching_and_status_promotion_work(): void
    {
        $branch = Branch::factory()->create();
        $area = Area::factory()->for($branch)->create();
        $customer = Customer::factory()->create();
        $admin = $this->account();
        $this->actingAs($admin)->post(route('customers.addresses.store', $customer), ['area_id' => $area->id, 'address_line_1' => 'One'])->assertSessionHasNoErrors();
        $this->post(route('customers.addresses.store', $customer), ['area_id' => $area->id, 'address_line_1' => 'Two'])->assertSessionHasNoErrors();
        [$first, $second] = $customer->addresses()->orderBy('id')->get();
        $this->patch(route('customers.addresses.primary', [$customer, $second]))->assertSessionHasNoErrors();
        $this->assertTrue($second->fresh()->is_primary);
        $this->patch(route('customers.addresses.status', [$customer, $second]), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($second->fresh()->is_primary);
        $this->assertTrue($first->fresh()->is_primary);
    }

    public function test_address_in_inactive_area_cannot_be_reactivated(): void
    {
        $area = Area::factory()->create(['is_active' => false]);
        $customer = Customer::factory()->create();
        $address = CustomerAddress::factory()->for($customer)->for($area)->create(['is_active' => false]);

        $this->actingAs($this->account())->patch(route('customers.addresses.status', [$customer, $address]), ['is_active' => true])->assertSessionHasErrors('address');
        $this->assertFalse($address->fresh()->is_active);
    }

    public function test_search_matches_case_insensitive_name_and_normalized_phone(): void
    {
        $admin = $this->account();
        $named = Customer::factory()->create(['name' => 'Samar Unique']);
        $phoned = Customer::factory()->create(['name' => 'Different']);
        CustomerPhone::factory()->for($phoned)->create(['phone' => '+963 955 000 111', 'normalized_phone' => '963955000111']);

        $this->actingAs($admin)->get(route('customers.index', ['search' => 'sAmAr']))->assertSee($named->name)->assertDontSee($phoned->name);
        $this->get(route('customers.index', ['search' => '955-000']))->assertSee($phoned->name)->assertDontSee($named->name);
    }

    public function test_customer_status_uses_separate_activate_and_deactivate_permissions(): void
    {
        $employee = $this->account('ORDER_EMPLOYEE');
        $customer = Customer::factory()->create(['created_by_user_id' => $employee->id]);
        $this->actingAs($employee)->patch(route('customers.status', $customer), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($customer->fresh()->is_active);
        $this->patch(route('customers.status', $customer), ['is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_nested_resource_tampering_is_rejected_and_customer_has_no_delete_route(): void
    {
        $admin = $this->account();
        [$one, $two] = Customer::factory()->count(2)->create();
        $phone = CustomerPhone::factory()->for($two)->create();
        $address = CustomerAddress::factory()->for($two)->create();

        $this->actingAs($admin)->put(route('customers.phones.update', [$one, $phone]), ['phone' => '333333'])->assertNotFound();
        $this->put(route('customers.addresses.update', [$one, $address]), ['area_id' => $address->area_id, 'address_line_1' => 'Tampered'])->assertNotFound();
        $this->delete('/customers/'.$one->id)->assertStatus(405);
    }

    public function test_inactive_user_is_blocked_and_customer_content_is_escaped(): void
    {
        $admin = $this->account();
        $customer = Customer::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->actingAs($admin)->get(route('customers.index'))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $admin->forceFill(['is_active' => false])->save();
        $this->get(route('customers.show', $customer))->assertRedirect('/login');
    }
}
