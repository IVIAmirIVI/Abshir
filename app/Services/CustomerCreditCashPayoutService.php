<?php

namespace App\Services;

use App\Enums\FinancialAccountKind;
use App\Enums\FinancialEntryDirection;
use App\Enums\FinancialEventType;
use App\Exceptions\FinancialLedgerException;
use App\Models\Branch;
use App\Models\Captain;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreditNotice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerCreditCashPayoutService
{
    public function __construct(private readonly FinancialPostingService $posting, private readonly CustomerRefundEvidenceService $evidence) {}

    public function record(User $actor, Customer $source, array $data, UploadedFile $proof): object
    {
        Gate::forUser($actor)->authorize('customer_credits.payout');
        app(CustomerCreditService::class)->authorize($actor, $source);
        if (! Schema::hasTable('customer_credit_cash_payouts')) {
            throw ValidationException::withMessages(['credit' => __('credit.payout_blocked')]);
        }
        $data = Validator::make($data, [
            'request_key' => ['required', 'uuid'], 'branch_id' => ['required', 'integer'], 'currency_id' => ['required', 'integer'],
            'captain_id' => ['required', 'integer'], 'amount' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'], 'reference' => ['required', 'string', 'max:191'],
            'occurred_at' => ['required', 'date'], 'personal_cash_confirmed' => ['required', 'accepted'],
        ])->validate();
        $metadata = $this->evidence->inspect($proof);
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $source, $data, $metadata, $proof, &$path): object {
                $customer = Customer::query()->lockForUpdate()->findOrFail($source->id);
                app(CustomerCreditService::class)->authorize($actor, $customer);
                Gate::forUser($actor)->authorize('customer_credits.payout');
                $currency = Currency::query()->lockForUpdate()->findOrFail($data['currency_id']);
                $branch = Branch::findOrFail($data['branch_id']);
                $captain = Captain::findOrFail($data['captain_id']);
                abort_unless($actor->canAccessBranch($branch) && $actor->canAccessCaptain($captain), 403);
                $time = CarbonImmutable::parse($data['occurred_at'], 'Asia/Damascus')->utc();
                $facts = ['customer_id' => $customer->id, 'branch_id' => $branch->id, 'currency_id' => $currency->id,
                    'captain_id' => $captain->id, 'recorded_by' => $actor->id, 'amount' => $this->posting->validatePlannedAmount($currency, $data['amount']),
                    'occurred_at' => $time->format('Y-m-d H:i:s.uP'), 'reason' => trim($data['reason']), 'reference' => trim($data['reference']),
                    'personal_cash_confirmed' => true, 'evidence_sha256' => $metadata['sha256']];
                $hash = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
                if ($existing = DB::table('customer_credit_cash_payouts')->where('request_key', $data['request_key'])->first()) {
                    if (! hash_equals($existing->payload_hash, $hash)) {
                        $this->invalid();
                    }

                    return $existing;
                }
                $grants = DB::table('customer_credit_entries')->where('customer_id', $customer->id)->where('branch_id', $branch->id)->where('currency_id', $currency->id)
                    ->select('*')->selectRaw('amount-customer_credit_grant_spent(id) AS available')->whereRaw('amount>customer_credit_grant_spent(id)')->orderBy('id')->get();
                $total = $grants->reduce(fn (BigDecimal $sum, $grant) => $sum->plus($grant->available), BigDecimal::zero());
                if ($time->isFuture() || ! $total->isEqualTo($facts['amount'])) {
                    $this->invalid();
                }
                $path = $this->evidence->store($proof, $metadata);
                $id = DB::table('customer_credit_cash_payouts')->insertGetId($facts + ['request_key' => $data['request_key'], 'payload_hash' => $hash,
                    'evidence_path' => $path, 'evidence_name' => $metadata['name'], 'evidence_size' => $metadata['size'], 'evidence_mime' => $metadata['mime']]);
                foreach ($grants as $grant) {
                    $parties = $grant->source_return_case_id ? DB::table('customer_return_responsibilities')->where('case_id', $grant->source_return_case_id)
                        ->select('id')->selectRaw('amount-ret_funded_responsibility(id) AS available')->orderBy('id')->get() : collect();
                    $grantId = DB::table('customer_credit_cash_payout_grants')->insertGetId(['payout_id' => $id, 'grant_id' => $grant->id, 'amount' => $grant->available]);
                    foreach ($parties as $party) {
                        if (BigDecimal::of($party->available)->isGreaterThan(0)) {
                            DB::table('customer_credit_cash_payout_attributions')->insert(['payout_grant_id' => $grantId, 'responsibility_id' => $party->id, 'amount' => $party->available]);
                        }
                    }
                }
                $accounts = app(FinancialAccountService::class);
                $account = $accounts->getOrCreate($accounts->ownerForCaptain($captain), $currency, FinancialAccountKind::CaptainCompanyReceivable);
                $this->posting->postEvent($currency, 'CUSTOMER_CREDIT_CASH_PAYOUT', $id, FinancialEventType::CustomerCreditPersonalFundsDue->value, 1, $time,
                    [['account' => $account, 'direction' => FinancialEntryDirection::Increase, 'amount' => $facts['amount']]], $branch, $actor, $facts['reason']);
                DB::select('SELECT assert_credit_cash_payout(?)', [$id]);
                $recipients = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'SUPER_ADMIN'))->get()
                    ->filter(fn ($user) => $user->can('customer_credits.view') && $user->canAccessBranch($branch));
                Notification::sendNow($recipients, new CustomerCreditNotice([
                    'customer_id' => $customer->id, 'branch_id' => $branch->id, 'order_id' => null, 'request_key' => $data['request_key'],
                    'title' => __('credit.cash_paid'), 'body' => __('credit.cash_paid').' · '.$facts['amount'].' '.$currency->code.' · '.$facts['reason'].' · #'.$actor->id.' · Captain #'.$captain->id,
                ]), ['database']);

                return DB::table('customer_credit_cash_payouts')->find($id);
            });
        } catch (FinancialLedgerException) {
            $this->invalid();
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? '', ['23514', '23505', '23503', 'P0002'], true)) {
                $this->invalid();
            }
            throw $exception;
        } finally {
            if ($path && ! DB::table('customer_credit_cash_payouts')->where('evidence_path', $path)->exists()) {
                $this->evidence->delete($path);
            }
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['credit' => __('credit.cash_invalid')]);
    }
}
