<?php

declare(strict_types=1);

namespace Meteric\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Meteric\Contracts\Clock;
use Meteric\Meteric;
use Meteric\Models\BillingAccount;
use Meteric\Models\Subscription;

/**
 * Close due billing cycles: roll up each due subscription's elapsed usage window
 * into charges, then renew (accrue the next cycle). Idempotent via the billing
 * guard, so schedule it as often as you like. Pass --invoice to also issue an
 * invoice per affected account; leave it off to invoice on your own schedule.
 */
final class RunBillingCommand extends Command
{
    protected $signature = 'meteric:bill {--invoice : Issue invoices for affected accounts} {--at= : Run as of this datetime}';

    protected $description = 'Roll up due usage and renew due subscriptions';

    public function handle(Meteric $meteric, Clock $clock): int
    {
        $at = $this->option('at') ? CarbonImmutable::parse((string) $this->option('at')) : $clock->now();

        $rolled = 0;
        $renewed = 0;
        $accountIds = [];

        Subscription::query()->dueForRenewal($at)->with('items')->cursor()->each(
            function (Subscription $sub) use ($meteric, $at, &$rolled, &$renewed, &$accountIds): void {
                foreach ($sub->items as $item) {
                    $item->setRelation('subscription', $sub);
                    if ($item->current_period !== null) {
                        $rolled += count($meteric->rollupUsage($item, $item->current_period));
                    }
                }
                $renewed += count($meteric->renew($sub, $at));
                $accountIds[$sub->account_id] = $sub->account_id;
            }
        );

        if ($this->option('invoice')) {
            foreach ($accountIds as $id) {
                $account = BillingAccount::find($id);
                if ($account !== null) {
                    $meteric->invoicePending($account);
                }
            }
        }

        $this->info("meteric:bill done: {$rolled} usage charge(s), {$renewed} renewal charge(s) across ".count($accountIds).' account(s).');

        return self::SUCCESS;
    }
}
