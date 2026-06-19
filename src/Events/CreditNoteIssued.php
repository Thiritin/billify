<?php

declare(strict_types=1);

namespace Meteric\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meteric\Models\CreditNote;

/**
 * A credit note was issued against an invoice (a correction or a refund record).
 * The actual money return is your gateway's job; this is the accounting document.
 */
final class CreditNoteIssued
{
    use Dispatchable;

    public function __construct(public readonly CreditNote $creditNote) {}
}
