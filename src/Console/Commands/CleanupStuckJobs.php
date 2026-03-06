<?php

namespace Storvia\Vantage\Console\Commands;

use Storvia\Vantage\Models\VantageJob;
use Illuminate\Console\Command;

class CleanupStuckJobs extends Command
{
    protected $signature = 'vantage:cleanup-stuck
                            {--timeout=1 : Hours to consider a job stuck (default: 1 hour)}
                            {--dry-run : Show what would be cleaned without actually cleaning}';

    protected $description = 'Clean up stuck "processing" jobs that never completed';

    public function handle(): int
    {
        $timeoutHours = (int) $this->option('timeout');
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subHours($timeoutHours);

        // Find stuck jobs
        $stuckJobs = VantageJob::where('status', 'processing')
            ->where('started_at', '<', $cutoff)
            ->get();

        if ($stuckJobs->isEmpty()) {
            $this->info('No stuck jobs found!');

            return self::SUCCESS;
        }

        $this->warn("Found {$stuckJobs->count()} stuck jobs (processing for more than {$timeoutHours}h)");

        if ($dryRun) {
            $this->table(
                ['ID', 'Job Class', 'Started At', 'Age'],
                $stuckJobs->map(fn ($job) => [
                    $job->id,
                    class_basename($job->job_class),
                    $job->started_at->format('Y-m-d H:i:s'),
                    $job->started_at->diffForHumans(),
                ])
            );

            $this->line("\nDry run - no changes made. Run without --dry-run to clean these up.");

            return self::SUCCESS;
        }

        // Mark as failed with timeout
        foreach ($stuckJobs as $job) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'exception_class' => 'TimeoutException',
                'exception_message' => "Job stuck in processing state for more than {$timeoutHours} hours",
            ]);
        }

        $this->info("Marked {$stuckJobs->count()} stuck jobs as failed");

        return self::SUCCESS;
    }
}
