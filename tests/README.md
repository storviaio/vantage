# Testing Guide

This directory contains tests for the Vantage queue monitoring package.

## Running Tests

```bash
# Run all tests
composer test

# Run with Pest directly
./vendor/bin/pest

# Run with coverage
./vendor/bin/pest --coverage

# Run specific test file
./vendor/bin/pest tests/Unit/VantageJobTest.php

# Run in parallel (faster)
./vendor/bin/pest -p
```

## Test Structure

### Unit Tests (`tests/Unit/`)
- **VantageJobTest.php**: Tests for the VantageJob model
  - Model creation and attributes
  - Relationship tests (retries, retriedFrom)
  - Scope methods (withTag, failed, successful, etc.)
  - Helper methods (hasTag, formatted_duration)

### Feature Tests (`tests/Feature/`)
- **JobListenersTest.php**: Integration tests for queue event listeners
  - Recording job start
  - Recording job success with duration calculation
  - Recording job failures with exception details
  - Retry chain tracking
  - Tag extraction and storage

- **QueueMonitorControllerTest.php**: Tests for web interface
  - Dashboard statistics
  - Job listing and filtering
  - Individual job details
  - Retry chain display
  - Tag filtering
  - Failed jobs page
- **RoutePrefixTest.php**: Verifies custom route prefix configuration serves the dashboard correctly

## Load Testing

Use the artisan command to generate test jobs for load testing:

```bash
# Generate 100 test jobs (default)
php artisan vantage:generate-test-jobs

# Generate 1000 jobs with 90% success rate
php artisan vantage:generate-test-jobs --count=1000 --success-rate=90

# Generate jobs with custom tags
php artisan vantage:generate-test-jobs --count=500 --tags=load-test,production,email

# Generate jobs with variable duration (10ms to 10 seconds)
php artisan vantage:generate-test-jobs --count=200 --duration-min=10 --duration-max=10000

# Dispatch in smaller batches
php artisan vantage:generate-test-jobs --count=1000 --batch-size=25
```

### Load Testing Workflow

1. **Generate test jobs:**
   ```bash
   php artisan vantage:generate-test-jobs --count=1000 --success-rate=85
   ```

2. **Start queue worker (in separate terminal):**
   ```bash
   php artisan queue:work
   ```

3. **Monitor progress:**
   - Visit `/vantage` to see dashboard
   - Visit `/vantage/jobs` to see job list
   - Watch statistics update in real-time

4. **Verify results:**
   - Check that all jobs are recorded
   - Verify success/failure rates match expectations
   - Check duration calculations are accurate
   - Verify tags are extracted correctly
   - Test retry functionality if failures occur

### Example: Full Load Test Scenario

```bash
# Terminal 1: Generate jobs
php artisan vantage:generate-test-jobs \
  --count=5000 \
  --success-rate=80 \
  --tags=load-test,production,stress-test \
  --duration-min=50 \
  --duration-max=2000 \
  --batch-size=100

# Terminal 2: Run queue worker
php artisan queue:work --tries=3 --timeout=300

# Terminal 3: Monitor database
php artisan tinker
>>> \Storvia\Vantage\Models\VantageJob::count()
>>> \Storvia\Vantage\Models\VantageJob::where('status', 'processed')->count()
>>> \Storvia\Vantage\Models\VantageJob::where('status', 'failed')->count()
```

## Writing New Tests

### Example Unit Test

```php
it('does something', function () {
    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'processing',
    ]);

    expect($job->status)->toBe('processing');
});
```

### Example Feature Test

```php
it('handles a feature', function () {
    $response = $this->get('/vantage/jobs');
    
    $response->assertStatus(200);
});
```

## Test Database

Tests use an in-memory SQLite database by default. This is configured in `TestCase.php`. The database is automatically migrated and refreshed before each test thanks to `RefreshDatabase` trait.

## CI/CD Integration

Tests are designed to run in CI/CD pipelines. The `composer.json` includes a test script that runs Pest with parallel execution:

```json
"scripts": {
    "test": "pest -p"
}
```

Add this to your CI configuration:

```yaml
# Example GitHub Actions
- name: Run tests
  run: composer test
```

