<?php

declare(strict_types=1);

namespace Meteric\Pricing;

/**
 * The frozen result of pricing a checkout: the cart contents (with minor amounts
 * baked into every item/addon/option), the due-now totals, the ongoing recurring
 * total, and the read-only quote snapshot. Persisted verbatim onto an Order.
 */
final class PricedCheckout
{
    /** @param list<array<string,mixed>> $contents */
    public function __construct(
        public readonly array $contents,
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
        public readonly int $recurringTotalMinor,
        public readonly array $quoteSnapshot,
    ) {}
}
