<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Enums\ItemState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Addon extends BillifyModel
{
    protected $table = 'billify_addons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'state' => ItemState::class,
            'metadata' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id');
    }
}
