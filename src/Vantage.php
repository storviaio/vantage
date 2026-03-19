<?php

namespace Storvia\Vantage;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\JobRestorer;
use Storvia\Vantage\Support\QueueDepthChecker;
use Storvia\Vantage\Support\VantageLogger;

class Vantage
{
    /**
     * Get the queue depth for all or specific queues.
     */
    public function queueDepth(?string $queue = null): Collection
    {
        return app(QueueDepthChecker::class)->check($queue);
    }

    /**
     * Get jobs with a specific status.
     */
    public function jobsByStatus(string $status, int $limit = 50): Collection
    {
        return VantageJob::where('status', $status)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get failed jobs.
     */
    public function failedJobs(int $limit = 50): Collection
    {
        return $this->jobsByStatus('failed', $limit);
    }

    /**
     * Get processing jobs.
     */
    public function processingJobs(int $limit = 50): Collection
    {
        return $this->jobsByStatus('processing', $limit);
    }

    /**
     * Get jobs by tag.
     */
    public function jobsByTag(string $tag, int $limit = 50): Collection
    {
        return VantageJob::withTag($tag)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get statistics for the dashboard.
     */
    public function statistics(?string $startDate = null): array
    {
        $query = VantageJob::query();

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        $stats = $query->select(
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN status = "processed" THEN 1 ELSE 0 END) as processed'),
            DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed'),
            DB::raw('SUM(CASE WHEN status = "processing" THEN 1 ELSE 0 END) as processing')
        )->first();

        $successRate = $stats->total > 0
            ? round(($stats->processed / ($stats->processed + $stats->failed)) * 100, 2)
            : 0;

        return [
            'total' => $stats->total ?? 0,
            'processed' => $stats->processed ?? 0,
            'failed' => $stats->failed ?? 0,
            'processing' => $stats->processing ?? 0,
            'success_rate' => $successRate,
        ];
    }

    /**
     * Retry a failed job.
     */
    public function retryJob(int $jobId): bool
    {
        $job = VantageJob::find($jobId);

        if (! $job || $job->status !== 'failed') {
            return false;
        }

        // Get the expected job class from the stored job_class field
        $expectedJobClass = $job->job_class;

        if (! $expectedJobClass || ! is_string($expectedJobClass) || ! class_exists($expectedJobClass)) {
            return false;
        }

        // Validate it's a valid job class (implements ShouldQueue or extends Job)
        if (! is_subclass_of($expectedJobClass, ShouldQueue::class) &&
            ! is_subclass_of($expectedJobClass, Job::class)) {
            return false;
        }

        // Try to restore job from payload with safety checks
        $command = app(JobRestorer::class)->restore($job, $expectedJobClass);

        if (! $command) {
            // Only allow fallback if payload is completely missing (not corrupted/malicious)
            // If payload exists but restoration failed, it's a security issue - don't fallback
            if (! $job->payload) {
                // Safe fallback: create new instance with empty constructor if payload unavailable
                try {
                    $command = new $expectedJobClass;
                } catch (\Throwable $e) {
                    return false;
                }
            } else {
                // Payload exists but restoration failed - this could be a security issue
                // Don't fallback to prevent bypassing security checks
                return false;
            }
        }

        // Validate the restored command is of the expected class
        if (! $command instanceof $expectedJobClass) {
            return false;
        }

        dispatch($command)->onQueue($job->queue ?? 'default');

        return true;
    }

    /**
     * Clean up stuck processing jobs.
     */
    public function cleanupStuckJobs(int $hoursOld = 24): int
    {
        return VantageJob::where('status', 'processing')
            ->where('started_at', '<', now()->subHours($hoursOld))
            ->update(['status' => 'failed', 'exception_class' => 'Timeout']);
    }

    /**
     * Prune old jobs.
     */
    public function pruneOldJobs(int $daysOld = 30): int
    {
        return VantageJob::where('created_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    /**
     * Get the VantageLogger instance.
     */
    public function logger(): VantageLogger
    {
        return app(VantageLogger::class);
    }

    /**
     * Enable Vantage.
     */
    public function enable(): void
    {
        config(['vantage.enabled' => true]);
    }

    /**
     * Disable Vantage.
     */
    public function disable(): void
    {
        config(['vantage.enabled' => false]);
    }

    /**
     * Check if Vantage is enabled.
     */
    public function enabled(): bool
    {
        return config('vantage.enabled', true);
    }
}
