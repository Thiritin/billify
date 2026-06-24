<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum PricingModel: string
{
    case Fixed = 'fixed';
    case PerUnit = 'per_unit';
    case Tiered = 'tiered';
    case Volume = 'volume';
    case Metered = 'metered';
    case Hourly = 'hourly';
    case OneOff = 'one_off';
    case Relative = 'relative';   // a percentage of the owning item's base price (addons)

    public function isUsageBased(): bool
    {
        return in_array($this, [self::Metered, self::Hourly], true);
    }
}
