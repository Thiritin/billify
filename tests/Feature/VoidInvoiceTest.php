<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Meteric\Enums\ChargeState;
use Meteric\Enums\InvoiceState;
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

it('voids an unpaid invoice and leaves the charges attached', function () {
    [, $invoice] = vinInvoice();
    expect($invoice)->not->toBeNull()
        ->and(Charge::where('invoice_id', $invoice->id)->count())->toBeGreaterThan(0);

    Event::fake([InvoiceVoided::class]);
    Meteric::voidInvoice($invoice);

    expect($invoice->fresh()->state)->toBe(InvoiceState::Void)
        ->and(Charge::where('invoice_id', $invoice->id)->where('state', ChargeState::Invoiced->value)->count())->toBeGreaterThan(0);  // untouched
    Event::assertDispatched(InvoiceVoided::class);
});

it('refuses to void a paid invoice and points to a credit note', function () {
    [, $invoice] = vinInvoice();
    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'));

    expect(fn () => Meteric::voidInvoice($invoice->fresh()))->toThrow(LogicException::class);
});
