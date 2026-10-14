<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinancialTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CaptainBenefitWorkflowNotice;
use App\Services\CaptainBenefitNotificationService;
use App\Services\CaptainBenefitRequestService;
use App\Services\CaptainBenefitReviewService;
use App\Services\InternalNoticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CaptainBenefitFixtures;
use Tests\TestCase;

class CaptainBenefitNotificationTest extends TestCase
{
    use CaptainBenefitFixtures, RefreshDatabase;

    #[DataProvider('kinds')]
    public function test_submission_notifies_current_authorized_reviewers_not_creator_wrong_permission_or_foreign_branch(string $kind): void
    {
        $c = $this->benefitContext($kind);
        $permission = $kind === 'BONUS' ? 'captain_bonuses.approve' : 'captain_expenses.approve';
        $delegate = $this->delegate($c, [$permission]);
        $foreign = $this->delegate($c, [$permission], Branch::factory()->create());
        $wrong = $this->delegate($c, [$kind === 'BONUS' ? 'captain_expenses.approve' : 'captain_bonuses.approve']);
        $inactive = $this->delegate($c, [$permission]);
        $inactive->forceFill(['is_active' => false])->save();

        $review = $this->benefitAction($c, 'SUBMIT');

        $ids = DB::table('notifications')->orderBy('notifiable_id')->pluck('notifiable_id')->all();
        $expected = [$c['reviewer']->id, $delegate->id];
        sort($expected);
        $this->assertSame($expected, $ids);
        foreach ([$c['creator'], $c['employee'], $foreign, $wrong, $inactive, $c['captain']->user] as $excluded) {
            $this->assertSame(0, $excluded->notifications()->count());
        }
        $notice = $delegate->notifications()->sole();
        $this->assertSame(['database'], (new CaptainBenefitWorkflowNotice($review, $delegate->id))->via($delegate));
        $keys = array_keys($notice->data);
        sort($keys);
        $this->assertSame(['branch_id', 'captain_id', 'event', 'request_id', 'request_type', 'review_id'], $keys);
        $this->assertSame($kind, $notice->data['request_type']);
        $this->actingAs($delegate)->get(route('notifications.index'))->assertSee(__('internal.workflow.'.$kind.'.SUBMIT'))->assertViewHas('unreadCount', 1);
        $this->get(route('notifications.open', $notice->id))->assertRedirect(route('captains.requests.show', [$c['captain'], $c['proposal']]));
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function kinds(): array
    {
        return [['BONUS'], ['EXPENSE']];
    }

    #[DataProvider('decisions')]
    public function test_decisions_notify_actual_submitter_and_preserve_existing_accrual_without_payment(string $action): void
    {
        $c = $this->benefitContext();
        $submitter = $this->delegate($c, ['captain_bonuses.submit']);
        $this->benefitAction($c, 'SUBMIT', $submitter);

        $review = $this->benefitAction($c, $action, reason: $action === 'REJECT' ? 'Not approved' : null);
        app(CaptainBenefitNotificationService::class)->enqueue($review);

        $notice = $submitter->notifications()->sole();
        $this->assertSame($action, $notice->data['event']);
        $this->assertSame(0, $c['creator']->notifications()->count());
        $this->actingAs($submitter)->get(route('notifications.index'))->assertSee(__('internal.workflow.BONUS.'.$action));
        $this->get(route('notifications.open', $notice->id))->assertRedirect(route('captains.requests.show', [$c['captain'], $c['proposal']]));
        $this->assertDatabaseCount('financial_transactions', $action === 'APPROVE' ? 1 : 0);
        if ($action === 'APPROVE') {
            $this->assertSame('CAPTAIN_BONUS_DUE', FinancialTransaction::sole()->event_type);
            $this->assertSame('20.0000', FinancialTransaction::sole()->entries()->sole()->amount);
        }
        $this->assertDatabaseCount('captain_receivable_payouts', 0);
        $this->assertDatabaseCount('captain_deposit_receipts', 0);
    }

    public static function decisions(): array
    {
        return [['APPROVE'], ['REJECT']];
    }

    public function test_only_pending_cancellation_notifies_reviewers_and_draft_revision_is_silent(): void
    {
        $c = $this->benefitContext();
        $revision = app(CaptainBenefitRequestService::class)->save($c['creator'], $c['captain'],
            ['request_key' => (string) Str::uuid(), 'expected_version' => 1, 'amount' => '25',
                'description' => 'Revised draft', 'revision_note' => 'Correction'], null, $c['proposal']);
        $c['proposal'] = $revision;
        $this->assertDatabaseCount('notifications', 0);
        $this->benefitAction($c, 'CANCEL', $c['creator']);
        $this->assertDatabaseCount('captain_benefit_notification_events', 0);
        $this->assertDatabaseCount('notifications', 0);

        $another = $this->benefitContext();
        $this->benefitAction($another, 'SUBMIT');
        $this->benefitAction($another, 'CANCEL', $another['creator']);

        $events = $another['reviewer']->notifications()->get()->pluck('data.event')->sort()->values()->all();
        $this->assertSame(['CANCEL', 'SUBMIT'], $events);
        $this->assertSame(0, $another['creator']->notifications()->count());
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_no_reviewer_warns_submitter_without_approving_or_granting_permissions(): void
    {
        $c = $this->benefitContext();
        $c['reviewer']->forceFill(['is_active' => false])->save();
        $permissionCount = DB::table('role_permissions')->count();

        $this->actingAs($c['creator'])->post(route('captains.requests.review', [$c['captain'], $c['proposal']]),
            ['request_key' => (string) Str::uuid(), 'action' => 'SUBMIT'])
            ->assertSessionHas('warning', __('internal.no_reviewer'))->assertSessionHasNoErrors();

        $this->assertSame('PENDING', app(CaptainBenefitReviewService::class)->summary($c['proposal'])['state']);
        $this->assertSame($permissionCount, DB::table('role_permissions')->count());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->get(route('captains.requests.show', [$c['captain'], $c['proposal']]))->assertSee(__('internal.no_reviewer'));
    }

    public function test_submit_retry_is_durable_and_rejected_duplicate_transition_is_silent(): void
    {
        $c = $this->benefitContext();
        $data = ['request_key' => (string) Str::uuid(), 'action' => 'SUBMIT'];
        $service = app(CaptainBenefitReviewService::class);

        $first = $service->act($c['creator'], $c['captain'], $c['proposal'], $data);
        $again = $service->act($c['creator'], $c['captain'], $c['proposal'], $data);
        $this->assertSame($first->id, $again->id);
        $this->actingAs($c['creator'])->post(route('captains.requests.review', [$c['captain'], $c['proposal']]),
            array_replace($data, ['request_key' => (string) Str::uuid()]))->assertSessionHasErrors('proposal');

        $this->assertDatabaseCount('captain_benefit_reviews', 1);
        $this->assertDatabaseCount('captain_benefit_notification_events', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_delivery_waits_for_outer_commit_and_rollback_creates_no_notifications(): void
    {
        $c = $this->benefitContext();
        DB::beginTransaction();
        try {
            $this->benefitAction($c, 'SUBMIT');
            $this->assertDatabaseCount('notifications', 0);
            $this->assertDatabaseCount('captain_benefit_notification_events', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('captain_benefit_reviews', 0);
        $this->assertDatabaseCount('captain_benefit_notification_events', 0);
        $this->assertDatabaseCount('notifications', 0);

        DB::beginTransaction();
        $this->benefitAction($c, 'SUBMIT');
        $this->assertDatabaseCount('notifications', 0);
        DB::commit();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_old_notifications_are_hidden_and_links_denied_after_approval_permission_or_branch_is_revoked(): void
    {
        $c = $this->benefitContext();
        $delegate = $this->delegate($c, ['captain_bonuses.approve']);
        $this->benefitAction($c, 'SUBMIT');
        $notice = $delegate->notifications()->sole();
        $this->actingAs($delegate)->get(route('notifications.index'))->assertViewHas('unreadCount', 1);

        $delegate->roles()->sole()->permissions()->detach(Permission::where('code', 'captain_bonuses.approve')->sole());
        $this->get(route('notifications.index'))->assertViewHas('unreadCount', 0)->assertDontSee(__('internal.workflow.BONUS.SUBMIT'));
        $this->get(route('notifications.open', $notice->id))->assertNotFound();
        $this->post(route('notifications.read', $notice->id))->assertNotFound();
        $delegate->roles()->sole()->permissions()->attach(Permission::where('code', 'captain_bonuses.approve')->sole());
        $delegate->branches()->detach($c['branch']);
        $this->get(route('notifications.index'))->assertViewHas('unreadCount', 0);
        $this->get(route('notifications.open', $notice->id))->assertNotFound();
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_failed_after_commit_delivery_leaves_durable_pending_event_and_retry_is_idempotent(): void
    {
        $c = $this->benefitContext();
        DB::unprepared("CREATE FUNCTION test_reject_notice() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'simulated notification storage failure'; END $$;
            CREATE TRIGGER test_reject_notice BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION test_reject_notice()");

        $review = $this->benefitAction($c, 'SUBMIT');

        $this->assertSame('PENDING', app(CaptainBenefitReviewService::class)->summary($c['proposal'])['state']);
        $this->assertNull(DB::table('captain_benefit_notification_events')->where('review_id', $review->id)->value('delivered_at'));
        $this->assertDatabaseCount('notifications', 0);
        DB::unprepared('DROP TRIGGER test_reject_notice ON notifications; DROP FUNCTION test_reject_notice()');
        $this->artisan('abshir:deliver-benefit-notifications')->assertSuccessful();
        $this->artisan('abshir:deliver-benefit-notifications')->assertSuccessful();
        $this->assertNotNull(DB::table('captain_benefit_notification_events')->where('review_id', $review->id)->value('delivered_at'));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_recipient_is_reauthorized_after_commit_and_localization_keys_match(): void
    {
        $c = $this->benefitContext();
        DB::beginTransaction();
        $this->benefitAction($c, 'SUBMIT');
        $c['reviewer']->forceFill(['is_active' => false])->save();
        DB::commit();

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('captain_benefit_reviews', 1);
        $this->assertSame(array_keys(Arr::dot(require lang_path('ar/internal.php'))), array_keys(Arr::dot(require lang_path('en/internal.php'))));
    }

    public function test_custom_reviewer_inbox_does_not_expand_existing_manual_notice_access(): void
    {
        $c = $this->benefitContext();
        app(InternalNoticeService::class)->send($c['creator'], [
            'request_key' => (string) Str::uuid(), 'title' => 'Original manual staff notice', 'body' => 'Private staff content',
            'recipient_ids' => [$c['employee']->id], 'branch_id' => $c['branch']->id,
        ]);
        $notice = $c['employee']->notifications()->sole();
        $this->actingAs($c['employee'])->get(route('notifications.index'))->assertSee('Original manual staff notice');

        $custom = $this->delegate($c, ['captain_bonuses.approve']);
        $c['employee']->roles()->sync($custom->roles()->pluck('roles.id'));
        $this->benefitAction($c, 'SUBMIT');

        $this->get(route('notifications.index'))->assertDontSee('Original manual staff notice')
            ->assertSee(__('internal.workflow.BONUS.SUBMIT'))->assertViewHas('unreadCount', 1);
        $this->get(route('notifications.open', $notice->id))->assertNotFound();
        $this->post(route('notifications.read', $notice->id))->assertNotFound();
        $this->assertTrue($notice->fresh()->unread());
    }

    private function delegate(array $c, array $permissions, ?Branch $branch = null): User
    {
        $user = User::factory()->create();
        $role = Role::create(['code' => 'NOTICE_'.str_replace('-', '', (string) Str::uuid()), 'name' => 'Scoped reviewer']);
        $role->permissions()->attach(Permission::whereIn('code', ['captains.view', ...$permissions])->pluck('id'));
        $user->roles()->attach($role);
        $user->branches()->attach($branch ?? $c['branch']);

        return $user;
    }
}
