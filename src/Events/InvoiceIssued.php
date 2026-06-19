<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\Invoice;

/** An invoice was issued (driver confirmed). */
final class InvoiceIssued
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
