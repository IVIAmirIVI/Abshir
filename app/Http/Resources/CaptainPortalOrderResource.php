<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Explicit self-scoped representation; never serializes an entire Order model. */
class CaptainPortalOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (int) $this->resource->id, 'order_number' => $this->resource->order_number,
            'ordered_at' => CarbonImmutable::parse($this->resource->ordered_at)->utc()->toIso8601String(),
            'status' => $this->resource->operational_status, 'branch_id' => (int) $this->resource->branch_id,
            'shared_participation' => (bool) $this->resource->history_shared,
            'assignment_role' => $this->resource->assignment_role,
            'assignment_removed_at' => $this->resource->removed_at === null ? null : CarbonImmutable::parse($this->resource->removed_at)->utc()->toIso8601String()];
    }
}
