<?php

declare(strict_types=1);

namespace Billify\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends BillifyModel
{
    protected $table = 'billify_payment_allocations';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
