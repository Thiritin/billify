<?php

declare(strict_types=1);

namespace Meteric\Models;

use Carbon\CarbonImmutable;
use Meteric\Enums\LineKind;
use Meteric\Support\Period;

/**
 * A typed, read-only view over one frozen entry of an Order's `contents` cart.
 * Nothing here is persisted on its own — it just makes the jsonb cart pleasant to
 * iterate when converting an order into a subscription.
 */
final class OrderItem
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data) {}

    public function productId(): string
    {
        return (string) $this->data['product_id'];
    }

    public function priceId(): string
    {
        return (string) $this->data['price_id'];
    }

    public function quantity(): float
    {
        return (float) ($this->data['quantity'] ?? 1);
    }

    public function label(): ?string
    {
        return $this->data['label'] ?? null;
    }

    public function group(): ?string
    {
        return $this->data['group'] ?? null;
    }

    public function resourceType(): ?string
    {
        return $this->data['resource_type'] ?? null;
    }

    public function resourceId(): ?string
    {
        return $this->data['resource_id'] ?? null;
    }

    /** Frozen due-now amount for the base line, in minor units. */
    public function amountMinor(): int
    {
        return (int) ($this->data['amount_minor'] ?? 0);
    }

    /** Frozen LineKind of the due-now charge (recurring, prorated, one_off, ...). */
    public function kind(): LineKind
    {
        return LineKind::from((string) $this->data['kind']);
    }

    /** Frozen first-period window for the due-now charge, if any. */
    public function covers(): ?Period
    {
        $covers = $this->data['covers'] ?? null;
        if (! is_array($covers) || count($covers) !== 2) {
            return null;
        }

        return new Period(CarbonImmutable::parse((string) $covers[0]), CarbonImmutable::parse((string) $covers[1]));
    }

    /** @return list<array<string,mixed>> */
    public function addons(): array
    {
        return array_values($this->data['addons'] ?? []);
    }

    /** @return list<array<string,mixed>> */
    public function options(): array
    {
        return array_values($this->data['options'] ?? []);
    }
}
