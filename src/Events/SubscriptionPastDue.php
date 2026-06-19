<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Invoice;
use Meteric\Models\Subscription;

final class SubscriptionPastDue
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Invoice $invoice,
    ) {}
}
