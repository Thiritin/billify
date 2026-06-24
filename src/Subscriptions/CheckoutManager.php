<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Meteric\Anchoring\PeriodPlanner;
use Meteric\Contracts\Clock;
use Meteric\Enums\ChargeState;
use Meteric\Enums\CheckoutState;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Enums\SubscriptionState;
use Meteric\Events\OrderCanceled;
use Meteric\Events\OrderConverted;
use Meteric\Events\OrderExpired;
use Meteric\Meteric;
use Meteric\Models\Addon;
use Meteric\Models\BillingAccount;
use Meteric\Models\BillingPeriod;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\ItemOption;
use Meteric\Models\Order;
use Meteric\Models\OrderItem;
use Meteric\Models\Price;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

/**
 * Settles a pending Order: convert it into a real Subscription with frozen
 * pending Charges, pay it (invoice + record payment), or cancel/expire it.
 *
 * Conversion replays the order's frozen `contents`: each entry's `amount_minor`
 * + `kind` are taken as-is (immutable), and only the service period is recomputed
 * at the actual payment date so the window is correct. A referenced price that no
 * longer exists is a hard error — the order cannot be honoured.
 */
final class CheckoutManager
{
    public function __construct(private Clock $clock) {}

    /**
     * Convert a pending order into a Subscription with its frozen pending charges.
     * Idempotent: a converted order returns its existing subscription.
     */
    public function convert(Order $order, ?CarbonImmutable $at = null): Subscription
    {
        $at ??= $this->clock->now();

        if (! $order->state->canConvert()) {
            if ($order->subscription_id !== null) {
                return Subscription::findOrFail($order->subscription_id);
            }
            throw new \LogicException("Order {$order->id} is {$order->state->value} and cannot convert.");
        }

        return DB::transaction(function () use ($order, $at): Subscription {
            $trialEnd = $order->trial_days > 0 ? $at->addDays($order->trial_days) : null;
            $signup = $trialEnd ?? $at;

            $sub = Subscription::create([
                'account_id' => $order->account_id,
                'customer_type' => $order->customer_type,
                'customer_id' => $order->customer_id,
                'currency' => $order->currency,
                'state' => $trialEnd ? SubscriptionState::Trialing : SubscriptionState::Active,
                'anchor_mode' => $order->anchor_mode,
                'anchor_day' => $order->anchor_day,
                'first_period' => $order->first_period,
                'trial_end' => $trialEnd,
            ]);

            $ends = [];
            foreach ($order->lines() as $line) {
                $ends[] = $this->materialize($order, $sub, $line, $at, $signup, (bool) $trialEnd);
            }

            $end = $ends === [] ? $at->addSecond() : min($ends);
            $sub->forceFill(['current_period' => new Period($at, $end)])->save();

            $order->forceFill([
                'state' => CheckoutState::Converted,
                'subscription_id' => $sub->id,
                'converted_at' => $at,
            ])->save();

            OrderConverted::dispatch($order->refresh(), $sub);

            return $sub->refresh();
        });
    }

    /**
     * Convert and bill in one step: materialize the subscription, issue the frozen
     * pending charges onto an invoice, and record full payment. Returns the paid
     * invoice. Use this when you have collected the money (gateway success).
     */
    public function pay(Order $order, ?CarbonImmutable $at = null): ?Invoice
    {
        $at ??= $this->clock->now();
        $this->convert($order, $at);
        $order->refresh();

        $account = BillingAccount::findOrFail($order->account_id);
        $invoice = app(Meteric::class)->invoicePending($account, $order->currency);

        if ($invoice !== null) {
            app(Meteric::class)->recordPayment($invoice, $invoice->total(), 'order:'.$order->id);
            $order->forceFill(['invoice_id' => $invoice->id, 'paid_at' => $at])->save();
        } else {
            // Free / fully-trial order: nothing to bill, but it is paid (settled).
            $order->forceFill(['paid_at' => $at])->save();
        }

        return $invoice;
    }

    /** Cancel a pending order (buyer abandoned / merchant voided). No-op if terminal. */
    public function cancel(Order $order, ?CarbonImmutable $at = null): Order
    {
        $at ??= $this->clock->now();

        if (! $order->state->canConvert()) {
            return $order;
        }

        $order->forceFill(['state' => CheckoutState::Canceled, 'canceled_at' => $at])->save();
        OrderCanceled::dispatch($order);

        return $order;
    }

    /**
     * Expire pending orders past their expires_at. Idempotent — the partial index
     * only matches pending rows, so a re-run skips already-expired orders.
     * Returns the count expired.
     */
    public function expireDue(?CarbonImmutable $at = null): int
    {
        $at ??= $this->clock->now();
        $count = 0;

        Order::query()
            ->where('state', CheckoutState::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at)
            ->each(function (Order $order) use ($at, &$count): void {
                $order->forceFill(['state' => CheckoutState::Expired, 'canceled_at' => $at])->save();
                OrderExpired::dispatch($order);
                $count++;
            });

        return $count;
    }

    /**
     * Materialize one frozen content line into a SubscriptionItem (+ its addons,
     * options, and pending charges). Returns the item's period end (renewal moment).
     */
    private function materialize(Order $order, Subscription $sub, OrderItem $line, CarbonImmutable $at, CarbonImmutable $signup, bool $trialing): CarbonImmutable
    {
        $price = $this->resolvePrice($line->priceId());

        // Recompute the service period at the actual payment date; amount stays frozen.
        $covers = $this->itemPeriod($price, $order, $signup);

        $item = SubscriptionItem::create([
            'subscription_id' => $sub->id,
            'product_id' => $line->productId(),
            'price_id' => $price->id,
            'resource_type' => $line->resourceType(),
            'resource_id' => $line->resourceId(),
            'label' => $line->label(),
            'group' => $line->group(),
            'quantity' => $line->quantity(),
            'state' => ItemState::Active,
            'activated_at' => $signup,
            'current_period' => $covers,
        ]);
        $item->setRelation('subscription', $sub);
        $item->setRelation('price', $price);

        // Base due-now charge (frozen amount). Skipped for trial / zero / usage.
        if (! $trialing && $line->amountMinor() !== 0) {
            $this->charge($sub, $item, 'subscription_item', $item->id, $line->kind(), $line->amountMinor(), $item->lineTitle(), $covers);
        }

        // Reserve the first window so a renewal sweep doesn't re-bill it.
        if ($price->isRecurring() && $covers !== null && ! $trialing) {
            $this->reserve($item, $covers);
        }

        foreach ($line->addons() as $addon) {
            $this->materializeAddon($sub, $item, $addon, $covers, $trialing);
        }

        foreach ($line->options() as $option) {
            $this->materializeOption($sub, $item, $option, $covers, $trialing);
        }

        return $covers?->end ?? $signup->addSecond();
    }

    /** @param array<string,mixed> $addon */
    private function materializeAddon(Subscription $sub, SubscriptionItem $item, array $addon, ?Period $covers, bool $trialing): void
    {
        $price = $this->resolvePrice((string) $addon['price_id']);

        $row = Addon::create([
            'item_id' => $item->id,
            'product_id' => $addon['product_id'],
            'price_id' => $price->id,
            'group_key' => $addon['group_key'] ?? null,
            'quantity' => (float) ($addon['quantity'] ?? 1),
            'state' => ItemState::Active,
        ]);

        $amount = (int) ($addon['amount_minor'] ?? 0);
        if (! $trialing && $amount !== 0) {
            $desc = $price->isRelative() ? $price->percentLabel().'% of '.($item->product->name ?? 'plan') : ($price->product->name ?? 'Addon');
            $this->charge($sub, $item, 'addon', $row->id, LineKind::Addon, $amount, $desc, $covers);
        }
    }

    /** @param array<string,mixed> $option */
    private function materializeOption(Subscription $sub, SubscriptionItem $item, array $option, ?Period $covers, bool $trialing): void
    {
        $price = isset($option['price_id']) && $option['price_id'] !== null ? $this->resolvePrice((string) $option['price_id']) : null;

        $row = ItemOption::create([
            'item_id' => $item->id,
            'key' => $option['key'],
            'type' => $option['type'],
            'value' => $option['value'],
            'price_id' => $price?->id,
            'quantity' => (float) ($option['quantity'] ?? 1),
            'min_qty' => $option['min_qty'] ?? null,
            'max_qty' => $option['max_qty'] ?? null,
        ]);

        // Setup fee always bills now (even on trial); the recurring part defers on trial.
        $setup = (int) ($option['setup_minor'] ?? 0);
        if ($setup !== 0) {
            $this->charge($sub, $item, 'item_option', $row->id, LineKind::Setup, $setup, ucfirst((string) $option['key']).' setup', null);
        }

        $amount = (int) ($option['amount_minor'] ?? 0);
        if (! $trialing && $amount !== 0) {
            $this->charge($sub, $item, 'item_option', $row->id, LineKind::Option, $amount, ucfirst((string) $option['key']), $covers);
        }
    }

    /** The recurring item's first-period window, recomputed at the payment date. */
    private function itemPeriod(Price $price, Order $order, CarbonImmutable $signup): ?Period
    {
        if ($price->pricing_model->isUsageBased()) {
            return null;
        }
        if (! $price->isRecurring()) {
            return new Period($signup, $signup->addSecond());
        }

        return app(PeriodPlanner::class)
            ->plan($signup, $price->recurrence(), $order->anchor_mode, $order->anchor_day, $order->first_period)
            ->ongoing;
    }

    private function resolvePrice(string $priceId): Price
    {
        $price = Price::find($priceId);
        if ($price === null) {
            throw new \RuntimeException("Order references price {$priceId} which no longer exists; cannot convert.");
        }

        return $price;
    }

    private function reserve(SubscriptionItem $item, Period $period): void
    {
        $overlaps = BillingPeriod::query()
            ->where('item_id', $item->id)
            ->whereNull('dimension_id')
            ->whereRaw('covers && ?::tstzrange', [$period->toRange()])
            ->exists();

        if (! $overlaps) {
            BillingPeriod::create(['item_id' => $item->id, 'covers' => $period]);
        }
    }

    private function charge(Subscription $sub, SubscriptionItem $item, string $originType, string $originId, LineKind $kind, int $amountMinor, string $desc, ?Period $covers): void
    {
        Charge::create([
            'account_id' => $sub->account_id,
            'subscription_id' => $sub->id,
            'origin_type' => $originType,
            'origin_id' => $originId,
            'kind' => $kind,
            'billing_mode' => $item->billingMode(),
            'state' => ChargeState::Pending,
            'title' => $item->lineTitle(),
            'group' => $item->group,
            'description' => $desc,
            'quantity' => $item->quantity,
            'unit' => $item->price->interval?->value,
            'unit_minor' => $amountMinor,
            'amount_minor' => $amountMinor,
            'currency' => $sub->currency,
            'covers' => $covers,
            'idempotency_key' => 'order_'.Str::uuid()->toString(),
        ]);
    }
}
