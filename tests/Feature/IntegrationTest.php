<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\SubscriptionState;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

// US account → EU VAT resolver returns 0%, so invoice total == subtotal (clean numbers).
function intAccount(string $country = 'US'): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => $country, 'merchant_country' => 'DE'],
    ]);
}

function intPlan(int $minor, string $model = 'fixed', ?array $tiers = null): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => 'it-'.uniqid(), 'name' => 'VPS', 'pricing_model' => $model]);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => $model, 'interval' => 'month', 'interval_count' => 1,
        'tiers' => $tiers ?? [],
    ]);
}

/** Run a month: renew at the boundary, then invoice that cycle's pending charges. */
function billMonth(Subscription $sub, string $at): ?Invoice
{
    Meteric::renew($sub->fresh(), CarbonImmutable::parse($at));

    return Meteric::invoicePending(BillingAccount::findOrFail($sub->account_id));
}

function payInvoice(Invoice $invoice): void
{
    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pay-'.$invoice->id);
}

it('bills a clean separate invoice every month and leaves an unpaid one open', function () {
    $acc = intAccount();
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add(intPlan(1000), 1)->create();

    payInvoice(Meteric::invoicePending($acc));          // June, paid
    payInvoice(billMonth($sub, '2026-07-01Z'));         // July, paid
    $aug = billMonth($sub, '2026-08-01Z');              // August, UNPAID
    payInvoice(billMonth($sub, '2026-09-01Z'));         // September, paid

    // Four distinct invoices, one open, each exactly the month's charge.
    expect(Invoice::count())->toBe(4)
        ->and(Invoice::where('state', InvoiceState::Paid->value)->count())->toBe(3)
        ->and(Invoice::where('state', InvoiceState::Open->value)->count())->toBe(1)
        ->and($aug->fresh()->state)->toBe(InvoiceState::Open)
        ->and(Invoice::pluck('subtotal_minor')->unique()->all())->toBe([1000]);

    // The whole ledger reconciles: charges sum == invoices sum, nothing double billed.
    expect((int) Charge::sum('amount_minor'))->toBe(4000)
        ->and((int) Invoice::sum('subtotal_minor'))->toBe(4000)
        ->and(Charge::where('state', 'pending')->count())->toBe(0);

    // Account still owes exactly the August invoice.
    expect($aug->fresh()->outstanding()->getMinorAmount()->toInt())->toBe(1000);
});

it('keeps invoicing across months until suspended, then stops, then resumes on payment', function () {
    $acc = intAccount();
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add(intPlan(1000), 1)->create();
    $june = Meteric::invoicePending($acc); // unpaid

    // July still bills (no suspension yet) — an unpaid invoice alone does not stop billing.
    $july = billMonth($sub, '2026-07-01Z');
    expect(Invoice::count())->toBe(2);

    // Now suspend (the prepaid policy your listener would apply on overdue).
    Meteric::pause($sub->fresh());
    expect($sub->fresh()->state)->toBe(SubscriptionState::Paused);

    // August and September accrue nothing while paused (no active service, no invoice).
    expect(billMonth($sub, '2026-08-01Z'))->toBeNull();
    expect(billMonth($sub, '2026-09-01Z'))->toBeNull();
    expect(Invoice::count())->toBe(2);

    // Pay the arrears, then resume: a fresh cycle from the resume date bills now,
    // and the paused gap is forgiven (not back-billed).
    payInvoice($june);
    payInvoice($july);
    Meteric::resume($sub->fresh(), CarbonImmutable::parse('2026-10-01Z'));
    $resumed = Meteric::invoicePending($acc);

    expect($sub->fresh()->state)->toBe(SubscriptionState::Active)
        ->and($resumed)->not->toBeNull()
        ->and($resumed->subtotal_minor)->toBe(1000)
        ->and(Invoice::count())->toBe(3); // June, July, resumed — the paused months added nothing
});

it('runs a year of renewals idempotently with no double billing', function () {
    $acc = intAccount();
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-01-01Z'))->add(intPlan(1000), 1)->create();
    payInvoice(Meteric::invoicePending($acc));

    foreach (['2026-02-01Z', '2026-03-01Z', '2026-04-01Z', '2026-05-01Z', '2026-06-01Z'] as $month) {
        Meteric::renew($sub->fresh(), CarbonImmutable::parse($month));
        Meteric::renew($sub->fresh(), CarbonImmutable::parse($month)); // re-run: must be a no-op
        payInvoice(Meteric::invoicePending($acc));
    }

    // 6 months billed once each, all paid, nothing pending or doubled.
    expect(Charge::count())->toBe(6)
        ->and((int) Charge::sum('amount_minor'))->toBe(6000)
        ->and(Invoice::where('state', InvoiceState::Paid->value)->count())->toBe(6);
});

it('catches up multiple skipped months in one renewal', function () {
    $acc = intAccount();
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add(intPlan(1000), 1)->create();
    Meteric::invoicePending($acc); // June

    // No renewals run for months, then one renewal at October catches up. In-advance
    // billing covers July, August, September, and the October cycle that starts at the 1st.
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-10-01Z'));

    expect(Charge::count())->toBe(5); // June + four caught up (Jul, Aug, Sep, Oct)
    $invoice = Meteric::invoicePending($acc);
    expect($invoice->subtotal_minor)->toBe(4000)
        ->and($invoice->lines)->toHaveCount(4);
});

it('applies volume quantity pricing through a mid-cycle change and the next renewal', function () {
    $acc = intAccount();
    // 1-10 @ €5, 11-50 @ €4, 51+ @ €3 (volume: whole qty at the reached tier)
    $price = intPlan(0, 'volume', [
        ['up_to' => 10, 'unit_minor' => 500],
        ['up_to' => 50, 'unit_minor' => 400],
        ['up_to' => null, 'unit_minor' => 300],
    ]);
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 5)->create();

    // First cycle billed at 5 units → 5 × €5 = €25.
    expect((int) Charge::sum('amount_minor'))->toBe(2500);

    // Scale to 60 mid-June (15 days left of 30): prorated delta at the volume rate.
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub)->setRelation('price', $price);
    Meteric::setQuantity($item, 60, CarbonImmutable::parse('2026-06-16Z'));

    // Renew July at 60 units → whole 60 at €3 = €180.
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));
    expect(Charge::where('kind', 'recurring')->where('amount_minor', 18000)->exists())->toBeTrue();
});

it('separates prepaid base and arrears usage per cycle with usage resetting', function () {
    $acc = intAccount();
    $base = intPlan(1000); // €10/mo prepaid
    $cloud = Product::create(['type' => 'cloud', 'slug' => 'itc-'.uniqid(), 'name' => 'Cloud', 'pricing_model' => 'metered']);
    MeterDimension::create(['product_id' => $cloud->id, 'key' => 'traffic', 'unit' => 'GB', 'aggregation' => 'sum', 'rate' => '0.500000', 'currency' => 'EUR', 'included_qty' => 0]);
    $cloudPrice = Price::create(['product_id' => $cloud->id, 'currency' => 'EUR', 'amount_minor' => 0, 'pricing_model' => 'metered', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_arrears']);

    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($base, 1)->create();
    $usageItem = SubscriptionItem::create(['subscription_id' => $sub->id, 'product_id' => $cloud->id, 'price_id' => $cloudPrice->id, 'quantity' => 1]);

    $june = new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'));
    $july = new Period(CarbonImmutable::parse('2026-07-01Z'), CarbonImmutable::parse('2026-08-01Z'));

    // June: 20 GB usage. Bill base (prepaid, June) + usage (arrears, June).
    Meteric::recordUsage($usageItem, 'traffic', 20, CarbonImmutable::parse('2026-06-10Z'));
    Meteric::rollupUsage($usageItem, $june);
    $juneInvoice = Meteric::invoicePending($acc);
    expect($juneInvoice->subtotal_minor)->toBe(1000 + 1000); // €10 base + 20×€0.50

    // July: renew base, fresh usage of 10 GB (the meter reset). Separate invoice.
    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));
    Meteric::recordUsage($usageItem, 'traffic', 10, CarbonImmutable::parse('2026-07-10Z'));
    Meteric::rollupUsage($usageItem->fresh(), $july);
    $julyInvoice = Meteric::invoicePending($acc);

    expect($julyInvoice->subtotal_minor)->toBe(1000 + 500) // €10 base + 10×€0.50
        ->and(Invoice::count())->toBe(2);
});
