<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
use Meteric\Enums\VoidCharges;
use Meteric\Events\InvoiceVoided;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

function vinInvoice(): array
{
    $account = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $product = Product::create(['type' => 'vps', 'slug' => 'vin-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 2000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
    Meteric::subscribe()->account($account)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 1)->create();
    $invoice = Meteric::invoicePending($account);

    return [$account, $invoice];
}

it('keeps the charges by default for a manual re-issue', function () {
    [$account, $invoice] = vinInvoice();
    expect($invoice)->not->toBeNull()
        ->and(Charge::where('invoice_id', $invoice->id)->count())->toBeGreaterThan(0);

    Event::fake([InvoiceVoided::class]);
    Meteric::voidInvoice($invoice);   // default Keep

    expect($invoice->fresh()->state)->toBe(InvoiceState::Void)
        ->and(Charge::where('invoice_id', $invoice->id)->where('state', ChargeState::Invoiced->value)->count())->toBeGreaterThan(0);  // untouched
    Event::assertDispatched(InvoiceVoided::class);
});

it('releases charges to pending when asked', function () {
    [$account, $invoice] = vinInvoice();

    Meteric::voidInvoice($invoice, VoidCharges::Release);

    expect($invoice->fresh()->state)->toBe(InvoiceState::Void)
        ->and(Charge::where('invoice_id', $invoice->id)->count())->toBe(0)               // detached
        ->and(Charge::where('account_id', $account->id)->where('state', ChargeState::Pending->value)->count())->toBeGreaterThan(0);  // re-billable
});

it('discards the charges when asked', function () {
    [$account, $invoice] = vinInvoice();

    Meteric::voidInvoice($invoice, VoidCharges::Discard);

    expect($invoice->fresh()->state)->toBe(InvoiceState::Void)
        ->and(Charge::where('account_id', $account->id)->where('state', ChargeState::Pending->value)->count())->toBe(0)
        ->and(Charge::where('account_id', $account->id)->where('state', ChargeState::Void->value)->count())->toBeGreaterThan(0);
});

it('refuses to void a paid invoice and points to a credit note', function () {
    [, $invoice] = vinInvoice();
    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'));

    expect(fn () => Meteric::voidInvoice($invoice->fresh()))->toThrow(LogicException::class);
});
