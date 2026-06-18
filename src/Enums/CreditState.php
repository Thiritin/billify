<?php

declare(strict_types=1);

namespace Billify\Enums;

enum CreditState: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Applied = 'applied';
    case Void = 'void';
}
