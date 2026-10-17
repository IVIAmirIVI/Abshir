<?php

namespace App\Support;

use App\Models\Captain;
use App\Models\User;

/** Identity-only read access; never grants administrative or mutation permissions. */
class CaptainSelfView
{
    public const ABILITY = 'view-own-captain-information';

    public function resolve(User $actor): ?Captain
    {
        if (! $actor->is_active || ! $actor->roles()->where('code', 'CAPTAIN')->exists()) {
            return null;
        }
        // Use a fresh relationship query, not an eagerly loaded/possibly stale Captain.
        // The unique FK already prevents ambiguity; still fail closed if that ever changes.
        $matches = $actor->captain()->limit(2)->get();
        if ($matches->count() !== 1 || $matches->sole()->user_id !== $actor->id) {
            return null;
        }

        return $matches->sole();
    }
}
