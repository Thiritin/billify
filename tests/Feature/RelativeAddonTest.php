<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\LineKind;
use Meteric\Enums\UpgradePolicy;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\SubscriptionItem;

uses(RefreshDatabase::class);

function relAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function relBase(int $minor, string $name = 'VPS S', string $model = 'fixed'): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => 'rel-'.uniqid(), 'name' => $name, 'pricing_model' => $model]);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => $model, 'interval' => 'month', 'interval_count' => 1,
        'billing_mode' => $model === 'metered' ? 'in_arrears' : 'in_advance',
    ]);
}

function relAddon(float $percent, string $currency = 'EUR'): Price
{
    $p = Product::create(['type' => 'addon', 'slug' => 'relbk-'.uniqid(), 'name' => 'Backups', 'pricing_model' => 'relative']);

    return Price::create([
        'product_id' => $p->id, 'currency' => $currency, 'purpose' => 'addon',
        'pricing_model' => 'relative', 'percent' => $percent, 'amount_minor' => 0,
        'interval' => 'month', 'interval_count' => 1,
    ]);
}

function relItem(BillingAccount $acc, Price $base): SubscriptionItem
{
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add($base, 1)->create();
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub)->setRelation('price', $base);

    return $item;
}

function relJulyAddon(string $subId): ?Charge
{
    return Charge::where('subscription_id', $subId)->where('kind', LineKind::Addon->value)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")->first();
}

it('bills a relative addon as a percent of the base each cycle', function () {
    $acc = relAccount();
    $base = relBase(1000);                          // €10 base
    $item = relItem($acc, $base);

    Meteric::addAddon($item, relAddon(20), group: 'backups', qty: 1, at: CarbonImmutable::parse('2026-06-01Z'));
    Meteric::renew($item->subscription->fresh(), CarbonImmutable::parse('2026-07-02Z'));

    $line = relJulyAddon($item->subscription_id);
    expect($line->amount_minor)->toBe(200)          // 20% of €10
        ->and($line->description)->toBe('20% of VPS S')
        ->and($line->unit_minor)->toBe(200);        // per-line unit price reads the computed amount
});

it('tracks an upgrade: the percent recomputes against the new base', function () {
    $acc = relAccount();
    $base = relBase(1000);
    $big = relBase(5000, 'VPS XL');
    $item = relItem($acc, $base);

    Meteric::addAddon($item, relAddon(20), group: 'backups', qty: 1, at: CarbonImmutable::parse('2026-06-01Z'));
    Meteric::changePlan($item->fresh()->setRelation('subscription', $item->subscription)->setRelation('price', $base), $big, upgrade: UpgradePolicy::Prorate, at: CarbonImmutable::parse('2026-06-16Z'));
    Meteric::renew($item->subscription->fresh(), CarbonImmutable::parse('2026-07-02Z'));

    expect(relJulyAddon($item->subscription_id)->amount_minor)->toBe(1000); // 20% of €50
});

it('ignores the addon quantity for a relative price', function () {
    $acc = relAccount();
    $item = relItem($acc, relBase(1000));

    Meteric::addAddon($item, relAddon(20), group: 'backups', qty: 3, at: CarbonImmutable::parse('2026-06-01Z'));
    Meteric::renew($item->subscription->fresh(), CarbonImmutable::parse('2026-07-02Z'));

    expect(relJulyAddon($item->subscription_id)->amount_minor)->toBe(200); // 20% of base, not ×3
});

it('prorates a mid-cycle relative addon and credits it on removal', function () {
    $acc = relAccount();
    $item = relItem($acc, relBase(1000));

    // Added halfway through June: ~half of the €2 full = ~€1.
    $addon = Meteric::addAddon($item, relAddon(20), group: 'backups', qty: 1, at: CarbonImmutable::parse('2026-06-16Z'));
    $added = Charge::where('subscription_id', $item->subscription_id)->where('kind', LineKind::Addon->value)->latest('created_at')->first();
    expect($added->amount_minor)->toBe(100);

    Meteric::removeAddon($addon->fresh(), CarbonImmutable::parse('2026-06-16Z'));
    $credit = Charge::where('subscription_id', $item->subscription_id)->where('kind', LineKind::Credit->value)->first();
    expect($credit->amount_minor)->toBe(-100);
});

it('rejects a relative addon on a usage base or a mismatched currency', function () {
    $acc = relAccount();
    $usageItem = relItem($acc, relBase(0, 'Metered', 'metered'));
    expect(fn () => Meteric::addAddon($usageItem, relAddon(20), at: CarbonImmutable::parse('2026-06-01Z')))
        ->toThrow(InvalidArgumentException::class);

    $item = relItem(relAccount(), relBase(1000));
    expect(fn () => Meteric::addAddon($item, relAddon(20, 'USD'), at: CarbonImmutable::parse('2026-06-01Z')))
        ->toThrow(InvalidArgumentException::class);
});
