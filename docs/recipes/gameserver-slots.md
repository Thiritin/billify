# Bill a gameserver per slot and per hour

A worked example: a gameserver is sized by player slots (a configurable option
with a volume discount) and billed for the hours it actually runs (a metered
dimension). This combines a per-slot quantity option with hourly runtime
billing on one item.

The pieces are covered in [Addons and options](/usage/addons-and-options),
[Products and prices](/usage/products-and-prices), and
[Usage billing](/usage/usage-billing). This page wires them together.

## The catalog

### Gameserver product

The base gameserver is a metered product: there is no fixed monthly fee, you bill
runtime hours plus the slot option. Add a zero-amount metered price so the item
can carry a billing cycle.

```php
use Meteric\Models\{Product, Price};
use Meteric\Enums\{PricingModel, PricePurpose, Interval, BillingMode};

$gameserver = Product::create([
    'type' => 'gameserver',
    'slug' => 'gameserver-minecraft',
    'name' => 'Minecraft server',
    'pricing_model' => PricingModel::Metered,
    'is_proratable' => true,
]);

$runtimePrice = Price::create([
    'product_id' => $gameserver->id,
    'currency' => 'EUR',
    'amount_minor' => 0,
    'purpose' => PricePurpose::Recurring,
    'pricing_model' => PricingModel::Metered,
    'interval' => Interval::Month,
    'interval_count' => 1,
    'billing_mode' => BillingMode::InArrears,
]);
```

### Slots: a volume-priced quantity option

Slots are a `quantity` option priced with `PricingModel::Volume`: the more slots,
the cheaper each one. A tier is `['up_to' => int|null, 'unit_minor' => int]`,
ordered low to high, `up_to: null` is the last unbounded tier.

```php
$slotProduct = Product::create([
    'type' => 'option',
    'slug' => 'option-slots',
    'name' => 'Player slots',
    'pricing_model' => PricingModel::Volume,
    'is_proratable' => true,
]);

$slotPrice = Price::create([
    'product_id' => $slotProduct->id,
    'currency' => 'EUR',
    'purpose' => PricePurpose::Option,
    'pricing_model' => PricingModel::Volume,
    'tiers' => [
        ['up_to' => 10,   'unit_minor' => 50], // 1 to 10 slots at €0.50 each
        ['up_to' => 50,   'unit_minor' => 40], // 11 to 50 slots at €0.40 each
        ['up_to' => null, 'unit_minor' => 30], // 51+ slots at €0.30 each
    ],
]);
```

With `Volume`, the whole quantity prices at the tier it lands in. Check it:

```php
$slotPrice->amountFor(32);  // Money €12.80  (32 × €0.40)
$slotPrice->amountFor(64);  // Money €19.20  (64 × €0.30)
```

### Runtime: an hourly meter dimension

Runtime is a metered dimension on the gameserver. `rate` is the price per hour as
a high-precision string. No allowance here, every hour bills.

```php
use Meteric\Models\MeterDimension;
use Meteric\Enums\Aggregation;

MeterDimension::create([
    'product_id' => $gameserver->id,
    'key' => 'runtime_hours',
    'unit' => 'hour',
    'aggregation' => Aggregation::Sum,
    'rate' => '0.05000000',   // €0.05 per running hour
    'currency' => 'EUR',
    'included_qty' => 0,
]);
```

## Provision and size the server

Subscribe the customer to the gameserver item, then set the slot count.
`setOption()` prorates the option's price over the item's remaining period.

```php
use Meteric\Facades\Meteric;

$subscription = Meteric::subscribe($user)
    ->add($runtimePrice, qty: 1, resource: $gameserverRecord)
    ->create();

$item = $subscription->items()->first();

Meteric::setOption(
    item: $item,
    key: 'slots',
    value: '32',
    type: 'quantity',
    price: $slotPrice,
    qty: 32,
);
```

Resizing reprices the delta. Raising slots charges the prorated increase;
lowering credits the prorated difference:

```php
Meteric::setOption($item, 'slots', '64', 'quantity', $slotPrice, qty: 64);
```

## Record runtime

Report the hours the server ran. Batch them or report per hour. `recordUsage()`
is idempotent on `key`, so a retried meter push does not double-bill.

```php
use Carbon\CarbonImmutable;

Meteric::recordUsage(
    item: $item,
    dimension: 'runtime_hours',
    quantity: 6.0,                    // ran 6 hours this window
    occurredAt: CarbonImmutable::now(),
    key: 'runtime-2026-06-19-00',
);
```

## Roll up and invoice

At cycle close, `rollupUsage()` aggregates the runtime records into one in-arrears
charge. The slot option already accrued its prorated charge when you set it, and
renews with the item's cycle.

```php
use Meteric\Support\Period;

$cycle = Meteric::billingCycle($item);
$charges = Meteric::rollupUsage($item, $cycle);  // one runtime_hours charge
```

Then bill the account. The runtime usage (in arrears) and the slot option (in
advance) land on the same invoice:

```php
use Meteric\Models\BillingAccount;

$account = BillingAccount::firstOrCreate(
    ['owner_type' => $user->getMorphClass(), 'owner_id' => $user->getKey()],
    ['currency' => 'EUR'],
);

$invoice = Meteric::invoicePending($account);
// lines: slot option (€12.80 for 32 slots), runtime (6h × €0.05 = €0.30)
```

A changed hourly rate takes effect going forward, usage before the change bills at
the old rate, usage after at the new one. Roll up the old window before switching
the rate for a clean cutover. See [Plan changes](/usage/plan-changes#hourly-and-metered-plans).

## What the invoice looks like

A monthly invoice for a 16-slot server with metered runtime. With the
[Lexware Office driver](/usage/invoicing#lexware-office-lexoffice) the line title
becomes the lexoffice `name`, the description stays the description, `unit`
becomes `unitName`, and amounts post as **net** with a tax percentage so
lexoffice computes the gross. The numbers below use 19% German VAT. The billed
cycle posts as a service period with an inclusive end (`2026-06-01 to
2026-06-30`, not `to 2026-07-01`).

| Item | Detail | Qty | Unit | Net | VAT | Gross |
|------|--------|-----|------|-----|-----|-------|
| Gameserver - mc-eu-04.example | 2026-06-01 to 2026-06-30 | 16 | slots | €40.00 | €7.60 | €47.60 |
| Gameserver - mc-eu-04.example | Runtime: 540 hours | 540 | hours | €5.40 | €1.03 | €6.43 |
| **Subtotal (net)** | | | | **€45.40** | | |
| **VAT (19%)** | | | | | **€8.63** | |
| **Total (gross)** | | | | | | **€54.03** |

The slot line is priced through the volume tiers; the runtime line is the rolled
up hourly usage for the cycle.
