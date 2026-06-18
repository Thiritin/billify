<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE billify_coupons (
          id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          code            text NOT NULL UNIQUE,
          type            billify_discount_type NOT NULL,
          value           numeric(12,4) NOT NULL,
          value_minor     bigint,
          currency        char(3) CHECK (currency ~ '^[A-Z]{3}$'),
          duration_cycles integer,
          max_redemptions integer,
          redeemed_count  integer NOT NULL DEFAULT 0,
          valid_from      timestamptz,
          valid_to        timestamptz,
          exclusive       boolean NOT NULL DEFAULT false,
          metadata        jsonb NOT NULL DEFAULT '{}',
          created_at      timestamptz NOT NULL DEFAULT now()
        );

        CREATE TABLE billify_discounts (
          id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
          coupon_id        uuid REFERENCES billify_coupons(id) ON DELETE SET NULL,
          target_type      text NOT NULL,
          target_id        text NOT NULL,
          remaining_cycles integer,
          created_at       timestamptz NOT NULL DEFAULT now()
        );
        CREATE INDEX billify_discounts_target_idx ON billify_discounts (target_type, target_id);

        CREATE TABLE billify_ledger (
          id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
          account_id    uuid NOT NULL REFERENCES billify_billing_accounts(id) ON DELETE RESTRICT,
          txn_id        uuid NOT NULL,
          entry         text NOT NULL,
          debit_minor   bigint NOT NULL DEFAULT 0,
          credit_minor  bigint NOT NULL DEFAULT 0,
          currency      char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
          ref_type      text,
          ref_id        text,
          posted_at     timestamptz NOT NULL DEFAULT now(),
          CHECK (debit_minor = 0 OR credit_minor = 0)
        );
        CREATE INDEX billify_ledger_account_idx ON billify_ledger (account_id, posted_at);
        CREATE INDEX billify_ledger_txn_idx     ON billify_ledger (txn_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TABLE IF EXISTS billify_ledger, billify_discounts, billify_coupons CASCADE;
        SQL);
    }
};
