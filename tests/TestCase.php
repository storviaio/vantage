<?php

namespace Storvia\Vantage\Tests;

use Storvia\Vantage\VantageServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected string $routePrefix = 'vantage';

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            VantageServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup database
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup queue to sync for testing
        $app['config']->set('queue.default', 'sync');

        // Enable routes for testing
        $app['config']->set('vantage.routes', true);
        $app['config']->set('vantage.route_prefix', $this->routePrefix);

        // Provide application key for encryption-dependent features
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Disable dashboard auth during tests
        $app['config']->set('vantage.auth.enabled', false);
    }
}
