<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Casts\PeriodCast;
use Billify\Support\Period;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger of fully-billed windows. The GiST EXCLUDE constraint on this table is
 * the DB-level guarantee that no window is ever billed twice per item+dimension.
 *
 * @property ?Period $covers
 */
class BillingPeriod extends BillifyModel
{
    protected $table = 'billify_billing_periods';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'covers' => PeriodCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }
}
