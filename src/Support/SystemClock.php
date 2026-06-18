<?php

declare(strict_types=1);

namespace Billify\Support;

use Billify\Contracts\Clock;
use Carbon\CarbonImmutable;

final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
