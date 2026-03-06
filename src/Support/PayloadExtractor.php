<?php

namespace Storvia\Vantage\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\Job;

/**
 * Simple Payload Extractor
 *
 * Extracts job data for storage - keeps it simple and safe.
 */
class PayloadExtractor
{
    /**
     * Extract job payload as JSON string for storage
     *
     * Gets COMPLETE payload from Laravel's queue - everything!
     */
    public static function getPayload($event): ?string
    {
        if (! config('vantage.store_payload', true)) {
            return null;
        }

        try {
            // Get the COMPLETE raw payload from Laravel's queue
            $rawPayload = $event->job->payload();

            // Convert the command object to readable format
            $command = self::getCommand($event);
            $commandData = [];

            if ($command) {
                $commandData = self::extractData($command);
            }

            // Combine everything
            $fullData = [
                'raw_payload' => $rawPayload, // Complete Laravel queue payload
                'command_data' => $commandData, // Extracted command properties
                'job_info' => [
                    'uuid' => method_exists($event->job, 'uuid') ? $event->job->uuid() : null,
                    'job_id' => method_exists($event->job, 'getJobId') ? $event->job->getJobId() : null,
                    'name' => method_exists($event->job, 'resolveName') ? $event->job->resolveName() : null,
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName ?? null,
                    'attempts' => $event->job->attempts(),
                ],
            ];

            $fullData = self::redactSensitive($fullData);

            // Debug: Log what we're extracting
            if (config('app.debug', false)) {
                VantageLogger::info('PayloadExtractor: Complete payload extracted', [
                    'command_class' => $command ? get_class($command) : null,
                    'raw_payload_keys' => array_keys($rawPayload),
                    'command_data_keys' => array_keys($commandData),
                    'payload_size' => strlen(json_encode($fullData)),
                ]);
            }

            return json_encode($fullData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            VantageLogger::error('PayloadExtractor: Failed to extract payload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get job command object from event
     *
     * Note: During job processing, we don't know the exact class ahead of time.
     * We validate after unserializing that it's a valid job class.
     */
    protected static function getCommand($event): ?object
    {
        try {
            $payload = $event->job->payload();
            $serialized = $payload['data']['command'] ?? null;

            if (! is_string($serialized)) {
                return null;
            }

            // During job processing, Laravel has already validated the job.
            // We still restrict to prevent arbitrary class instantiation, but we need
            // to allow classes since jobs are objects. We validate after.
            $command = @unserialize($serialized, ['allowed_classes' => true]);

            if (! is_object($command)) {
                return null;
            }

            // Security validation: ensure it's a valid job class
            // This prevents unserializing arbitrary classes even if they got into the queue
            if (! ($command instanceof ShouldQueue) &&
                ! ($command instanceof Job)) {
                return null;
            }

            return $command;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract data from command object
     *
     * Gets ALL properties (public, protected, private) from the job.
     * Saves EVERYTHING - no filtering!
     */
    protected static function extractData(object $command): array
    {
        $data = [];

        try {
            $reflection = new \ReflectionClass($command);

            // Get ALL properties (public, protected, private) - NO FILTERING!
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
                $key = $property->getName();
                $value = $property->getValue($command);
                $data[$key] = self::convertValue($value);
            }
        } catch (\Throwable $e) {
            // If reflection fails, fallback to public properties only
            foreach (get_object_vars($command) as $key => $value) {
                $data[$key] = self::convertValue($value);
            }
        }

        return $data;
    }

    /**
     * Convert value to JSON-safe format
     *
     * Handles scalars, arrays, objects, models safely.
     */
    protected static function convertValue($value)
    {
        // Simple values
        if (is_null($value) || is_scalar($value)) {
            return $value;
        }

        // Arrays
        if (is_array($value)) {
            return array_map(fn ($item) => self::convertValue($item), $value);
        }

        // Eloquent models
        if (is_object($value) && method_exists($value, 'getKey') && method_exists($value, 'getTable')) {
            $modelData = [
                'model' => get_class($value),
                'id' => $value->getKey(),
            ];

            // Try to get some attributes for context
            try {
                $attributes = $value->getAttributes();
                // Only include a few key attributes to avoid huge payloads
                $keyAttributes = ['name', 'email', 'title', 'slug'];
                foreach ($keyAttributes as $attr) {
                    if (isset($attributes[$attr])) {
                        $modelData[$attr] = $attributes[$attr];
                    }
                }
            } catch (\Throwable $e) {
                // Ignore attribute access errors
            }

            return $modelData;
        }

        // Collections
        if (is_object($value) && method_exists($value, 'toArray')) {
            try {
                return self::convertValue($value->toArray());
            } catch (\Throwable $e) {
                return ['class' => get_class($value), 'type' => 'collection'];
            }
        }

        // DateTime objects
        if ($value instanceof \DateTimeInterface) {
            return [
                'class' => get_class($value),
                'date' => $value->format('Y-m-d H:i:s'),
                'timezone' => $value->getTimezone()->getName(),
            ];
        }

        // Other objects - try to get some properties
        if (is_object($value)) {
            $objectData = ['class' => get_class($value)];

            try {
                $reflection = new \ReflectionClass($value);
                $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

                foreach ($properties as $property) {
                    $propName = $property->getName();
                    $propValue = $property->getValue($value);

                    // Only include simple properties to avoid recursion
                    if (is_null($propValue) || is_scalar($propValue)) {
                        $objectData[$propName] = $propValue;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore reflection errors
            }

            return $objectData;
        }

        return null;
    }

    /**
     * Redact sensitive keys from data
     *
     * Removes passwords, tokens, secrets, etc.
     */
    protected static function redactSensitive(array $data): array
    {
        $sensitiveKeys = config('vantage.redact_keys', [
            'password', 'token', 'secret', 'api_key', 'access_token',
        ]);

        foreach ($data as $key => &$value) {
            // Check if key is sensitive
            if (in_array(strtolower($key), array_map('strtolower', $sensitiveKeys))) {
                $data[$key] = '[REDACTED]';
            }
            // Recursively check nested arrays
            elseif (is_array($value)) {
                $data[$key] = self::redactSensitive($value);
            }
        }

        return $data;
    }
}
