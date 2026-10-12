<?php

namespace App\Services;

use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\CaptainBenefitRequest;
use App\Models\Currency;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaptainBenefitRequestService
{
    public function __construct(private readonly CaptainFinancialEvidenceService $evidence) {}

    public function assertView(User $actor, Captain $captain): void
    {
        Gate::forUser($actor)->authorize('captains.view');
        $historicalAccess = ! $actor->canAccessCaptain($captain) && Schema::hasTable('captain_benefit_requests')
            && CaptainBenefitRequest::where('captain_id', $captain->id)
                ->whereIn('branch_id', $actor->availableBranches()->select('branches.id'))->exists();
        abort_unless($actor->canAccessCaptain($captain) || $historicalAccess, 403);
    }

    public function assertRecord(User $actor, Captain $captain, CaptainBenefitRequest $proposal): void
    {
        $this->assertView($actor, $captain);
        abort_unless($proposal->captain_id === $captain->id, 404);
        abort_unless($actor->canAccessBranch($proposal->branch), 403);
    }

    public function save(User $actor, Captain $captain, array $data, ?UploadedFile $file = null, ?CaptainBenefitRequest $previous = null): CaptainBenefitRequest
    {
        $this->assertView($actor, $captain);
        Gate::forUser($actor)->authorize('captains.update');
        if ($previous) {
            $this->assertRecord($actor, $captain, $previous);
        }
        if (! Schema::hasTable('captain_benefit_requests')) {
            throw ValidationException::withMessages(['proposal' => __('captain_requests.activation_required')]);
        }
        $allowed = ['request_key', 'amount', 'description', 'category', 'expense_date', 'revision_note', 'expected_version'];
        if (! $previous) {
            $allowed = [...$allowed, 'branch_id', 'currency_id', 'order_id', 'kind'];
        }
        if (array_diff(array_keys($data), $allowed)) {
            throw ValidationException::withMessages(['proposal' => __('captain_requests.invalid_fields')]);
        }
        $data = array_replace($data, $previous ? $previous->only(['branch_id', 'currency_id', 'order_id', 'kind']) : []);
        $data['description'] = is_string($data['description'] ?? null) ? trim($data['description']) : ($data['description'] ?? null);
        $data = Validator::make($data, [
            'request_key' => ['required', 'uuid'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'kind' => ['required', 'in:BONUS,EXPENSE'],
            'amount' => ['required', 'string', 'regex:/^[0-9]{1,60}(?:\.[0-9]{1,4})?$/D'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ($data['kind'] ?? null) === 'EXPENSE' ? ['required', 'in:TRANSPORT,COMMUNICATION,EQUIPMENT,OTHER'] : ['prohibited'],
            'expense_date' => ($data['kind'] ?? null) === 'EXPENSE' ? ['required', 'date_format:Y-m-d', 'before_or_equal:today'] : ['prohibited'],
            'expected_version' => $previous ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'revision_note' => $previous ? ['required', 'string', 'max:2000'] : ['prohibited'],
        ])->validate();
        $metadata = $file ? $this->evidence->inspect($file) : null;
        $path = null;
        $saved = null;
        try {
            $saved = DB::transaction(function () use ($actor, $captain, $data, $file, $metadata, $previous, &$path): CaptainBenefitRequest {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['captain-request:'.$data['request_key']]);
                $branch = Branch::query()->lockForUpdate()->findOrFail($data['branch_id']);
                $order = filled($data['order_id'] ?? null) ? Order::query()->lockForUpdate()->findOrFail($data['order_id']) : null;
                $captain = Captain::query()->lockForUpdate()->findOrFail($captain->id);
                $this->assertView($actor, $captain);
                abort_unless($actor->canAccessBranch($branch), 403);
                if (! $previous) {
                    abort_unless($captain->activeOperationalBranches()->contains('id', $branch->id), 403);
                }
                $currency = Currency::query()->lockForUpdate()->findOrFail($data['currency_id']);
                if (! $currency->is_active) {
                    throw ValidationException::withMessages(['currency_id' => __('captain_requests.inactive_currency')]);
                }
                if ($order) {
                    Gate::forUser($actor)->authorize('orders.view');
                    abort_unless($order->branch_id === $branch->id && $actor->canAccessBranch($order->branch), 403);
                    abort_unless($order->captainAssignments()->where('captain_id', $captain->id)->exists(), 403);
                }
                try {
                    $amount = app(FinancialPostingService::class)->validatePlannedAmount($currency, $data['amount']);
                } catch (FinancialLedgerException) {
                    throw ValidationException::withMessages(['amount' => __('captain_requests.invalid_amount')]);
                }
                $facts = ['captain_id' => $captain->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                    'order_id' => filled($data['order_id'] ?? null) ? (int) $data['order_id'] : null,
                    'kind' => $data['kind'], 'amount' => $amount, 'description' => $data['description'],
                    'category' => $data['kind'] === 'EXPENSE' ? $data['category'] : null,
                    'expense_date' => $data['kind'] === 'EXPENSE' ? $data['expense_date'] : null,
                    'revision_note' => $previous ? trim($data['revision_note']) : null];
                $hash = hash('sha256', json_encode([$facts, $actor->id, $previous?->id, (int) ($data['expected_version'] ?? 0), $metadata['sha256'] ?? null], JSON_THROW_ON_ERROR));
                $existing = CaptainBenefitRequest::where('request_key', $data['request_key'])->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw ValidationException::withMessages(['request_key' => __('captain_requests.conflict')]);
                    }

                    return $existing;
                }
                if ($previous) {
                    $previous = CaptainBenefitRequest::query()->lockForUpdate()->findOrFail($previous->id);
                    if (Schema::hasTable('captain_benefit_reviews') && $previous->reviews()->exists()) {
                        throw ValidationException::withMessages(['proposal' => __('captain_requests.reviewed_frozen')]);
                    }
                    if ($previous->version !== (int) $data['expected_version'] || $previous->nextVersion()->exists()) {
                        throw ValidationException::withMessages(['expected_version' => __('captain_requests.stale')]);
                    }
                }
                $evidence = $previous ? $previous->only(['evidence_path', 'evidence_name', 'evidence_mime', 'evidence_size', 'evidence_sha256']) : [];
                if ($file) {
                    $path = $this->evidence->store($file, $metadata);
                    $evidence = ['evidence_path' => $path, 'evidence_name' => mb_substr($metadata['name'], 0, 255),
                        'evidence_mime' => $metadata['mime'], 'evidence_size' => $metadata['size'], 'evidence_sha256' => $metadata['sha256']];
                }

                return CaptainBenefitRequest::create($facts + $evidence + [
                    'request_key' => $data['request_key'], 'request_hash' => $hash,
                    'proposal_key' => $previous?->proposal_key ?? (string) Str::uuid(),
                    'version' => ($previous?->version ?? 0) + 1, 'replaces_id' => $previous?->id,
                    'created_by_user_id' => $previous?->created_by_user_id ?? $actor->id,
                    'recorded_by_user_id' => $actor->id,
                    'created_at' => $previous?->created_at ?? now(), 'recorded_at' => now(),
                ]);
            });

            return $saved;
        } finally {
            if ($path && (! $saved || $saved->evidence_path !== $path)) {
                $this->evidence->delete($path);
            }
        }
    }
}
