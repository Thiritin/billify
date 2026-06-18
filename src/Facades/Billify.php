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
 * @method static \Billify\Subscriptions\SubscriptionBuilder subscribe(?\Illuminate\Database\Eloquent\Model $customer = null)
 * @method static array renew(\Billify\Models\Subscription $sub, ?\Carbon\CarbonImmutable $at = null)
 * @method static \Billify\Models\SubscriptionItem changePlan(\Billify\Models\SubscriptionItem $item, \Billify\Models\Price $newPrice, string $apply = 'now', ?\Carbon\CarbonImmutable $at = null)
 * @method static \Billify\Models\Subscription cancel(\Billify\Models\Subscription $sub, string $at = 'period_end', ?\Carbon\CarbonImmutable $when = null)
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
