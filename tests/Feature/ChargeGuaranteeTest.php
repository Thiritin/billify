<?php

declare(strict_types=1);

use Billify\Billify;
use Billify\Contracts\InvoiceDriver;
use Billify\Enums\ChargeState;
use Billify\Enums\LineKind;
use Billify\Facades\Billify as BillifyFacade;
use Billify\Invoicing\CreditNoteDraft;
use Billify\Invoicing\InvoiceDraft;
use Billify\Invoicing\IssuedCreditNote;
use Billify\Invoicing\IssuedInvoice;
use Billify\Models\BillingAccount;
use Billify\Models\Charge;
use Billify\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function guaranteeAccount(): BillingAccount
{
    return BillingAccount::create(['owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR']);
}

function guaranteeCharge(BillingAccount $acc, int $minor, string $currency = 'EUR'): Charge
{
    return Charge::create([
        'account_id' => $acc->id,
        'origin_type' => 'manual', 'origin_id' => (string) Str::uuid(),
        'kind' => LineKind::OneOff, 'billing_mode' => 'in_advance',
        'state' => ChargeState::Pending, 'description' => 'Item',
        'quantity' => 1, 'unit_minor' => $minor, 'amount_minor' => $minor,
        'currency' => $currency, 'idempotency_key' => (string) Str::uuid(),
    ]);
}

/** A driver that always fails — simulates the accounting system being down. */
function throwingDriver(): InvoiceDriver
{
    return new class implements InvoiceDriver
    {
        public function issue(InvoiceDraft $draft): IssuedInvoice
        {
            throw new RuntimeException('accounting system unavailable');
        }

        public function void(IssuedInvoice $invoice): void {}

        public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote
        {
            throw new RuntimeException('unavailable');
        }
    };
}

it('keeps charges pending when the invoice driver fails (the core guarantee)', function () {
    $acc = guaranteeAccount();
    guaranteeCharge($acc, 1000);
    guaranteeCharge($acc, 2000);

    $billify = new Billify(throwingDriver());

    expect(fn () => $billify->invoicePending($acc))->toThrow(RuntimeException::class);

    // No invoice written, charges untouched — revenue not lost, retried next run.
    expect(Invoice::count())->toBe(0)
        ->and(Charge::where('account_id', $acc->id)->pending()->count())->toBe(2);
});

it('only bills charges in the requested currency', function () {
    $acc = guaranteeAccount();
    guaranteeCharge($acc, 1000, 'EUR');
    guaranteeCharge($acc, 5000, 'USD');

    $invoice = BillifyFacade::invoicePending($acc); // defaults to account currency EUR

    expect($invoice->currency)->toBe('EUR')
        ->and($invoice->subtotal_minor)->toBe(1000)
        ->and(Charge::where('account_id', $acc->id)->where('currency', 'USD')->pending()->count())->toBe(1);
});
