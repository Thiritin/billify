<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE billify_usage_records (
          id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          item_id         uuid NOT NULL REFERENCES billify_subscription_items(id) ON DELETE CASCADE,
          dimension_id    uuid NOT NULL REFERENCES billify_meter_dimensions(id) ON DELETE RESTRICT,
          quantity        numeric(20,6) NOT NULL CHECK (quantity >= 0),
          occurred_at     timestamptz NOT NULL,
          window          tstzrange,
          source          text,
          idempotency_key text NOT NULL,
          charge_id       uuid,
          created_at      timestamptz NOT NULL DEFAULT now(),
          UNIQUE (idempotency_key)
        );
        CREATE INDEX billify_usage_unbilled_idx ON billify_usage_records (item_id, dimension_id, occurred_at)
          WHERE charge_id IS NULL;

        -- The double-bill guard: no two billed windows may overlap per item+dimension.
        CREATE TABLE billify_billing_periods (
          id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          item_id      uuid NOT NULL REFERENCES billify_subscription_items(id) ON DELETE CASCADE,
          dimension_id uuid,
          covers       tstzrange NOT NULL,
          charge_id    uuid,
          created_at   timestamptz NOT NULL DEFAULT now(),
          CONSTRAINT billify_period_valid CHECK (lower(covers) < upper(covers)),
          CONSTRAINT billify_no_overlap EXCLUDE USING gist (
            item_id WITH =,
            COALESCE(dimension_id, '00000000-0000-0000-0000-000000000000'::uuid) WITH =,
            covers  WITH &&
          )
        );

        CREATE TABLE billify_charges (
          id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          account_id      uuid NOT NULL REFERENCES billify_billing_accounts(id) ON DELETE RESTRICT,
          subscription_id uuid REFERENCES billify_subscriptions(id) ON DELETE SET NULL,
          origin_type     text NOT NULL,
          origin_id       text NOT NULL,
          dimension_id    uuid REFERENCES billify_meter_dimensions(id),
          kind            billify_line_kind NOT NULL,
          billing_mode    billify_billing_mode NOT NULL,
          state           billify_charge_state NOT NULL DEFAULT 'pending',
          description     text NOT NULL,
          quantity        numeric(20,6) NOT NULL DEFAULT 1,
          unit_minor      bigint,                 -- integer unit price (fixed/per-unit lines)
          unit_rate       numeric(20,8),          -- sub-cent unit rate (usage lines), for display
          amount_minor    bigint NOT NULL,        -- rounded billable amount (integer minor)
          currency        char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          covers          tstzrange,
          invoice_id      uuid,
          idempotency_key text NOT NULL,
          metadata        jsonb NOT NULL DEFAULT '{}',
          version         integer NOT NULL DEFAULT 0,
          created_at      timestamptz NOT NULL DEFAULT now(),
          updated_at      timestamptz NOT NULL DEFAULT now(),
          UNIQUE (idempotency_key)
        );
        CREATE INDEX billify_charges_pending_idx ON billify_charges (account_id, currency) WHERE state = 'pending';
        CREATE INDEX billify_charges_origin_idx  ON billify_charges (origin_type, origin_id);
        CREATE INDEX billify_charges_invoice_idx ON billify_charges (invoice_id);
        CREATE TRIGGER billify_charges_touch BEFORE UPDATE ON billify_charges
          FOR EACH ROW EXECUTE FUNCTION billify_touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS billify_charges, billify_billing_periods, billify_usage_records CASCADE;
        SQL);
    }
};
