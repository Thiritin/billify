<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Casts\PeriodCast;
use Billify\Enums\CommitmentState;
use Billify\Enums\Interval;
use Billify\Support\Period;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Interval $term_interval
 * @property int $term_count
 * @property CommitmentState $state
 * @property ?Period $term
 */
class Commitment extends BillifyModel
{
    protected $table = 'billify_commitments';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'term_interval' => Interval::class,
            'term_count' => 'integer',
            'upfront_minor' => 'integer',
            'rate_minor' => 'integer',
            'term' => PeriodCast::class,
            'early_term' => 'array',
            'state' => CommitmentState::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    public function isActive(): bool
    {
        return $this->state === CommitmentState::Active;
    }

    public function committedRate(): Money
    {
        return Money::ofMinor($this->rate_minor, $this->currency);
    }
}
