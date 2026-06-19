<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Enums\ItemState;
use Meteric\Enums\LineKind;
use Meteric\Enums\OptionType;
use Meteric\Facades\Meteric;
use Meteric\Models\Addon;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\SubscriptionItem;
use Meteric\Subscriptions\ItemManager;

uses(RefreshDatabase::class);

function mutEdgePrice(int $minor, string $model = 'fixed'): Price
{
    $product = Product::create(['type' => 'vps', 'slug' => 'me-'.uniqid(), 'name' => 'ME', 'pricing_model' => $model]);

    return Price::create([
        'product_id' => $product->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => $model, 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function mutEdgeItem(): SubscriptionItem
{
    $acc = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
    $sub = Meteric::subscribe()->account($acc)
        ->at(CarbonImmutable::parse('2026-06-01T00:00:00Z'))
        ->add(mutEdgePrice(3000), 1)
        ->create();
    $item = $sub->items->first();
    $item->setRelation('subscription', $sub);

    return $item;
}

function mutEdgeAt(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-16T00:00:00Z'); // 15/30 days left
}

it('removes an addon directly with a prorated credit', function () {
    $item = mutEdgeItem();
    $ram = mutEdgePrice(1000);
    $addon = Meteric::addAddon($item, $ram, group: 'ram', qty: 1, at: mutEdgeAt());

    app(ItemManager::class)->removeAddon($addon->fresh(), mutEdgeAt());

    // Booking charged +500 (15/30 of 1000); removal credits -500.
    $credit = Charge::where('origin_type', 'addon')->where('kind', LineKind::Credit->value)->first();
    expect($credit)->not->toBeNull()
        ->and($credit->amount_minor)->toBe(-500)
        ->and($addon->fresh()->state)->toBe(ItemState::Canceled);
});

it('keeps an addon active across a base plan change', function () {
    $item = mutEdgeItem();
    $addon = Meteric::addAddon($item, mutEdgePrice(1000), group: 'ram', qty: 1, at: mutEdgeAt());

    // Upgrade the base item to a larger plan.
    $large = mutEdgePrice(6000);
    $item->setRelation('price', $item->price);
    Meteric::changePlan($item, $large, at: mutEdgeAt());

    $survived = Addon::findOrFail($addon->id);
    expect($survived->state)->toBe(ItemState::Active)
        ->and($survived->item_id)->toBe($item->id)
        ->and($item->fresh()->price_id)->toBe($large->id);
});

it('sets a choice option without a price delta charge', function () {
    $item = mutEdgeItem();

    $option = Meteric::setOption($item, 'location', 'fsn1', 'choice', at: mutEdgeAt());

    expect($option->type)->toBe(OptionType::Choice)
        ->and($option->value)->toBe('fsn1')
        ->and(Charge::where('origin_type', 'item_option')->count())->toBe(0);
});

it('sets a toggle option with a prorated charge', function () {
    $item = mutEdgeItem();
    $backups = mutEdgePrice(400); // €4/mo flag

    $option = Meteric::setOption($item, 'backups', 'on', 'toggle', price: $backups, qty: 1, at: mutEdgeAt());

    expect($option->type)->toBe(OptionType::Toggle);
    // €4 prorated 15/30 = €2.00
    $charge = Charge::where('origin_type', 'item_option')->first();
    expect($charge->amount_minor)->toBe(200)
        ->and($charge->kind)->toBe(LineKind::Option);
});

it('credits a base quantity decrease', function () {
    $item = mutEdgeItem();
    Meteric::setQuantity($item, 3, mutEdgeAt()); // +2 -> +3000

    Meteric::setQuantity($item->fresh(), 1, mutEdgeAt()); // -2 -> credit

    $credit = Charge::where('description', 'Quantity change')->where('kind', LineKind::Credit->value)->first();
    expect($credit)->not->toBeNull()
        ->and($credit->amount_minor)->toBe(-3000)
        ->and($item->fresh()->quantity)->toBe(1.0);
});

it('credits a configurable option decrease', function () {
    $item = mutEdgeItem();
    $slot = mutEdgePrice(30, 'per_unit'); // €0.30/slot

    Meteric::setOption($item, 'slots', '8', 'quantity', price: $slot, qty: 8, at: mutEdgeAt()); // +120
    Meteric::setOption($item->fresh(), 'slots', '2', 'quantity', price: $slot, qty: 2, at: mutEdgeAt()); // -6 slots

    // delta -6 slots × €0.30 = €1.80, prorated 15/30 = €0.90 credit.
    $credit = Charge::where('origin_type', 'item_option')->where('kind', LineKind::Credit->value)->first();
    expect($credit)->not->toBeNull()
        ->and($credit->amount_minor)->toBe(-90);
});
