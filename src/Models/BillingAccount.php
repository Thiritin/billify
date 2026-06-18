<?php

declare(strict_types=1);

namespace Billify\Models;

use Billify\Tax\TaxContext;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $currency
 * @property array $tax_profile
 * @property int $balance_minor
 */
class BillingAccount extends BillifyModel
{
    protected $table = 'billify_billing_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tax_profile' => 'array',
            'metadata' => 'array',
            'balance_minor' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'account_id');
    }

    public function creditBalance(): Money
    {
        return Money::ofMinor($this->balance_minor, $this->currency);
    }

    public function applyCredit(Money $amount): void
    {
        $this->increment('balance_minor', $amount->getMinorAmount()->toInt());
    }

    public function taxContext(bool $inclusive = false): TaxContext
    {
        return TaxContext::fromProfile($this->tax_profile ?? [], $inclusive);
    }

    /** Accounts whose charges roll into this payer (self + descendants). */
    public function payerScopeIds(): array
    {
        return [$this->id, ...$this->children()->pluck('id')->all()];
    }
}
