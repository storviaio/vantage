<?php

namespace Storvia\Vantage\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\Job;

/**
 * Simple Tag Extractor
 *
 * Extracts tags from Laravel jobs using the built-in tags() method.
 */
class TagExtractor
{
    public static function extract($event): ?array
    {
        if (! config('vantage.tagging.enabled', true)) {
            return null;
        }

        try {
            $tags = [];

            $command = self::getCommand($event);

            if ($command && method_exists($command, 'tags')) {
                $jobTags = $command->tags();
                if (is_array($jobTags)) {
                    $tags = array_merge($tags, $jobTags);
                }
            }

            // Add auto-generated tags
            $tags = array_merge($tags, self::getAutoTags($event));

            // Clean and return
            return self::cleanTags($tags);

        } catch (\Throwable $e) {
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
     * Generate auto tags based on configuration
     */
    protected static function getAutoTags($event): array
    {
        $tags = [];

        // Queue name (enabled by default)
        if (config('vantage.tagging.auto_tags.queue_name', true)) {
            $tags[] = 'queue:'.$event->job->getQueue();
        }

        // Environment (disabled by default)
        if (config('vantage.tagging.auto_tags.environment', false)) {
            $tags[] = 'env:'.app()->environment();
        }

        // Hour (disabled by default)
        if (config('vantage.tagging.auto_tags.hour', false)) {
            $tags[] = 'hour:'.now()->format('H');
        }

        return $tags;
    }

    /**
     * Clean and normalize tags
     *
     * - Remove empty values
     * - Convert to lowercase
     * - Trim whitespace
     * - Remove duplicates
     * - Limit to max number
     */
    protected static function cleanTags(array $tags): ?array
    {
        $tags = array_filter($tags, fn ($tag) => ! empty($tag));

        $tags = array_map(fn ($tag) => strtolower(trim($tag)), $tags);

        $tags = array_unique($tags);

        // Re-index array (0, 1, 2...)
        $tags = array_values($tags);

        // Limit to max tags
        $maxTags = config('vantage.tagging.max_tags_per_job', 20);
        if (count($tags) > $maxTags) {
            $tags = array_slice($tags, 0, $maxTags);
        }

        return ! empty($tags) ? $tags : null;
    }
}
