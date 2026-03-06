<?php

namespace Storvia\Vantage\Support\Traits;

use Storvia\Vantage\Support\VantageLogger;
use Illuminate\Support\Str;

trait ExtractsRetryOf
{
    /**
     * Extract the retry_of ID from the job payload
     */
    protected function getRetryOf($event): ?int
    {
        $retryOf = null;

        try {
            $payload = $event->job->payload();
            $cmd = $payload['data']['command'] ?? null;

            if (is_object($cmd) && property_exists($cmd, 'queueMonitorRetryOf')) {
                $retryOf = (int) $cmd->queueMonitorRetryOf;
            } elseif (is_string($cmd)) {
                $obj = @unserialize($cmd);
                if (is_object($obj) && property_exists($obj, 'queueMonitorRetryOf')) {
                    $retryOf = (int) $obj->queueMonitorRetryOf;
                }
            }
        } catch (\Throwable $e) {
            // Log error if needed, but don't break the application
            VantageLogger::debug('Error extracting retryOf', ['error' => $e->getMessage()]);
        }

        VantageLogger::debug('QM retryOf check', ['retryOf' => $retryOf]);

        return $retryOf;
    }

    /**
     * Get the job class name
     */
    protected function getJobClass($event): string
    {
        return method_exists($event->job, 'resolveName')
            ? $event->job->resolveName()
            : get_class($event->job);
    }

    /**
     * Get the best available UUID for the job
     */
    protected function getBestUuid($event): string
    {
        // Prefer a stable id if available (Laravel versions differ here)
        if (method_exists($event->job, 'uuid') && $event->job->uuid()) {
            return (string) $event->job->uuid();
        }
        if (method_exists($event->job, 'getJobId') && $event->job->getJobId()) {
            return (string) $event->job->getJobId();
        }

        // Otherwise we'll generate a UUID
        return (string) Str::uuid();
    }
}
