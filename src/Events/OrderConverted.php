<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Order;
use Meteric\Models\Subscription;

/** A pending order was converted into a real subscription. */
final class OrderConverted
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly Subscription $subscription,
    ) {}
}
