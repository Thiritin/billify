<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Casts\PeriodCast;
use Billify\Enums\AnchorMode;
use Billify\Enums\FirstPeriodPolicy;
use Billify\Enums\SubscriptionState;
use Billify\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $currency
 * @property SubscriptionState $state
 * @property AnchorMode $anchor_mode
 * @property ?int $anchor_day
 * @property FirstPeriodPolicy $first_period
 * @property ?Period $current_period
 * @property ?CarbonImmutable $trial_end
 */
class Subscription extends BillifyModel
{
    protected $table = 'billify_subscriptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => SubscriptionState::class,
            'anchor_mode' => AnchorMode::class,
            'anchor_day' => 'integer',
            'first_period' => FirstPeriodPolicy::class,
            'current_period' => PeriodCast::class,
            'trial_end' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'cancel_at' => 'immutable_datetime',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'account_id');
    }

    public function customer(): MorphTo
    {
        return $this->morphTo('customer', 'customer_type', 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class, 'subscription_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'subscription_id');
    }

    public function isBillable(): bool
    {
        return $this->state->isBillable();
    }

    public function isOnTrial(): bool
    {
        return $this->trial_end !== null && $this->trial_end->isFuture();
    }

    /** Earliest item period end = subscription-level renewal moment. */
    public function nextRenewalAt(): ?CarbonImmutable
    {
        return $this->items
            ->map(fn (SubscriptionItem $i) => $i->current_period?->end)
            ->filter()
            ->min();
    }

    public function scopeDueForRenewal($query, CarbonImmutable $at)
    {
        return $query->whereIn('state', ['active', 'trialing', 'past_due'])
            ->whereRaw('upper(current_period) <= ?', [$at]);
    }
}
