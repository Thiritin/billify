<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Meteric\Contracts\Clock;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\CheckoutState;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Models\BillingAccount;
use Meteric\Models\Order;
use Meteric\Models\Price;
use Meteric\Pricing\CheckoutPricer;
use Meteric\Tax\TaxContext;

/**
 * Fluent checkout creation. Collects a cart (base items + their addons/options),
 * prices and freezes it through the CheckoutPricer, then persists a single Order
 * row with the frozen `contents`. Nothing is billed yet — the Order is a pending
 * intent that ->pay() or ->convert() later turns into a real subscription.
 */
final class CheckoutBuilder
{
    private ?BillingAccount $account = null;

    private ?Model $customer = null;

    private ?string $currency = null;

    private AnchorMode $anchorMode = AnchorMode::Signup;

    private ?int $anchorDay = null;

    private FirstPeriodPolicy $firstPeriod = FirstPeriodPolicy::ProrateOnly;

    private int $trialDays = 0;

    private ?CarbonImmutable $at = null;

    private ?TaxContext $taxContext = null;

    private ?string $idempotencyKey = null;

    private ?int $ttlMinutes = null;

    /** @var array<string,mixed> */
    private array $metadata = [];

    /** @var list<array{price:Price,qty:float,resource:?Model,label:?string,group:?string,addons:list<array{price:Price,group:?string,qty:float}>,options:list<array{key:string,value:string,type:string,price:?Price,qty:float,min:?float,max:?float}>}> */
    private array $cart = [];

    public function __construct(
        private Clock $clock,
        private CheckoutPricer $pricer,
        string $defaultCurrency = 'EUR',
        ?int $defaultTtlMinutes = null,
    ) {
        $this->currency = $defaultCurrency;
        $this->ttlMinutes = $defaultTtlMinutes;
    }

    public function account(BillingAccount $account): self
    {
        $this->account = $account;
        $this->currency = $account->currency;

        return $this;
    }

    public function for(Model $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function currency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function anchor(AnchorMode $mode, ?int $day = null): self
    {
        $this->anchorMode = $mode;
        $this->anchorDay = $day;

        return $this;
    }

    public function firstPeriod(FirstPeriodPolicy $policy): self
    {
        $this->firstPeriod = $policy;

        return $this;
    }

    public function trialDays(int $days): self
    {
        $this->trialDays = $days;

        return $this;
    }

    public function at(CarbonImmutable $at): self
    {
        $this->at = $at;

        return $this;
    }

    public function tax(TaxContext $context): self
    {
        $this->taxContext = $context;

        return $this;
    }

    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /** Minutes until a pending order expires (the sweep cancels it). Null = no expiry. */
    public function expiresIn(?int $minutes): self
    {
        $this->ttlMinutes = $minutes;

        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Add a base line to the cart, with optional addons and options.
     *
     * @param  list<array{price:Price,group:?string,qty:float}>  $addons
     * @param  list<array{key:string,value:string,type:string,price:?Price,qty:float,min:?float,max:?float}>  $options
     */
    public function add(Price $price, float $qty = 1, ?Model $resource = null, ?string $label = null, ?string $group = null, array $addons = [], array $options = []): self
    {
        $this->cart[] = [
            'price' => $price,
            'qty' => $qty,
            'resource' => $resource,
            'label' => $label,
            'group' => $group,
            'addons' => $addons,
            'options' => $options,
        ];

        return $this;
    }

    /** Price, freeze, and persist the pending Order. */
    public function create(): Order
    {
        $at = $this->at ?? $this->clock->now();
        $account = $this->account ?? $this->resolveAccount();
        $currency = $this->currency ?? $account->currency;

        if ($this->idempotencyKey !== null) {
            $existing = Order::query()->where('idempotency_key', $this->idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $taxContext = $this->taxContext ?? $account->taxContext();

        $frozen = $this->pricer->price(
            $this->cart,
            $currency,
            $at,
            $this->anchorMode,
            $this->anchorDay,
            $this->firstPeriod,
            $this->trialDays,
            $taxContext,
        );

        return Order::create([
            'account_id' => $account->id,
            'customer_type' => $this->customer?->getMorphClass() ?? $account->owner_type,
            'customer_id' => $this->customer?->getKey() ?? $account->owner_id,
            'currency' => $currency,
            'state' => CheckoutState::Pending,
            'anchor_mode' => $this->anchorMode,
            'anchor_day' => $this->anchorDay,
            'first_period' => $this->firstPeriod,
            'trial_days' => $this->trialDays,
            'subtotal_minor' => $frozen->subtotalMinor,
            'tax_minor' => $frozen->taxMinor,
            'total_minor' => $frozen->totalMinor,
            'recurring_total_minor' => $frozen->recurringTotalMinor,
            'contents' => $frozen->contents,
            'quote_snapshot' => $frozen->quoteSnapshot,
            'token' => Str::random(40),
            'idempotency_key' => $this->idempotencyKey,
            'expires_at' => $this->ttlMinutes !== null ? $at->addMinutes($this->ttlMinutes) : null,
            'metadata' => $this->metadata,
        ]);
    }

    private function resolveAccount(): BillingAccount
    {
        if ($this->customer === null) {
            throw new \LogicException('checkout() needs an account() or for(customer).');
        }

        return BillingAccount::firstOrCreate(
            ['owner_type' => $this->customer->getMorphClass(), 'owner_id' => $this->customer->getKey()],
            ['currency' => $this->currency],
        );
    }
}
