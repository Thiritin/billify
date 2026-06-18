<?php

declare(strict_types=1);

namespace Billify\Subscriptions;

use Billify\Models\Invoice;
use Billify\Models\Subscription;

/** Result of a checkout: the created subscription + the invoice billed now. */
final class Checkout
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?Invoice $invoice,
    ) {}
}
