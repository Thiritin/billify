<?php

declare(strict_types=1);

namespace Billify\Enums;

enum CommitmentState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Terminated = 'terminated';
}
