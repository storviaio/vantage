<?php

namespace Storvia\Vantage\Support;

use Storvia\Vantage\Models\VantageJob;

class JobRestorer
{
    /**
     * Safely restore a job instance from its stored payload.
     *
     * Performs the following security checks before returning an object:
     *  - The expected class must exist in the application.
     *  - The stored payload must be a valid JSON array.
     *  - The serialized command must be present (new or legacy format).
     *  - Unserialize is restricted to the expected class only.
     *  - The resulting object must be an instance of the expected class.
     */
    public function restore(VantageJob $job, string $expectedJobClass): ?object
    {
        if (! $job->payload) {
            return null;
        }

        if (! class_exists($expectedJobClass)) {
            VantageLogger::warning('Vantage: Expected job class does not exist', [
                'job_id' => $job->id,
                'expected_class' => $expectedJobClass,
            ]);

            return null;
        }

        try {
            $payload = is_array($job->payload) ? $job->payload : json_decode($job->payload, true);

            if (! is_array($payload)) {
                VantageLogger::warning('Vantage: Invalid payload format', ['job_id' => $job->id]);

                return null;
            }

            // Support both the new format (from PayloadExtractor) and the legacy format.
            $serialized = $payload['raw_payload']['data']['command']
                ?? $payload['data']['command']
                ?? null;

            if (! $serialized || ! is_string($serialized)) {
                VantageLogger::warning('Vantage: No serialized command in payload', ['job_id' => $job->id]);

                return null;
            }

            $command = @unserialize($serialized, ['allowed_classes' => [$expectedJobClass]]);

            if (! is_object($command)) {
                VantageLogger::warning('Vantage: Unserialize did not return an object', [
                    'job_id' => $job->id,
                    'result_type' => gettype($command),
                ]);

                return null;
            }

            if (! $command instanceof $expectedJobClass) {
                VantageLogger::warning('Vantage: Unserialized job class does not match expected class', [
                    'job_id' => $job->id,
                    'expected_class' => $expectedJobClass,
                    'actual_class' => get_class($command),
                ]);

                return null;
            }

            VantageLogger::info('Vantage: Successfully restored job from payload', [
                'job_id' => $job->id,
                'job_class' => get_class($command),
            ]);

            return $command;

        } catch (\Throwable $e) {
            VantageLogger::error('Vantage: Exception while restoring job from payload', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
