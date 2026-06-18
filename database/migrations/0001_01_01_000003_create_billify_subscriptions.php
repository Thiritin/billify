<?php

declare(strict_types=1);

use Billify\Enums\BillingMode;
use Billify\Enums\CommitmentState;
use Billify\Enums\Interval;
use Billify\Enums\ItemState;
use Billify\Enums\OptionType;
use Billify\Enums\SubscriptionState;
use Billify\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billify_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('account_id')->constrained('billify_billing_accounts')->restrictOnDelete();
            $table->string('customer_type');
            $table->string('customer_id');
            $table->char('currency', 3);
            $table->string('state')->default(SubscriptionState::Incomplete->value);
            $table->string('anchor_mode')->default('signup');
            $table->smallInteger('anchor_day')->nullable();
            $table->string('first_period')->default('prorate_only');
            $table->timestampTzRange('current_period')->nullable();
            $table->timestampTz('trial_end')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('cancel_at')->nullable();
            $table->integer('version')->default(0);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index('account_id');
            $table->index(['customer_type', 'customer_id']);
            $table->index('(upper(current_period))', 'billify_subs_due_idx')->where("state IN ('active','trialing','past_due')");
        });
        Pg::currencyCheck('billify_subscriptions');
        Pg::enumCheck('billify_subscriptions', 'state', SubscriptionState::class);
        Pg::check('billify_subscriptions', 'billify_subs_anchor_day', 'anchor_day IS NULL OR anchor_day BETWEEN 1 AND 31');

        Schema::create('billify_subscription_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('subscription_id')->constrained('billify_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('billify_products')->restrictOnDelete();
            $table->foreignUuid('price_id')->constrained('billify_prices')->restrictOnDelete();
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->string('billing_mode')->nullable();
            $table->string('state')->default(ItemState::Pending->value);
            $table->timestampTzRange('current_period')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->jsonb('pending_change')->nullable();
            $table->integer('version')->default(0);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index('subscription_id');
            $table->index(['resource_type', 'resource_id']);
            $table->index('(upper(current_period))', 'billify_items_due_idx')->where("state = 'active'");
        });
        Pg::enumCheck('billify_subscription_items', 'state', ItemState::class);
        Pg::enumCheck('billify_subscription_items', 'billing_mode', BillingMode::class, nullable: true);
        Pg::check('billify_subscription_items', 'billify_items_qty_nonneg', 'quantity >= 0');

        Schema::create('billify_addons', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('billify_subscription_items')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('billify_products')->restrictOnDelete();
            $table->foreignUuid('price_id')->constrained('billify_prices')->restrictOnDelete();
            $table->string('group_key')->nullable();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->string('state')->default(ItemState::Active->value);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            // At most one active addon per group per item.
            $table->uniqueIndex(['item_id', 'group_key'])->where("state = 'active' AND group_key IS NOT NULL");
        });
        Pg::enumCheck('billify_addons', 'state', ItemState::class);

        Schema::create('billify_item_options', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('billify_subscription_items')->cascadeOnDelete();
            $table->string('key');
            $table->string('type');
            $table->string('value');
            $table->foreignUuid('price_id')->nullable()->constrained('billify_prices')->restrictOnDelete();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->timestampsTz();

            $table->unique(['item_id', 'key']);
        });
        Pg::enumCheck('billify_item_options', 'type', OptionType::class);

        Schema::create('billify_commitments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('billify_subscription_items')->cascadeOnDelete();
            $table->string('term_interval');
            $table->integer('term_count');
            $table->bigInteger('upfront_minor')->default(0);
            $table->bigInteger('rate_minor');
            $table->char('currency', 3);
            $table->timestampTzRange('term');
            $table->jsonb('early_term')->default(DB::raw("'{}'::jsonb"));
            $table->string('state')->default(CommitmentState::Active->value);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('item_id');
        });
        Pg::currencyCheck('billify_commitments');
        Pg::enumCheck('billify_commitments', 'term_interval', Interval::class);
        Pg::enumCheck('billify_commitments', 'state', CommitmentState::class);
        Pg::check('billify_commitments', 'billify_commit_term_pos', 'term_count > 0');

        Schema::create('billify_allowances', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('item_id')->constrained('billify_subscription_items')->cascadeOnDelete();
            $table->foreignUuid('dimension_id')->constrained('billify_meter_dimensions')->cascadeOnDelete();
            $table->decimal('included_qty', 20, 6);
            $table->timestampTzRange('period');
            $table->decimal('consumed_qty', 20, 6)->default(0);
            $table->string('shared_pool')->nullable();

            $table->unique(['item_id', 'dimension_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billify_allowances');
        Schema::dropIfExists('billify_commitments');
        Schema::dropIfExists('billify_item_options');
        Schema::dropIfExists('billify_addons');
        Schema::dropIfExists('billify_subscription_items');
        Schema::dropIfExists('billify_subscriptions');
    }
};
