<?php

declare(strict_types=1);

use Billify\Enums\BillingMode;
use Billify\Enums\FirstPeriodPolicy;
use Billify\Invoicing\Drivers\DatabaseInvoiceDriver;
use Billify\Tax\EuVatResolver;
use Billify\Tax\FlatRateTaxResolver;
use Billify\Tax\NullTaxResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    */
    'currency' => env('BILLIFY_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Proration
    |--------------------------------------------------------------------------
    | Unit used to compute proration ratios. 'second' is the most precise and
    | DST/leap safe. 'day' rounds to whole days.
    */
    'proration' => [
        'unit' => env('BILLIFY_PRORATION_UNIT', 'second'), // second | day
    ],

    /*
    |--------------------------------------------------------------------------
    | Rounding
    |--------------------------------------------------------------------------
    | Applied per line; invoice total = sum of line totals so it reconciles.
    | One of brick/math RoundingMode names.
    */
    'rounding' => env('BILLIFY_ROUNDING', 'HALF_UP'),

    /*
    |--------------------------------------------------------------------------
    | Anchoring & first period defaults
    |--------------------------------------------------------------------------
    | Global default; overridable per subscription/product.
    */
    'anchor' => [
        'mode' => env('BILLIFY_ANCHOR_MODE', 'signup'),     // signup | fixed_day | fixed_dow
        'day' => env('BILLIFY_ANCHOR_DAY', 1),
        'first_period' => env('BILLIFY_FIRST_PERIOD', FirstPeriodPolicy::ProrateOnly->value),
        'default_billing_mode' => BillingMode::InAdvance->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    | Invoice emission + tax resolution are swappable. Bind your own class to
    | integrate lexoffice, EU VAT, etc.
    */
    'tax' => [
        'driver' => env('BILLIFY_TAX_DRIVER', 'eu_vat'), // eu_vat | flat | null
        'drivers' => [
            'eu_vat' => EuVatResolver::class,
            'flat' => FlatRateTaxResolver::class,
            'null' => NullTaxResolver::class,
        ],
        'flat_rate' => env('BILLIFY_TAX_FLAT_RATE', 0.19),
    ],

    'invoice' => [
        'driver' => env('BILLIFY_INVOICE_DRIVER', 'database'),
        'drivers' => [
            'database' => DatabaseInvoiceDriver::class,
            // 'lexoffice' => \App\Billing\LexofficeInvoiceDriver::class,
        ],
        // Mirror canonical record to DB even when a remote driver is primary.
        'mirror_to_database' => env('BILLIFY_INVOICE_MIRROR', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger (double-entry audit)
    |--------------------------------------------------------------------------
    */
    'ledger' => [
        'enabled' => env('BILLIFY_LEDGER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema
    |--------------------------------------------------------------------------
    | Morph key type for host references and Billify PKs.
    */
    'schema' => [
        'prefix' => 'billify_',
        'morph_key' => env('BILLIFY_MORPH_KEY', 'uuid'), // uuid | bigint
    ],
];
