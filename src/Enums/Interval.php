<?php

declare(strict_types=1);

namespace Billify\Enums;

use Carbon\CarbonImmutable;

enum Interval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /** Add `count` of this interval to a moment (calendar-aware for month/year). */
    public function add(CarbonImmutable $from, int $count): CarbonImmutable
    {
        return match ($this) {
            self::Day => $from->addDays($count),
            self::Week => $from->addWeeks($count),
            self::Month => $from->addMonthsNoOverflow($count),
            self::Year => $from->addYearsNoOverflow($count),
        };
    }
}
