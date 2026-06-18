<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Casts\PeriodCast;
use Billify\Support\Period;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property float $included_qty
 * @property float $consumed_qty
 * @property ?Period $period
 */
class Allowance extends BillifyModel
{
    protected $table = 'billify_allowances';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'included_qty' => 'float',
            'consumed_qty' => 'float',
            'period' => PeriodCast::class,
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(MeterDimension::class, 'dimension_id');
    }

    public function remaining(): float
    {
        return max(0.0, $this->included_qty - $this->consumed_qty);
    }
}
