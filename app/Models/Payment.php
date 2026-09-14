<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['user_id', 'subscription_id', 'amount', 'currency', 'status', 'external_id', 'provider', 'payload'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * Apply the payment filters (validated by the admin payments index
     * request, passed as-is).
     *
     * Date semantics: `dateFrom` includes the whole starting day
     * (`>= dateFrom 00:00:00`) and `dateTo` includes the whole ending day
     * (`<= dateTo 23:59:59`), so a bare `Y-m-d` date does not cut off
     * payments written later that day. `whereDate()` is deliberately avoided:
     * it wraps `created_at` in a function and defeats the index on it.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeFiltered(
        Builder $query,
        ?PaymentStatus $status = null,
        ?string $email = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): Builder {
        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if ($email !== null) {
            $query->whereHas('user', function (Builder $user) use ($email): void {
                // `whereHas` types the closure parameter as the generic
                // model builder; `searchByEmail` is a User scope, so the
                // generic is narrowed for PHPStan.
                /** @var Builder<User> $user */
                $user->searchByEmail($email);
            });
        }

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        return $query;
    }

    /**
     * The user that made the payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The subscription this payment was made for (NULL for standalone
     * payments, e.g. a failed attempt that never created one).
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'payload' => 'array',
            'amount' => 'integer',
        ];
    }
}
