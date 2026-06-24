<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\LineKind;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Invoice;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

it('rolls up due usage, renews, and invoices in one tick', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $product = Product::create(['type' => 'vps', 'slug' => 'rbc-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
    MeterDimension::create([
        'product_id' => $product->id, 'key' => 'traffic', 'unit' => 'GB',
        'aggregation' => 'sum', 'rate' => '0.100000', 'currency' => 'EUR', 'included_qty' => 0,
    ]);
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);

    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 1)->create();
    $item = $sub->items->first();
    Meteric::recordUsage($item, 'traffic', 500, CarbonImmutable::parse('2026-06-20Z')); // 500 GB in June

    // The cycle has closed (period ended 2026-07-01).
    test()->travelTo(CarbonImmutable::parse('2026-07-02T00:00:00Z'));
    test()->artisan('meteric:run')->assertSuccessful();

    $charges = Charge::where('subscription_id', $sub->id)->get();
    expect($charges->where('kind', LineKind::Usage->value)->where('amount_minor', 5000)->count())->toBe(1)  // 500 × €0.10
        ->and($charges->where('kind', LineKind::Recurring->value)->count())->toBe(2)                         // June + July base
        ->and(Invoice::where('account_id', $acc->id)->count())->toBe(1);
});

it('is idempotent: a second tick bills nothing new', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $price = Price::create([
        'product_id' => Product::create(['type' => 'vps', 'slug' => 'rbc2-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed'])->id,
        'currency' => 'EUR', 'amount_minor' => 1000, 'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 1)->create();

    test()->travelTo(CarbonImmutable::parse('2026-07-02T00:00:00Z'));
    test()->artisan('meteric:run')->assertSuccessful();
    $after = Charge::where('subscription_id', $sub->id)->count();

    test()->artisan('meteric:run')->assertSuccessful();
    expect(Charge::where('subscription_id', $sub->id)->count())->toBe($after); // guard holds
});
