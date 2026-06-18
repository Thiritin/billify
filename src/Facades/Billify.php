<?php

declare(strict_types=1);

namespace Billify\Facades;

use Billify\Contracts\InvoiceDriver;
use Billify\Models\BillingAccount;
use Billify\Models\Invoice;
use Billify\Models\Payment;
use Brick\Money\Money;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ?Invoice invoicePending(BillingAccount $account, ?string $currency = null)
 * @method static Payment recordPayment(Invoice $invoice, Money $amount, ?string $reference = null)
 * @method static \Billify\Quoting\QuoteBuilder quote()
 * @method static InvoiceDriver driver()
 *
 * @see \Billify\Billify
 */
final class Billify extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Billify\Billify::class;
    }
}
