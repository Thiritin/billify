<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE billify_invoices (
          id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          account_id     uuid NOT NULL REFERENCES billify_billing_accounts(id) ON DELETE RESTRICT,
          customer_type  text NOT NULL,
          customer_id    text NOT NULL,
          number         text,
          driver         text NOT NULL DEFAULT 'database',
          external_id    text,
          external_url   text,
          state          billify_invoice_state NOT NULL DEFAULT 'draft',
          currency       char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          subtotal_minor bigint NOT NULL DEFAULT 0,
          tax_minor      bigint NOT NULL DEFAULT 0,
          total_minor    bigint NOT NULL DEFAULT 0,
          paid_minor     bigint NOT NULL DEFAULT 0,
          issued_at      timestamptz,
          due_at         timestamptz,
          paid_at        timestamptz,
          idempotency_key text,
          metadata       jsonb NOT NULL DEFAULT '{}',
          version        integer NOT NULL DEFAULT 0,
          created_at     timestamptz NOT NULL DEFAULT now(),
          updated_at     timestamptz NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX billify_invoices_number_uq ON billify_invoices (number) WHERE number IS NOT NULL;
        CREATE UNIQUE INDEX billify_invoices_batch_uq  ON billify_invoices (idempotency_key) WHERE idempotency_key IS NOT NULL;
        CREATE INDEX billify_invoices_account_idx ON billify_invoices (account_id, state);
        CREATE TRIGGER billify_invoices_touch BEFORE UPDATE ON billify_invoices
          FOR EACH ROW EXECUTE FUNCTION billify_touch_updated_at();

        CREATE TABLE billify_invoice_lines (
          id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          invoice_id   uuid NOT NULL REFERENCES billify_invoices(id) ON DELETE CASCADE,
          charge_id    uuid REFERENCES billify_charges(id) ON DELETE SET NULL,
          kind         billify_line_kind NOT NULL,
          description  text NOT NULL,
          quantity     numeric(20,6) NOT NULL DEFAULT 1,
          unit_minor   bigint,                  -- integer unit price (fixed/per-unit lines)
          unit_rate    numeric(20,8),           -- sub-cent unit rate (usage lines), for display
          amount_minor bigint NOT NULL,         -- rounded net amount (integer minor)
          tax_rate     numeric(6,4) NOT NULL DEFAULT 0,
          tax_minor    bigint NOT NULL DEFAULT 0,
          tax_label    text,
          currency     char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          covers       tstzrange,
          dimension_id uuid,
          sort         integer NOT NULL DEFAULT 0,
          metadata     jsonb NOT NULL DEFAULT '{}'
        );
        CREATE INDEX billify_lines_invoice_idx ON billify_invoice_lines (invoice_id, sort);

        CREATE TABLE billify_credit_notes (
          id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          invoice_id   uuid NOT NULL REFERENCES billify_invoices(id) ON DELETE RESTRICT,
          number       text,
          driver       text NOT NULL DEFAULT 'database',
          external_id  text,
          state        billify_credit_state NOT NULL DEFAULT 'draft',
          reason       text,
          amount_minor bigint NOT NULL,
          tax_minor    bigint NOT NULL DEFAULT 0,
          currency     char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          issued_at    timestamptz,
          metadata     jsonb NOT NULL DEFAULT '{}',
          created_at   timestamptz NOT NULL DEFAULT now()
        );
        CREATE UNIQUE INDEX billify_credit_number_uq ON billify_credit_notes (number) WHERE number IS NOT NULL;

        CREATE TABLE billify_payments (
          id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          account_id   uuid NOT NULL REFERENCES billify_billing_accounts(id) ON DELETE RESTRICT,
          amount_minor bigint NOT NULL CHECK (amount_minor > 0),
          currency     char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          reference    text,
          received_at  timestamptz NOT NULL DEFAULT now(),
          metadata     jsonb NOT NULL DEFAULT '{}',
          UNIQUE (reference)
        );
        CREATE TABLE billify_payment_allocations (
          id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          payment_id   uuid NOT NULL REFERENCES billify_payments(id) ON DELETE CASCADE,
          invoice_id   uuid NOT NULL REFERENCES billify_invoices(id) ON DELETE RESTRICT,
          amount_minor bigint NOT NULL CHECK (amount_minor > 0),
          UNIQUE (payment_id, invoice_id)
        );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS billify_payment_allocations, billify_payments, billify_credit_notes,
            billify_invoice_lines, billify_invoices CASCADE;
        SQL);
    }
};
