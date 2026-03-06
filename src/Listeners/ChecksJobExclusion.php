<?php

declare(strict_types=1);

namespace Storvia\Vantage\Listeners;

use Storvia\Vantage\Contracts\ShouldNotBeTracked;

trait ChecksJobExclusion
{
    protected function isExcluded(string $jobClass): bool
    {
        // Check interface
        if (is_a($jobClass, ShouldNotBeTracked::class, true)) {
            return true;
        }

        // Check config exclude list
        $excluded = config('vantage.exclude_jobs', []);

        return in_array($jobClass, $excluded, true);
    }
}
