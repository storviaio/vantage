<?php

namespace Storvia\Vantage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Str;
use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\JobRestorer;

class RetryFailedJob extends Command
{
    protected $signature = 'vantage:retry {run_id}';

    protected $description = 'Retry a failed job run by ID using stored payload';

    public function handle(): int
    {
        $run = VantageJob::find($this->argument('run_id'));

        if (! $run || $run->status !== 'failed') {
            $this->error('Job run not found or not failed.');

            return self::FAILURE;
        }

        $jobClass = (string) $run->job_class;

        if (! class_exists($jobClass)) {
            $this->error("Job class {$jobClass} not found.");

            return self::FAILURE;
        }

        if (! is_subclass_of($jobClass, ShouldQueue::class) &&
            ! is_subclass_of($jobClass, Job::class)) {
            $this->error("Invalid job class: {$jobClass}");

            return self::FAILURE;
        }

        $job = app(JobRestorer::class)->restore($run, $jobClass);
        if (! $job) {
            $this->error('Unable to restore job. Payload might be missing or corrupted.');

            return self::FAILURE;
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
}
