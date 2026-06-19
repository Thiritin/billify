<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Invoice;
use Meteric\Models\Payment;

final class InvoicePartiallyPaid
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly Payment $payment,
    ) {}
}
