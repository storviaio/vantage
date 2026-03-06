<?php

use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\QueueDepthChecker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('jobs');
});

it('returns queue depths with metadata for the database driver', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->default('default');
        $table->longText('payload')->nullable();
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at')->nullable();
        $table->unsignedInteger('created_at')->nullable();
    });

    $counts = [
        'default' => 2,
        'emails' => 150,
        'critical-queue' => 1200,
    ];

    foreach ($counts as $queue => $count) {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'queue' => $queue,
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ];
        }
        DB::table('jobs')->insert($rows);
    }

    $depths = QueueDepthChecker::getQueueDepthWithMetadata();

    expect($depths)->toHaveKeys(['default', 'emails', 'critical-queue'])
        ->and($depths['default']['depth'])->toBe(2)
        ->and($depths['default']['status'])->toBe('normal')
        ->and($depths['emails']['status'])->toBe('warning')
        ->and($depths['critical-queue']['status'])->toBe('critical')
        ->and($depths['default']['driver'])->toBe('database');

    expect(QueueDepthChecker::getTotalQueueDepth())->toBe(array_sum($counts));
});

it('returns a default queue entry when no jobs are present', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
    ]);

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->default('default');
        $table->longText('payload')->nullable();
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at')->nullable();
        $table->unsignedInteger('created_at')->nullable();
    });

    $depths = QueueDepthChecker::getQueueDepthWithMetadataAlways();

    expect($depths)->toHaveKey('default')
        ->and($depths['default']['depth'])->toBe(0)
        ->and($depths['default']['status'])->toBe('healthy')
        ->and($depths['default']['driver'])->toBe('database');
});

it('falls back to processing jobs when the driver is unsupported', function () {
    config()->set('queue.default', 'sync');
    config()->set('queue.connections.sync.driver', 'sync');

    VantageJob::create([
        'uuid' => 'processing-job',
        'job_class' => 'App\\Jobs\\ExampleJob',
        'queue' => 'reports',
        'connection' => 'sync',
        'status' => 'processing',
    ]);

    $depths = QueueDepthChecker::getQueueDepth('reports');

    expect($depths)->toBe(['reports' => 1])
        ->and(QueueDepthChecker::getTotalQueueDepth())->toBe(1);
});
