<?php

declare(strict_types=1);

namespace Meteric\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Subscription;

/**
 * A future cancellation was scheduled (cancel_at set). The subscription stays
 * active and billing until the boundary, where SubscriptionCanceled fires.
 */
final class SubscriptionCancellationScheduled
{
    use Dispatchable;

    /** @param  array<string,mixed>  $meta */
    public function __construct(
        public readonly Subscription $subscription,
        public readonly CarbonImmutable $at,
        public readonly array $meta = [],
    ) {}
}
