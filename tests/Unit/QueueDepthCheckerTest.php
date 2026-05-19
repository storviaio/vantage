<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\QueueDepthChecker;

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

it('queries the queue-specific connection, not the app default', function () {
    // Regression test for the bug where QueueDepthChecker used DB::table(),
    // which always hits the default connection. When the database queue
    // driver is configured with a `connection` key (e.g. a dedicated SQLite
    // file via DB_QUEUE_DATABASE, or a separate MySQL host), the check must
    // route through DB::connection($dbConnection)->table().
    //
    // Before the fix, this test failed with:
    //   SQLSTATE[HY000]: General error: 1 no such table: jobs
    // …because the jobs table only exists on the `queue_db` connection,
    // not on the default `testing` connection.

    config()->set('database.connections.queue_db', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'queue_db',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    // Create jobs table ONLY on the queue connection — leaving the default
    // connection without one. Any DB::table('jobs') call against the default
    // would throw.
    Schema::connection('queue_db')->create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->default('default');
        $table->longText('payload')->nullable();
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at')->nullable();
        $table->unsignedInteger('created_at')->nullable();
    });

    $counts = [
        'default' => 3,
        'emails' => 7,
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
        DB::connection('queue_db')->table('jobs')->insert($rows);
    }

    $depths = QueueDepthChecker::getQueueDepth();

    expect($depths)
        ->toHaveKeys(['default', 'emails'])
        ->and($depths['default'])->toBe(3)
        ->and($depths['emails'])->toBe(7);
});

it('queries the queue-specific connection when filtering by queue name', function () {
    config()->set('database.connections.queue_db', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'queue_db',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    Schema::connection('queue_db')->create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->default('default');
        $table->longText('payload')->nullable();
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at')->nullable();
        $table->unsignedInteger('created_at')->nullable();
    });

    DB::connection('queue_db')->table('jobs')->insert([
        ['queue' => 'reports', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
        ['queue' => 'reports', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
        ['queue' => 'other', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp],
    ]);

    expect(QueueDepthChecker::getQueueDepth('reports'))->toBe(['reports' => 2]);
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
