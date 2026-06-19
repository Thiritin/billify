<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Subscription;

/** Billing stopped for this subscription. While paused, renew() accrues nothing. */
final class SubscriptionPaused
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
