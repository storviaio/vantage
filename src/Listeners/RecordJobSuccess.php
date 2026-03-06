<?php

namespace Storvia\Vantage\Listeners;

use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\JobPerformanceContext;
use Storvia\Vantage\Support\PayloadExtractor;
use Storvia\Vantage\Support\TagExtractor;
use Storvia\Vantage\Support\Traits\ExtractsRetryOf;
use Storvia\Vantage\Support\VantageLogger;
use Illuminate\Queue\Events\JobProcessed;

class RecordJobSuccess
{
    use ChecksJobExclusion;
    use ExtractsRetryOf;

    public function handle(JobProcessed $event): void
    {
        // Master switch: if package is disabled, don't track anything
        if (! config('vantage.enabled', true)) {
            return;
        }

        if ($this->isExcluded($event->job->resolveName())) {
            return;
        }

        // Some jobs (like rate-limited ones) are "processed" only to be released immediately.
        // Laravel exposes helpers to detect this so we don't count them as successful runs.
        if (method_exists($event->job, 'isReleased') && $event->job->isReleased()) {
            VantageLogger::debug('Queue Monitor: Job was released, skipping processed record', [
                'job_class' => $this->jobClass($event),
            ]);

            return;
        }

        $uuid = $this->bestUuid($event);
        $jobClass = $this->jobClass($event);
        $queue = $event->job->getQueue();
        $connection = $event->connectionName ?? null;

        // Try to find existing processing record
        $row = null;

        // First, try by stable UUID if available (most reliable)
        $hasStableUuid = (method_exists($event->job, 'uuid') && $event->job->uuid())
                      || (method_exists($event->job, 'getJobId') && $event->job->getJobId());

        if ($hasStableUuid) {
            $row = VantageJob::where('uuid', $uuid)
                ->where('status', 'processing')
                ->first();
        }

        // Fallback: try by job class, queue, connection (ONLY if UUID not available)
        // This should rarely be needed since Laravel 8+ provides uuid()
        if (! $row && ! $hasStableUuid) {
            $row = VantageJob::where('job_class', $jobClass)
                ->where('queue', $queue)
                ->where('connection', $connection)
                ->where('status', 'processing')
                ->where('created_at', '>', now()->subMinute()) // Keep it tight to avoid matching wrong job
                ->orderByDesc('id')
                ->first();
        }

        if ($row) {
            // Capture end metrics
            $telemetryEnabled = config('vantage.telemetry.enabled', true);
            $captureCpu = config('vantage.telemetry.capture_cpu', true);

            $memoryEnd = null;
            $memoryPeakEnd = null;
            $cpuDelta = ['user_ms' => null, 'sys_ms' => null];

            if ($telemetryEnabled) {
                $memoryEnd = @memory_get_usage(true) ?: null;
                $memoryPeakEnd = @memory_get_peak_usage(true) ?: null;

                if ($captureCpu && function_exists('getrusage')) {
                    $ru = @getrusage();
                    if (is_array($ru)) {
                        $userUs = ($ru['ru_utime.tv_sec'] ?? 0) * 1_000_000 + ($ru['ru_utime.tv_usec'] ?? 0);
                        $sysUs = ($ru['ru_stime.tv_sec'] ?? 0) * 1_000_000 + ($ru['ru_stime.tv_usec'] ?? 0);
                        $baseline = JobPerformanceContext::getBaseline($uuid);
                        if ($baseline) {
                            $cpuDelta['user_ms'] = max(0, (int) round(($userUs - ($baseline['cpu_start_user_us'] ?? 0)) / 1000));
                            $cpuDelta['sys_ms'] = max(0, (int) round(($sysUs - ($baseline['cpu_start_sys_us'] ?? 0)) / 1000));
                        }
                    }
                }
            }

            // Update existing record
            $row->status = 'processed';
            $row->finished_at = now();
            if ($row->started_at) {
                $duration = $row->finished_at->diffInUTCMilliseconds($row->started_at, true);
                $row->duration_ms = max(0, (int) $duration);
            }

            // Memory updates
            $row->memory_end_bytes = $memoryEnd;
            $row->memory_peak_end_bytes = $memoryPeakEnd;
            if ($row->memory_peak_start_bytes !== null && $memoryPeakEnd !== null) {
                $row->memory_peak_delta_bytes = max(0, (int) ($memoryPeakEnd - $row->memory_peak_start_bytes));
            }

            // CPU deltas
            $row->cpu_user_ms = $cpuDelta['user_ms'];
            $row->cpu_sys_ms = $cpuDelta['sys_ms'];

            $row->save();

            // Clear baseline
            JobPerformanceContext::clearBaseline($uuid);

            VantageLogger::debug('Queue Monitor: Job completed', [
                'id' => $row->id,
                'job_class' => $jobClass,
                'duration_ms' => $row->duration_ms,
            ]);
        } else {
            // Fallback: Create a new processed record if we didn't catch the start
            VantageLogger::warning('Queue Monitor: No processing record found, creating new', [
                'job_class' => $jobClass,
                'uuid' => $uuid,
            ]);

            VantageJob::create([
                'uuid' => $uuid,
                'job_class' => $jobClass,
                'queue' => $queue,
                'connection' => $connection,
                'attempt' => $event->job->attempts(),
                'status' => 'processed',
                'finished_at' => now(),
                'retried_from_id' => $this->getRetryOf($event),
                'payload' => PayloadExtractor::getPayload($event),
                'job_tags' => TagExtractor::extract($event),
            ]);
        }
    }

    protected function bestUuid(JobProcessed $event): string
    {
        // Try Laravel's built-in UUID
        if (method_exists($event->job, 'uuid') && $event->job->uuid()) {
            return (string) $event->job->uuid();
        }

        // Fallback to job ID
        if (method_exists($event->job, 'getJobId') && $event->job->getJobId()) {
            return (string) $event->job->getJobId();
        }

        // Last resort: generate new UUID
        return (string) Str::uuid();
    }

    protected function jobClass(JobProcessed $event): string
    {
        if (method_exists($event->job, 'resolveName')) {
            return $event->job->resolveName();
        }

        return get_class($event->job);
    }
}
