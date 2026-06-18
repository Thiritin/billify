# Billify

Dynamic billing engine for hosting systems — subscriptions, proration, usage
metering, addons, and the charge-vs-invoice safety model, behind a well-tested,
PostgreSQL-backed package. Inspired by Stripe Billing + WHMCS, sized for hosts
running VPS, domains, webhosting, cloud, gameservers and IP projects.

> **Status:** scaffold. Schema, models, drivers, proration and the invoicing
> guarantee are in place. The fluent `subscribe()/quote()/checkout()` builders
> are the next milestone (see [Roadmap](#roadmap)). Design lives in
> [`DESIGN.md`](DESIGN.md); schema in [`SCHEMA.md`](SCHEMA.md).

## Why

Most hosting billing tangles money math into the app. Billify isolates it:

- **Charge ≠ invoice.** Charges accrue as the source of truth; an invoice is a
  document that bills them. If your accounting system (e.g. Lexware Office) is
  down, charges stay `pending` — no revenue lost, retried next run.
- **Pluggable drivers.** Invoice emission and tax are swappable contracts.
  Ships a database invoice driver + an EU VAT resolver; bind your own.
- **PostgreSQL-native safety.** A GiST `EXCLUDE` constraint makes it physically
  impossible to bill the same service window twice.
- **No floats.** Money is integer minor units via `brick/money`.

## Requirements

- PHP 8.5+
- Laravel 12
- PostgreSQL 13+ (uses `tstzrange`, `btree_gist`, `pgcrypto`, enum types)

## Install

```bash
composer require pawhost/billify
php artisan vendor:publish --tag=billify-config
php artisan migrate
```

## Core concepts

| Concept | What it is |
|---------|-----------|
| `Product` / `Price` | Catalog + versioned pricing (recurrence, billing mode, tiers, caps). |
| `Subscription` / `SubscriptionItem` | A customer commitment and its billed lines; items morph to the provisioned resource. |
| `Addon` / `ItemOption` | Bookable extras (+4GB RAM) and configurable dimensions (gameserver slots). |
| `MeterDimension` / `UsageRecord` | Multi-dimension usage (cpu-hours, traffic) for hourly/metered billing. |
| `Charge` | Money owed. Accrues `pending`, flips to `invoiced` only on driver success. |
| `Invoice` / `InvoiceLine` | Immutable document; each line carries its own service period. |

## The invoicing guarantee

```php
use Billify\Facades\Billify;
use Brick\Money\Money;

// Collect an account's pending charges and issue them via the bound driver.
// If the driver throws (e.g. accounting system down), charges stay `pending`.
$invoice = Billify::invoicePending($account);

// Inbound payment from your gateway drives invoice state.
Billify::recordPayment($invoice, Money::of('49.98', 'EUR'), 'pi_123');
```

## Custom drivers

```php
// config/billify.php
'invoice' => [
    'driver' => 'lexoffice',
    'drivers' => [
        'database'  => \Billify\Invoicing\Drivers\DatabaseInvoiceDriver::class,
        'lexoffice' => \App\Billing\LexofficeInvoiceDriver::class,
    ],
],
'tax' => [
    'driver' => 'eu_vat', // eu_vat | flat | null, or your own
],
```

A driver implements `Billify\Contracts\InvoiceDriver`. Throwing from `issue()`
is the failure boundary that preserves pending charges.

## Proration & quoting

`Prorator` computes second-precision proration for upgrades/downgrades and
mid-cycle changes. The `Quote` builder (roadmap) renders a due-now + recurring
breakdown for checkout pages without persisting anything — same calculators as
real billing, so the quote always matches the eventual invoice.

## Testing

```bash
composer test        # Pest
composer analyse     # Larastan
vendor/bin/pint      # format
```

## Roadmap

- [x] Schema, models, casts, enums
- [x] Tax drivers (EU VAT, flat, null) + database invoice driver
- [x] Proration engine + charge/invoice/payment flow
- [ ] Fluent `subscribe()` / `changePlan()` / `cancel()` managers
- [ ] `quote()` / `checkout()` builders (JSON for frontends)
- [ ] Usage rollup + anchoring/first-period planner
- [ ] Commitments & consolidated billing
- [ ] Full Pest suite per use case in `DESIGN.md`

## License

MIT.
