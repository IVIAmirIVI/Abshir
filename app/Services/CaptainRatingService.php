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
            throw ValidationException::withMessages(['rating' => 'Rating must be an integer from 1 to 5.']);
        }

        return DB::transaction(function () use ($order, $captain, $rating, $source, $enteredBy): CaptainRating {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->operational_status !== OrderOperationalStatus::Delivered) {
                throw ValidationException::withMessages(['order' => 'Captain ratings may only be recorded for delivered Orders.']);
            }
            $assignment = $order->captainAssignments()->where('captain_id', $captain->id)->whereNull('removed_at')->latest('assigned_at')->latest('id')->lockForUpdate()->first();
            if (! $assignment) {
                throw ValidationException::withMessages(['captain_id' => 'Only a Captain who participated in this Order may be rated.']);
            }
            if ($order->captainRatings()->where('captain_id', $captain->id)->exists()) {
                throw ValidationException::withMessages(['captain_id' => 'This Captain already has a rating for the Order.']);
            }

            try {
                return $order->captainRatings()->create(['captain_id' => $captain->id, 'order_captain_id' => $assignment->id, 'rating' => $rating, 'source' => $source, 'rated_at' => now(), 'entered_by' => $enteredBy?->id]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['captain_id' => 'This Captain already has a rating for the Order.']);
            }
        });
    }
}
