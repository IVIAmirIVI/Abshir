<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Captain;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CaptainBenefitFixtures;
use Tests\TestCase;

class CaptainManagementNavigationTest extends TestCase
{
    use CaptainBenefitFixtures, RefreshDatabase;

    public static function managers(): array
    {
        return [['reviewer'], ['employee']];
    }

    #[DataProvider('managers')]
    public function test_index_links_each_captain_to_all_features_and_pages_have_active_and_return_links(string $actorKey): void
    {
        $c = $this->benefitContext();
        $this->assertSame('abshir_testing', DB::scalar('SELECT current_database()'));
        $captains = [$c['captain'], Captain::factory()->create(['home_branch_id' => $c['branch']->id])];
        $index = $this->actingAs($c[$actorKey])->get(route('captains.index'))->assertOk();
        foreach ($captains as $captain) {
            $xpath = $this->xpath($index->getContent());
            $hrefs = [];
            foreach ($xpath->query('//nav[@data-captain-navigation="'.$captain->id.'"]/a') as $link) {
                $hrefs[] = $link->getAttribute('href');
            }
            $routes = ['captains.show', 'captains.information', 'captains.financials.show', 'captains.requests.index'];
            $this->assertSame(array_map(fn ($route) => route($route, $captain), $routes), $hrefs);
            foreach ($routes as $route) {
                $response = $this->get(route($route, $captain))->assertOk();
                $xpath = $this->xpath($response->getContent());
                $active = $xpath->query('//nav[@data-captain-navigation="'.$captain->id.'"]/a[@aria-current="page"]');
                $this->assertSame(1, $active->length);
                $this->assertSame(route($route, $captain), $active->item(0)->getAttribute('href'));
                $this->assertSame(1, $xpath->query('//nav[@data-captain-navigation]//a[@href="'.route('captains.index').'"]')->length);
                foreach (['desktop', 'mobile'] as $surface) {
                    $this->assertSame(1, $xpath->query('//*[@data-navigation="'.$surface.'"]//details[@open]//a[@href="'.route('captains.index').'" and @aria-current="page"]')->length);
                }
            }
        }
        $this->get(route('captains.requests.show', [$c['captain'], $c['proposal']]))->assertOk()->assertSee(route('captains.requests.index', $c['captain']), false);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
    }

    public function test_read_only_permissions_show_only_authorized_features_and_captain_self_view_stays_separate(): void
    {
        $c = $this->benefitContext();
        $reader = User::factory()->create();
        $role = Role::create(['code' => 'NAV_READ_ONLY', 'name' => 'Read only']);
        $role->permissions()->sync(Permission::where('code', 'captains.view')->pluck('id'));
        $reader->roles()->attach($role);
        $reader->branches()->attach($c['branch']);
        $response = $this->actingAs($reader)->get(route('captains.show', $c['captain']))->assertOk();
        $response->assertSee(route('captains.requests.index', $c['captain']), false)
            ->assertDontSee(route('captains.financials.show', $c['captain']), false);
        $this->get(route('captains.requests.index', $c['captain']))->assertOk()->assertSee(__('captain_requests.read_only_access'))
            ->assertDontSee('id="captain-benefit-create"', false);
        $this->get(route('captains.financials.show', $c['captain']))->assertForbidden();
        $this->actingAs($c['captain']->user);
        foreach (['captains.show', 'captains.information', 'captains.financials.show', 'captains.requests.index'] as $route) {
            $this->get(route($route, $c['captain']))->assertForbidden();
        }
        $this->get(route('captain.self-information'))->assertOk()->assertDontSee('data-captain-navigation', false);
    }

    public function test_foreign_branch_captains_are_not_listed_and_direct_feature_urls_are_rejected(): void
    {
        $c = $this->benefitContext();
        $foreign = Captain::factory()->create(['home_branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($c['employee'])->get(route('captains.index'))->assertOk()->assertDontSee('data-captain-navigation="'.$foreign->id.'"', false);
        foreach (['captains.show', 'captains.information', 'captains.financials.show', 'captains.requests.index'] as $route) {
            $this->get(route($route, $foreign))->assertForbidden();
        }
    }

    public function test_historical_branch_links_survive_transfer_without_advertising_forbidden_profile_access(): void
    {
        $c = $this->benefitContext();
        $this->benefitAction($c, 'SUBMIT');
        $this->benefitAction($c, 'APPROVE');
        $c['captain']->update(['home_branch_id' => Branch::factory()->create()->id]);
        foreach (['captains.financials.show', 'captains.requests.index'] as $route) {
            $response = $this->actingAs($c['employee'])->get(route($route, $c['captain']))->assertOk();
            $xpath = $this->xpath($response->getContent());
            $links = [];
            foreach ($xpath->query('//nav[@data-captain-navigation]/a') as $link) {
                $links[] = $link->getAttribute('href');
            }
            $this->assertSame([route('captains.index'), route('captains.financials.show', $c['captain']), route('captains.requests.index', $c['captain'])], $links);
        }
        $this->get(route('captains.show', $c['captain']))->assertForbidden();
        $this->get(route('captains.information', $c['captain']))->assertForbidden();
        $this->get(route('captain-financials.index'))->assertOk()->assertSee(route('captains.financials.show', $c['captain']), false);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($document);
    }
}
