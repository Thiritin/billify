<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Invoice;

/**
 * An issued invoice passed its due date unpaid (fired by meteric:mark-overdue).
 * This is your suspension trigger: decide per product whether to suspend
 * (prepaid: pause billing + stop the service) or keep invoicing and dun
 * (contracts).
 */
final class InvoiceOverdue
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
