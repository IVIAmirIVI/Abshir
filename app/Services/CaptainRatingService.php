<?php

namespace App\Services;

use App\Enums\CaptainRatingSource;
use App\Enums\OrderOperationalStatus;
use App\Models\Captain;
use App\Models\CaptainRating;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaptainRatingService
{
    public function recordManual(User $actor, Order $order, Captain $captain, int $rating): CaptainRating
    {
        Gate::forUser($actor)->authorize('captain_ratings.record');
        abort_unless(Order::query()->accessibleTo($actor)->whereKey($order)->exists(), 403);

        return $this->record($order, $captain, $rating, CaptainRatingSource::WhatsAppManual, $actor);
    }

    public function recordCustomerApp(Order $order, Captain $captain, int $rating): CaptainRating
    {
        return $this->record($order, $captain, $rating, CaptainRatingSource::CustomerApp, null);
    }

    private function record(Order $order, Captain $captain, int $rating, CaptainRatingSource $source, ?User $enteredBy): CaptainRating
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => __('domain.rating_must_be_an_integer_from_1_to_5')]);
        }

        return DB::transaction(function () use ($order, $captain, $rating, $source, $enteredBy): CaptainRating {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->operational_status !== OrderOperationalStatus::Delivered) {
                throw ValidationException::withMessages(['order' => __('domain.captain_ratings_may_only_be_recorded_for_delivered_orders')]);
            }
            $assignment = $order->captainAssignments()->where('captain_id', $captain->id)->whereNull('removed_at')->latest('assigned_at')->latest('id')->lockForUpdate()->first();
            if (! $assignment) {
                throw ValidationException::withMessages(['captain_id' => __('domain.only_a_captain_who_participated_in_this_order_may_be_rated')]);
            }
            if ($order->captainRatings()->where('captain_id', $captain->id)->exists()) {
                throw ValidationException::withMessages(['captain_id' => __('domain.this_captain_already_has_a_rating_for_the_order')]);
            }

            try {
                return $order->captainRatings()->create(['captain_id' => $captain->id, 'order_captain_id' => $assignment->id, 'rating' => $rating, 'source' => $source, 'rated_at' => now(), 'entered_by' => $enteredBy?->id]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['captain_id' => __('domain.this_captain_already_has_a_rating_for_the_order')]);
            }
        });
    }
}
