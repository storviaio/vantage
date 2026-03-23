# Vantage

[![Latest Version on Packagist](https://img.shields.io/packagist/v/storviaio/vantage.svg?style=flat-square)](https://packagist.org/packages/storviaio/vantage)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/storviaio/vantage/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/storviaio/vantage/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/storviaio/vantage/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/storviaio/vantage/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/storviaio/vantage.svg?style=flat-square)](https://packagist.org/packages/storviaio/vantage)
[![License](https://img.shields.io/packagist/l/storviaio/vantage.svg?style=flat-square)](https://packagist.org/packages/storviaio/vantage)

A Laravel package that tracks and monitors your queue jobs. 
Automatically records job execution history, failures, retries, 
and provides a simple web interface to view everything.

## Installation

```bash
composer require storviaio/vantage
php artisan vendor:publish --tag=vantage-config
php artisan migrate
```

The package will automatically register itself using

**Publishing Assets:**
- Config: `php artisan vendor:publish --tag=vantage-config`
- Views: `php artisan vendor:publish --tag=vantage-views` (optional, for customization)
- Migrations: Automatically loaded, but you can publish with `php artisan vendor:publish --tag=vantage-migrations` if needed

### Requirements

- Laravel 10.x, 11.x, 12.x, or 13.x
- PHP 8.2, 8.3, or 8.4
- One of the following databases:
  - MySQL 5.7+ / MariaDB 10.3+
  - PostgreSQL 9.6+
  - SQLite 3.8.8+

## Features

### Universal Queue Driver Support

**Works with all Laravel queue drivers** - database, Redis, SQS, Beanstalkd, and any other driver. Unlike other monitoring tools that require specific drivers, Vantage tracks jobs from **any queue driver** and saves all data to your database for persistent tracking and analysis.

### Job Tracking

Every job gets tracked in the `vantage_jobs` table with:
- Job class, queue, connection
- Status (processing, processed, failed)
- Start/finish times and duration
- UUID for tracking across retries
- **All data saved to database** - complete history of every job execution

### Failure Details

When jobs fail, we store the exception class, message, and full stack trace. Much easier to debug than Laravel's default failed_jobs table.

Visit `/vantage/failed` to see all failed jobs with exception details and retry options.

![Failed Jobs](screenshots/vantage_04.png)

![Failed Jobs List](screenshots/vanatge_06.png)

### Web Interface

Visit `/vantage` to access the comprehensive monitoring dashboard:

**Dashboard** (`/vantage`) - Overview of your queue system:
- **Statistics Cards**: Total jobs, processed, failed, processing, and success rate
- **Queue Depth Monitoring**: Real-time pending job counts per queue with health status
- **Success Rate Trend Chart**: Visual representation of job success/failure over time
- **Top Failing Jobs**: See which job classes fail most often
- **Top Exceptions**: Most common error types with counts
- **Recent Jobs Table**: Latest 20 jobs with quick actions
- **Recent Batches**: Track Laravel job batches with success/failure rates
- **Time Period Filters**: View data for last hour, 6 hours, 24 hours, 7 days, 30 days, or all time

![Dashboard](screenshots/vantage_01.png)

**Recent Jobs Table** - Quick view of the latest 20 jobs with status, duration, and quick actions:

The Recent Jobs table appears on the dashboard showing:
- Job ID, class name, and queue
- Tags associated with each job
- Status indicators (Processing, Processed, Failed)
- Duration and creation time
- Quick "View" action to see full job details

**Recent Batches** - Track Laravel job batches with success/failure rates:

![Recent Batches](screenshots/vantage_03.png)

**Jobs List** - View and filter all jobs with advanced filtering options:

Visit `/vantage/jobs` to access the jobs list with powerful filtering capabilities:
- Filter by status (processed, failed, processing)
- Filter by queue name
- Filter by job class (partial match supported)
- Filter by tags (supports multiple tags with "all" or "any" mode)
- Filter by date range
- Popular tags cloud for quick filtering
- Pagination (50 jobs per page)

![Jobs List](screenshots/vantage_02.png)

Filter jobs by status, queue, job class, tags, and date range:

![Jobs List with Filters](screenshots/vantage_08.png)

**Job Details** (`/vantage/jobs/{id}`) - Comprehensive job information:
- **Basic Information**: Status, UUID, queue, connection, job class
- **Timing**: Start time, finish time, duration
- **Exception Details**: Full exception class, message, and stack trace for failed jobs
- **Payload**: Complete job payload with JSON formatting
- **Tags**: All tags associated with the job
- **Retry Chain**: View original job and all retry attempts
- **Quick Actions**: Retry failed jobs directly from the details page

**Note:** The dashboard requires authentication by default. Make sure you're logged in, or customize the `viewVantage` gate / `VANTAGE_AUTH_ENABLED` setting (explained below) if you need different behavior.

### Retry Failed Jobs

```bash
php artisan vantage:retry {job_id}
```

Or use the web interface - just click retry on any failed job.

![Retry Jobs](screenshots/vantage_09.png)

### Programmatic Access

Vantage provides a convenient facade for easy programmatic access to queue monitoring data. The facade is automatically registered and ready to use:

```php
use Storvia\Vantage\Facades\Vantage;

// Get queue depth for all queues
$depths = Vantage::queueDepth();

// Get failed jobs
$failedJobs = Vantage::failedJobs(limit: 100);

// Get jobs by tag
$emailJobs = Vantage::jobsByTag('email', limit: 50);

// Get statistics
$stats = Vantage::statistics(startDate: now()->subDays(7));
// Returns: ['total' => 1000, 'processed' => 950, 'failed' => 50, 'processing' => 0, 'success_rate' => 95.0]

// Retry a failed job programmatically
Vantage::retryJob($jobId);

// Clean up stuck jobs older than 24 hours
$cleaned = Vantage::cleanupStuckJobs(hoursOld: 24);

// Prune old jobs
$deleted = Vantage::pruneOldJobs(daysOld: 30);

// Check if Vantage is enabled
if (Vantage::enabled()) {
    // Monitor critical jobs
}
```

**Available Facade Methods:**
- `queueDepth(?string $queue = null)` - Get queue depths for all or specific queues
- `jobsByStatus(string $status, int $limit = 50)` - Get jobs by status (processing, processed, failed)
- `failedJobs(int $limit = 50)` - Get failed jobs
- `processingJobs(int $limit = 50)` - Get currently processing jobs
- `jobsByTag(string $tag, int $limit = 50)` - Get jobs filtered by tag
- `statistics(?string $startDate = null)` - Get dashboard statistics
- `retryJob(int $jobId)` - Retry a failed job programmatically
- `cleanupStuckJobs(int $hoursOld = 24)` - Clean up stuck processing jobs
- `pruneOldJobs(int $daysOld = 30)` - Prune old job records
- `logger()` - Get the VantageLogger instance
- `enable()` / `disable()` / `enabled()` - Control package state

### Job Tagging

Jobs with tags (using Laravel's `tags()` method) are automatically tracked. Visit `/vantage/tags` to see:

- **Tags Analytics**: View statistics for all tags (total jobs, processed, failed, processing, success rate, average duration)
- **Search**: Filter tags by name in real-time
- **Sortable Columns**: Click any column header to sort by that metric
- **Clickable Tags**: Click a tag to view all jobs with that tag
- **Time Filters**: View data for last 24 hours, 7 days, or 30 days

![Tags Analytics](screenshots/vantage_07.png)

Filter and view jobs by tag in the web interface.

### Queue Depth Monitoring

Real-time queue depth tracking for all your queues. See how many jobs are pending in each queue with health status indicators.

![Queue Depth](screenshots/vantage_10.png)

Visit `/vantage` to see queue depths displayed with:
- Current pending job count per queue
- Health status (healthy/normal/warning/critical)
- Support for database and Redis queue drivers

### Performance Telemetry

Vantage automatically tracks performance metrics for your jobs:
- Memory usage (start, end, peak)
- CPU time (user and system)
- Execution duration

Telemetry can be configured via environment variables (see Environment Variables section below).

## Configuration

The config file should already be published during installation. If you need to republish it:

```bash
php artisan vendor:publish --tag=vantage-config
```

### Main Settings

- `store_payload` - Whether to store job payloads (for debugging/retry)
- `redact_keys` - Keys to redact from payloads (password, token, etc.)
- `retention_days` - Default number of days to keep job history (used by `vantage:prune` command, default: 14)
- `routes` - Master switch to register dashboard routes
- `route_prefix` - Base URI segment for all dashboard routes (default: `vantage`)
- `logging.enabled` - Toggle Vantage's own log output
- `notify.email` - Email to notify on failures
- `notify.slack_webhook` - Slack webhook URL for failures
- `telemetry.enabled` - Enable performance telemetry (memory/CPU)
- `telemetry.sample_rate` - Sampling rate (0.0-1.0, default: 1.0)
- `telemetry.capture_cpu` - Enable CPU time tracking

### Enable/Disable Package

To disable the package entirely (useful for staging environments):

```env
VANTAGE_ENABLED=false
```

When disabled:
- No job tracking occurs
- Routes are not registered
- Event listeners are not active
- Commands are not registered
- No database writes
- Gate authorization is not registered

Perfect for testing in staging without affecting production data!

### Multi-Database Support

If your application uses multiple databases, you can specify which database connection to use for storing queue job runs:

```env
VANTAGE_DATABASE_CONNECTION=mysql
```

This ensures the `vantage_jobs` table is created and accessed from the correct database connection. The package automatically detects your database driver (MySQL, PostgreSQL, SQLite) and uses the appropriate SQL syntax for queries.

### Authentication

Vantage protects the dashboard with Laravel's Gate system (similar to Horizon) via the `viewVantage` gate.

- **Default behaviour:** Any authenticated user can access the dashboard.
- **Gate input:** The gate receives the authenticated user instance or `null` if no one is logged in.
- **Customization:** Override the gate in your `AppServiceProvider` to implement your own rules.
- **Disable entirely:** Set `VANTAGE_AUTH_ENABLED=false` to bypass the gate (not recommended for production).

Make sure your application has authentication set up (Laravel Breeze, Jetstream or your own implementation) unless you intentionally open the dashboard to everyone.

To customize access (e.g., only allow admins), override the `viewVantage` gate in your `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewVantage', function ($user = null) {
        // Only allow admins
        return optional($user)->isAdmin();
        
        // Or any other custom logic
        // return $user && $user->hasRole('developer');
    });
}
```

Want to keep the dashboard public but still record jobs? Either return `true` from the gate even for guests, or set `VANTAGE_AUTH_ENABLED=false`.

### Exclude Jobs from Monitoring

if you want to exclude some jobs from being tracked by Vantage, you can add them to the `exclude_jobs` config array:

```php
    'exclude_jobs' => [
        App\Jobs\SomeNoisyJob::class,
    ],
```

Or implement the 'ShouldNotBeTracked' class.

```php
use Storvia\Vantage\Contracts\ShouldNotBeTracked;

class MyNoisyJob implements ShouldQueue, ShouldNotBeTracked
{
    public function handle(): void { ... }
}
```

## Testing

### Using the Model Factory

Vantage includes a comprehensive model factory for creating test data in your application tests:

```php
use Storvia\Vantage\Models\VantageJob;

// Create test jobs
$job = VantageJob::factory()->create();

// Create specific job states
$failedJob = VantageJob::factory()->failed()->create();
$processingJob = VantageJob::factory()->processing()->create();
$processedJob = VantageJob::factory()->processed()->create();

// Create jobs with tags
$taggedJob = VantageJob::factory()
    ->withTags(['email', 'notification'])
    ->create();

// Create jobs on specific queues
$highPriorityJob = VantageJob::factory()
    ->onQueue('high')
    ->create();

// Create a retry chain
$originalJob = VantageJob::factory()->failed()->create();
$retryJob = VantageJob::factory()
    ->retriedFrom($originalJob->id)
    ->create();

// Create jobs with specific class names
$emailJob = VantageJob::factory()
    ->jobClass('App\\Jobs\\SendEmailJob')
    ->create();
```

### Running Package Tests

Run the test suite:

```bash
composer test
```

## Commands

### Retry Failed Job

```bash
php artisan vantage:retry {job_id}
```

Retry a failed job by its ID. The job will be re-queued with the same payload and settings.

### Cleanup Stuck Jobs

```bash
php artisan vantage:cleanup-stuck [--timeout=1] [--dry-run]
```

Clean up jobs that are stuck in "processing" state. Useful for jobs that were interrupted or crashed.

- `--timeout=1` - Hours to consider a job stuck (default: 1 hour)
- `--dry-run` - Show what would be cleaned without actually cleaning

Example:
```bash
# Clean up jobs stuck for more than 2 hours
php artisan vantage:cleanup-stuck --timeout=2

# Preview what would be cleaned
php artisan vantage:cleanup-stuck --dry-run
```

### Prune Old Jobs

```bash
php artisan vantage:prune [--days=30] [--hours=] [--status=] [--keep-processing] [--dry-run] [--force]
```

Prune old job records from the database to free up space. This is essential for high-volume applications where the database can grow very large over time.

**Options:**
- `--days=30` - Keep jobs from the last X days (defaults to `retention_days` config value or 30)
- `--hours=` - Keep jobs from the last X hours (overrides `--days`)
- `--status=` - Only prune jobs with specific status (`processed`, `failed`, or `processing`). Leave empty to prune all
- `--keep-processing` - Always keep jobs with "processing" status (recommended)
- `--dry-run` - Show what would be deleted without actually deleting
- `--force` - Skip confirmation prompt

**Examples:**
```bash
# Prune jobs older than 30 days (uses config default)
php artisan vantage:prune

# Prune jobs older than 7 days, keeping processing jobs
php artisan vantage:prune --days=7 --keep-processing

# Prune only failed jobs older than 14 days
php artisan vantage:prune --days=14 --status=failed

# Preview what would be deleted
php artisan vantage:prune --days=30 --dry-run

# Prune jobs older than 12 hours
php artisan vantage:prune --hours=12 --force
```

**Scheduling Automatic Cleanup:**

Add this to your `app/Console/Kernel.php` to automatically prune old jobs:

```php
protected function schedule(Schedule $schedule)
{
    // Prune jobs older than retention_days config (uses VANTAGE_RETENTION_DAYS or default: 14 days)
    // --force skips confirmation prompt (required for scheduled tasks)
    // --keep-processing preserves active jobs
    $schedule->command('vantage:prune --force --keep-processing')
        ->daily()
        ->at('02:00');
}
```

**For scheduled tasks:**
- Use `--force` to skip confirmation (required for unattended execution)
- Omit `--days` to use `vantage.retention_days` config value automatically
- The command will show "(from config: vantage.retention_days)" in the output when using config

**Important Notes:**
- The command handles retry chain relationships automatically (orphans children when parents are deleted)
- Processing jobs are preserved by default to avoid deleting active work
- Deletion happens in chunks to avoid memory issues with large datasets
- Use `--dry-run` first to preview what will be deleted

## Environment Variables

```env
# Master switch - Enable/disable entire package (default: true)
VANTAGE_ENABLED=true

# Database connection for vantage_jobs table (optional)
VANTAGE_DATABASE_CONNECTION=mysql

# Authentication (default: true)
VANTAGE_AUTH_ENABLED=true

# Payload storage (default: true)
VANTAGE_STORE_PAYLOAD=true

# Telemetry (default: true)
VANTAGE_TELEMETRY_ENABLED=true
VANTAGE_TELEMETRY_SAMPLE_RATE=1.0
VANTAGE_TELEMETRY_CPU=true

# Data retention (default: 14 days)
VANTAGE_RETENTION_DAYS=14

# Notifications
VANTAGE_NOTIFY_EMAIL=admin@example.com
VANTAGE_SLACK_WEBHOOK=https://hooks.slack.com/services/...

# Routes (default: true)
VANTAGE_ROUTES=true

# Change the base URL path for the dashboard (default: vantage)
VANTAGE_ROUTE_PREFIX=vantage

# Logging (default: true)
VANTAGE_LOGGING_ENABLED=true
```

## Demo

Watch a brief demo of the Vantage dashboard in action:

[![Vantage Demo](https://img.youtube.com/vi/IZAjYTtzL7I/0.jpg)](https://www.youtube.com/watch?v=ZPea5E3o_2w)

> Click the thumbnail above to watch the demo video on YouTube.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details on how to contribute to this project.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
