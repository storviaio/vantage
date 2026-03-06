<?php

use Storvia\Vantage\Models\VantageJob;
use Illuminate\Support\Str;

beforeEach(function () {
    VantageJob::query()->delete();
});

it('displays dashboard with job statistics', function () {
    // Create test jobs
    VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'processed',
        'created_at' => now()->subDays(1),
    ]);

    VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'failed',
        'created_at' => now()->subDays(2),
    ]);

    $response = $this->get('/vantage');

    $response->assertStatus(200)
        ->assertSee('Dashboard')
        ->assertSee('TestJob', false);
});

it('displays jobs list with filtering', function () {
    VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'processed',
        'queue' => 'default',
    ]);

    VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\FailedJob',
        'status' => 'failed',
        'queue' => 'default',
    ]);

    $response = $this->get('/vantage/jobs');

    $response->assertStatus(200)
        ->assertSee('TestJob', false)
        ->assertSee('FailedJob', false);

    // Filter by status
    $response = $this->get('/vantage/jobs?status=failed');

    $response->assertStatus(200)
        ->assertSee('FailedJob', false)
        ->assertDontSee('TestJob', false);
});

it('displays individual job details', function () {
    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'processed',
        'queue' => 'default',
        'connection' => 'database',
        'duration_ms' => 1500,
        'job_tags' => ['important', 'email'],
    ]);

    $response = $this->get("/vantage/jobs/{$job->id}");

    $response->assertStatus(200)
        ->assertSee('TestJob', false)
        ->assertSee('important', false)
        ->assertSee('email', false)
        ->assertSee('1.5s', false);
});

it('displays retry chain in job details', function () {
    $original = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'failed',
    ]);

    $retry = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'processed',
        'retried_from_id' => $original->id,
    ]);

    $response = $this->get("/vantage/jobs/{$retry->id}");

    $response->assertStatus(200)
        ->assertSee('Retry Chain', false);
});

it('filters jobs by tags', function () {
    VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'processed',
        'job_tags' => ['email', 'important'],
    ]);

    VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\OtherJob',
        'status' => 'processed',
        'job_tags' => ['report'],
    ]);

    $response = $this->get('/vantage/jobs?tags=email');

    $response->assertStatus(200)
        ->assertSee('TestJob', false)
        ->assertDontSee('OtherJob', false);
});

it('displays failed jobs page', function () {
    VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'App\\Jobs\\TestJob',
        'status' => 'failed',
        'exception_class' => 'Exception',
        'exception_message' => 'Test error',
    ]);

    $response = $this->get('/vantage/failed');

    $response->assertStatus(200)
        ->assertSee('Failed', false)
        ->assertSee('Test error', false);
});
