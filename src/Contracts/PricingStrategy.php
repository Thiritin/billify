<?php

declare(strict_types=1);

namespace Billify\Contracts;

use Billify\Models\Price;
use Billify\Pricing\PricingContext;
use Brick\Money\Money;

/**
 * Strategy for turning a quantity + price into a Money amount.
 * One implementation per PricingModel enum case.
 */
interface PricingStrategy
{
    public function price(float $quantity, Price $price, PricingContext $context): Money;
}
