<?php

declare(strict_types=1);

namespace Billify\Pricing;

use Billify\Support\Period;

/** Ambient inputs a pricing strategy may need beyond the price + quantity. */
final class PricingContext
{
    public function __construct(
        public readonly string $currency,
        public readonly ?Period $period = null,
        public readonly float $includedAllowance = 0.0,
    ) {}
}
