<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Enums\OptionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemOption extends BillifyModel
{
    protected $table = 'billify_item_options';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => OptionType::class,
            'quantity' => 'float',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id');
    }

    public function boolValue(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }
}
