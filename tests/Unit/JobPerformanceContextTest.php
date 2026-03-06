<?php

use Storvia\Vantage\Support\JobPerformanceContext;

beforeEach(function () {
    // Clear baselines before each test
    $reflection = new \ReflectionClass(JobPerformanceContext::class);
    $property = $reflection->getProperty('baselines');
    $property->setAccessible(true);
    $property->setValue(null, []);
});

it('stores and retrieves baseline data', function () {
    $uuid = 'test-uuid-123';
    $baseline = [
        'cpu_start_user_us' => 1000000,
        'cpu_start_sys_us' => 500000,
    ];

    JobPerformanceContext::setBaseline($uuid, $baseline);

    $retrieved = JobPerformanceContext::getBaseline($uuid);

    expect($retrieved)->toBe($baseline)
        ->and($retrieved['cpu_start_user_us'])->toBe(1000000)
        ->and($retrieved['cpu_start_sys_us'])->toBe(500000);
});

it('returns null for non-existent baseline', function () {
    $retrieved = JobPerformanceContext::getBaseline('non-existent-uuid');

    expect($retrieved)->toBeNull();
});

it('clears a specific baseline', function () {
    $uuid1 = 'uuid-1';
    $uuid2 = 'uuid-2';

    JobPerformanceContext::setBaseline($uuid1, ['cpu_start_user_us' => 1000]);
    JobPerformanceContext::setBaseline($uuid2, ['cpu_start_user_us' => 2000]);

    JobPerformanceContext::clearBaseline($uuid1);

    expect(JobPerformanceContext::getBaseline($uuid1))->toBeNull()
        ->and(JobPerformanceContext::getBaseline($uuid2))->not->toBeNull();
});

it('handles multiple baselines independently', function () {
    $uuid1 = 'uuid-1';
    $uuid2 = 'uuid-2';

    $baseline1 = ['cpu_start_user_us' => 1000000, 'cpu_start_sys_us' => 500000];
    $baseline2 = ['cpu_start_user_us' => 2000000, 'cpu_start_sys_us' => 1000000];

    JobPerformanceContext::setBaseline($uuid1, $baseline1);
    JobPerformanceContext::setBaseline($uuid2, $baseline2);

    expect(JobPerformanceContext::getBaseline($uuid1))->toBe($baseline1)
        ->and(JobPerformanceContext::getBaseline($uuid2))->toBe($baseline2);
});

it('overwrites existing baseline for same uuid', function () {
    $uuid = 'test-uuid';

    JobPerformanceContext::setBaseline($uuid, ['cpu_start_user_us' => 1000]);
    JobPerformanceContext::setBaseline($uuid, ['cpu_start_user_us' => 2000]);

    $retrieved = JobPerformanceContext::getBaseline($uuid);

    expect($retrieved['cpu_start_user_us'])->toBe(2000);
});
