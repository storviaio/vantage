<?php

namespace Storvia\Vantage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Storvia\Vantage\Vantage
 *
 * @method static \Illuminate\Support\Collection queueDepth(?string $queue = null)
 * @method static \Illuminate\Support\Collection jobsByStatus(string $status, int $limit = 50)
 * @method static \Illuminate\Support\Collection failedJobs(int $limit = 50)
 * @method static \Illuminate\Support\Collection processingJobs(int $limit = 50)
 * @method static \Illuminate\Support\Collection jobsByTag(string $tag, int $limit = 50)
 * @method static array statistics(?string $startDate = null)
 * @method static bool retryJob(int $jobId)
 * @method static int cleanupStuckJobs(int $hoursOld = 24)
 * @method static int pruneOldJobs(int $daysOld = 30)
 * @method static \Storvia\Vantage\Support\VantageLogger logger()
 * @method static void enable()
 * @method static void disable()
 * @method static bool enabled()
 */
class Vantage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Storvia\Vantage\Vantage::class;
    }
}
