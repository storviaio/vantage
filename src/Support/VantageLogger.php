<?php

namespace Storvia\Vantage\Support;

use Illuminate\Support\Facades\Log;

class VantageLogger
{
    protected static function enabled(): bool
    {
        return (bool) config('vantage.logging.enabled', true);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (static::enabled()) {
            Log::debug($message, $context);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        if (static::enabled()) {
            Log::info($message, $context);
        }
    }

    public static function warning(string $message, array $context = []): void
    {
        if (static::enabled()) {
            Log::warning($message, $context);
        }
    }

    public static function error(string $message, array $context = []): void
    {
        if (static::enabled()) {
            Log::error($message, $context);
        }
    }
}
