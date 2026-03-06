<?php

namespace Storvia\Vantage\Console\Commands;

use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\TagAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOldJobs extends Command
{
    protected $signature = 'vantage:prune
                            {--days= : Keep jobs from the last X days (defaults to config value or 30)}
                            {--hours= : Keep jobs from the last X hours (overrides --days)}
                            {--status= : Only prune jobs with specific status (processed, failed, or processing). Leave empty to prune all}
                            {--keep-processing : Always keep jobs with "processing" status}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Prune old job records from the database to free up space';

    public function handle(): int
    {
        // Get default days from config, fallback to 30
        $configDays = config('vantage.retention_days', 30);
        $daysOption = $this->option('days');
        $days = $daysOption !== null ? (int) $daysOption : $configDays;
        $usingConfig = $daysOption === null;
        $hours = $this->option('hours') ? (int) $this->option('hours') : null;
        $statusFilter = $this->option('status');
        $keepProcessing = $this->option('keep-processing');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Calculate cutoff date
        if ($hours !== null) {
            $cutoff = now()->subHours($hours);
            $period = "{$hours} hours";
        } else {
            $cutoff = now()->subDays($days);
            $period = "{$days} days";
            if ($usingConfig) {
                $period .= ' (from config: vantage.retention_days)';
            }
        }

        $query = VantageJob::where('created_at', '<', $cutoff);

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        // Always keep processing jobs unless explicitly included
        if ($keepProcessing || (! $statusFilter && ! $keepProcessing)) {
            $query->where('status', '!=', 'processing');
        }

        // Get count before deletion
        $count = $query->count();

        if ($count === 0) {
            $this->info("No jobs found older than {$period} to prune.");

            return self::SUCCESS;
        }

        // Show summary
        $this->info("Found {$count} job(s) older than {$period} to prune.");

        if ($statusFilter) {
            $this->line("Status filter: {$statusFilter}");
        }

        if ($keepProcessing) {
            $this->line("Keeping all 'processing' jobs regardless of age.");
        }

        // Show breakdown by status
        $breakdown = VantageJob::where('created_at', '<', $cutoff)
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when($keepProcessing || (! $statusFilter && ! $keepProcessing), fn ($q) => $q->where('status', '!=', 'processing'))
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        if (! empty($breakdown)) {
            $this->table(
                ['Status', 'Count'],
                collect($breakdown)->map(fn ($count, $status) => [$status, number_format($count)])
            );
        }

        // Dry run mode
        if ($dryRun) {
            $this->warn("\n[DRY RUN] No changes made. Run without --dry-run to delete these records.");

            return self::SUCCESS;
        }

        // Confirmation
        if (! $force && ! $this->confirm("Are you sure you want to delete {$count} job record(s)?", true)) {
            $this->info('Pruning cancelled.');

            return self::SUCCESS;
        }

        // Delete in chunks to avoid memory issues
        $this->info('Deleting records...');
        $deleted = 0;
        $chunkSize = 1000;

        // Use chunking for large deletions
        $query->chunkById($chunkSize, function ($jobs) use (&$deleted) {
            // Handle retry chain relationships
            // First, nullify retried_from_id for children of jobs we're about to delete
            $parentIds = $jobs->pluck('id')->toArray();

            if (! empty($parentIds)) {
                // Find children that reference these parents
                $childrenCount = VantageJob::whereIn('retried_from_id', $parentIds)->count();

                if ($childrenCount > 0) {
                    // Option 1: Delete children too (cascade)
                    // Option 2: Nullify the relationship (orphan the children)
                    // We'll nullify to preserve retry history of remaining jobs
                    VantageJob::whereIn('retried_from_id', $parentIds)
                        ->update(['retried_from_id' => null]);

                    $this->line("  → Orphaned {$childrenCount} retry child record(s)");
                }
            }

            // Delete the jobs
            $chunkDeleted = VantageJob::whereIn('id', $parentIds)->delete();
            $deleted += $chunkDeleted;

            $this->line("  → Deleted {$chunkDeleted} record(s) (total: {$deleted})");
        });

        $this->info("\nSuccessfully pruned {$deleted} job record(s) older than {$period}.");

        // Also prune the denormalized tags table if it exists
        $tagAggregator = new TagAggregator;
        if ($tagAggregator->hasTagsTable()) {
            $tagsDeleted = $tagAggregator->pruneOldTags($cutoff);
            if ($tagsDeleted > 0) {
                $this->line("Also pruned {$tagsDeleted} tag record(s) from vantage_job_tags table.");
            }
        }

        // Show remaining stats
        $remaining = VantageJob::count();
        $this->line('Remaining jobs in database: '.number_format($remaining));

        return self::SUCCESS;
    }
}
