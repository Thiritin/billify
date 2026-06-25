<?php

declare(strict_types=1);

namespace Meteric\Enums;

/** What happens to an invoice's charges when the invoice is voided. */
enum VoidCharges: string
{
    /** Leave the charges untouched. The document was wrong (e.g. address); re-issue it manually. */
    case Keep = 'keep';

    /** Detach and return the charges to pending so they bill again on the next run. */
    case Release = 'release';

    /** Void the charges too: they were the error and should not bill. */
    case Discard = 'discard';
}
