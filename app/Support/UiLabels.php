<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Support\Facades\Lang;

/** Display-only labels; never modifies persisted identifiers or domain enums. */
final class UiLabels
{
    public static function direction(?string $locale = null): string
    {
        return explode('-', str_replace('_', '-', $locale ?? app()->getLocale()))[0] === 'ar' ? 'rtl' : 'ltr';
    }

    public static function label(string $group, BackedEnum|string $value): string
    {
        $identifier = $value instanceof BackedEnum ? (string) $value->value : $value;
        $key = $group.'.'.$identifier;

        return Lang::has($key) ? __($key) : $identifier;
    }

    public static function role(string $code, ?string $customName = null): string
    {
        return Lang::has('admin.roles.'.$code) ? __('admin.roles.'.$code) : ($customName ?? $code);
    }

    public static function permission(string $code, ?string $customName = null): string
    {
        [$group, $action] = array_pad(explode('.', $code, 2), 2, '');
        $actions = __('admin.permission_actions');
        $groups = __('admin.permission_groups');

        return isset($groups[$group], $actions[$action])
            ? $actions[$action].' — '.$groups[$group]
            : ($customName ?? $code);
    }
}
