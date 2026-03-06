<?php

namespace Storvia\Vantage\Console\Commands;

use Storvia\Vantage\Models\VantageJob;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RetryFailedJob extends Command
{
    protected $signature = 'vantage:retry {run_id} {--force : Retry even if payload is not available}';

    protected $description = 'Retry a failed job run by ID using stored payload';

    public function handle(): int
    {
        $run = VantageJob::find($this->argument('run_id'));

        if (! $run || $run->status !== 'failed') {
            $this->error('Job run not found or not failed.');

            return self::FAILURE;
        }

        $jobClass = $run->job_class;

        if (! class_exists($jobClass)) {
            $this->error("Job class {$jobClass} not found.");

            return self::FAILURE;
        }

        // Try to restore job from payload
        $job = $this->restoreJobFromPayload($run);

        if (! $job) {
            if (! $this->option('force')) {
                $this->warn("No payload available for job #{$run->id}. Use --force to retry with empty constructor.");

                return self::FAILURE;
            }

            $this->warn('Creating job with empty constructor (--force)');
            $job = new $jobClass;
        }

        $job->queueMonitorRetryOf = $run->id;

        dispatch($job)
            ->onQueue($run->queue ?? 'default')
            ->onConnection($run->connection ?? config('queue.default'));

        $this->info("Retried job {$jobClass} from run #{$run->id}");

        if ($run->job_tags) {
            $this->line('Tags: '.implode(', ', $run->job_tags));
        }

        if ($run->exception_message) {
            $this->line('Original failure: '.Str::limit($run->exception_message, 100));
        }

        return self::SUCCESS;
    }

    /**
     * Restore job instance from stored payload
     */
    protected function restoreJobFromPayload(VantageJob $run): ?object
    {
        if (! $run->payload) {
            return null;
        }

        try {
            $payload = json_decode($run->payload, true);

            if (! $payload) {
                return null;
            }

            $jobClass = $run->job_class;

            return $this->recreateJobWithReflection($jobClass, $payload);
        } catch (\Throwable $e) {
            $this->error('Failed to restore job from payload: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Recreate job using reflection
     */
    protected function recreateJobWithReflection(string $jobClass, array $payload): ?object
    {
        try {
            $reflection = new \ReflectionClass($jobClass);
            $constructor = $reflection->getConstructor();

            if (! $constructor) {
                return new $jobClass;
            }

            $params = $constructor->getParameters();
            $args = [];

            foreach ($params as $param) {
                $paramName = $param->getName();

                if (isset($payload[$paramName])) {
                    $args[] = $this->unserializeValue($payload[$paramName]);
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    $args[] = null;
                }
            }

            return $reflection->newInstanceArgs($args);
        } catch (\Throwable $e) {
            $this->error('Failed to recreate job with reflection: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Convert payload value back to original type
     */
    protected function unserializeValue($value)
    {
        if (is_array($value) && isset($value['model']) && isset($value['id'])) {
            // Restore Eloquent model
            $modelClass = $value['model'];
            if (class_exists($modelClass)) {
                return $modelClass::find($value['id']);
            }
        }

        return $value;
    }
}
