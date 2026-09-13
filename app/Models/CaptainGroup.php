<?php

namespace App\Models;

use Database\Factories\CaptainGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'description'])]
class CaptainGroup extends Model
{
    /** @use HasFactory<CaptainGroupFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CaptainGroupMember::class);
    }
}
