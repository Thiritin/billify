<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Order;

/** A pending order passed its expiry without being paid. */
final class OrderExpired
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
