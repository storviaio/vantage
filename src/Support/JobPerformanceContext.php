<?php

namespace Storvia\Vantage\Support;

/**
 * Simple static context to keep per-job runtime baselines in memory
 * keyed by job UUID. Used for CPU deltas and other metrics we don't
 * persist as separate start columns.
 */
class JobPerformanceContext
{
    protected static array $baselines = [];

    public static function setBaseline(string $uuid, array $data): void
    {
        self::$baselines[$uuid] = $data;
    }

    public static function getBaseline(string $uuid): ?array
    {
        return self::$baselines[$uuid] ?? null;
    }

    public static function clearBaseline(string $uuid): void
    {
        unset(self::$baselines[$uuid]);
    }
}
