<?php

declare(strict_types=1);

use Billify\Enums\SubscriptionState;
use Billify\Facades\Billify;
use Billify\Models\BillingAccount;
use Billify\Models\Charge;
use Billify\Models\Price;
use Billify\Models\Product;
use Billify\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function planPrice(int $minor, string $slug): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => $slug.'-'.uniqid(), 'name' => $slug, 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function freshAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function subAt(BillingAccount $acc, Price $price, string $at): Subscription
{
    return Billify::subscribe()->account($acc)->at(CarbonImmutable::parse($at))->add($price, 1)->create();
}

it('renews the next cycle when the period has elapsed', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    // First cycle: one charge for June.
    expect(Charge::where('subscription_id', $sub->id)->count())->toBe(1);

    // Renew after July starts → second charge for July.
    $created = Billify::renew($sub, CarbonImmutable::parse('2026-07-02T00:00:00Z'));

    expect($created)->toHaveCount(1)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(2);
});

it('does not renew before the period elapses', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    $created = Billify::renew($sub, CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    expect($created)->toHaveCount(0)
        ->and(Charge::where('subscription_id', $sub->id)->count())->toBe(1);
});

it('prorates an immediate plan change (credit old + charge new)', function () {
    $acc = freshAccount();
    $small = planPrice(1000, 'small');
    $large = planPrice(3000, 'large');
    $sub = subAt($acc, $small, '2026-06-01T00:00:00Z');
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $small);

    // Upgrade halfway through June (15 days left of 30).
    Billify::changePlan($item, $large, 'now', CarbonImmutable::parse('2026-06-16T00:00:00Z'));

    $charges = Charge::where('subscription_id', $sub->id)->get();
    // base 1000 + credit (~-500 unused small) + prorated large (~+1500)
    expect($charges)->toHaveCount(3)
        ->and($item->fresh()->price_id)->toBe($large->id);

    $net = $charges->sum('amount_minor');
    expect($net)->toBe(1000 - 500 + 1500); // 2000
});

it('schedules a deferred plan change for period end', function () {
    $acc = freshAccount();
    $small = planPrice(1000, 'small');
    $large = planPrice(3000, 'large');
    $sub = subAt($acc, $small, '2026-06-01T00:00:00Z');
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);
    $item->setRelation('price', $small);

    Billify::changePlan($item, $large, 'period_end');

    expect($item->fresh()->price_id)->toBe($small->id)              // not yet applied
        ->and($item->fresh()->pending_change['price_id'])->toBe($large->id);

    // Renew at next cycle applies the change, then bills the new price.
    Billify::renew($sub, CarbonImmutable::parse('2026-07-02T00:00:00Z'));
    expect($item->fresh()->price_id)->toBe($large->id);
});

it('cancels immediately', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    Billify::cancel($sub, 'now', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    expect($sub->fresh()->state)->toBe(SubscriptionState::Canceled);
});

it('schedules a period-end cancel', function () {
    $acc = freshAccount();
    $sub = subAt($acc, planPrice(1000, 'std'), '2026-06-01T00:00:00Z');

    Billify::cancel($sub, 'period_end');

    expect($sub->fresh()->state)->toBe(SubscriptionState::Active)
        ->and($sub->fresh()->cancel_at)->not->toBeNull();
});
