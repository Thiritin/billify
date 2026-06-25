# Invoicing

Invoicing is where Meteric's core promise lives: a charge is the source of
truth, and an invoice is a document that bills a set of charges. The two are
decoupled so an outage in your accounting system never loses revenue.

## The charge-vs-invoice guarantee

A `Charge` accrues as `pending` the moment money is owed, a renewal, an
upgrade, a usage rollup, an addon. It has nothing to do with documents yet.

`invoicePending()` collects an account's pending charges in one currency, builds
a draft, and hands it to the bound invoice driver. Then:

- If the driver's `issue()` **succeeds**, the charges flip from `pending` to
  `invoiced` inside a transaction, attached to the new invoice.
- If `issue()` **throws**, accounting API down, network timeout, nothing flips.
  The charges stay `pending`. The exception is re-thrown so you see it, and the
  next run picks the same charges up.

```php
use Meteric\Facades\Meteric;

$invoice = Meteric::invoicePending($account);
// null when nothing was pending; otherwise the issued Invoice.
```

The driver call is the failure boundary. A retried run reuses a deterministic
batch key, so retrying after a partial failure does not create a second invoice
for the same charges.

## An invoice is never negative

An invoice total floors at zero. Credits ride along as itemized negative charge
lines, each carrying the product name of what it credits, never a single
summarized "credit" line. The negative lines offset the positive charges down to
zero, no further.

If the pending credits outweigh the charges, `invoicePending()` issues nothing
and returns `null`. The credit lines stay `pending` and reduce a later invoice
once new charges land. Money going back to a customer is a credit note plus a
host listener, not a negative invoice.

## Drivers

The invoice driver is swappable. The default `database` driver writes the
invoice and its lines to the `meteric_*` tables. To send invoices to an external
system, bind a class implementing `Meteric\Contracts\InvoiceDriver`:

```php
// config/meteric.php
'invoice' => [
    'driver' => 'lexoffice',
    'drivers' => [
        'database'  => \Meteric\Invoicing\Drivers\DatabaseInvoiceDriver::class,
        'lexoffice' => \Meteric\Invoicing\Drivers\LexofficeInvoiceDriver::class,
    ],
    'mirror_to_database' => true,
],
```

Throwing from `issue()` is the boundary that preserves pending charges, so a
remote driver that fails to reach its API should throw rather than swallow the
error. With `mirror_to_database` on, the canonical invoice is still written
locally even when a remote driver is primary. Reach the active driver directly
with `Meteric::driver()`.

The bundled `lexoffice` driver wraps the `database` driver: it persists the
canonical local invoice first, then finalizes the document in Lexware Office. The
local invoice is the source of truth, so if the Lexware Office call fails it
re-throws without rolling the local invoice back. See
[Lexware Office (lexoffice)](#lexware-office-lexoffice) below.

## Rendering options and sub-items

A driver's `issue(InvoiceDraft $draft)` receives `$draft->charges`, a flat
collection of `Charge` rows. A product and its configurable options and addons
arrive as separate charges. Two fields tie them together:

- `line_group`: the owning subscription item id. Every charge a product produces
  (the base line, each option, each addon, proration, setup, usage) carries the
  same `line_group`. Account-level charges with no item have a null `line_group`.
- `kind`: a `LineKind`. Call `$charge->kind->isBaseLine()` to find the product's
  parent line. It returns true for `Recurring`, `Prorated`, `FullPeriod`, and
  `OneOff`, and false for `Option`, `Addon`, `Setup`, `Usage`, `Discount`, and
  `Credit`.

Group the charges by `line_group` to reconstruct a product, then pick the base
line as the parent and treat the rest as sub-items:

```php
foreach ($draft->charges->groupBy('line_group') as $group) {
    $parent = $group->firstWhere(fn ($c) => $c->kind->isBaseLine()) ?? $group->first();
    $subItems = $group->reject(fn ($c) => $c === $parent);
    // render $parent as the product line, $subItems nested under it
}
```

A charge with a null `line_group` is its own line. Render the result itemized
(one line per charge), nested (sub-items under the parent), or consolidated (one
line per product) as the target system supports.

### Built-in consolidated mode

The `database` and `lexoffice` drivers do this for you. Set the line mode:

```php
// config/meteric.php
'invoice' => [
    'line_mode' => 'consolidated', // itemized (default) | consolidated
],
```

`itemized` writes one invoice line per charge. `consolidated` writes one line
per `line_group`: the base charge sets the title, unit, quantity, and period;
the options and addons fold into the line. Their amounts sum into the line total,
their labels and amounts append to the line description, and a structured copy
lands in `metadata['items']` (each entry has `kind`, `title`, `description`,
`amount_minor`) so a template renders the sub-items without parsing text.

Tax is recomputed on the summed net, so the invoice subtotal, tax, and total
match the itemized invoice for the same charges. Every charge still flips to
`invoiced`, including the options and addons that no longer have their own line.
The `lexoffice` driver wraps the `database` driver, so it emits one Lexware
Office line item per product with the sub-items in that line's description.

## Lexware Office (lexoffice)

To send finalized invoices and credit notes to Lexware Office, set the driver
and token:

```dotenv
METERIC_INVOICE_DRIVER=lexoffice
METERIC_LEXOFFICE_TOKEN=your-api-token
```

The config block:

```php
// config/meteric.php
'invoice' => [
    'lexoffice' => [
        'api_token' => env('METERIC_LEXOFFICE_TOKEN'),
        'base_url'  => env('METERIC_LEXOFFICE_BASE_URL', 'https://api.lexware.io'),
        'tax_type'  => 'net',   // line amounts are posted net
        'country'   => 'DE',
    ],
],
```

Production runs against `https://api.lexware.io`. Trial and sandbox keys only
work against the sandbox gateway at `https://api.lexware-sandbox.io`; generate
them at `app.lexware-sandbox.de/addons/public-api`.

The driver keeps the canonical invoice locally, then POSTs to Lexware Office and
stores the returned id and resource URI on `external_id` / `external_url`:

- `issue()` posts to `/v1/invoices?finalize=true`.
- `creditNote()` posts to `/v1/credit-notes?finalize=true`.

Lexware Office cannot void a finalized invoice, so `void()` refuses one that
already reached the API and points you at a [credit note](#credit-notes-and-refunds)
instead.

Lines map to lexoffice line items: the line title becomes `name`, the multi-line
description stays the description, `quantity` and `unit` (as `unitName`) carry
over, and amounts post net with a `taxRatePercentage` so lexoffice computes the
gross. A line `group` becomes a `type:"text"` separator (a heading row), and the
billed cycle posts as a `serviceperiod` spanning the invoice with an inclusive
end date.

## Payments

Meteric does not talk to gateways. When your gateway confirms money arrived, you
record it against the invoice:

```php
use Brick\Money\Money;

Meteric::recordPayment($invoice, Money::of('49.98', 'EUR'), 'pi_123');
```

This creates a `Payment` and a `PaymentAllocation` and advances the invoice
state: `partially_paid` while the running total is below the invoice total,
`paid` once it reaches it. Read the position off the invoice:

```php
$invoice->total();        // Money
$invoice->outstanding();  // Money still owed
$invoice->isPaid();       // bool
$invoice->isOverdue();    // bool, issued, past due, not paid
```

To reverse a payment, issue a [credit note](#credit-notes-and-refunds) and refund
through your gateway.

## Credit notes and refunds

Meteric does not move money. A credit note is the accounting reversal document.
The actual refund is your payment gateway's job, the same gateway-agnostic split
as payments: Meteric records the document, you move the money.

```php
use Brick\Money\Money;

// Reverse the full net of an invoice; VAT is mirrored automatically.
$note = Meteric::creditNote($invoice, Money::ofMinor($invoice->subtotal_minor, 'EUR'), 'Customer refund');
```

`creditNote(Invoice $invoice, Money $amount, ?string $reason = null): CreditNote`
takes the **net** amount to credit. The driver adds the invoice's tax rate on top
so the credit note reverses the same VAT the invoice charged, and fires a
`CreditNoteIssued` event. The `CreditNote` model carries `amount_minor` (net),
`tax_minor` (mirrored), `currency`, `number`, `reason`, and `state`.

### Void or credit note

`Meteric::voidInvoice($invoice)` cancels an invoice issued in error, before any
money moves. It works only on an unpaid invoice and refuses once any payment
exists; correct a paid or finalized invoice with a credit note instead.

```php
Meteric::voidInvoice($invoice);
```

The charges stay attached to the now-void invoice. They are a record of what the
voided document billed. To re-bill them, move them onto a fresh draft with
[`transferCharges`](#draft-invoices-and-charge-transfer); to drop them, void the
charges yourself.

Voiding routes through the driver, so the Lexware Office driver voids a draft
that never reached the API and refuses a finalized one (use a credit note).

With the [Lexware Office driver](#lexware-office-lexoffice), `creditNote()` also
POSTs a real credit-note document to lexoffice (`POST /v1/credit-notes?finalize=true`)
and stores its `external_id`. The credit note mirrors the invoice's tax rate, so a
net 10.00 EUR credit at 19% VAT comes to 11.90 EUR gross.

### Draft invoices and charge transfer

`invoicePending` issues immediately. To review or edit an invoice before it goes
out, open a draft instead:

```php
$draft = Meteric::draftInvoice($account);
```

`draftInvoice(BillingAccount $account, ?string $currency = null): Invoice` pulls
the account's pending charges, attaches them, and builds the lines. The charges
flip to `invoiced` so they leave the pending pool: a later `invoicePending` skips
them. The draft has no number, no due date, and fires no `InvoiceIssued`. With
nothing pending you get an empty draft with zero totals.

A draft's charges are its line items. Lines always rebuild from the charges
attached to the invoice, so editing a draft means moving charges in or out, not
editing lines directly.

```php
Meteric::transferCharges($from, $to);   // move $from's charges onto draft $to
Meteric::transferCharges($from, null);  // detach them back to pending
```

`transferCharges(Invoice $from, ?Invoice $to): void` reassigns every charge on
`$from`. `$to` must be a draft, or `null` to return the charges to pending (the
next `invoicePending` bills them again). `$from` must be a draft or a void
invoice; an open or paid invoice throws, so void it first. Both drafts rebuild
their lines and totals from the charges they now hold. A void `$from` keeps its
historical lines as the frozen record of what it billed.

Send a draft with `finalizeInvoice`:

```php
$invoice = Meteric::finalizeInvoice($draft);
```

`finalizeInvoice(Invoice $draft): Invoice` requires a draft. It sends the draft's
current lines through the driver (no rebuild from charges), sets the due date
from `meteric.invoice.net_days`, flips the invoice to `open`, and fires
`InvoiceIssued`. Payment and overdue tracking apply from here, so `recordPayment`
and `markOverdue` work on the finalized invoice. A driver failure leaves the
draft untouched.

#### Re-issue with a corrected header

When a document is wrong but the charges are right (a wrong billing address, say),
void the original and carry its charges onto a corrected copy:

```php
$copy = Meteric::copyInvoice($source);   // empty draft, clones the header
Meteric::voidInvoice($source);
Meteric::transferCharges($source, $copy);
$fixed = Meteric::finalizeInvoice($copy);
```

`copyInvoice(Invoice $source): Invoice` returns an empty draft cloning the
source's account, customer, currency, and metadata. No charges or lines come
across; `transferCharges` carries them.

## Consolidated billing

A payer account can bill its own pending charges plus all its child accounts'
charges onto a single invoice, a reseller or an organization with sub-accounts.

```php
$invoice = Meteric::invoiceConsolidated($payer);
```

This collects pending charges across the payer's scope (itself and its
children, via `payerScopeIds()`) and issues one invoice, itemized per account.
The same guarantee applies: a driver failure leaves every charge `pending`.

Set the relationship by giving a child account a `parent_id`:

```php
use Meteric\Models\BillingAccount;

BillingAccount::create([
    'owner_type' => $org->getMorphClass(),
    'owner_id' => $org->getKey(),
    'parent_id' => $payer->id,
    'currency' => 'EUR',
]);
```
