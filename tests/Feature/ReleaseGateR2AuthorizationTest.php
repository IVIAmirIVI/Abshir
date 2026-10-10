<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerAddressService;
use App\Services\CustomerRefundService;
use App\Services\InvoiceFinancialCorrectionService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ReceivableDispositionFixtures;
use Tests\TestCase;

class ReleaseGateR2AuthorizationTest extends TestCase
{
    use ReceivableDispositionFixtures, RefreshDatabase;

    public function test_shared_customer_does_not_authorize_moving_a_hidden_address_into_an_accessible_branch(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [$local, $foreign] = Branch::factory()->count(2)->create();
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($local);
        $customer = Customer::factory()->create();
        $localArea = Area::factory()->for($local)->create();
        $visible = CustomerAddress::factory()->for($customer)->for($localArea)->create();
        $hidden = CustomerAddress::factory()->for($customer)->for(Area::factory()->for($foreign))->create();
        $before = $hidden->fresh()->getAttributes();

        $this->actingAs($actor)->get(route('customers.show', $customer))->assertOk();
        $this->get(route('customers.addresses.edit', [$customer, $hidden]))->assertForbidden();
        $this->put(route('customers.addresses.update', [$customer, $hidden]), [
            'area_id' => $localArea->id, 'address_line_1' => 'Unauthorized move', 'is_primary' => true,
        ])->assertForbidden();
        $this->assertSame($before, $hidden->fresh()->getAttributes());
        $this->assertSame(2, $customer->addresses()->count());

        $this->put(route('customers.addresses.update', [$customer, $visible]), [
            'area_id' => $localArea->id, 'address_line_1' => 'Authorized local edit',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Authorized local edit', $visible->fresh()->address_line_1);
    }

    public function test_address_service_authorizes_the_locked_stored_area_and_retains_super_admin_bypass(): void
    {
        $this->seed(AuthorizationSeeder::class);
        [$local, $foreign] = Branch::factory()->count(2)->create();
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $actor->branches()->attach($local);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $customer = Customer::factory()->create();
        $localArea = Area::factory()->for($local)->create();
        CustomerAddress::factory()->for($customer)->for($localArea)->create();
        $hidden = CustomerAddress::factory()->for($customer)->for(Area::factory()->for($foreign))->create();
        $before = $hidden->fresh()->getAttributes();
        // A caller's stale/in-memory Area must not replace the locked persisted identity.
        $hidden->area_id = $localArea->id;
        $data = ['area_id' => $localArea->id, 'address_line_1' => 'Authorized administrator move'];

        $this->assertForbiddenAction(fn () => app(CustomerAddressService::class)->save($actor, $customer, $hidden, $data));
        $this->assertSame($before, $hidden->fresh()->getAttributes());
        $saved = app(CustomerAddressService::class)->save($admin, $customer, $hidden, $data);
        $this->assertSame($localArea->id, $saved->fresh()->area_id);
        $this->assertSame($data['address_line_1'], $saved->fresh()->address_line_1);
    }

    public static function correctionActions(): array
    {
        return ['prepare' => ['prepare'], 'abandon' => ['abandon'], 'refund' => ['refund'], 'finalize' => ['finalize']];
    }

    #[DataProvider('correctionActions')]
    public function test_delegated_financial_permission_does_not_authorize_a_foreign_branch_correction(string $action): void
    {
        $f = $this->fixture('USD');
        $correction = $this->correction($f, '70');
        $service = app(InvoiceFinancialCorrectionService::class);
        if (in_array($action, ['refund', 'finalize'], true)) {
            $correction = $service->prepare($f['admin'], $correction);
        }
        if ($action === 'finalize') {
            app(CustomerRefundService::class)->record($f['admin'], $correction, (string) Str::uuid(), now(), 'VALID-REFUND', $this->proof());
            $correction->refresh();
        }
        $permission = match ($action) {
            'prepare', 'abandon' => 'invoice_corrections.prepare',
            'refund' => 'customer_refunds.record',
            'finalize' => 'invoice_corrections.finalize',
        };
        $actor = $this->delegatedActor(Branch::factory()->create(), [$permission]);
        $this->assertTrue($actor->can($permission));
        $this->assertFalse($actor->canAccessBranch($f['branch']));
        $before = $this->correctionHistory();
        $files = Storage::disk('customer_refunds')->allFiles();
        $data = ['request_key' => (string) Str::uuid(), 'refunded_at' => now()->toIso8601String(),
            'reference' => 'FORGED-REFUND', 'evidence' => $this->proof()];

        $this->actingAs($actor)->post(route('invoice-corrections.'.$action, $correction), $action === 'refund' ? $data : [])->assertForbidden();
        $this->assertSame($before, $this->correctionHistory());
        $this->assertForbiddenAction(fn () => $action === 'refund'
            ? app(CustomerRefundService::class)->record($actor, $correction, $data['request_key'], $data['refunded_at'], $data['reference'], $data['evidence'])
            : $service->{$action}($actor, $correction));
        $this->assertSame($before, $this->correctionHistory());
        $this->assertSame($files, Storage::disk('customer_refunds')->allFiles());
    }

    public function test_authorized_branch_delegate_can_complete_the_existing_audited_correction_flow(): void
    {
        $f = $this->fixture('USD');
        $actor = $this->delegatedActor($f['branch'], ['invoice_corrections.prepare', 'invoice_corrections.finalize', 'customer_refunds.record']);
        $correction = $this->correction($f, '70');
        $this->actingAs($actor)->post(route('invoice-corrections.prepare', $correction))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($actor->id, $correction->fresh()->prepared_by);
        $this->post(route('invoice-corrections.refund', $correction), ['request_key' => (string) Str::uuid(),
            'refunded_at' => now()->toIso8601String(), 'reference' => 'BRANCH-REFUND', 'evidence' => $this->proof(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($actor->id, $correction->fresh()->refund->authorized_by);
        $this->post(route('invoice-corrections.finalize', $correction))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('COMPLETED', $correction->fresh()->state->value);
        $this->assertSame($actor->id, $correction->fresh()->finalized_by);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function delegatedActor(Branch $branch, array $permissions): User
    {
        $role = Role::create(['code' => 'R2_FINANCIAL_REVIEWER', 'name' => 'Branch financial reviewer']);
        $role->permissions()->attach(Permission::whereIn('code', $permissions)->pluck('id'));
        $actor = User::factory()->create();
        $actor->roles()->attach($role);
        $actor->branches()->attach($branch);

        return $actor;
    }

    private function assertForbiddenAction(callable $action): void
    {
        try {
            $action();
            $this->fail('Foreign branch mutation was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function correctionHistory(): array
    {
        $history = [];
        foreach (['orders', 'invoices', 'invoice_items', 'invoice_collections', 'invoice_financial_corrections',
            'customer_refunds', 'invoice_correction_receivable_dispositions', 'invoice_correction_reversals',
            'financial_transactions', 'financial_entries'] as $table) {
            $history[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $history;
    }
}
