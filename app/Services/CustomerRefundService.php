<?php

namespace App\Services;

use App\Enums\InvoiceCollectionMethod;
use App\Enums\InvoiceFinancialCorrectionState;
use App\Models\CustomerRefund;
use App\Models\InvoiceCollection;
use App\Models\InvoiceFinancialCorrection;
use App\Models\Order;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerRefundService
{
    public function __construct(private readonly CustomerRefundEvidenceService $evidence) {}

    public function record(User $actor, InvoiceFinancialCorrection $source, string $requestKey, mixed $refundedAt, string $reference, UploadedFile $file): CustomerRefund
    {
        Gate::forUser($actor)->authorize('customer_refunds.record');
        if (! Str::isUuid($requestKey) || trim($reference) === '') {
            throw ValidationException::withMessages(['refund' => 'A request key and payment reference are required.']);
        }
        $metadata = $this->evidence->inspect($file);
        $when = CarbonImmutable::parse($refundedAt)->utc();
        if ($when->isFuture()) {
            throw ValidationException::withMessages(['refunded_at' => 'Refund time cannot be in the future.']);
        }
        $path = null;
        $refund = null;
        $committed = false;
        try {
            $refund = DB::transaction(function () use ($actor, $source, $requestKey, $when, $reference, $file, $metadata, &$path): CustomerRefund {
                Order::query()->lockForUpdate()->findOrFail($source->order_id);
                $correction = InvoiceFinancialCorrection::query()->lockForUpdate()->findOrFail($source->id);
                $collection = InvoiceCollection::query()->lockForUpdate()->findOrFail($correction->original_collection_id);
                if ($correction->customer_delta === null || ! BigDecimal::of($correction->customer_delta)->isLessThan(0)) {
                    throw ValidationException::withMessages(['refund' => 'A prepared downward correction is required.']);
                }
                $amount = (string) BigDecimal::of($correction->customer_delta)->negated()->toScale(4);
                $facts = [
                    'invoice_financial_correction_id' => $correction->id,
                    'original_invoice_id' => $correction->original_invoice_id,
                    'original_collection_id' => $collection->id,
                    'branch_id' => $correction->branch_id,
                    'currency_id' => $correction->currency_id,
                    'amount' => $amount,
                    'method' => $collection->collection_method->value,
                    'refunding_captain_id' => $collection->collection_method === InvoiceCollectionMethod::Cash ? $collection->captain_id : null,
                    'refunding_order_captain_id' => $collection->collection_method === InvoiceCollectionMethod::Cash ? $collection->order_captain_id : null,
                    'authorized_by' => $actor->id,
                    'refunded_at' => $when->format('Y-m-d H:i:s.uP'),
                    'reference' => trim($reference),
                    'evidence_sha256' => $metadata['sha256'],
                ];
                $hash = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
                $existing = CustomerRefund::query()->where('request_key', $requestKey)->orWhere('invoice_financial_correction_id', $correction->id)->first();
                if ($existing) {
                    if ($existing->request_key !== $requestKey || ! hash_equals($existing->payload_hash, $hash)) {
                        throw ValidationException::withMessages(['request_key' => 'Refund identity already has different facts or proof.']);
                    }

                    return $existing;
                }
                if ($when->lessThan($collection->collected_at)) {
                    throw ValidationException::withMessages(['refunded_at' => 'Refund cannot precede original Collection.']);
                }
                if (! in_array($correction->state, [InvoiceFinancialCorrectionState::Prepared, InvoiceFinancialCorrectionState::ReadyToFinalize], true)) {
                    throw ValidationException::withMessages(['refund' => 'A prepared downward correction is required.']);
                }
                $path = $this->evidence->store($file, $metadata);
                $refund = CustomerRefund::query()->create($facts + [
                    'request_key' => $requestKey,
                    'evidence_path' => $path,
                    'evidence_name' => mb_substr($metadata['name'], 0, 255),
                    'evidence_mime' => $metadata['mime'],
                    'evidence_size' => $metadata['size'],
                    'payload_hash' => $hash,
                ]);
                $correction->update(['state' => InvoiceFinancialCorrectionState::ReadyToFinalize]);

                return $refund;
            }, 3);

            $committed = true;

            return $refund;
        } finally {
            if ($path && ! $committed) {
                $this->evidence->delete($path);
            }
        }
    }
}
