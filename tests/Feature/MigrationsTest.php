<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('runs all migrations and creates the core tables', function () {
    foreach ([
        'billify_billing_accounts', 'billify_products', 'billify_prices',
        'billify_meter_dimensions', 'billify_subscriptions', 'billify_subscription_items',
        'billify_addons', 'billify_item_options', 'billify_commitments', 'billify_allowances',
        'billify_usage_records', 'billify_billing_periods', 'billify_charges',
        'billify_invoices', 'billify_invoice_lines', 'billify_credit_notes',
        'billify_payments', 'billify_payment_allocations', 'billify_coupons',
        'billify_discounts', 'billify_ledger',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }
});

it('creates tstzrange columns as real ranges', function () {
    $type = DB::selectOne("
        SELECT data_type FROM information_schema.columns
        WHERE table_name = 'billify_subscriptions' AND column_name = 'current_period'
    ");

    expect($type->data_type)->toBe('tstzrange');
});

it('enforces enum values via check constraint', function () {
    $accountId = insertAccount();

    DB::table('billify_products')->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'type' => 'vps', 'slug' => 'bad-'.uniqid(), 'name' => 'Bad',
        'pricing_model' => 'not_a_real_model', // violates CHECK
    ]);
})->throws(QueryException::class);

it('rejects an invalid currency format', function () {
    DB::table('billify_billing_accounts')->insert([
        'id' => DB::raw('gen_random_uuid()'),
        'owner_type' => 'user', 'owner_id' => '1',
        'currency' => 'eur', // lowercase violates ^[A-Z]{3}$
    ]);
})->throws(QueryException::class);

function insertAccount(): string
{
    $id = (string) Str::uuid();
    DB::table('billify_billing_accounts')->insert([
        'id' => $id, 'owner_type' => 'user', 'owner_id' => '1', 'currency' => 'EUR',
    ]);

    return $id;
}
