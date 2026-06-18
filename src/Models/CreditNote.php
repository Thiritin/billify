<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Enums\CreditState;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CreditState $state
 * @property int $amount_minor
 * @property int $tax_minor
 * @property string $currency
 */
class CreditNote extends BillifyModel
{
    protected $table = 'billify_credit_notes';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => CreditState::class,
            'amount_minor' => 'integer',
            'tax_minor' => 'integer',
            'issued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
