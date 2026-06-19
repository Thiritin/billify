<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Charge;
use Meteric\Models\Subscription;

final class SubscriptionRenewed
{
    use Dispatchable;

    /** @param  list<Charge>  $charges  charges accrued by this renewal */
    public function __construct(
        public readonly Subscription $subscription,
        public readonly array $charges,
    ) {}
}
