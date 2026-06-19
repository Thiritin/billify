# Quickstart

This walks the full path: a product with a price, a subscription, an invoice,
and a recorded payment. Every call goes through the `Billify` facade.

## 1. Create a product and a price

```php
use Billify\Models\{Product, Price};
use Billify\Enums\{PricingModel, Interval, BillingMode, PricePurpose};

$product = Product::create([
    'type' => 'vps',
    'slug' => 'vps-xl',
    'name' => 'VPS XL',
    'pricing_model' => PricingModel::Fixed,
    'is_proratable' => true,
]);

$price = Price::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'amount_minor' => 1000,            // €10.00
    'purpose' => PricePurpose::Recurring,
    'pricing_model' => PricingModel::Fixed,
    'interval' => Interval::Month,
    'interval_count' => 1,
    'billing_mode' => BillingMode::InAdvance,
]);
```

Money is stored in minor units. `amount_minor => 1000` is €10.00.

## 2. Subscribe a customer

```php
use Billify\Facades\Billify;

$subscription = Billify::subscribe($user)
    ->add($price)
    ->create();
```

`subscribe($user)` resolves or creates a billing account for the model.
`create()` persists the subscription and its items and accrues the first cycle
as `pending` charges. The default first-period policy is `prorate_only`, so
the first charge covers the stub up to the anchor.

## 3. Issue the invoice

```php
$invoice = Billify::invoicePending($subscription->account);
```

This collects the account's pending charges in one currency and hands them to
the bound invoice driver. On success the charges flip to `invoiced` and you get
the `Invoice` back. If the driver throws, the charges stay `pending`.

To subscribe and invoice in one step, use checkout:

```php
$result = Billify::subscribe($user)
    ->add($price)
    ->checkout();

$result->subscription; // the created Subscription
$result->invoice;      // the Invoice billed now (or null if nothing was due)
```

## 4. Record a payment

Your gateway tells you money arrived. Record it against the invoice.

```php
use Brick\Money\Money;

Billify::recordPayment($invoice, Money::of('10.00', 'EUR'), 'pi_123');
```

The invoice moves to `partially_paid` or `paid` based on the running total.

## 5. Renew on a schedule

Renewal accrues the next cycle for due items. Run it from your scheduler.

```php
use Illuminate\Support\Facades\Schedule;
use Billify\Models\Subscription;
use Billify\Facades\Billify;
use Carbon\CarbonImmutable;

Schedule::call(function () {
    $at = CarbonImmutable::now();

    Subscription::dueForRenewal($at)->each(function (Subscription $sub) use ($at) {
        Billify::renew($sub, $at);          // accrue next cycle (idempotent)
        Billify::invoicePending($sub->account); // bill what accrued
    });
})->hourly();
```

`renew()` is idempotent: the billing-period guard means a re-run over the same
window does nothing. From here, see [Subscriptions](/usage/subscriptions) for
trials and anchoring, and [Invoicing](/usage/invoicing) for the charge-vs-invoice
guarantee in detail.
