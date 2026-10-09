<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Enums\OrderBillPaymentRequestStatus;
use App\Enums\OrderOperationalStatus;
use App\Exceptions\FinancialLedgerException;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderBillPayment;
use App\Models\OrderBillPaymentRequest;
use App\Models\OrderCaptain;
use App\Models\User;
use App\Support\UiLabels;
use Brick\Math\BigDecimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderBillPaymentService
{
    public const DISK = 'order_bill_payments';

    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly BillPaymentFeeRuleService $fees,
        private readonly FinancialAccountService $accounts,
        private readonly FinancialPostingService $posting,
    ) {}

    public function request(User $actor, Order $order, int $currencyId): OrderBillPaymentRequest
    {
        $this->authorizeRecord($actor, $order);

        return DB::transaction(function () use ($actor, $order, $currencyId): OrderBillPaymentRequest {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($problem = app(SharedPurchaseBoundary::class)->financialProblem($order)) {
                throw ValidationException::withMessages(['order' => $problem]);
            }
            app(SharedPurchaseBoundary::class)->assertLegacyFlowAllowedLocked($order);
            if (Schema::hasTable('order_cancellation_intents') && $order->cancellationIntent()->exists()) {
                throw ValidationException::withMessages(['order' => __('domain.bill_payment_is_blocked_by_a_cancellation_intent')]);
            }
            if (in_array($order->operational_status, [OrderOperationalStatus::Delivered, OrderOperationalStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['order' => __('domain.new_additional_service_requests_require_an_open_order')]);
            }
            $currency = Currency::query()->lockForUpdate()->findOrFail($currencyId);
            $requestedAt = now();
            $rule = $currency->is_active ? $this->fees->applicable($currency->id, $requestedAt) : null;
            if (! $rule) {
                throw ValidationException::withMessages(['currency_id' => __('domain.no_active_bill_payment_fee_applies_to_this_currency')]);
            }

            return $order->billPaymentRequests()->create([
                'currency_id' => $currency->id,
                'fee_rule_id' => $rule->id,
                'service_fee' => $rule->fixed_fee,
                'status' => OrderBillPaymentRequestStatus::Requested,
                'requested_by' => $actor->id,
                'requested_at' => $requestedAt,
            ]);
        });
    }

    public function cancel(User $actor, Order $order, OrderBillPaymentRequest $request, string $reason): void
    {
        $this->authorizeRecord($actor, $order);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('domain.a_cancellation_reason_is_required')]);
        }

        DB::transaction(function () use ($actor, $order, $request, $reason): void {
            Order::query()->lockForUpdate()->findOrFail($order->id);
            $request = $order->billPaymentRequests()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== OrderBillPaymentRequestStatus::Requested) {
                throw ValidationException::withMessages(['request' => __('domain.only_a_requested_additional_service_can_be_cancelled')]);
            }
            $request->update(['status' => OrderBillPaymentRequestStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => trim($reason)]);
        });
    }

    public function preview(User $actor, Order $order, OrderBillPaymentRequest $request, string $principal): array
    {
        $this->authorizeRecord($actor, $order);
        Validator::make(['principal_amount' => $principal], ['principal_amount' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/']])->validate();
        $request = $order->billPaymentRequests()->findOrFail($request->id);
        if ($request->status !== OrderBillPaymentRequestStatus::Requested || $order->cancellationIntent()->exists()
            || $order->operational_status === OrderOperationalStatus::Cancelled) {
            throw ValidationException::withMessages(['principal_amount' => __('domain.this_bill_payment_request_has_already_been_resolved')]);
        }
        try {
            $currency = $request->currency;
            $principal = $this->posting->validatePlannedAmount($currency, $principal);
            $fee = $this->fees->calculate($request->feeRule, $currency, $principal);
        } catch (FinancialLedgerException $exception) {
            throw ValidationException::withMessages(['principal_amount' => UiLabels::error($exception->getMessage())]);
        }

        return ['principal' => $principal, 'fee' => $fee, 'total' => (string) BigDecimal::of($principal)->plus($fee)->toScale(4)];
    }

    /** @param array<int, UploadedFile> $images */
    public function complete(User $actor, Order $order, OrderBillPaymentRequest $request, array $data, array $images): OrderBillPayment
    {
        $this->authorizeRecord($actor, $order);
        Validator::make($data, [
            'order_captain_id' => ['required', 'integer'],
            'currency_id' => ['required', 'integer'],
            'bill_number' => ['required', 'string', 'max:191'],
            'bill_date' => ['required', 'date', 'before_or_equal:today'],
            'provider_name' => ['nullable', 'string', 'max:191'],
            'principal_amount' => ['required', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'payment_reference' => ['required', 'string', 'max:191'],
        ])->validate();
        if ($images === []) {
            throw ValidationException::withMessages(['evidence' => __('domain.successful_sham_cash_payment_evidence_is_required')]);
        }
        if (count($images) > 5) {
            throw ValidationException::withMessages(['evidence' => __('domain.at_most_five_evidence_images_are_allowed')]);
        }
        $extensions = [];
        $mimes = [];
        $digests = [];
        foreach ($images as $image) {
            $mime = $image instanceof UploadedFile && $image->isValid() ? (new \finfo(FILEINFO_MIME_TYPE))->file($image->getRealPath()) : '';
            if (! $image instanceof UploadedFile || ! $image->isValid() || ! isset(self::MIME_EXTENSIONS[$mime]) || ($image->getSize() ?? 0) > self::MAX_FILE_SIZE) {
                throw ValidationException::withMessages(['evidence' => __('domain.evidence_must_be_jpeg_png_or_webp_and_at_most_5_mb_per_image')]);
            }
            $extensions[] = self::MIME_EXTENSIONS[$mime];
            $mimes[] = $mime;
            $digest = hash_file('sha256', $image->getRealPath());
            if (! is_string($digest)) {
                throw ValidationException::withMessages(['evidence' => __('domain.evidence_could_not_be_read_for_integrity_verification')]);
            }
            $digests[] = $digest;
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($actor, $order, $request, $data, $images, $extensions, $mimes, $digests, &$storedPaths): OrderBillPayment {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                app(SharedPurchaseBoundary::class)->assertLegacyFlowAllowedLocked($order);
                $request = $order->billPaymentRequests()->lockForUpdate()->findOrFail($request->id);
                if (Schema::hasTable('order_cancellation_intents') && $order->cancellationIntent()->exists()) {
                    throw ValidationException::withMessages(['order' => __('domain.bill_payment_is_blocked_by_a_cancellation_intent')]);
                }
                if ($request->status !== OrderBillPaymentRequestStatus::Requested) {
                    throw ValidationException::withMessages(['request' => __('domain.this_bill_payment_request_has_already_been_resolved')]);
                }
                if ($order->operational_status !== OrderOperationalStatus::Delivered) {
                    throw ValidationException::withMessages(['order' => __('domain.the_order_must_reach_the_customer_before_bill_payment_completion')]);
                }
                $deliveredAt = $order->statusHistory()->where('new_status', OrderOperationalStatus::Delivered->value)->orderByDesc('changed_at')->first()?->changed_at;
                if (! $deliveredAt) {
                    throw ValidationException::withMessages(['order' => __('domain.the_order_has_no_authoritative_delivery_timestamp')]);
                }
                $assignment = OrderCaptain::query()->where('order_id', $order->id)->whereNull('removed_at')->lockForUpdate()->find($data['order_captain_id']);
                if (! $assignment) {
                    throw ValidationException::withMessages(['order_captain_id' => __('domain.choose_a_participating_captain_for_this_order')]);
                }
                if ($assignment->assigned_at->gt($deliveredAt)) {
                    throw ValidationException::withMessages(['order_captain_id' => __('domain.the_collector_did_not_participate_in_this_delivery')]);
                }
                $currency = Currency::query()->lockForUpdate()->findOrFail($request->currency_id);
                try {
                    $principal = $this->posting->validatePlannedAmount($currency, $data['principal_amount']);
                    $fee = $this->fees->calculate($request->feeRule, $currency, $principal);
                } catch (FinancialLedgerException $exception) {
                    throw ValidationException::withMessages(['principal_amount' => UiLabels::error($exception->getMessage())]);
                }
                if ((int) ($data['currency_id'] ?? 0) !== $currency->id) {
                    throw ValidationException::withMessages(['currency_id' => __('domain.the_currency_must_match_the_request_snapshot')]);
                }
                $total = (string) BigDecimal::of($principal)->plus($fee)->toScale(4);
                $completedAt = now();
                $payment = $order->billPayments()->create([
                    'request_id' => $request->id,
                    'order_captain_id' => $assignment->id,
                    'captain_id' => $assignment->captain_id,
                    'currency_id' => $currency->id,
                    'fee_rule_id' => $request->fee_rule_id,
                    'bill_number' => trim($data['bill_number']),
                    'bill_date' => $data['bill_date'],
                    'provider_name' => filled($data['provider_name'] ?? null) ? trim($data['provider_name']) : null,
                    'principal_amount' => $principal,
                    'service_fee' => $fee,
                    'total_customer_cash' => $total,
                    'delivered_at' => $deliveredAt,
                    'paid_at' => $completedAt,
                    'paid_by' => $actor->id,
                    'payment_reference' => trim($data['payment_reference']),
                ]);
                $handover = $payment->handover()->create([
                    'order_id' => $order->id,
                    'order_captain_id' => $assignment->id,
                    'captain_id' => $assignment->captain_id,
                    'currency_id' => $currency->id,
                    'principal_amount' => $principal,
                    'received_by' => $actor->id,
                    'occurred_at' => $completedAt,
                ]);

                foreach ($images as $index => $image) {
                    $path = $image->storeAs($order->id.'/'.$request->id.'/'.$payment->id, Str::uuid().'.'.$extensions[$index], self::DISK);
                    if (! $path) {
                        throw ValidationException::withMessages(['evidence' => __('domain.evidence_could_not_be_stored')]);
                    }
                    $storedPaths[] = $path;
                    $storedBytes = Storage::disk(self::DISK)->get($path);
                    if (! hash_equals($digests[$index], hash('sha256', $storedBytes))
                        || strlen($storedBytes) !== $image->getSize()
                        || (new \finfo(FILEINFO_MIME_TYPE))->buffer($storedBytes) !== $mimes[$index]) {
                        throw ValidationException::withMessages(['evidence' => __('domain.stored_evidence_failed_its_integrity_check')]);
                    }
                    $payment->evidence()->create([
                        'storage_path' => $path,
                        'sha256' => $digests[$index],
                        'original_filename' => basename(str_replace('\\', '/', $image->getClientOriginalName())),
                        'mime_type' => $mimes[$index],
                        'file_size' => $image->getSize(),
                        'uploaded_by' => $actor->id,
                        'uploaded_at' => $completedAt,
                    ]);
                }

                $owner = $this->accounts->ownerForCaptain($assignment->captain);
                $custody = $this->accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainCashCustody);
                $deposit = $this->accounts->getOrCreate($owner, $currency, FinancialAccountKind::CaptainDeposit);
                $this->posting->postEvent($currency, 'ORDER_BILL_PAYMENT', $payment->id, FinancialEventType::BillPaymentCashCollected->value, 1, $deliveredAt,
                    [['account' => $custody, 'direction' => FinancialEntryDirection::Increase, 'amount' => $principal]], $order->branch, $actor);
                $this->posting->postEvent($currency, 'ORDER_BILL_PAYMENT_PRINCIPAL_HANDOVER', $handover->id, FinancialEventType::BillPaymentPrincipalHandedOver->value, 1, $completedAt,
                    [['account' => $custody, 'direction' => FinancialEntryDirection::Decrease, 'amount' => $principal]], $order->branch, $actor);
                $this->posting->postEvent($currency, 'ORDER_BILL_PAYMENT', $payment->id, FinancialEventType::BillPaymentServiceFeeRetained->value, 1, $completedAt,
                    [['account' => $deposit, 'direction' => FinancialEntryDirection::Decrease, 'amount' => $fee]], $order->branch, $actor);
                $request->update(['status' => OrderBillPaymentRequestStatus::Completed, 'completed_at' => $completedAt]);

                return $payment;
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $exception;
        }
    }

    private function authorizeRecord(User $actor, Order $order): void
    {
        Gate::forUser($actor)->authorize('bill_payments.record');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order->id)->exists(), 403);
    }
}
