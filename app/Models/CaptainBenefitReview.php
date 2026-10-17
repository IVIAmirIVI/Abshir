<?php

namespace App\Models;

use App\Models\Concerns\HasImmutableFinancialHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaptainBenefitReview extends Model
{
    use HasImmutableFinancialHistory;

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CaptainBenefitRequest::class, 'captain_benefit_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
