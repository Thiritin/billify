<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE EXTENSION IF NOT EXISTS pgcrypto;
        CREATE EXTENSION IF NOT EXISTS btree_gist;

        CREATE TYPE billify_interval         AS ENUM ('day','week','month','year');
        CREATE TYPE billify_billing_mode     AS ENUM ('in_advance','in_arrears');
        CREATE TYPE billify_pricing_model    AS ENUM ('fixed','per_unit','tiered','volume','metered','hourly','one_off');
        CREATE TYPE billify_price_purpose    AS ENUM ('recurring','setup','register','renew','transfer','addon','option');
        CREATE TYPE billify_anchor_mode      AS ENUM ('signup','fixed_day','fixed_dow');
        CREATE TYPE billify_first_period     AS ENUM ('prorate_only','prorate_plus_full','full_period','free_until_anchor');
        CREATE TYPE billify_sub_state        AS ENUM ('incomplete','trialing','active','past_due','paused','canceled','expired');
        CREATE TYPE billify_item_state       AS ENUM ('pending','active','paused','canceled');
        CREATE TYPE billify_charge_state     AS ENUM ('pending','invoiced','settled','void');
        CREATE TYPE billify_invoice_state    AS ENUM ('draft','open','partially_paid','paid','void','uncollectible');
        CREATE TYPE billify_option_type      AS ENUM ('quantity','choice','toggle');
        CREATE TYPE billify_aggregation      AS ENUM ('sum','max','last');
        CREATE TYPE billify_discount_type    AS ENUM ('percent','fixed');
        CREATE TYPE billify_commitment_state AS ENUM ('active','expired','terminated');
        CREATE TYPE billify_credit_state     AS ENUM ('draft','issued','applied','void');
        CREATE TYPE billify_line_kind        AS ENUM ('recurring','prorated','full_period','usage','setup','one_off','addon','option','discount','credit');

        CREATE OR REPLACE FUNCTION billify_touch_updated_at() RETURNS trigger AS $$
        BEGIN NEW.updated_at = now(); RETURN NEW; END;
        $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP FUNCTION IF EXISTS billify_touch_updated_at();
        DROP TYPE IF EXISTS billify_line_kind, billify_credit_state, billify_commitment_state,
            billify_discount_type, billify_aggregation, billify_option_type, billify_invoice_state,
            billify_charge_state, billify_item_state, billify_sub_state, billify_first_period,
            billify_anchor_mode, billify_price_purpose, billify_pricing_model, billify_billing_mode,
            billify_interval;
        SQL);
    }
};
