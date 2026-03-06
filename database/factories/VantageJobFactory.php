<?php

namespace Storvia\Vantage\Database\Factories;

use Storvia\Vantage\Models\VantageJob;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VantageJobFactory extends Factory
{
    protected $model = VantageJob::class;

    public function definition(): array
    {
        $status = $this->faker->randomElement(['processing', 'processed', 'failed']);
        $startedAt = $this->faker->dateTimeBetween('-7 days', 'now');
        $finishedAt = null;
        $duration = null;
        $exception = null;
        $exceptionMessage = null;
        $failedAt = null;

        if ($status === 'processed') {
            $finishedAt = $this->faker->dateTimeBetween($startedAt, 'now');
            $duration = $finishedAt->getTimestamp() - $startedAt->getTimestamp();
        } elseif ($status === 'failed') {
            $failedAt = $this->faker->dateTimeBetween($startedAt, 'now');
            $duration = $failedAt->getTimestamp() - $startedAt->getTimestamp();
            $exception = $this->faker->randomElement([
                'Exception',
                'RuntimeException',
                'InvalidArgumentException',
                'ModelNotFoundException',
                'QueryException',
            ]);
            $exceptionMessage = $this->faker->sentence();
        }

        $jobClasses = [
            'App\\Jobs\\SendEmailJob',
            'App\\Jobs\\ProcessPaymentJob',
            'App\\Jobs\\GenerateReportJob',
            'App\\Jobs\\ImportDataJob',
            'App\\Jobs\\NotifyUserJob',
            'App\\Jobs\\SyncInventoryJob',
            'App\\Jobs\\CleanupOldDataJob',
            'App\\Jobs\\ProcessWebhookJob',
        ];

        return [
            'uuid' => (string) Str::uuid(),
            'status' => $status,
            'queue' => $this->faker->randomElement(['default', 'emails', 'high', 'low']),
            'connection' => $this->faker->randomElement(['database', 'redis', 'sync']),
            'job_name' => $this->faker->randomElement($jobClasses),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'failed_at' => $failedAt,
            'duration' => $duration,
            'exception_class' => $exception,
            'exception_message' => $exceptionMessage,
            'payload' => json_encode([
                'displayName' => $this->faker->randomElement($jobClasses),
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'command' => serialize(new \stdClass),
                ],
            ]),
            'job_tags' => $this->faker->randomElements(
                ['email', 'notification', 'report', 'payment', 'sync', 'cleanup', 'import', 'webhook'],
                $this->faker->numberBetween(0, 3)
            ),
            'retried_from_id' => null,
            'memory_usage' => $this->faker->numberBetween(1000000, 50000000), // 1MB to 50MB
            'peak_memory_usage' => $this->faker->numberBetween(2000000, 100000000), // 2MB to 100MB
            'cpu_usage' => $this->faker->randomFloat(2, 0.1, 100.0),
        ];
    }

    /**
     * Indicate that the job is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'finished_at' => null,
            'failed_at' => null,
            'duration' => null,
            'exception_class' => null,
            'exception_message' => null,
        ]);
    }

    /**
     * Indicate that the job has processed successfully.
     */
    public function processed(): static
    {
        return $this->state(function (array $attributes) {
            $startedAt = $attributes['started_at'] ?? now()->subMinutes(5);
            $finishedAt = now();

            return [
                'status' => 'processed',
                'finished_at' => $finishedAt,
                'failed_at' => null,
                'duration' => $finishedAt->diffInSeconds($startedAt),
                'exception_class' => null,
                'exception_message' => null,
            ];
        });
    }

    /**
     * Indicate that the job has failed.
     */
    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            $startedAt = $attributes['started_at'] ?? now()->subMinutes(5);
            $failedAt = now();

            return [
                'status' => 'failed',
                'finished_at' => null,
                'failed_at' => $failedAt,
                'duration' => $failedAt->diffInSeconds($startedAt),
                'exception_class' => 'Exception',
                'exception_message' => 'Something went wrong',
            ];
        });
    }

    /**
     * Indicate that the job has specific tags.
     */
    public function withTags(array $tags): static
    {
        return $this->state(fn (array $attributes) => [
            'job_tags' => $tags,
        ]);
    }

    /**
     * Indicate that the job was retried from another job.
     */
    public function retriedFrom(int $jobId): static
    {
        return $this->state(fn (array $attributes) => [
            'retried_from_id' => $jobId,
        ]);
    }

    /**
     * Indicate that the job is on a specific queue.
     */
    public function onQueue(string $queue): static
    {
        return $this->state(fn (array $attributes) => [
            'queue' => $queue,
        ]);
    }

    /**
     * Indicate that the job is using a specific connection.
     */
    public function onConnection(string $connection): static
    {
        return $this->state(fn (array $attributes) => [
            'connection' => $connection,
        ]);
    }

    /**
     * Indicate that the job is a specific class.
     */
    public function jobClass(string $class): static
    {
        return $this->state(fn (array $attributes) => [
            'job_name' => $class,
            'payload' => json_encode([
                'displayName' => $class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'command' => serialize(new \stdClass),
                ],
            ]),
        ]);
    }
}
