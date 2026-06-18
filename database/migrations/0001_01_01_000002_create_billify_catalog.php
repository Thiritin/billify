<?php

declare(strict_types=1);

use Billify\Enums\Aggregation;
use Billify\Enums\BillingMode;
use Billify\Enums\PricePurpose;
use Billify\Enums\PricingModel;
use Billify\Support\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tpetry\PostgresqlEnhanced\Schema\Blueprint;
use Tpetry\PostgresqlEnhanced\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billify_billing_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('parent_id')->nullable();
            $table->string('owner_type');
            $table->string('owner_id');
            $table->char('currency', 3);
            $table->jsonb('tax_profile')->default(DB::raw("'{}'::jsonb"));
            $table->bigInteger('balance_minor')->default(0);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['owner_type', 'owner_id']);
            $table->index('parent_id');
        });
        // Self-referencing FK added after the table (and its PK) exists.
        Schema::table('billify_billing_accounts', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('billify_billing_accounts')->restrictOnDelete();
        });
        Pg::currencyCheck('billify_billing_accounts');

        Schema::create('billify_products', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('billable_type')->nullable();
            $table->string('billable_id')->nullable();
            $table->string('type');
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('pricing_model');
            $table->boolean('is_proratable')->default(true);
            $table->jsonb('config')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('active')->default(true);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['billable_type', 'billable_id']);
            $table->index('type')->where('active');
        });
        Pg::enumCheck('billify_products', 'pricing_model', PricingModel::class);

        Schema::create('billify_prices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->constrained('billify_products')->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor')->default(0);          // flat/base amount (integer minor)
            $table->decimal('unit_rate', 20, 8)->nullable();         // per-unit/usage rate (major units, sub-cent)
            $table->string('purpose')->default(PricePurpose::Recurring->value);
            $table->string('pricing_model');
            $table->string('interval')->nullable();
            $table->integer('interval_count')->nullable();
            $table->string('billing_mode')->default(BillingMode::InAdvance->value);
            $table->bigInteger('setup_fee_minor')->default(0);
            $table->bigInteger('cap_minor')->nullable();             // hourly monthly cap
            $table->bigInteger('min_charge_minor')->default(0);
            $table->jsonb('tiers')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('tax_inclusive')->default(false);
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_to')->nullable();             // null = current; old rows kept (grandfathering)
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->index(['product_id', 'currency', 'purpose'])->where('valid_to IS NULL');
            $table->index('tiers')->algorithm('gin');
        });
        Pg::currencyCheck('billify_prices');
        Pg::enumCheck('billify_prices', 'purpose', PricePurpose::class);
        Pg::enumCheck('billify_prices', 'pricing_model', PricingModel::class);
        Pg::enumCheck('billify_prices', 'billing_mode', BillingMode::class);
        Pg::check('billify_prices', 'billify_prices_amount_nonneg', 'amount_minor >= 0');
        Pg::check('billify_prices', 'billify_prices_rate_nonneg', 'unit_rate IS NULL OR unit_rate >= 0');

        Schema::create('billify_meter_dimensions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('product_id')->constrained('billify_products')->cascadeOnDelete();
            $table->string('key');
            $table->string('unit');
            $table->string('aggregation')->default(Aggregation::Sum->value);
            $table->decimal('rate', 20, 8);                          // per-unit rate (major units, sub-cent capable)
            $table->char('currency', 3);
            $table->decimal('included_qty', 20, 6)->default(0);      // free allowance per cycle
            $table->bigInteger('cap_minor')->nullable();
            $table->jsonb('tiers')->default(DB::raw("'[]'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['product_id', 'key']);
        });
        Pg::currencyCheck('billify_meter_dimensions');
        Pg::enumCheck('billify_meter_dimensions', 'aggregation', Aggregation::class);
        Pg::check('billify_meter_dimensions', 'billify_md_rate_nonneg', 'rate >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('billify_meter_dimensions');
        Schema::dropIfExists('billify_prices');
        Schema::dropIfExists('billify_products');
        Schema::dropIfExists('billify_billing_accounts');
    }
};
