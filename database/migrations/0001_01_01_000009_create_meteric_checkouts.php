<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Meteric\Enums\CheckoutState;
use Meteric\Support\Pg;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meteric_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('account_id')->constrained('meteric_billing_accounts')->restrictOnDelete();
            $table->string('customer_type');                  // frozen morph of the buyer
            $table->string('customer_id');
            $table->char('currency', 3);
            $table->string('state')->default(CheckoutState::Pending->value);
            $table->string('anchor_mode')->default('signup');
            $table->smallInteger('anchor_day')->nullable();
            $table->string('first_period')->default('prorate_only');
            $table->integer('trial_days')->default(0);
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('recurring_total_minor')->default(0);  // ongoing-period total (display only)

            // The whole frozen cart: one row per intended subscription item, each
            // carrying its frozen due-now amount, kind, first-period range, and its
            // addons/options. The amounts here are the immutable source of truth, so
            // later catalog price changes never move a pending order's figures.
            $table->jsonb('contents')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('quote_snapshot')->default(DB::raw("'{}'::jsonb"));

            $table->string('token')->unique();
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignUuid('invoice_id')->nullable()->constrained('meteric_invoices')->nullOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('meteric_subscriptions')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->integer('version')->default(0);
            $table->timestampsTz();

            $table->index('account_id');
            // Sweep target: only pending orders that can expire.
            $table->index('expires_at', 'meteric_checkouts_expiry_idx')->where("state = 'pending'");
        });
        Pg::currencyCheck('meteric_checkouts');
        Pg::enumCheck('meteric_checkouts', 'state', CheckoutState::class);
        Pg::check('meteric_checkouts', 'meteric_checkouts_total_nonneg', 'total_minor >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('meteric_checkouts');
    }
};
