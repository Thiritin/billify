<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Invoice;
use Meteric\Models\Order;
use Meteric\Models\Subscription;

/**
 * A subscription was materialized from a paid order. Listen here to provision the
 * resources the order described (the frozen contents map to the new items).
 */
final class SubscriptionStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly Subscription $subscription,
        public readonly ?Invoice $invoice,
    ) {}
}
