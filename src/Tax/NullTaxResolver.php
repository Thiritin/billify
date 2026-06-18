<?php

declare(strict_types=1);

namespace Billify\Tax;

use Billify\Contracts\TaxResolver;
use Brick\Money\Money;

final class NullTaxResolver implements TaxResolver
{
    public function resolve(Money $net, TaxContext $context): TaxResult
    {
        return TaxResult::none($net->multipliedBy(0), 'No tax');
    }
}
