<?php

namespace Storvia\Vantage;

use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\QueueDepthChecker;
use Storvia\Vantage\Support\VantageLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        if (! is_subclass_of($expectedJobClass, \Illuminate\Contracts\Queue\ShouldQueue::class) &&
            ! is_subclass_of($expectedJobClass, \Illuminate\Queue\Jobs\Job::class)) {
            return false;
        }

        // Try to restore job from payload with safety checks
        $command = $this->restoreJobFromPayload($job, $expectedJobClass);

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
     * Safely restore job from payload with security checks.
     */
    protected function restoreJobFromPayload(VantageJob $job, string $expectedJobClass): ?object
    {
        if (! $job->payload) {
            return null;
        }

        try {
            $payload = is_array($job->payload) ? $job->payload : json_decode($job->payload, true);

            if (! is_array($payload)) {
                return null;
            }

            // Try new format first (from PayloadExtractor)
            $serialized = $payload['raw_payload']['data']['command'] ?? null;

            // Fallback to old format
            if (! $serialized) {
                $serialized = $payload['data']['command'] ?? null;
            }

            if (! $serialized || ! is_string($serialized)) {
                return null;
            }

            // Unserialize with allowed_classes restriction - only allow the expected class
            $command = @unserialize($serialized, ['allowed_classes' => [$expectedJobClass]]);

            // Validate the result
            if (! is_object($command)) {
                return null;
            }

            // Double-check the class matches
            if (! $command instanceof $expectedJobClass) {
                return null;
            }

            return $command;
        } catch (\Throwable $e) {
            return null;
        }
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
