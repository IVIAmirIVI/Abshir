<?php

namespace App\Services;

use App\Models\SecurityAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SecurityAuditService
{
    public function userValues(User $user): array
    {
        $roles = $user->exists ? $user->roles()->orderBy('roles.id')->pluck('roles.id')->map(fn ($id) => (int) $id)->all() : [];
        $branches = $user->exists ? $user->branches()->orderBy('branches.id')->pluck('branches.id')->map(fn ($id) => (int) $id)->all() : [];

        return $user->exists ? ['role_ids' => $roles, 'branch_ids' => $branches, 'is_active' => $user->is_active] : [];
    }

    public function record(User $actor, string $kind, string|int $key, string $action, array $before, array $after, ?int $branchId = null, ?string $operationKey = null, ?string $hash = null): ?SecurityAudit
    {
        $allowed = match ($kind) {
            'USER' => ['role_ids', 'branch_ids', 'is_active'],
            'ROLE' => ['code', 'permission_ids'],
            'BRANCH' => ['is_active', 'default_currency_id', 'access_granted_user_id'],
            'NOTICE' => ['recipient_ids', 'resource_type', 'resource_id', 'branch_id'],
            default => throw new \InvalidArgumentException('Unsupported security audit resource.'),
        };
        if (array_diff(array_keys($before), $allowed) || array_diff(array_keys($after), $allowed)) {
            throw new \InvalidArgumentException('Security audit payload contains non-permitted values.');
        }
        if ($before === $after) {
            return null;
        }
        if (! Schema::hasTable('security_audits')) {
            throw ValidationException::withMessages(['audit' => __('internal.activation_required')]);
        }
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Security changes and their audit must share one transaction.');
        }

        return SecurityAudit::create([
            'actor_id' => $actor->id, 'resource_kind' => $kind, 'resource_key' => (string) $key,
            'action' => $action, 'branch_id' => $branchId,
            'before_values' => (object) $before, 'after_values' => (object) $after,
            'operation_key' => $operationKey, 'payload_hash' => $hash, 'recorded_at' => now(),
        ]);
    }
}
