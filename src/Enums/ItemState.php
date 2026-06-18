<?php

declare(strict_types=1);

namespace Billify\Enums;

enum ItemState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Canceled = 'canceled';
}
