<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'tier', 'status', 'starts_at', 'ends_at', 'cancelled_at', 'external_id', 'provider'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /**
     * Subscriptions whose paid access is in force right now: `Active`, or
     * `Cancelled` while the paid period lasts (cancelling stops the
     * renewal, not the access). `ends_at` is strictly in the future; a
     * NULL `ends_at`, e.g. on a `Pending` row, never matches — hence the
     * explicit `whereNotNull`.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Cancelled->value,
            ])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now());
    }

    /**
     * The user that owns the subscription.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Payments made for this subscription.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => SubscriptionTier::class,
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
