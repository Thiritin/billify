<?php

declare(strict_types=1);

namespace Meteric\Console;

use Illuminate\Console\Command;
use Meteric\Subscriptions\SubscriptionManager;

/**
 * Flag issued invoices past their due date as overdue and fire InvoiceOverdue /
 * SubscriptionPastDue. Schedule this; your listeners decide what to suspend.
 */
final class MarkOverdueCommand extends Command
{
    protected $signature = 'meteric:mark-overdue';

    protected $description = 'Mark past-due unpaid invoices overdue and fire the events';

    public function handle(SubscriptionManager $manager): int
    {
        $count = $manager->markOverdue();
        $this->info("meteric:mark-overdue done: {$count} overdue invoice(s).");

        return self::SUCCESS;
    }
}
