<?php

namespace App\Services;

use App\Models\Captain;
use App\Models\CaptainReceivableRecovery;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceCollection;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaptainReceivableRecoveryService
{
    public function __construct(private readonly CaptainReceivableRecoveryEvidenceService $evidence) {}

    public function active(): bool
    {
        return DB::scalar("SELECT to_regprocedure('captain_receivable_recoveries_active()') IS NOT NULL")
            && DB::scalar('SELECT captain_receivable_recoveries_active()');
    }

    public function record(User $actor, InvoiceFinancialCorrection $source, Captain $captain, array $data, UploadedFile $file): CaptainReceivableRecovery
    {
        Gate::forUser($actor)->authorize('captain_receivable_recoveries.record');
        Validator::make($data, ['request_key' => ['required', 'uuid'], 'received_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:191']])->validate();
        if (! $this->active()) {
            throw ValidationException::withMessages(['recovery' => 'Captain recovery deployment is not active.']);
        }
        if (! Str::isUuid($data['request_key'] ?? '') || ($data['method'] ?? 'CASH') !== 'CASH') {
            throw ValidationException::withMessages(['recovery' => 'A UUID request key and CASH method are required.']);
        }
        $metadata = $this->evidence->inspect($file);
        $when = CarbonImmutable::parse($data['received_at'])->utc();
        if ($when->isFuture()) {
            throw ValidationException::withMessages(['received_at' => 'Receipt time cannot be in the future.']);
        }
        $path = null;
        $committed = false;
        try {
            $receipt = DB::transaction(function () use ($actor, $source, $captain, $data, $metadata, $when, $file, &$path): CaptainReceivableRecovery {
                Order::query()->lockForUpdate()->findOrFail($source->order_id);
                Invoice::query()->lockForUpdate()->findOrFail($source->original_invoice_id);
                InvoiceCollection::query()->lockForUpdate()->findOrFail($source->original_collection_id);
                $correction = InvoiceFinancialCorrection::query()->lockForUpdate()->findOrFail($source->id);
                $this->assertReceiverLocked($actor, $correction);
                $rows = $correction->receivableDispositions()->where('captain_id', $captain->id)->where('recovery_required', '>', 0)->orderBy('id')->lockForUpdate()->get();
                $currency = Currency::query()->lockForUpdate()->findOrFail($correction->currency_id);
                if ($rows->isEmpty() || ! $correction->receivable_dispositions_captured || $correction->receivable_contract_version !== 2) {
                    throw ValidationException::withMessages(['recovery' => 'No exact recovery obligation exists for this Captain.']);
                }
                $amount = (string) $rows->reduce(fn (BigDecimal $sum, $d) => $sum->plus($d->recovery_required), BigDecimal::zero());
                foreach (['branch_id' => $correction->branch_id, 'currency_id' => $currency->id, 'received_by_user_id' => $actor->id] as $field => $value) {
                    if (isset($data[$field]) && (string) $data[$field] !== (string) $value) {
                        throw ValidationException::withMessages([$field => 'Recovery source/receiver cannot be reassigned.']);
                    }
                }
                if (isset($data['amount']) && (! is_string($data['amount']) || ! preg_match('/^\d+(?:\.\d+)?$/', $data['amount'])
                    || ! BigDecimal::of($data['amount'])->isEqualTo($amount)
                    || BigDecimal::of($data['amount'])->strippedOfTrailingZeros()->getScale() > $currency->decimal_places)) {
                    throw ValidationException::withMessages(['amount' => 'Only the exact full frozen recovery amount is accepted.']);
                }
                $facts = ['invoice_financial_correction_id' => $correction->id, 'captain_id' => $captain->id, 'branch_id' => $correction->branch_id,
                    'currency_id' => $currency->id, 'amount' => $amount, 'method' => 'CASH', 'received_by_user_id' => $actor->id,
                    'received_at' => $when->format('Y-m-d H:i:s.uP'), 'request_key' => strtolower($data['request_key']),
                    'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                    'evidence_name' => mb_substr($metadata['name'], 0, 255), 'evidence_mime' => $metadata['mime'], 'evidence_size' => $metadata['size'], 'evidence_sha256' => $metadata['sha256']];
                $existing = CaptainReceivableRecovery::query()->where('request_key', $facts['request_key'])
                    ->orWhere(fn ($q) => $q->where('invoice_financial_correction_id', $correction->id)->where('captain_id', $captain->id))->first();
                if ($existing) {
                    $facts['evidence_path'] = $existing->evidence_path;
                    $hash = $this->hash($facts);
                    if ($existing->request_key !== $facts['request_key'] || ! hash_equals($existing->payload_hash, $hash)) {
                        throw ValidationException::withMessages(['request_key' => 'Recovery already exists with different identity or facts.']);
                    }
                    DB::select('SELECT validate_captain_recovery(?)', [$existing->id]);

                    return $existing;
                }
                if ($correction->state->value !== 'READY_TO_FINALIZE' || $when->lessThan($correction->prepared_at)) {
                    throw ValidationException::withMessages(['recovery' => 'A ready correction and receipt time after preparation are required.']);
                }
                DB::select('SELECT validate_recovery_disposition_source(?)', [$correction->id]);
                $path = $this->evidence->store($file, $metadata);
                $facts['evidence_path'] = $path;
                $receiptId = DB::table('captain_receivable_recoveries')->insertGetId($facts + ['payload_hash' => $this->hash($facts)]);
                $receipt = CaptainReceivableRecovery::query()->findOrFail($receiptId);
                foreach ($rows as $d) {
                    $receipt->allocations()->create(['disposition_id' => $d->id, 'amount' => $d->recovery_required]);
                }
                DB::select('SELECT validate_captain_recovery(?)', [$receipt->id]);

                return $receipt;
            });
            $committed = true;

            return $receipt;
        } finally {
            if ($path && ! $committed) {
                $this->evidence->delete($path);
            }
        }
    }

    public function summary(InvoiceFinancialCorrection $correction): array
    {
        if (! $this->active()) {
            return [];
        }
        $correction->loadMissing('recoveries.receiver', 'receivableDispositions');
        $captainNames = Captain::query()->with('user')->whereIn('id', $correction->receivableDispositions->pluck('captain_id'))->get()->keyBy('id');

        return $correction->receivableDispositions->filter(fn ($d) => BigDecimal::of($d->recovery_required)->isGreaterThan(0))
            ->groupBy('captain_id')->map(function ($rows, $captainId) use ($correction, $captainNames): array {
                return ['captain_id' => $captainId, 'captain_name' => $captainNames->get($captainId)?->user?->name, 'amount' => (string) $rows->reduce(fn (BigDecimal $s, $d) => $s->plus($d->recovery_required), BigDecimal::zero()),
                    'dispositions' => $rows, 'receipt' => $correction->recoveries->firstWhere('captain_id', $captainId)];
            })->all();
    }

    private function hash(array $facts): string
    {
        return DB::scalar("SELECT encode(sha256(convert_to(captain_recovery_payload(jsonb_populate_record(NULL::captain_receivable_recoveries,?::jsonb))::text,'UTF8')),'hex')", [json_encode($facts, JSON_THROW_ON_ERROR)]);
    }

    private function assertReceiverLocked(User $actor, InvoiceFinancialCorrection $correction): void
    {
        $receiver = User::query()->sharedLock()->findOrFail($actor->id);
        $role = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $actor->id)->where('r.code', 'ORDER_EMPLOYEE')->sharedLock()->first();
        $branch = DB::table('user_branches')->where('user_id', $actor->id)->where('branch_id', $correction->branch_id)->sharedLock()->first();
        abort_unless($receiver->is_active && $role && $branch, 403, 'The actual receiver must be an Order Employee assigned to the historical Branch.');
    }
}
