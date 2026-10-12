<?php

namespace App\Support;

use App\Models\User;

final class StaffInbox
{
    public const ABILITY = 'staff-notifications';

    public static function allowed(User $user): bool
    {
        return $user->is_active && (self::manualAllowed($user) || $user->can('captains.view'));
    }

    public static function manualAllowed(User $user): bool
    {
        return $user->is_active && $user->roles()->whereIn('code', ['SUPER_ADMIN', 'ORDER_EMPLOYEE'])->exists();
    }
}
