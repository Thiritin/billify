<?php

declare(strict_types=1);

namespace Billify\Contracts;

use Billify\Enums\PricingModel;
use Billify\Models\Price;

/**
 * Implemented by a host-app plan (VpsPlan, Tld, WebHostingPlan, …) so it can be
 * attached to a Billify Product as the morph target.
 */
interface Billable
{
    public function pricingModel(): PricingModel;

    public function defaultPrice(string $currency): Price;

    public function isProratable(): bool;
}
