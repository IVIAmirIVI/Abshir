<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SecurityAudit;
use App\Models\User;
use App\Notifications\InternalStaffNotice;
use App\Services\InternalNoticeService;
use App\Services\ManageUser;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SharedPurchaseFixtures;
use Tests\TestCase;

class SecurityAuditAndInternalNoticeTest extends TestCase
{
    use RefreshDatabase, SharedPurchaseFixtures;

    public function test_role_and_branch_assignments_have_one_redacted_audit_and_no_op_is_not_duplicated(): void
    {
        $c = $this->context();
        $target = User::factory()->create();
        $data = ['name' => 'Sensitive name', 'email' => 'sensitive@example.test', 'password' => 'private-password',
            'role_ids' => [$c['employeeRole']->id], 'branch_ids' => [$c['branch']->id]];
        app(ManageUser::class)->save($c['admin'], $target, $data);
        $audit = SecurityAudit::sole();
        $this->assertSame($c['admin']->id, $audit->actor_id);
        $this->assertSame((string) $target->id, $audit->resource_key);
        $this->assertSame('USER_ACCESS_CHANGED', $audit->action);
        $this->assertSame([], $audit->before_values['role_ids']);
        $this->assertSame([$c['employeeRole']->id], $audit->after_values['role_ids']);
        $this->assertSame([$c['branch']->id], $audit->after_values['branch_ids']);
        $encoded = json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR);
        foreach (['private-password', 'sensitive@example.test', 'Sensitive name', 'remember_token'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
        app(ManageUser::class)->save($c['admin'], $target, $data);
        $this->assertDatabaseCount('security_audits', 1);
        app(ManageUser::class)->save($c['admin'], $target, array_replace($data, ['role_ids' => [], 'branch_ids' => []]));
        $latest = SecurityAudit::latest('id')->firstOrFail();
        $this->assertSame([$c['employeeRole']->id], $latest->before_values['role_ids']);
        $this->assertSame([], $latest->after_values['role_ids']);
        $this->assertNoFinancialChange();
    }

    public function test_role_permission_changes_and_status_changes_are_audited_atomically(): void
    {
        $c = $this->context();
        $permission = Permission::where('code', 'orders.view')->sole();
        $this->actingAs($c['admin'])->post(route('admin.roles.store'), ['name' => 'Operator', 'code' => 'AUDITED_OPERATOR',
            'permission_ids' => [$permission->id]])->assertSessionHasNoErrors();
        $role = Role::where('code', 'AUDITED_OPERATOR')->sole();
        $audit = SecurityAudit::sole();
        $this->assertSame('ROLE_CREATED', $audit->action);
        $this->assertSame([$permission->id], $audit->after_values['permission_ids']);
        $this->put(route('admin.roles.update', $role), ['name' => 'Operator', 'code' => 'AUDITED_OPERATOR', 'permission_ids' => []])->assertSessionHasNoErrors();
        $latest = SecurityAudit::latest('id')->firstOrFail();
        $this->assertSame([$permission->id], $latest->before_values['permission_ids']);
        $this->assertSame([], $latest->after_values['permission_ids']);
        $this->patch(route('admin.users.status', $c['employee']), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('security_audits', ['resource_kind' => 'USER', 'resource_key' => (string) $c['employee']->id, 'action' => 'USER_STATUS_CHANGED']);
        $this->patch(route('admin.branches.status', $c['branch']), ['is_active' => false])->assertSessionHasNoErrors();
        $audit = SecurityAudit::latest('id')->firstOrFail();
        $this->assertSame($c['branch']->id, $audit->branch_id);
        $this->assertSame(['is_active' => true], $audit->before_values);
        $this->assertSame(['is_active' => false], $audit->after_values);
        $this->assertNoFinancialChange();
    }

    public function test_unauthorized_changes_and_audit_views_are_denied_without_recording_fake_actions(): void
    {
        $c = $this->context();
        $this->actingAs($c['employee'])->post(route('admin.roles.store'), ['name' => 'Escalate', 'code' => 'ESCALATE', 'permission_ids' => []])->assertForbidden();
        $this->put(route('admin.users.update', $c['admin']), ['name' => 'Tamper', 'email' => 'tamper@example.test', 'role_ids' => [$c['employeeRole']->id]])->assertForbidden();
        $this->get(route('admin.security-audit.index', ['branch_id' => $c['foreignBranch']->id]))->assertForbidden();
        $this->assertDatabaseCount('security_audits', 0);
        $this->assertTrue($c['admin']->fresh()->isSuperAdmin());
    }

    public function test_audit_filters_paginate_and_history_is_immutable_in_postgresql(): void
    {
        $c = $this->context();
        app(ManageUser::class)->setActive($c['admin'], $c['employee'], false);
        $this->actingAs($c['admin'])->get(route('admin.security-audit.index', [
            'actor_id' => $c['admin']->id, 'resource_kind' => 'USER', 'resource_key' => $c['employee']->id,
            'action' => 'USER_STATUS_CHANGED',
        ]))->assertOk()->assertSee(__('internal.actions.USER_STATUS_CHANGED'))->assertViewHas('audits', fn ($rows) => $rows->total() === 1 && $rows->perPage() === 25);
        $audit = SecurityAudit::sole();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('security_audits')->where('id', $audit->id)->update(['actor_id' => $c['employee']->id]));
    }

    public function test_missing_audit_storage_rolls_back_security_changes_instead_of_silently_omitting_history(): void
    {
        $c = $this->context();
        DB::statement('ALTER TABLE security_audits RENAME TO held_security_audits');
        try {
            $this->assertThrows(fn () => app(ManageUser::class)->setActive($c['admin'], $c['employee'], false), ValidationException::class);
            $this->assertTrue($c['employee']->fresh()->is_active);
        } finally {
            DB::statement('ALTER TABLE held_security_audits RENAME TO security_audits');
        }
    }

    public function test_manual_notice_is_internal_only_idempotent_and_its_audit_excludes_message_content(): void
    {
        $c = $this->context();
        $data = $this->noticeData($c);
        $this->actingAs($c['admin'])->post(route('notifications.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('notifications.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('security_audits', 1);
        $audit = SecurityAudit::sole();
        $this->assertSame('NOTICE_SENT', $audit->action);
        $this->assertSame([$c['employee']->id], $audit->after_values['recipient_ids']);
        $this->assertStringNotContainsString($data['title'], json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($data['body'], json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertSame(['database'], (new InternalStaffNotice([]))->via($c['employee']));
        $this->post(route('notifications.store'), array_replace($data, ['body' => 'Different message']))->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNoFinancialChange();
    }

    public function test_own_inbox_unread_and_mark_read_never_leak_other_recipient_or_revoked_branch_data(): void
    {
        $c = $this->context();
        app(InternalNoticeService::class)->send($c['admin'], $this->noticeData($c));
        $notice = $c['employee']->notifications()->sole();
        $this->actingAs($c['employee'])->get(route('notifications.index'))->assertOk()->assertSee('Private branch notice')->assertViewHas('unreadCount', 1);
        $this->actingAs($c['foreign'])->get(route('notifications.index'))->assertOk()->assertDontSee('Private branch notice')->assertViewHas('unreadCount', 0);
        $this->post(route('notifications.read', $notice->id))->assertNotFound();
        $this->actingAs($c['employee'])->post(route('notifications.read', $notice->id))->assertRedirect();
        $firstRead = $notice->fresh()->read_at->format('c');
        $this->post(route('notifications.read', $notice->id))->assertRedirect();
        $this->assertSame($firstRead, $notice->fresh()->read_at->format('c'));
        $this->get(route('notifications.index'))->assertViewHas('unreadCount', 0);
        $c['employee']->branches()->detach($c['branch']);
        $this->get(route('notifications.index'))->assertOk()->assertDontSee('Private branch notice');
        $this->post(route('notifications.read', $notice->id))->assertNotFound();
    }

    public function test_staff_recipient_validation_and_atomic_delivery_prevent_cross_branch_and_captain_notices(): void
    {
        $c = $this->context();
        $service = app(InternalNoticeService::class);
        $this->actingAs($c['employee'])->post(route('notifications.store'), $this->noticeData($c))->assertForbidden();
        $captainUser = User::factory()->create();
        $captainUser->roles()->attach(Role::where('code', 'CAPTAIN')->sole());
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->roles()->attach($c['employeeRole']);
        foreach ([$c['foreign'], $captainUser, $inactive] as $invalid) {
            $data = array_replace($this->noticeData($c), ['recipient_ids' => [$c['employee']->id, $invalid->id]]);
            $this->assertThrows(fn () => $service->send($c['admin'], $data), ValidationException::class);
        }
        $this->actingAs($captainUser)->get(route('notifications.index'))->assertForbidden();
        $this->actingAs($c['admin'])->post(route('notifications.store'), $this->noticeData($c) + ['url' => 'javascript:alert(1)'])->assertSessionHasErrors('notice');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('security_audits', 0);
    }

    public function test_order_link_reauthorizes_on_send_list_count_and_open_without_arbitrary_urls(): void
    {
        $c = $this->sharedContext(2, 1);
        $data = ['request_key' => (string) str()->uuid(), 'title' => 'Permitted Order notice', 'body' => 'Open the authorized workflow',
            'recipient_ids' => [$c['actor']->id], 'order_id' => $c['order']->id];
        $service = app(InternalNoticeService::class);
        $service->send($c['admin'], $data);
        $notice = $c['actor']->notifications()->sole();
        $this->actingAs($c['actor'])->get(route('notifications.open', $notice->id))->assertRedirect(route('orders.workflow', $c['order']));
        $c['actor']->branches()->detach($c['branch']);
        $this->get(route('notifications.open', $notice->id))->assertNotFound();
        $this->get(route('notifications.index'))->assertOk()->assertDontSee('Permitted Order notice')->assertViewHas('unreadCount', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_translations_escape_message_content_and_preserve_english_and_navigation(): void
    {
        $c = $this->context();
        $data = array_replace($this->noticeData($c), ['title' => '<script>unsafe-title</script>', 'body' => '<img src=x onerror=unsafe()>']);
        app(InternalNoticeService::class)->send($c['admin'], $data);
        $this->actingAs($c['employee'])->get(route('notifications.index'))->assertOk()
            ->assertSee('&lt;script&gt;', false)->assertDontSee($data['title'], false)->assertDontSee($data['body'], false);
        $ar = Arr::dot(require lang_path('ar/internal.php'));
        $en = Arr::dot(require lang_path('en/internal.php'));
        $this->assertSame(array_keys($ar), array_keys($en));
        $this->assertSame(array_keys(Arr::dot(require lang_path('ar/admin.php'))), array_keys(Arr::dot(require lang_path('en/admin.php'))));
        app()->setLocale('en');
        $this->get(route('notifications.index'))->assertOk()->assertSee('Internal notifications');
    }

    private function context(): array
    {
        $this->seed(AuthorizationSeeder::class);
        $branch = Branch::factory()->create();
        $foreignBranch = Branch::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->sole());
        $employeeRole = Role::where('code', 'ORDER_EMPLOYEE')->sole();
        $employee = User::factory()->create();
        $employee->roles()->attach($employeeRole);
        $employee->branches()->attach($branch);
        $foreign = User::factory()->create();
        $foreign->roles()->attach($employeeRole);
        $foreign->branches()->attach($foreignBranch);

        return compact('branch', 'foreignBranch', 'admin', 'employeeRole', 'employee', 'foreign');
    }

    private function noticeData(array $c): array
    {
        return ['request_key' => (string) str()->uuid(), 'title' => 'Private branch notice', 'body' => 'Only permitted staff should see this',
            'recipient_ids' => [$c['employee']->id], 'branch_id' => $c['branch']->id];
    }

    private function assertNoFinancialChange(): void
    {
        foreach (['financial_transactions', 'financial_entries', 'captain_receivable_payouts', 'captain_deposit_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
