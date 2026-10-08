<?php

namespace Tests\Feature;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Livewire\Shell\NavigationFinder;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Services\CaptainBranchService;
use App\Services\CaptainInformationQuery;
use App\Services\FinancialAccountService;
use App\Services\FinancialBalanceQueryService;
use App\Services\FinancialPostingService;
use App\Support\CaptainSelfView;
use Carbon\Carbon;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CaptainSelfViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Branch $branch;

    private Branch $otherBranch;

    private Captain $captain;

    private Captain $otherCaptain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        $this->seed(AuthorizationSeeder::class);
        $this->branch = Branch::factory()->create(['name' => 'Historical branch']);
        $this->otherBranch = Branch::factory()->create(['name' => 'Current branch']);
        $this->user = $this->account('CAPTAIN');
        $this->admin = $this->account('SUPER_ADMIN');
        $this->captain = Captain::factory()->create(['user_id' => $this->user->id, 'home_branch_id' => $this->branch->id]);
        $this->otherCaptain = Captain::factory()->create(['home_branch_id' => $this->otherBranch->id]);
    }

    public function test_self_route_uses_only_authenticated_identity_even_with_tampered_ids_or_cached_relation(): void
    {
        // The resolver must not trust a stale/eager-loaded User::captain property.
        $this->user->setRelation('captain', $this->otherCaptain);
        $this->assertSame($this->captain->id, app(CaptainSelfView::class)->resolve($this->user)->id);
        $response = $this->actingAs($this->user)->get(route('captain.self-information', [
            'captain_id' => $this->otherCaptain->id, 'user_id' => $this->otherCaptain->user_id,
        ]));
        $response->assertOk()->assertViewHas('captain', fn ($record) => $record->id === $this->captain->id)
            ->assertSee($this->user->name)->assertDontSee($this->otherCaptain->user->name)
            ->assertDontSee(route('captains.show', $this->captain), false);
        $this->get(route('captains.information', $this->otherCaptain))->assertForbidden();
        $this->get(route('captain.self-information').'/'.$this->otherCaptain->id)->assertNotFound();
        $this->get(route('captain.self-information').'?branch_id='.$this->otherBranch->id)->assertForbidden();
        $this->assertCount(0, $this->user->branches);
    }

    public function test_missing_inactive_unqualified_and_revoked_identity_fail_closed(): void
    {
        $unlinked = $this->account('CAPTAIN');
        $this->actingAs($unlinked)->get(route('captain.self-information'))->assertForbidden();
        $this->assertFalse(Gate::forUser($unlinked)->allows(CaptainSelfView::ABILITY));
        $this->user->roles()->detach();
        $this->actingAs($this->user)->get(route('captain.self-information'))->assertForbidden();
        $this->user->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->get(route('captain.self-information'))->assertOk();
        $this->user->forceFill(['is_active' => false])->save();
        $this->assertFalse(Gate::forUser($this->user)->allows(CaptainSelfView::ABILITY));
        $this->get(route('captain.self-information'))->assertRedirect(route('login'));
        $this->assertSame(0, Role::where('code', 'CAPTAIN')->sole()->permissions()->count());
    }

    public function test_mixed_roles_and_super_admin_bypass_never_replace_self_identity(): void
    {
        $url = route('captain.self-information').'?captain_id='.$this->otherCaptain->id;
        $this->user->roles()->attach(Role::where('code', 'ORDER_EMPLOYEE')->sole());
        $this->user->branches()->attach($this->otherBranch);
        $this->actingAs($this->user)->get($url)->assertOk()
            ->assertViewHas('captain', fn ($record) => $record->id === $this->captain->id);
        $this->get(route('captains.information', $this->otherCaptain))->assertForbidden();
        $this->user->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $this->get($url)->assertOk()->assertViewHas('captain', fn ($record) => $record->id === $this->captain->id);
        // Existing unrestricted administrative access remains on the separate admin route.
        $this->get(route('captains.information', $this->otherCaptain))->assertOk();
        $this->assertFalse(Gate::forUser($this->admin)->allows(CaptainSelfView::ABILITY));
        $this->admin->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $this->actingAs($this->admin)->get($url)->assertForbidden();
        $this->get(route('captains.information', $this->captain))->assertOk();
    }

    public function test_navigation_is_authorized_and_livewire_finder_cannot_change_captain_identity(): void
    {
        $finder = Livewire::actingAs($this->user)->test(NavigationFinder::class);
        $this->assertSame(['dashboard', 'captain.self-information', 'profile.edit'], array_column($finder->get('links'), 'route'));
        $finder->set('search', __('admin.navigation.my_captain_information'))->call('$refresh')
            ->assertSee(route('captain.self-information'), false)->assertDontSee(route('captains.index'), false);
        $response = $this->actingAs($this->user)->get(route('captain.self-information'))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['desktop', 'mobile'] as $surface) {
            $link = $xpath->query('//*[@data-navigation="'.$surface.'"]//a[@aria-current="page"]');
            $this->assertSame(1, $link->length);
            $this->assertSame(route('captain.self-information'), $link->item(0)->getAttribute('href'));
        }
        $this->assertSame(0, $xpath->query('//*[@data-captain-information]//*[@*[name()="wire:id"]]')->length);
        $this->user->roles()->detach();
        $finder->set('search', '')->call('$refresh')->assertDontSee(route('captain.self-information'), false);
        $this->get(route('captain.self-information'))->assertForbidden();
    }

    public function test_identity_is_unique_and_cannot_be_automatically_inferred_for_other_users(): void
    {
        $this->assertThrows(fn () => DB::transaction(fn () => Captain::factory()->create([
            'user_id' => $this->user->id, 'home_branch_id' => $this->branch->id,
        ])), QueryException::class);
        $this->assertSame($this->captain->id, app(CaptainSelfView::class)->resolve($this->user)->id);
        $employee = $this->account('ORDER_EMPLOYEE');
        $employee->branches()->attach($this->branch);
        $before = Captain::count();
        $this->actingAs($employee)->get(route('captain.self-information'))->assertForbidden();
        $this->assertSame($before, Captain::count());
        $this->assertNull($employee->captain);
    }

    public function test_global_accounts_survive_real_transfer_and_inactive_branch_in_separate_currencies(): void
    {
        $usd = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 4]);
        $syp = Currency::create(['code' => 'SYP', 'name' => 'Lira', 'decimal_places' => 0]);
        $hidden = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2]);
        $this->movement($usd, $this->branch, FinancialAccountKind::CaptainDeposit, '50.1234', true);
        $this->movement($usd, $this->otherBranch, FinancialAccountKind::CaptainDeposit, '80', false);
        $this->movement($usd, $this->branch, FinancialAccountKind::CaptainCompanyReceivable, '5', true);
        $this->movement($usd, $this->otherBranch, FinancialAccountKind::CaptainCompanyReceivable, '7.25', true);
        $this->movement($usd, $this->branch, FinancialAccountKind::CaptainCashCustody, '300', true);
        $this->movement($usd, $this->otherBranch, FinancialAccountKind::CaptainCashCustody, '1.25', true);
        $this->movement($syp, $this->branch, FinancialAccountKind::CaptainDeposit, '2000', true);
        $this->movement($syp, $this->otherBranch, FinancialAccountKind::CaptainDeposit, '5', true);
        $this->movement($hidden, $this->otherBranch, FinancialAccountKind::CaptainDeposit, '900', true, $this->otherCaptain);
        $before = app(CaptainInformationQuery::class)->selfSnapshot($this->user)['balanceRows'];
        $accountIds = DB::table('financial_accounts')->orderBy('id')->pluck('id')->all();
        app(CaptainBranchService::class)->transfer($this->admin, $this->captain, [
            'branch_id' => $this->otherBranch->id, 'starts_at' => now(), 'reason' => 'Fixture permanent transfer',
        ]);
        $this->otherBranch->forceFill(['is_active' => false])->save();
        $this->assertSame($this->otherBranch->id, $this->captain->fresh()->home_branch_id);
        foreach (['today', '7days', '30days'] as $period) {
            foreach ([null, $this->branch->id, $this->otherBranch->id] as $branchId) {
                $snapshot = app(CaptainInformationQuery::class)->selfSnapshot($this->user, $period, $branchId);
                $this->assertEquals($before, $snapshot['balanceRows']);
                $this->assertCount(2, $snapshot['balanceRows']);
                $usdRow = collect($snapshot['balanceRows'])->firstWhere('currency.code', 'USD');
                $this->assertSame(['CAPTAIN_DEPOSIT' => '-29.8766', 'CAPTAIN_COMPANY_RECEIVABLE' => '12.2500', 'CAPTAIN_CASH_CUSTODY' => '301.2500'], $usdRow['values']);
                $this->assertSame('2005.0000', collect($snapshot['balanceRows'])->firstWhere('currency.code', 'SYP')['values']['CAPTAIN_DEPOSIT']);
                $this->assertSame([], $snapshot['rows']);
                if ($branchId !== null) {
                    $expectedName = $branchId === $this->branch->id ? $this->branch->name : $this->otherBranch->name;
                    $this->assertTrue($snapshot['movements']->every(fn ($row) => $row->branch_name === $expectedName));
                }
            }
        }
        $this->assertSame($accountIds, DB::table('financial_accounts')->orderBy('id')->pluck('id')->all());
        foreach ([$usd, $syp] as $currency) {
            $this->assertSame(app(FinancialBalanceQueryService::class)->captainBalances($this->captain, $currency),
                collect($before)->firstWhere('currency.code', $currency->code)['values']);
        }
        $this->actingAs($this->user)->get(route('captain.self-information'))->assertOk()
            ->assertSee('-29.8766 USD')->assertSee('12.2500 USD')->assertSee('301.2500 USD')->assertSee('2005.0000 SYP')
            ->assertDontSee('data-global-currency="EUR"', false);
    }

    public function test_self_view_is_read_only_and_no_financial_or_operational_mutation_permission_is_added(): void
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $this->movement($currency, $this->branch, FinancialAccountKind::CaptainDeposit, '10', true);
        $before = DB::table('financial_entries')->orderBy('id')->get()->toJson();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $response = $this->actingAs($this->user)->get(route('captain.self-information'))->assertOk();
        $this->get(route('captain.self-information', ['period' => '30days']))->assertOk();
        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table('financial_entries')->orderBy('id')->get()->toJson());
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//*[@data-captain-information]//form[translate(@method,"POST","post")="post"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-captain-information]//*[@*[name()="wire:click"]]')->length);
        $this->post(route('captain.self-information'), ['captain_id' => $this->otherCaptain->id])->assertStatus(405);
        foreach (['captains.view', 'captain_financials.view', 'orders.view', 'orders.status.update', 'captain_deposits.create', 'branches.view'] as $ability) {
            $this->assertFalse($this->user->can($ability));
        }
        $this->get(route('captain-financials.index'))->assertForbidden();
        $this->get(route('captains.index'))->assertForbidden();
    }

    public function test_existing_empty_accounts_are_reported_as_zero_without_creating_missing_accounts(): void
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'decimal_places' => 2]);
        $accounts = app(FinancialAccountService::class);
        $accounts->getOrCreate($accounts->ownerForCaptain($this->captain), $currency, FinancialAccountKind::CaptainDeposit);
        $values = app(FinancialBalanceQueryService::class)->captainBalancesByCurrency($this->captain);
        $this->assertSame(['CAPTAIN_DEPOSIT' => '0.0000', 'CAPTAIN_COMPANY_RECEIVABLE' => '0.0000', 'CAPTAIN_CASH_CUSTODY' => '0.0000'], $values[$currency->id]);
        $this->actingAs($this->user)->get(route('captain.self-information'))->assertOk()->assertSee('0.0000 USD');
        $this->assertDatabaseCount('financial_accounts', 1);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    private function account(string $code): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $code)->sole());

        return $user;
    }

    private function movement(Currency $currency, Branch $branch, FinancialAccountKind $kind, string $amount, bool $increase, ?Captain $captain = null): void
    {
        $accounts = app(FinancialAccountService::class);
        $account = $accounts->getOrCreate($accounts->ownerForCaptain($captain ?? $this->captain), $currency, $kind);
        app(FinancialPostingService::class)->postEvent($currency, 'U5_SELF_FIXTURE', (string) Str::uuid(), 'U5_SELF_MOVEMENT', 1, now()->subMonth(), [
            ['account' => $account, 'direction' => $increase ? FinancialEntryDirection::Increase : FinancialEntryDirection::Decrease, 'amount' => $amount],
        ], $branch);
    }
}
