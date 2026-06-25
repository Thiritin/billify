<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Meteric\Enums\ChargeState;
use Meteric\Enums\LineKind;
use Meteric\Enums\OptionType;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Charge;
use Meteric\Models\Price;
use Meteric\Models\Product;
use Meteric\Models\SubscriptionItem;

uses(RefreshDatabase::class);

function lgAccount(): BillingAccount
{
    return BillingAccount::create([
        'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
        'tax_profile' => ['country' => 'DE', 'merchant_country' => 'DE'],
    ]);
}

function lgBasePrice(): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => 'lg-'.uniqid(), 'name' => 'VPS XL', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => 1000,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function lgFixedOption(string $product, int $minor): Price
{
    return Price::create([
        'product_id' => $product, 'currency' => 'EUR', 'purpose' => 'option',
        'pricing_model' => 'fixed', 'amount_minor' => $minor,
        'interval' => 'month', 'interval_count' => 1,
    ]);
}

function lgAddonPrice(int $minor): Price
{
    $p = Product::create(['type' => 'addon', 'slug' => 'lga-'.uniqid(), 'name' => 'Backups', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'purpose' => 'addon',
        'pricing_model' => 'fixed', 'amount_minor' => $minor,
        'interval' => 'month', 'interval_count' => 1,
    ]);
}

/**
 * A product with a base + a configurable option + an addon, all accrued for the
 * same renewal period. Returns [account, item, the July charges].
 *
 * @return array{BillingAccount, SubscriptionItem, Collection<int,Charge>}
 */
function lgProductWithExtras(): array
{
    $acc = lgAccount();
    $base = lgBasePrice();
    $at = CarbonImmutable::parse('2026-06-01Z');

    $sub = Meteric::subscribe()->account($acc)->at($at)->add($base, 1)->create();
    /** @var SubscriptionItem $item */
    $item = $sub->items()->first();
    $item->setRelation('subscription', $sub)->setRelation('price', $base);

    Meteric::setOption($item, 'slots', '2', OptionType::Quantity->value, lgFixedOption($base->product_id, 300), 2, $at);
    Meteric::addAddon($item, lgAddonPrice(200), group: 'backups', qty: 1, at: $at);

    // Drop the June accrual so only the clean July period remains under test.
    Charge::query()->whereRaw("lower(covers) = '2026-06-01 00:00:00+00'")->delete();
    Charge::query()->whereNull('covers')->delete();

    Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-07-01Z'));

    $july = Charge::query()
        ->where('account_id', $acc->id)
        ->whereRaw("lower(covers) = '2026-07-01 00:00:00+00'")
        ->orderBy('created_at')
        ->get();

    return [$acc, $item, $july];
}

it('tags base, option, and addon charges with the owning item id as line_group', function () {
    [, $item, $july] = lgProductWithExtras();

    expect($july)->toHaveCount(3)
        ->and($july->pluck('line_group')->unique()->all())->toBe([$item->id])
        ->and($july->pluck('kind')->map->value->sort()->values()->all())
        ->toBe(['addon', 'option', 'recurring']);
});

it('itemizes one line per charge by default, sharing the line_group', function () {
    [$acc, $item, $july] = lgProductWithExtras();

    $invoice = Meteric::invoicePending($acc);

    expect($invoice->lines)->toHaveCount(3)
        ->and($invoice->lines->pluck('line_group')->unique()->all())->toBe([$item->id])
        ->and($invoice->subtotal_minor)->toBe(1800);   // 1000 base + 600 option (300x2) + 200 addon

    expect($july->pluck('state')->unique()->all())->toBe([ChargeState::Pending]);
});

it('consolidates a product and its extras into one line, totals unchanged', function () {
    config(['meteric.invoice.line_mode' => 'consolidated']);

    [$acc, $item, $july] = lgProductWithExtras();

    // Itemized totals for the same charges, computed independently.
    $expectedSubtotal = (int) $july->sum('amount_minor');

    $invoice = Meteric::invoicePending($acc);

    expect($invoice->lines)->toHaveCount(1);

    $line = $invoice->lines->first();

    expect($line->line_group)->toBe($item->id)
        ->and($line->kind)->toBe(LineKind::Recurring)         // the base line is the parent
        ->and($line->amount_minor)->toBe(1800)                // 1000 base + 600 option (300x2) + 200 addon
        ->and($line->amount_minor)->toBe($expectedSubtotal)
        ->and($line->description)->toContain('Slots')         // option folded in
        ->and($line->description)->toContain('Backups');      // addon folded in

    // Structured sub-items for templates.
    $items = $line->metadata['items'] ?? [];
    expect($items)->toHaveCount(2)
        ->and(collect($items)->pluck('kind')->sort()->values()->all())->toBe(['addon', 'option'])
        ->and((int) collect($items)->sum('amount_minor'))->toBe(800);   // option 600 + addon 200

    // The invoice math equals the itemized math: subtotal = sum of charges, and
    // tax is recomputed on the summed net so subtotal + tax = total still holds.
    expect($invoice->subtotal_minor)->toBe($expectedSubtotal)
        ->and($invoice->total_minor)->toBe($invoice->subtotal_minor + $invoice->tax_minor);

    // Every charge in the group still flips to invoiced, even the ones with no line.
    expect(Charge::whereIn('id', $july->pluck('id'))->where('state', ChargeState::Invoiced->value)->count())->toBe(3);
});

it('consolidated subtotal/tax/total equal the itemized invoice for the same charges', function () {
    // Itemized run.
    [$acc1] = lgProductWithExtras();
    $itemized = Meteric::invoicePending($acc1);

    // Consolidated run with an independent but identical product.
    config(['meteric.invoice.line_mode' => 'consolidated']);
    [$acc2] = lgProductWithExtras();
    $consolidated = Meteric::invoicePending($acc2);

    expect($consolidated->subtotal_minor)->toBe($itemized->subtotal_minor)
        ->and($consolidated->tax_minor)->toBe($itemized->tax_minor)
        ->and($consolidated->total_minor)->toBe($itemized->total_minor)
        ->and($itemized->lines)->toHaveCount(3)
        ->and($consolidated->lines)->toHaveCount(1);
});

it('keeps an account-level charge with no item as its own line when consolidated', function () {
    config(['meteric.invoice.line_mode' => 'consolidated']);

    $acc = lgAccount();
    Charge::create([
        'account_id' => $acc->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::OneOff, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => 'Domain registration',
        'quantity' => 1, 'unit_minor' => 900, 'amount_minor' => 900,
        'currency' => 'EUR', 'idempotency_key' => (string) Str::uuid(),
    ]);

    $invoice = Meteric::invoicePending($acc);

    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->line_group)->toBeNull()
        ->and($invoice->lines->first()->amount_minor)->toBe(900);
});
