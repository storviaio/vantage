<?php

namespace Storvia\Vantage;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class VantageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Load our default config so config('vantage') works
        $this->mergeConfigFrom(__DIR__.'/../config/vantage.php', 'vantage');
    }

    public function boot(): void
    {
        // Always publish config file (needed for configuration)
        $this->publishes([
            __DIR__.'/../config/vantage.php' => config_path('vantage.php'),
        ], 'vantage-config');

        // Master switch: if package is disabled, don't register anything
        if (! config('vantage.enabled', true)) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\RetryFailedJob::class,
                Console\Commands\CleanupStuckJobs::class,
                Console\Commands\PruneOldJobs::class,
                Console\Commands\BackfillJobTags::class,
            ]);
        }

        // Register authorization gate (like Horizon)
        Gate::define('viewVantage', function ($user = null) {
            // If auth is disabled, allow access
            if (! config('vantage.auth.enabled', true)) {
                return true;
            }

            // If no user, deny access
            if (! $user) {
                return false;
            }

            // Allow all authenticated users by default
            // Users can customize this in their AppServiceProvider
            return true;
        });

        // Load our migrations automatically
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'vantage');

        // Load web routes if enabled
        if (config('vantage.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        // Load API routes if enabled
        if (config('vantage.api.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        // Listen to Laravel's built-in queue events
        Event::listen(JobProcessing::class, [Listeners\RecordJobStart::class, 'handle']);
        Event::listen(JobProcessed::class, [Listeners\RecordJobSuccess::class, 'handle']);
        Event::listen(JobFailed::class, [Listeners\RecordJobFailure::class, 'handle']);
    }
}
