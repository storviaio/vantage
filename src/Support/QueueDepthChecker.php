<?php

namespace Storvia\Vantage\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class QueueDepthChecker
{
    /**
     * Get queue depth for all queues or a specific queue
     */
    public static function getQueueDepth(?string $queueName = null): array
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");

        return match ($driver) {
            'database' => self::getDatabaseQueueDepth($queueName, $connection),
            'redis' => self::getRedisQueueDepth($queueName, $connection),
            default => self::getFallbackQueueDepth($queueName, $driver),
        };
    }

    /**
     * Get queue depths for all queues using database driver
     */
    protected static function getDatabaseQueueDepth(?string $queueName, string $connection): array
    {
        try {
            $table = config("queue.connections.{$connection}.table", 'jobs');

            $query = DB::table($table)
                ->whereNull('reserved_at')
                ->where('attempts', 0);

            if ($queueName) {
                $count = $query->where('queue', $queueName)->count();

                return [$queueName ?: 'default' => $count];
            } else {
                // Get depths for all queues
                $queues = DB::table($table)
                    ->whereNull('reserved_at')
                    ->where('attempts', 0)
                    ->distinct()
                    ->pluck('queue')
                    ->filter();

                $depths = [];
                foreach ($queues as $queue) {
                    $count = DB::table($table)
                        ->where('queue', $queue)
                        ->whereNull('reserved_at')
                        ->where('attempts', 0)
                        ->count();

                    $depths[$queue ?: 'default'] = $count;
                }

                // If no jobs found, still check default queue
                if (empty($depths)) {
                    $defaultCount = DB::table($table)
                        ->where('queue', 'default')
                        ->whereNull('reserved_at')
                        ->where('attempts', 0)
                        ->count();
                    if ($defaultCount > 0) {
                        $depths['default'] = $defaultCount;
                    }
                }

                return $depths;
            }
        } catch (\Throwable $e) {
            VantageLogger::warning('Failed to get database queue depth', [
                'error' => $e->getMessage(),
                'queue' => $queueName,
            ]);

            return [];
        }
    }

    /**
     * Get queue depths for all queues using Redis driver
     */
    protected static function getRedisQueueDepth(?string $queueName, string $connection): array
    {
        try {
            $redis = Redis::connection(config("queue.connections.{$connection}.connection", 'default'));
            $prefix = config("queue.connections.{$connection}.prefix", '');
            $database = config("queue.connections.{$connection}.database", 0);

            if ($database) {
                $redis->select($database);
            }

            if ($queueName) {
                $queue = $queueName ?: 'default';
                $key = "{$prefix}queues:{$queue}";
                $count = $redis->llen($key);

                return [$queue => $count];
            }

            // Get depths for all queues by scanning Redis keys
            $queues = [];
            $pattern = "{$prefix}queues:*";

            $keys = $redis->keys($pattern);
            foreach ($keys as $key) {
                // Extract queue name from key (e.g., "queues:default" -> "default")
                $queueName = str_replace("{$prefix}queues:", '', $key);
                $count = $redis->llen($key);

                if ($count > 0 || $queueName) {
                    $queues[$queueName ?: 'default'] = $count;
                }
            }

            // If no queues found but we have a default queue, check it
            if (empty($queues)) {
                $defaultKey = "{$prefix}queues:default";
                $count = $redis->llen($defaultKey);
                if ($count > 0) {
                    $queues['default'] = $count;
                }
            }

            return $queues;
        } catch (\Throwable $e) {
            VantageLogger::warning('Failed to get Redis queue depth', [
                'error' => $e->getMessage(),
                'queue' => $queueName,
            ]);

            return [];
        }
    }

    /**
     * Fallback method for unsupported drivers (returns empty or estimated)
     */
    protected static function getFallbackQueueDepth(?string $queueName, string $driver): array
    {
        VantageLogger::debug('Queue depth not supported for driver', [
            'driver' => $driver,
            'queue' => $queueName,
        ]);

        // For unsupported drivers, we can still show what we know from job_runs
        // Count jobs that are processing or recently started (might be queued)
        try {
            $query = \Storvia\Vantage\Models\VantageJob::where('status', 'processing');

            if ($queueName) {
                $query->where('queue', $queueName);
            }

            $count = $query->count();

            return [$queueName ?: 'default' => $count];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get total queue depth across all queues
     */
    public static function getTotalQueueDepth(): int
    {
        $depths = self::getQueueDepth();

        return array_sum($depths);
    }

    /**
     * Get queue depth with additional metadata
     */
    public static function getQueueDepthWithMetadata(?string $queueName = null): array
    {
        $depths = self::getQueueDepth($queueName);
        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");

        $result = [];
        foreach ($depths as $queue => $depth) {
            $result[$queue] = [
                'depth' => $depth,
                'driver' => $driver,
                'connection' => $connection,
                'status' => self::getQueueStatus($depth),
            ];
        }

        return $result;
    }

    /**
     * Determine queue health status based on depth
     */
    protected static function getQueueStatus(int $depth): string
    {
        if ($depth === 0) {
            return 'healthy';
        } elseif ($depth < 100) {
            return 'normal';
        } elseif ($depth < 1000) {
            return 'warning';
        } else {
            return 'critical';
        }
    }

    /**
     * Always return at least one queue entry, even if empty
     * This ensures the dashboard always shows the queue depth section
     */
    public static function getQueueDepthWithMetadataAlways(?string $queueName = null): array
    {
        $depths = self::getQueueDepthWithMetadata($queueName);

        // If empty, show at least the default queue with 0 depth
        if (empty($depths)) {
            $connection = config('queue.default');
            $driver = config("queue.connections.{$connection}.driver", 'sync');

            return [
                'default' => [
                    'depth' => 0,
                    'driver' => $driver,
                    'connection' => $connection,
                    'status' => 'healthy',
                ],
            ];
        }

        return $depths;
    }
}
