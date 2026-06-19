<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\MeterDimension;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\Subscription;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

uses(RefreshDatabase::class);

/** Metered item with a single dimension; pass dimension overrides. */
function blockItem(array $dimension): SubscriptionItem
{
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $product = Product::create(['type' => 'cloud', 'slug' => 'blk-'.uniqid(), 'name' => 'Cloud', 'pricing_model' => 'metered']);
    MeterDimension::create(array_merge([
        'product_id' => $product->id, 'key' => 'traffic', 'unit' => 'TB',
        'aggregation' => 'sum', 'currency' => 'EUR', 'included_qty' => 0,
    ], $dimension));
    $price = Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => 0,
        'pricing_model' => 'metered', 'interval' => 'month', 'interval_count' => 1, 'billing_mode' => 'in_arrears',
    ]);
    $sub = Subscription::create(['account_id' => $acc->id, 'customer_type' => 'user', 'customer_id' => '1', 'currency' => 'EUR']);

    return SubscriptionItem::create(['subscription_id' => $sub->id, 'product_id' => $product->id, 'price_id' => $price->id, 'quantity' => 1]);
}

function blockWindow(): Period
{
    return new Period(CarbonImmutable::parse('2026-06-01Z'), CarbonImmutable::parse('2026-07-01Z'));
}

it('bills per block after the included allowance (ceil)', function (float $used, int $expectedMinor) {
    $item = blockItem(['included_qty' => 100, 'block_size' => 50, 'rate' => '5.00000000']); // €5 per 50 TB block

    Meteric::recordUsage($item, 'traffic', $used, CarbonImmutable::parse('2026-06-10Z'));
    $charges = Meteric::rollupUsage($item, blockWindow());

    expect($charges[0]->amount_minor)->toBe($expectedMinor);
})->with([
    'within free' => [100.0, 0],     // overage 0
    'one over' => [101.0, 500],      // overage 1 -> 1 block -> €5
    'full block' => [150.0, 500],    // overage 50 -> 1 block
    'into second' => [151.0, 1000],  // overage 51 -> 2 blocks -> €10
    'two blocks' => [200.0, 1000],   // overage 100 -> 2 blocks
]);

it('records the unit and used quantity on the charge for formatting', function () {
    $item = blockItem(['included_qty' => 100, 'block_size' => 50, 'rate' => '5.00000000']);
    Meteric::recordUsage($item, 'traffic', 150, CarbonImmutable::parse('2026-06-10Z'));

    $charge = Meteric::rollupUsage($item, blockWindow())[0];

    expect($charge->metadata['unit'])->toBe('TB')
        ->and((float) $charge->metadata['used'])->toBe(150.0)
        ->and((float) $charge->quantity)->toBe(1.0); // 1 block
});

it('uses the last reported value for cycle-cumulative metering', function () {
    // Tenant API returns cycle-to-date usage that resets each cycle: aggregation 'last'.
    $item = blockItem(['aggregation' => 'last', 'rate' => '0.10000000', 'included_qty' => 0]);

    Meteric::recordUsage($item, 'traffic', 10, CarbonImmutable::parse('2026-06-05Z'), key: 'd1');
    Meteric::recordUsage($item, 'traffic', 50, CarbonImmutable::parse('2026-06-15Z'), key: 'd2');
    Meteric::recordUsage($item, 'traffic', 120, CarbonImmutable::parse('2026-06-25Z'), key: 'd3');

    $charge = Meteric::rollupUsage($item, blockWindow())[0];

    // last = 120 (not the sum 180). 120 × €0.10 = €12.00
    expect($charge->amount_minor)->toBe(1200);
});

it('exposes the billing cycle window to query the usage API against', function () {
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $p = Product::create(['type' => 'vps', 'slug' => 'bc-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);
    $price = Price::create(['product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => 1000, 'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1]);
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($price, 1)->create();

    $cycle = Meteric::billingCycle($sub->items->first());

    expect($cycle)->not->toBeNull()
        ->and($cycle->start->toDateString())->toBe('2026-06-01')
        ->and($cycle->end->toDateString())->toBe('2026-07-01');
});
