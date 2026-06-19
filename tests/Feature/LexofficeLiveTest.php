<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Contracts\TaxResolver;
use Meteric\Invoicing\Drivers\DatabaseInvoiceDriver;
use Meteric\Invoicing\Drivers\LexofficeInvoiceDriver;
use Meteric\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class)->group('live');

/**
 * Hits the real Lexware Office (lexoffice) sandbox API. Opt-in: skipped unless
 * METERIC_LEXOFFICE_TOKEN is set, so normal CI and contributors never need a key.
 * Point METERIC_LEXOFFICE_BASE_URL at the sandbox API base.
 */
beforeEach(function () {
    if (empty(env('METERIC_LEXOFFICE_TOKEN'))) {
        test()->markTestSkipped('Set METERIC_LEXOFFICE_TOKEN to run the lexoffice sandbox test.');
    }
});

function liveMeteric(): Meteric
{
    $driver = new LexofficeInvoiceDriver(
        local: new DatabaseInvoiceDriver(app(TaxResolver::class)),
        apiToken: (string) env('METERIC_LEXOFFICE_TOKEN'),
        baseUrl: (string) env('METERIC_LEXOFFICE_BASE_URL', 'https://api.lexoffice.io'),
        taxType: 'net',
        defaultCountry: 'DE',
    );

    return new Meteric($driver);
}

it('creates a real invoice in the lexoffice sandbox', function () {
    $meteric = liveMeteric();
    $acc = BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['name' => 'Meteric Sandbox Test', 'country' => 'DE'],
    ]);
    $product = Product::create(['type' => 'vps', 'slug' => 'live-'.uniqid(), 'name' => 'VPS XL', 'pricing_model' => 'fixed']);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);

    $meteric->subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01Z'))
        ->add($price, 1, null, label: 'vps-live.example')
        ->create();

    $invoice = $meteric->invoicePending($acc);

    // A real lexoffice voucher id and resource URI came back.
    expect($invoice)->not->toBeNull()
        ->and($invoice->external_id)->not->toBeNull()
        ->and($invoice->external_url)->toContain('/v1/invoices/');
});
