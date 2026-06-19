<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Subscription;

/** Billing resumed for this subscription. Start the resource in your provisioner. */
final class SubscriptionResumed
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
