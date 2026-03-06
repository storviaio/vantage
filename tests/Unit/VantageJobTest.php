<?php

use Storvia\Vantage\Models\VantageJob;
use Illuminate\Support\Str;

function makeJob(array $overrides = []): VantageJob
{
    return VantageJob::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'job_class' => 'App\\Jobs\\ExampleJob',
        'queue' => 'default',
        'connection' => 'database',
        'status' => 'processing',
    ], $overrides));
}

it('can create a queue job run', function () {
    $job = makeJob([
        'uuid' => 'test-uuid-123',
        'attempt' => 1,
        'started_at' => now(),
    ]);

    expect($job)->toBeInstanceOf(VantageJob::class)
        ->and($job->uuid)->toBe('test-uuid-123')
        ->and($job->job_class)->toBe('App\\Jobs\\ExampleJob')
        ->and($job->status)->toBe('processing');
});

it('casts job_tags to array', function () {
    $job = makeJob([
        'uuid' => 'test-uuid-tags',
        'job_tags' => ['tag1', 'tag2'],
    ]);

    expect($job->job_tags)->toBeArray()
        ->and($job->job_tags)->toHaveCount(2)
        ->and($job->job_tags)->toContain('tag1', 'tag2');
});

it('casts dates correctly', function () {
    $job = makeJob([
        'uuid' => 'test-uuid-dates',
        'started_at' => '2024-01-01 10:00:00',
        'finished_at' => '2024-01-01 10:05:00',
    ]);

    expect($job->started_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($job->finished_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('has retried_from relationship', function () {
    $parentJob = makeJob([
        'uuid' => 'parent-uuid',
        'status' => 'processed',
    ]);

    $retryJob = makeJob([
        'uuid' => 'retry-uuid',
        'retried_from_id' => $parentJob->id,
    ]);

    expect($retryJob->retriedFrom)->toBeInstanceOf(VantageJob::class)
        ->and($retryJob->retriedFrom->id)->toBe($parentJob->id);
});

it('has retries relationship', function () {
    $parentJob = makeJob([
        'uuid' => 'parent-uuid',
        'status' => 'processed',
    ]);

    $retry1 = makeJob([
        'uuid' => 'retry-1',
        'status' => 'processed',
        'retried_from_id' => $parentJob->id,
    ]);

    $retry2 = makeJob([
        'uuid' => 'retry-2',
        'status' => 'failed',
        'retried_from_id' => $parentJob->id,
    ]);

    $retries = $parentJob->retries()->get();

    expect($retries)->toHaveCount(2)
        ->and($retries->pluck('id')->toArray())->toContain($retry1->id, $retry2->id);
});

it('checks if job has tag', function () {
    $job = makeJob([
        'uuid' => 'test-uuid',
        'job_tags' => ['important', 'email', 'urgent'],
    ]);

    expect($job->hasTag('important'))->toBeTrue()
        ->and($job->hasTag('Important'))->toBeTrue() // Case insensitive
        ->and($job->hasTag('nonexistent'))->toBeFalse();
});

it('formats duration correctly', function () {
    $job1 = makeJob([
        'uuid' => 'test-1',
        'status' => 'processed',
        'duration_ms' => 500,
    ]);

    $job2 = makeJob([
        'uuid' => 'test-2',
        'status' => 'processed',
        'duration_ms' => 2500,
    ]);

    $job3 = makeJob([
        'uuid' => 'test-3',
        'duration_ms' => null,
    ]);

    expect($job1->formatted_duration)->toBe('500ms')
        ->and($job2->formatted_duration)->toBe('2.5s')
        ->and($job3->formatted_duration)->toBe('N/A');
});

it('filters by tag scope', function () {
    makeJob([
        'uuid' => 'test-1',
        'status' => 'processed',
        'job_tags' => ['email', 'important'],
    ]);

    makeJob([
        'uuid' => 'test-2',
        'status' => 'processed',
        'job_tags' => ['email'],
    ]);

    makeJob([
        'uuid' => 'test-3',
        'status' => 'processed',
        'job_tags' => ['important'],
    ]);

    $jobsWithEmail = VantageJob::withTag('email')->get();
    expect($jobsWithEmail)->toHaveCount(2);

    $jobsWithImportant = VantageJob::withTag('important')->get();
    expect($jobsWithImportant)->toHaveCount(2);
});

it('filters by status scope', function () {
    makeJob([
        'uuid' => 'test-1',
        'status' => 'processed',
    ]);

    makeJob([
        'uuid' => 'test-2',
        'status' => 'failed',
    ]);

    makeJob([
        'uuid' => 'test-3',
        'status' => 'processing',
    ]);

    expect(VantageJob::failed()->count())->toBe(1)
        ->and(VantageJob::successful()->count())->toBe(1)
        ->and(VantageJob::processing()->count())->toBe(1);
});

it('casts telemetry fields to integers', function () {
    $job = makeJob([
        'uuid' => 'test-telemetry',
        'duration_ms' => '500',
        'memory_start_bytes' => '1048576',
        'memory_end_bytes' => '2097152',
        'memory_peak_start_bytes' => '1048576',
        'memory_peak_end_bytes' => '3145728',
        'memory_peak_delta_bytes' => '2097152',
        'cpu_user_ms' => '100',
        'cpu_sys_ms' => '50',
    ]);

    expect($job->duration_ms)->toBeInt()->toBe(500)
        ->and($job->memory_start_bytes)->toBeInt()->toBe(1048576)
        ->and($job->memory_end_bytes)->toBeInt()->toBe(2097152)
        ->and($job->memory_peak_start_bytes)->toBeInt()->toBe(1048576)
        ->and($job->memory_peak_end_bytes)->toBeInt()->toBe(3145728)
        ->and($job->memory_peak_delta_bytes)->toBeInt()->toBe(2097152)
        ->and($job->cpu_user_ms)->toBeInt()->toBe(100)
        ->and($job->cpu_sys_ms)->toBeInt()->toBe(50);
});

it('handles null telemetry fields', function () {
    $job = makeJob([
        'uuid' => 'test-null-telemetry',
        'duration_ms' => null,
        'memory_start_bytes' => null,
        'cpu_user_ms' => null,
    ]);

    expect($job->duration_ms)->toBeNull()
        ->and($job->memory_start_bytes)->toBeNull()
        ->and($job->cpu_user_ms)->toBeNull();
});
