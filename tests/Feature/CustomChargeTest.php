<?php

declare(strict_types=1);

use Brick\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meteric\Facades\Meteric;
use Meteric\Models\BillingAccount;

uses(RefreshDatabase::class);

it('adds a custom charge that the next invoice bills', function () {
    $account = BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);

    $charge = Meteric::charge($account, Money::ofMinor(5000, 'EUR'), 'Setup fee', group: 'Services');

    expect($charge->state->value)->toBe('pending')
        ->and($charge->amount_minor)->toBe(5000)
        ->and($charge->title)->toBe('Setup fee');

    // The pending charge rides the account's next invoice.
    $invoice = Meteric::invoicePending($account);

    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(5000)
        ->and($charge->fresh()->state->value)->toBe('invoiced');
});
