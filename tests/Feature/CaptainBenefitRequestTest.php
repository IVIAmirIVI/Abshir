<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainBenefitRequest;
use App\Models\Currency;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBenefitRequestService;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class CaptainBenefitRequestTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_bonus_create_retry_revision_preserves_ownership_history_and_has_no_financial_effect(): void
    {
        $c = $this->context();
        $data = $this->data($c);
        $url = route('captains.requests.store', $c['captain']);
        $this->actingAs($c['admin'])->post($url, $data)->assertSessionHasNoErrors()->assertRedirect();
        $first = CaptainBenefitRequest::sole();
        $this->post($url, $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('captain_benefit_requests', 1);
        $this->post($url, array_replace($data, ['amount' => '21']))->assertSessionHasErrors('request_key');
        $revision = ['request_key' => (string) Str::uuid(), 'expected_version' => 1, 'amount' => '30',
            'description' => 'Revised proposal', 'revision_note' => 'Correction of draft'];
        $this->actingAs($c['manager'])->post(route('captains.requests.revise', [$c['captain'], $first]), $revision)
            ->assertSessionHasNoErrors()->assertRedirect();
        $second = $first->nextVersion()->sole();
        $this->assertSame('20.0000', $first->fresh()->amount);
        $this->assertSame('30.0000', $second->amount);
        $this->assertSame($c['admin']->id, $second->created_by_user_id);
        $this->assertSame($c['manager']->id, $second->recorded_by_user_id);
        $this->assertSame($first->created_at->format('c'), $second->created_at->format('c'));
        $this->post(route('captains.requests.revise', [$c['captain'], $first]), $revision)->assertSessionHasNoErrors();
        $this->post(route('captains.requests.revise', [$c['captain'], $first]), array_replace($revision, ['request_key' => (string) Str::uuid()]))
            ->assertSessionHasErrors('expected_version');
        $this->assertDatabaseCount('captain_benefit_requests', 2);
        $this->get(route('captains.requests.show', [$c['captain'], $second]))->assertOk()
            ->assertSee(__('captain_requests.draft'))->assertSee('Correction of draft')->assertSee('Original proposal');
        $this->assertNoFinancialEffect();
    }

    public function test_expense_evidence_is_private_branch_scoped_integrity_checked_and_history_keeps_original_file(): void
    {
        $c = $this->context();
        $data = array_replace($this->data($c), ['kind' => 'EXPENSE', 'category' => 'TRANSPORT', 'expense_date' => today()->toDateString()]);
        $file = $this->image();
        $this->actingAs($c['admin'])->post(route('captains.requests.store', $c['captain']), $data + ['evidence' => $file])->assertSessionHasNoErrors();
        $first = CaptainBenefitRequest::sole();
        Storage::disk('captain_financial_evidence')->assertExists($first->evidence_path);
        $this->assertFalse(config('filesystems.disks.captain_financial_evidence.serve'));
        $url = route('captains.requests.evidence', [$c['captain'], $first]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('captains.requests.show', [$c['captain'], $first]))->assertOk()->assertDontSee($first->evidence_path);
        $this->actingAs($c['foreign'])->get($url)->assertForbidden();
        $otherCaptain = Captain::factory()->create(['home_branch_id' => $c['branch']->id]);
        $this->actingAs($c['admin'])->get(route('captains.requests.evidence', [$otherCaptain, $first]))->assertNotFound();
        $second = app(CaptainBenefitRequestService::class)->save($c['admin'], $c['captain'], [
            'request_key' => (string) Str::uuid(), 'expected_version' => 1, 'amount' => '21',
            'description' => 'Revised expense', 'category' => 'TRANSPORT', 'expense_date' => today()->toDateString(), 'revision_note' => 'Corrected amount',
        ], $this->image(), $first);
        $this->assertNotSame($first->evidence_path, $second->evidence_path);
        Storage::disk('captain_financial_evidence')->assertExists($first->evidence_path);
        Storage::disk('captain_financial_evidence')->put($first->evidence_path, 'corrupted');
        $this->get($url)->assertStatus(409);
        $this->assertNoFinancialEffect();
    }

    public function test_branch_role_and_nested_id_tampering_are_denied_by_http_and_service(): void
    {
        $c = $this->context();
        $data = $this->data($c);
        $this->actingAs($c['employee'])->get(route('captains.requests.index', $c['captain']))->assertOk();
        $this->post(route('captains.requests.store', $c['captain']), $data)->assertForbidden();
        $this->actingAs($c['foreign'])->get(route('captains.requests.index', $c['captain']))->assertForbidden();
        $this->actingAs($c['captain']->user)->get(route('captains.requests.index', $c['captain']))->assertForbidden();
        $foreignBranch = Branch::factory()->create();
        $this->actingAs($c['manager'])->post(route('captains.requests.store', $c['captain']), array_replace($data, ['branch_id' => $foreignBranch->id]))->assertForbidden();
        $foreignCaptain = Captain::factory()->create(['home_branch_id' => $foreignBranch->id]);
        $this->actingAs($c['admin'])->post(route('captains.requests.store', $foreignCaptain), $data)->assertForbidden();
        try {
            app(CaptainBenefitRequestService::class)->save($c['employee'], $c['captain'], $data);
            $this->fail('The service must enforce write authorization');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('captain_benefit_requests', 0);
        }
    }

    public function test_optional_order_reference_requires_same_branch_and_participating_captain(): void
    {
        $c = $this->sharedContext(2, 1);
        $captain = $c['captains'][0];
        $data = ['request_key' => (string) Str::uuid(), 'kind' => 'BONUS', 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'order_id' => $c['order']->id, 'amount' => '20', 'description' => 'Order proposal'];
        $this->actingAs($c['admin'])->post(route('captains.requests.store', $captain), $data)->assertSessionHasNoErrors();
        $proposal = CaptainBenefitRequest::sole();
        $this->assertSame($c['order']->id, $proposal->order_id);
        $unrelated = Captain::factory()->create(['home_branch_id' => $c['branch']->id]);
        $this->post(route('captains.requests.store', $unrelated), array_replace($data, ['request_key' => (string) Str::uuid()]))->assertForbidden();
        $branch = Branch::factory()->create();
        $foreign = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $this->post(route('captains.requests.store', $foreign), array_replace($data, ['request_key' => (string) Str::uuid(), 'branch_id' => $branch->id]))->assertForbidden();
        $this->assertDatabaseCount('captain_benefit_requests', 1);
        $this->assertNoFinancialEffect();
    }

    #[DataProvider('invalidData')]
    public function test_invalid_money_product_financing_or_forged_fields_cannot_create_claims(array $changes, string $error): void
    {
        $c = $this->context();
        $this->actingAs($c['admin'])->post(route('captains.requests.store', $c['captain']), array_replace($this->data($c), $changes))
            ->assertSessionHasErrors($error);
        $this->assertDatabaseCount('captain_benefit_requests', 0);
        $this->assertNoFinancialEffect();
    }

    public static function invalidData(): array
    {
        return [
            'zero' => [['amount' => '0'], 'amount'],
            'negative' => [['amount' => '-1'], 'amount'],
            'precision' => [['amount' => '1.234'], 'amount'],
            'nonfinite' => [['amount' => 'NaN'], 'amount'],
            'product purchase' => [['kind' => 'EXPENSE', 'category' => 'PRODUCT_PURCHASE', 'expense_date' => '2026-01-01'], 'category'],
            'missing expense date' => [['kind' => 'EXPENSE', 'category' => 'OTHER'], 'expense_date'],
            'forged creator' => [['created_by_user_id' => 1], 'proposal'],
            'payable status' => [['status' => 'APPROVED'], 'proposal'],
        ];
    }

    public function test_disguised_evidence_is_rejected_before_writes(): void
    {
        $c = $this->context();
        $file = UploadedFile::fake()->createWithContent('evidence.jpg', '<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>');
        $this->actingAs($c['admin'])->post(route('captains.requests.store', $c['captain']), $this->data($c) + ['evidence' => $file])->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('captain_benefit_requests', 0);
        $this->assertSame([], Storage::disk('captain_financial_evidence')->allFiles());
    }

    public function test_database_preserves_revision_history_and_rejects_direct_update(): void
    {
        $c = $this->context();
        $proposal = app(CaptainBenefitRequestService::class)->save($c['admin'], $c['captain'], $this->data($c));
        $this->expectException(QueryException::class);
        DB::table('captain_benefit_requests')->where('id', $proposal->id)->update(['amount' => '50']);
    }

    public function test_localization_parity_and_draft_ui_escape_user_content(): void
    {
        $c = $this->context();
        $data = array_replace($this->data($c), ['description' => '<script>alert("unsafe")</script>']);
        $proposal = app(CaptainBenefitRequestService::class)->save($c['admin'], $c['captain'], $data);
        $this->actingAs($c['admin'])->get(route('captains.requests.show', [$c['captain'], $proposal]))
            ->assertOk()->assertDontSee($data['description'], false)->assertSee('&lt;script&gt;', false);
        $ar = require lang_path('ar/captain_requests.php');
        $en = require lang_path('en/captain_requests.php');
        $keys = function (array $values, string $prefix = '') use (&$keys): array {
            $result = [];
            foreach ($values as $key => $value) {
                $path = $prefix.$key;
                $result = [...$result, ...(is_array($value) ? $keys($value, $path.'.') : [$path])];
            }

            return $result;
        };
        $this->assertSame($keys($ar), $keys($en));
        app()->setLocale('en');
        $this->get(route('captains.requests.index', $c['captain']))->assertOk()->assertSee('Unapproved draft')->assertSee('not financial entitlements');
    }

    private function context(): array
    {
        $this->freezeTime();
        Storage::fake('captain_financial_evidence');
        $this->seed(AuthorizationSeeder::class);
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $branch = Branch::factory()->create(['default_currency_id' => $currency->id]);
        $captain = Captain::factory()->create(['home_branch_id' => $branch->id]);
        $captain->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $employee->branches()->attach($branch);
        $foreign = User::factory()->create();
        $foreign->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $foreign->branches()->attach(Branch::factory()->create());
        $manager = User::factory()->create();
        $role = Role::create(['code' => 'TEST_DRAFT_MANAGER', 'name' => 'Draft manager']);
        $role->permissions()->attach(Permission::whereIn('code', ['captains.view', 'captains.update'])->pluck('id'));
        $manager->roles()->attach($role);
        $manager->branches()->attach($branch);

        return compact('currency', 'branch', 'captain', 'admin', 'employee', 'foreign', 'manager');
    }

    private function data(array $c): array
    {
        return ['request_key' => (string) Str::uuid(), 'branch_id' => $c['branch']->id,
            'currency_id' => $c['currency']->id, 'kind' => 'BONUS', 'amount' => '20', 'description' => 'Original proposal'];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jA4sAAAAASUVORK5CYII='));
    }

    private function assertNoFinancialEffect(): void
    {
        foreach (['financial_transactions', 'financial_entries', 'financial_accounts', 'captain_deposit_receipts', 'captain_receivable_payouts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
