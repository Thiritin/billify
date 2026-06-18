<?php

declare(strict_types=1);

namespace Billify\Enums;

enum Aggregation: string
{
    case Sum = 'sum';
    case Max = 'max';
    case Last = 'last';
}
