<?php

declare(strict_types=1);

namespace Billify\Tests;

use Billify\BillifyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Tpetry\PostgresqlEnhanced\PostgresqlEnhancedServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PostgresqlEnhancedServiceProvider::class,
            BillifyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '55432'),
            'database' => env('DB_DATABASE', 'billify_test'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }
}
