<?php

declare(strict_types=1);

use Billify\Enums\BillingMode;
use Billify\Enums\ChargeState;
use Billify\Enums\LineKind;
use Billify\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billify_usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('billify_subscription_items')->cascadeOnDelete();
            $table->foreignUuid('dimension_id')->constrained('billify_meter_dimensions')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->timestampTz('occurred_at');
            $table->timestampTzRange('window')->nullable();
            $table->string('source')->nullable();
            $table->string('idempotency_key')->unique();
            $table->uuid('charge_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['item_id', 'dimension_id', 'occurred_at'])->where('charge_id IS NULL');
        });
        Pg::check('billify_usage_records', 'billify_usage_qty_nonneg', 'quantity >= 0');

        // The double-bill guard. EXCLUDE has no Blueprint equivalent — raw is the
        // only way to express "no two billed windows overlap per item+dimension".
        Schema::create('billify_billing_periods', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('billify_subscription_items')->cascadeOnDelete();
            $table->uuid('dimension_id')->nullable();   // null = base recurring window
            $table->timestampTzRange('covers');
            $table->uuid('charge_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        Pg::check('billify_billing_periods', 'billify_period_valid', 'lower(covers) < upper(covers)');
        DB::statement(<<<'SQL'
        ALTER TABLE billify_billing_periods ADD CONSTRAINT billify_no_overlap EXCLUDE USING gist (
            item_id WITH =,
            COALESCE(dimension_id, '00000000-0000-0000-0000-000000000000'::uuid) WITH =,
            covers WITH &&
        )
        SQL);

        Schema::create('billify_charges', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('account_id')->constrained('billify_billing_accounts')->restrictOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('billify_subscriptions')->nullOnDelete();
            $table->string('origin_type');
            $table->string('origin_id');
            $table->foreignUuid('dimension_id')->nullable()->constrained('billify_meter_dimensions');
            $table->string('kind');
            $table->string('billing_mode');
            $table->string('state')->default(ChargeState::Pending->value);
            $table->string('description');
            $table->decimal('quantity', 20, 6)->default(1);
            $table->bigInteger('unit_minor')->nullable();        // integer unit price (fixed/per-unit)
            $table->decimal('unit_rate', 20, 8)->nullable();     // sub-cent unit rate (usage), for display
            $table->bigInteger('amount_minor');                  // rounded billable amount (integer minor)
            $table->char('currency', 3);
            $table->timestampTzRange('covers')->nullable();      // service period billed
            $table->uuid('invoice_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->integer('version')->default(0);
            $table->timestampsTz();

            $table->index(['account_id', 'currency'])->where("state = 'pending'");  // invoicing run hot path
            $table->index(['origin_type', 'origin_id']);
            $table->index('invoice_id');
        });
        Pg::currencyCheck('billify_charges');
        Pg::enumCheck('billify_charges', 'state', ChargeState::class);
        Pg::enumCheck('billify_charges', 'billing_mode', BillingMode::class);
        Pg::enumCheck('billify_charges', 'kind', LineKind::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('billify_charges');
        Schema::dropIfExists('billify_billing_periods');
        Schema::dropIfExists('billify_usage_records');
    }
};
