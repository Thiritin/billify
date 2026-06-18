<?php

declare(strict_types=1);

namespace Billify\Contracts;

use Billify\Invoicing\CreditNoteDraft;
use Billify\Invoicing\InvoiceDraft;
use Billify\Invoicing\IssuedCreditNote;
use Billify\Invoicing\IssuedInvoice;

/**
 * Emits invoices to a persistence/accounting target. THE failure boundary:
 * if issue() throws, the caller leaves all charges `pending` and writes no
 * `invoiced` state — so an outage never loses revenue (DESIGN §2.5).
 *
 * Implementations: DatabaseInvoiceDriver (ships), LexofficeInvoiceDriver (app).
 */
interface InvoiceDriver
{
    public function issue(InvoiceDraft $draft): IssuedInvoice;

    public function void(IssuedInvoice $invoice): void;

    public function creditNote(IssuedInvoice $invoice, CreditNoteDraft $draft): IssuedCreditNote;
}
