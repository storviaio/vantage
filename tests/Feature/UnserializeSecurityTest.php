<?php

namespace Storvia\Vantage\Tests\Feature;

use Storvia\Vantage\Http\Controllers\QueueMonitorController;
use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Vantage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

beforeEach(function () {
    VantageJob::query()->delete();
});

class TestSecureJob implements ShouldQueue
{
    use \Illuminate\Bus\Queueable;

    public $testProperty;

    public function __construct($testProperty = 'default')
    {
        $this->testProperty = $testProperty;
    }

    public function handle() {}
}

class MaliciousClass
{
    public function __wakeup()
    {
        throw new \Exception('Malicious code executed!');
    }

    public function __destruct()
    {
        throw new \Exception('Malicious code executed!');
    }
}

class NotAJobClass
{
    public $data;
}

it('prevents unserializing malicious classes in Vantage::retryJob', function () {
    $vantage = new Vantage;

    // Serialized string to avoid instantiating MaliciousClass (which would trigger __destruct)
    $maliciousSerialized = 'O:15:"MaliciousClass":0:{}';

    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => $maliciousSerialized,
                ],
            ],
        ]),
    ]);

    $result = $vantage->retryJob($job->id);

    expect($result)->toBeFalse();
});

it('prevents unserializing wrong class in Vantage::retryJob', function () {
    $vantage = new Vantage;

    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => serialize(new NotAJobClass),
                ],
            ],
        ]),
    ]);

    $result = $vantage->retryJob($job->id);

    expect($result)->toBeFalse();
});

it('allows retrying valid job with correct class in Vantage::retryJob', function () {
    $vantage = new Vantage;

    $testJob = new TestSecureJob('test-value');
    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => serialize($testJob),
                ],
            ],
        ]),
    ]);

    $result = $vantage->retryJob($job->id);

    expect($result)->toBeTrue();
});

it('rejects invalid job class in Vantage::retryJob', function () {
    $vantage = new Vantage;

    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => 'NonExistent\\Class\\Name',
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => serialize(new TestSecureJob),
                ],
            ],
        ]),
    ]);

    $result = $vantage->retryJob($job->id);

    expect($result)->toBeFalse();
});

it('rejects non-job class in Vantage::retryJob', function () {
    $vantage = new Vantage;

    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => NotAJobClass::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => serialize(new NotAJobClass),
                ],
            ],
        ]),
    ]);

    $result = $vantage->retryJob($job->id);

    expect($result)->toBeFalse();
});

it('prevents unserializing malicious classes in QueueMonitorController::restoreJobFromPayload', function () {
    $controller = new QueueMonitorController;

    $maliciousSerialized = 'O:15:"MaliciousClass":0:{}';

    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => $maliciousSerialized,
                ],
            ],
        ]),
    ]);

    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('restoreJobFromPayload');
    $method->setAccessible(true);

    $result = $method->invoke($controller, $job, TestSecureJob::class);

    expect($result)->toBeNull();
});

it('prevents unserializing wrong class in QueueMonitorController::restoreJobFromPayload', function () {
    $controller = new QueueMonitorController;

    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => serialize(new NotAJobClass),
                ],
            ],
        ]),
    ]);

    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('restoreJobFromPayload');
    $method->setAccessible(true);

    $result = $method->invoke($controller, $job, TestSecureJob::class);

    expect($result)->toBeNull();
});

it('allows restoring valid job in QueueMonitorController::restoreJobFromPayload', function () {
    $controller = new QueueMonitorController;

    $testJob = new TestSecureJob('test-value');
    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => serialize($testJob),
                ],
            ],
        ]),
    ]);

    $reflection = new \ReflectionClass($controller);
    $method = $reflection->getMethod('restoreJobFromPayload');
    $method->setAccessible(true);

    $result = $method->invoke($controller, $job, TestSecureJob::class);

    expect($result)->not->toBeNull()
        ->and($result)->toBeInstanceOf(TestSecureJob::class)
        ->and($result->testProperty)->toBe('test-value');
});

it('handles old payload format in Vantage::retryJob', function () {
    $vantage = new Vantage;

    $testJob = new TestSecureJob('old-format');
    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => json_encode([
            'data' => [
                'command' => serialize($testJob),
            ],
        ]),
    ]);

    $result = $vantage->retryJob($job->id);

    expect($result)->toBeTrue();
});

it('handles missing payload gracefully in Vantage::retryJob', function () {
    $vantage = new Vantage;

    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'failed',
        'queue' => 'default',
        'payload' => null,
    ]);

    $result = $vantage->retryJob($job->id);

    expect($result)->toBeTrue();
});

it('rejects job with non-failed status in Vantage::retryJob', function () {
    $vantage = new Vantage;

    $job = VantageJob::create([
        'uuid' => Str::uuid(),
        'job_class' => TestSecureJob::class,
        'status' => 'processed',
        'queue' => 'default',
        'payload' => json_encode([
            'raw_payload' => [
                'data' => [
                    'command' => serialize(new TestSecureJob),
                ],
            ],
        ]),
    ]);

    $result = $vantage->retryJob($job->id);

    expect($result)->toBeFalse();
});
