<?php

declare(strict_types=1);

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Meteric\Enums\SubscriptionState;
use Meteric\Events\InvoiceOverdue;
use Meteric\Events\InvoicePaid;
use Meteric\Events\InvoicePartiallyPaid;
use Meteric\Events\SubscriptionPastDue;
use Meteric\Events\SubscriptionPaused;
use Meteric\Events\SubscriptionResumed;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Price;
use Meteric\Models\Product;

uses(RefreshDatabase::class);

function eventsPlan(int $minor = 1000): Price
{
    $p = Product::create(['type' => 'vps', 'slug' => 'ev-'.uniqid(), 'name' => 'VPS', 'pricing_model' => 'fixed']);

    return Price::create([
        'product_id' => $p->id, 'currency' => 'EUR', 'amount_minor' => $minor,
        'pricing_model' => 'fixed', 'interval' => 'month', 'interval_count' => 1,
    ]);
}

function eventsAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

it('fires InvoicePaid on full payment and the invoice maps back to its subscription', function () {
    Event::fake([InvoicePaid::class, InvoicePartiallyPaid::class]);
    $acc = eventsAccount();
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add(eventsPlan(1000), 1)->create();
    $invoice = Meteric::invoicePending($acc);

    Meteric::recordPayment($invoice, Money::ofMinor($invoice->total_minor, 'EUR'), 'pi_1');

    Event::assertDispatched(InvoicePaid::class);
    Event::assertNotDispatched(InvoicePartiallyPaid::class);
    expect($invoice->fresh()->subscriptions()->pluck('id')->all())->toContain($sub->id);
});

it('fires InvoicePartiallyPaid on a part payment', function () {
    Event::fake([InvoicePaid::class, InvoicePartiallyPaid::class]);
    $acc = eventsAccount();
    Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add(eventsPlan(1000), 1)->create();
    $invoice = Meteric::invoicePending($acc);

    Meteric::recordPayment($invoice, Money::ofMinor(400, 'EUR'), 'pi_part');

    Event::assertDispatched(InvoicePartiallyPaid::class);
    Event::assertNotDispatched(InvoicePaid::class);
});

it('sets a due date on issue from net_days', function () {
    config()->set('meteric.invoice.net_days', 14);
    $acc = eventsAccount();
    Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add(eventsPlan(1000), 1)->create();
    $invoice = Meteric::invoicePending($acc);

    expect($invoice->due_at)->not->toBeNull()
        ->and($invoice->due_at->greaterThan($invoice->issued_at))->toBeTrue();
});

it('marks overdue invoices past_due and fires the events', function () {
    Event::fake([InvoiceOverdue::class, SubscriptionPastDue::class]);
    config()->set('meteric.invoice.net_days', 14);
    $acc = eventsAccount();
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add(eventsPlan(1000), 1)->create();
    Meteric::invoicePending($acc); // issued now, due in net_days

    // due_at is real-time (issued_at = now), so check past the net window.
    $count = Meteric::markOverdue(CarbonImmutable::now()->addDays(30));

    expect($count)->toBe(1)
        ->and($sub->fresh()->state)->toBe(SubscriptionState::PastDue);
    Event::assertDispatched(InvoiceOverdue::class);
    Event::assertDispatched(SubscriptionPastDue::class);
});

it('pause stops billing and resume restarts it (the suspension hook)', function () {
    Event::fake([SubscriptionPaused::class, SubscriptionResumed::class]);
    $acc = eventsAccount();
    $sub = Meteric::subscribe()->account($acc)->at(CarbonImmutable::parse('2026-06-01Z'))->add(eventsPlan(1000), 1)->create();

    Meteric::pause($sub);
    expect($sub->fresh()->state)->toBe(SubscriptionState::Paused);

    // No active service, no invoice: a renewal past the period accrues nothing while paused.
    $charges = Meteric::renew($sub->fresh(), CarbonImmutable::parse('2026-08-01Z'));
    expect($charges)->toHaveCount(0);

    Meteric::resume($sub->fresh());
    expect($sub->fresh()->state)->toBe(SubscriptionState::Active);

    Event::assertDispatched(SubscriptionPaused::class);
    Event::assertDispatched(SubscriptionResumed::class);
});
