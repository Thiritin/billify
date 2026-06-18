<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Casts\MoneyCast;
use Billify\Casts\PeriodCast;
use Billify\Enums\LineKind;
use Billify\Support\Period;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property LineKind $kind
 * @property Money $amount
 * @property float $tax_rate
 * @property ?Period $covers
 */
class InvoiceLine extends BillifyModel
{
    protected $table = 'billify_invoice_lines';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => LineKind::class,
            'quantity' => 'float',
            'unit_minor' => 'integer',
            'unit_rate' => 'string',
            'amount_minor' => 'integer',
            'amount' => MoneyCast::class.':amount_minor,currency',
            'tax_rate' => 'float',
            'tax_minor' => 'integer',
            'covers' => PeriodCast::class,
            'sort' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }

    public function gross(): Money
    {
        return Money::ofMinor($this->amount_minor + $this->tax_minor, $this->currency);
    }
}
