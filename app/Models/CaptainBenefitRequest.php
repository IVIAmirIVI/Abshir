<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['request_key', 'request_hash', 'proposal_key', 'version', 'replaces_id', 'captain_id', 'branch_id', 'currency_id', 'order_id', 'kind', 'amount', 'description', 'category', 'expense_date', 'revision_note', 'created_by_user_id', 'recorded_by_user_id', 'created_at', 'recorded_at', 'evidence_path', 'evidence_name', 'evidence_mime', 'evidence_size', 'evidence_sha256'])]
class CaptainBenefitRequest extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime', 'expense_date' => 'immutable_date'];
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(Captain::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function nextVersion(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CaptainBenefitReview::class);
    }
}
