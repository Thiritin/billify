<?php

declare(strict_types=1);

namespace Billify\Contracts;

use Billify\Tax\TaxContext;
use Billify\Tax\TaxResult;
use Brick\Money\Money;

/**
 * Resolves tax for a single net amount in a given context. Swappable driver;
 * Billify ships EuVatResolver (default), FlatRateTaxResolver, NullTaxResolver.
 */
interface TaxResolver
{
    public function resolve(Money $net, TaxContext $context): TaxResult;
}
