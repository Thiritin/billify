<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE billify_subscriptions (
          id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          account_id     uuid NOT NULL REFERENCES billify_billing_accounts(id) ON DELETE RESTRICT,
          customer_type  text NOT NULL,
          customer_id    text NOT NULL,
          currency       char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          state          billify_sub_state NOT NULL DEFAULT 'incomplete',
          anchor_mode    billify_anchor_mode NOT NULL DEFAULT 'signup',
          anchor_day     smallint CHECK (anchor_day BETWEEN 1 AND 31),
          first_period   billify_first_period NOT NULL DEFAULT 'prorate_only',
          current_period tstzrange,
          trial_end      timestamptz,
          canceled_at    timestamptz,
          cancel_at      timestamptz,
          version        integer NOT NULL DEFAULT 0,
          metadata       jsonb NOT NULL DEFAULT '{}',
          created_at     timestamptz NOT NULL DEFAULT now(),
          updated_at     timestamptz NOT NULL DEFAULT now()
        );
        CREATE INDEX billify_subs_account_idx  ON billify_subscriptions (account_id);
        CREATE INDEX billify_subs_customer_idx ON billify_subscriptions (customer_type, customer_id);
        CREATE INDEX billify_subs_due_idx      ON billify_subscriptions (upper(current_period))
          WHERE state IN ('active','trialing','past_due');
        CREATE TRIGGER billify_subs_touch BEFORE UPDATE ON billify_subscriptions
          FOR EACH ROW EXECUTE FUNCTION billify_touch_updated_at();

        CREATE TABLE billify_subscription_items (
          id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          subscription_id uuid NOT NULL REFERENCES billify_subscriptions(id) ON DELETE CASCADE,
          product_id      uuid NOT NULL REFERENCES billify_products(id) ON DELETE RESTRICT,
          price_id        uuid NOT NULL REFERENCES billify_prices(id) ON DELETE RESTRICT,
          resource_type   text,
          resource_id     text,
          quantity        numeric(20,6) NOT NULL DEFAULT 1 CHECK (quantity >= 0),
          billing_mode    billify_billing_mode,
          state           billify_item_state NOT NULL DEFAULT 'pending',
          current_period  tstzrange,
          activated_at    timestamptz,
          ends_at         timestamptz,
          pending_change  jsonb,
          version         integer NOT NULL DEFAULT 0,
          metadata        jsonb NOT NULL DEFAULT '{}',
          created_at      timestamptz NOT NULL DEFAULT now(),
          updated_at      timestamptz NOT NULL DEFAULT now()
        );
        CREATE INDEX billify_items_sub_idx      ON billify_subscription_items (subscription_id);
        CREATE INDEX billify_items_resource_idx ON billify_subscription_items (resource_type, resource_id);
        CREATE INDEX billify_items_due_idx      ON billify_subscription_items (upper(current_period)) WHERE state = 'active';
        CREATE TRIGGER billify_items_touch BEFORE UPDATE ON billify_subscription_items
          FOR EACH ROW EXECUTE FUNCTION billify_touch_updated_at();

        CREATE TABLE billify_addons (
          id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          item_id    uuid NOT NULL REFERENCES billify_subscription_items(id) ON DELETE CASCADE,
          product_id uuid NOT NULL REFERENCES billify_products(id) ON DELETE RESTRICT,
          price_id   uuid NOT NULL REFERENCES billify_prices(id) ON DELETE RESTRICT,
          group_key  text,
          quantity   numeric(20,6) NOT NULL DEFAULT 1,
          state      billify_item_state NOT NULL DEFAULT 'active',
          metadata   jsonb NOT NULL DEFAULT '{}',
          created_at timestamptz NOT NULL DEFAULT now(),
          updated_at timestamptz NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX billify_addons_group_uq ON billify_addons (item_id, group_key)
          WHERE state = 'active' AND group_key IS NOT NULL;

        CREATE TABLE billify_item_options (
          id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          item_id    uuid NOT NULL REFERENCES billify_subscription_items(id) ON DELETE CASCADE,
          key        text NOT NULL,
          type       billify_option_type NOT NULL,
          value      text NOT NULL,
          price_id   uuid REFERENCES billify_prices(id) ON DELETE RESTRICT,
          quantity   numeric(20,6) NOT NULL DEFAULT 1,
          created_at timestamptz NOT NULL DEFAULT now(),
          updated_at timestamptz NOT NULL DEFAULT now(),
          UNIQUE (item_id, key)
        );

        CREATE TABLE billify_commitments (
          id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          item_id       uuid NOT NULL REFERENCES billify_subscription_items(id) ON DELETE CASCADE,
          term_interval billify_interval NOT NULL,
          term_count    integer NOT NULL CHECK (term_count > 0),
          upfront_minor bigint NOT NULL DEFAULT 0,
          rate_minor    bigint NOT NULL,
          currency      char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          term          tstzrange NOT NULL,
          early_term    jsonb NOT NULL DEFAULT '{}',
          state         billify_commitment_state NOT NULL DEFAULT 'active',
          created_at    timestamptz NOT NULL DEFAULT now()
        );
        CREATE INDEX billify_commitments_item_idx ON billify_commitments (item_id);

        CREATE TABLE billify_allowances (
          id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          item_id      uuid NOT NULL REFERENCES billify_subscription_items(id) ON DELETE CASCADE,
          dimension_id uuid NOT NULL REFERENCES billify_meter_dimensions(id) ON DELETE CASCADE,
          included_qty numeric(20,6) NOT NULL,
          period       tstzrange NOT NULL,
          consumed_qty numeric(20,6) NOT NULL DEFAULT 0,
          shared_pool  text,
          UNIQUE (item_id, dimension_id, period)
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS billify_allowances, billify_commitments, billify_item_options,
            billify_addons, billify_subscription_items, billify_subscriptions CASCADE;
        SQL);
    }
};
