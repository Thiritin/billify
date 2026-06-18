<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE billify_billing_accounts (
          id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          parent_id     uuid REFERENCES billify_billing_accounts(id) ON DELETE RESTRICT,
          owner_type    text NOT NULL,
          owner_id      text NOT NULL,
          currency      char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          tax_profile   jsonb NOT NULL DEFAULT '{}',
          balance_minor bigint NOT NULL DEFAULT 0,
          metadata      jsonb NOT NULL DEFAULT '{}',
          created_at    timestamptz NOT NULL DEFAULT now(),
          updated_at    timestamptz NOT NULL DEFAULT now()
        );
        CREATE INDEX billify_accounts_owner_idx  ON billify_billing_accounts (owner_type, owner_id);
        CREATE INDEX billify_accounts_parent_idx ON billify_billing_accounts (parent_id);
        CREATE TRIGGER billify_accounts_touch BEFORE UPDATE ON billify_billing_accounts
          FOR EACH ROW EXECUTE FUNCTION billify_touch_updated_at();

        CREATE TABLE billify_products (
          id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          billable_type  text,
          billable_id    text,
          type           text NOT NULL,
          slug           text NOT NULL UNIQUE,
          name           text NOT NULL,
          pricing_model  billify_pricing_model NOT NULL,
          is_proratable  boolean NOT NULL DEFAULT true,
          config         jsonb NOT NULL DEFAULT '{}',
          active         boolean NOT NULL DEFAULT true,
          metadata       jsonb NOT NULL DEFAULT '{}',
          created_at     timestamptz NOT NULL DEFAULT now(),
          updated_at     timestamptz NOT NULL DEFAULT now()
        );
        CREATE INDEX billify_products_billable_idx ON billify_products (billable_type, billable_id);
        CREATE INDEX billify_products_type_idx     ON billify_products (type) WHERE active;
        CREATE TRIGGER billify_products_touch BEFORE UPDATE ON billify_products
          FOR EACH ROW EXECUTE FUNCTION billify_touch_updated_at();

        CREATE TABLE billify_prices (
          id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          product_id       uuid NOT NULL REFERENCES billify_products(id) ON DELETE CASCADE,
          currency         char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          amount_minor     bigint NOT NULL DEFAULT 0 CHECK (amount_minor >= 0),  -- flat/base/setup amount (integer minor)
          unit_rate        numeric(20,8) CHECK (unit_rate IS NULL OR unit_rate >= 0), -- per-unit/usage rate (major units, sub-cent)
          purpose          billify_price_purpose NOT NULL DEFAULT 'recurring',
          pricing_model    billify_pricing_model NOT NULL,
          interval         billify_interval,
          interval_count   integer CHECK (interval_count IS NULL OR interval_count > 0),
          billing_mode     billify_billing_mode NOT NULL DEFAULT 'in_advance',
          setup_fee_minor  bigint NOT NULL DEFAULT 0 CHECK (setup_fee_minor >= 0),
          cap_minor        bigint CHECK (cap_minor IS NULL OR cap_minor >= 0),
          min_charge_minor bigint NOT NULL DEFAULT 0,
          tiers            jsonb NOT NULL DEFAULT '[]',
          tax_inclusive    boolean NOT NULL DEFAULT false,
          valid_from       timestamptz NOT NULL DEFAULT now(),
          valid_to         timestamptz,
          metadata         jsonb NOT NULL DEFAULT '{}',
          created_at       timestamptz NOT NULL DEFAULT now(),
          updated_at       timestamptz NOT NULL DEFAULT now(),
          CHECK (interval IS NOT NULL OR purpose IN ('one_off','setup','register'))
        );
        CREATE INDEX billify_prices_lookup_idx ON billify_prices (product_id, currency, purpose) WHERE valid_to IS NULL;
        CREATE INDEX billify_prices_tiers_gin  ON billify_prices USING gin (tiers);
        CREATE TRIGGER billify_prices_touch BEFORE UPDATE ON billify_prices
          FOR EACH ROW EXECUTE FUNCTION billify_touch_updated_at();

        CREATE TABLE billify_meter_dimensions (
          id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          product_id     uuid NOT NULL REFERENCES billify_products(id) ON DELETE CASCADE,
          key            text NOT NULL,
          unit           text NOT NULL,
          aggregation    billify_aggregation NOT NULL DEFAULT 'sum',
          rate           numeric(20,8) NOT NULL CHECK (rate >= 0),  -- per-unit rate (major units, sub-cent capable)
          currency       char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          included_qty   numeric(20,6) NOT NULL DEFAULT 0,
          cap_minor      bigint,  -- optional money cap (integer minor)
          tiers          jsonb NOT NULL DEFAULT '[]',
          created_at     timestamptz NOT NULL DEFAULT now(),
          UNIQUE (product_id, key)
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS billify_meter_dimensions, billify_prices, billify_products, billify_billing_accounts CASCADE;
        SQL);
    }
};
