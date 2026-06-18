<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Enums\ItemState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property float $quantity
 * @property ItemState $state
 * @property ?string $group_key
 * @property array $metadata
 */
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

    /** @return BelongsTo<SubscriptionItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class, 'item_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<Price, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'price_id');
    }
}
