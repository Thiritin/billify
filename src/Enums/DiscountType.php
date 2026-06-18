<?php

declare(strict_types=1);

namespace Billify\Enums;

enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
