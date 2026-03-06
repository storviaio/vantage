<?php

namespace Storvia\Vantage\Console\Commands;

use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\TagAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillJobTags extends Command
{
    protected $signature = 'vantage:backfill-tags
                            {--days= : Only backfill jobs from the last X days (default: all)}
                            {--chunk=1000 : Number of jobs to process per batch}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Backfill the vantage_job_tags table from existing jobs for optimized tag queries';

    public function handle(): int
    {
        $aggregator = new TagAggregator;

        // Check if tags table exists
        if (! $aggregator->hasTagsTable()) {
            $this->error('The vantage_job_tags table does not exist.');
            $this->line('');
            $this->line('Run the migration first:');
            $this->line('  php artisan migrate');
            $this->line('');
            $this->line('Or publish and run Vantage migrations:');
            $this->line('  php artisan vendor:publish --tag=vantage-migrations');
            $this->line('  php artisan migrate');

            return self::FAILURE;
        }

        $days = $this->option('days') ? (int) $this->option('days') : null;
        $chunkSize = (int) $this->option('chunk');
        $force = $this->option('force');

        // Build query for jobs to backfill
        $query = VantageJob::whereNotNull('job_tags');

        if ($days !== null) {
            $query->where('created_at', '>', now()->subDays($days));
            $period = "from the last {$days} days";
        } else {
            $period = 'all time';
        }

        // Count jobs to process
        $totalJobs = $query->count();

        if ($totalJobs === 0) {
            $this->info('No jobs with tags found to backfill.');

            return self::SUCCESS;
        }

        // Check if already populated
        $existingCount = DB::connection($this->getConnectionName())
            ->table('vantage_job_tags')
            ->count();

        if ($existingCount > 0) {
            $this->warn("The vantage_job_tags table already contains {$existingCount} records.");
            $this->line('');

            if (! $force && ! $this->confirm('Do you want to clear existing records and re-backfill?', false)) {
                $this->info('Backfill cancelled. Existing data preserved.');
                $this->line('');
                $this->line('Options:');
                $this->line('  - Use --force to overwrite without confirmation');
                $this->line('  - Use --days=X to only backfill recent jobs (appends to existing)');

                return self::SUCCESS;
            }

            // Clear existing records matching the time range
            $this->info('Clearing existing tag records...');
            if ($days !== null) {
                $cutoff = now()->subDays($days);
                DB::connection($this->getConnectionName())
                    ->table('vantage_job_tags')
                    ->where('created_at', '>', $cutoff)
                    ->delete();
            } else {
                DB::connection($this->getConnectionName())
                    ->table('vantage_job_tags')
                    ->truncate();
            }
        }

        $this->info("Backfilling tags for {$totalJobs} jobs ({$period})...");
        $this->line("Processing in chunks of {$chunkSize}...");
        $this->line('');

        $bar = $this->output->createProgressBar($totalJobs);
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% - %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        $processed = 0;
        $tagsInserted = 0;
        $errors = 0;

        $query->select(['id', 'job_tags', 'created_at'])
            ->orderBy('id')
            ->chunk($chunkSize, function ($jobs) use (&$processed, &$tagsInserted, &$errors, $bar) {
                $records = [];

                foreach ($jobs as $job) {
                    $processed++;

                    if (empty($job->job_tags) || ! is_array($job->job_tags)) {
                        continue;
                    }

                    foreach ($job->job_tags as $tag) {
                        if (is_string($tag) && trim($tag) !== '') {
                            $records[] = [
                                'job_id' => $job->id,
                                'tag' => trim($tag),
                                'created_at' => $job->created_at,
                            ];
                        }
                    }
                }

                // Batch insert for performance
                if (! empty($records)) {
                    try {
                        // Insert in smaller batches to avoid MySQL max_allowed_packet issues
                        foreach (array_chunk($records, 500) as $batch) {
                            DB::connection($this->getConnectionName())
                                ->table('vantage_job_tags')
                                ->insert($batch);
                            $tagsInserted += count($batch);
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        // Log but continue
                    }
                }

                $bar->setMessage("{$tagsInserted} tags inserted");
                $bar->setProgress($processed);
            });

        $bar->finish();
        $this->line('');
        $this->line('');

        // Summary
        $this->info('✓ Backfill completed!');
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Jobs processed', number_format($processed)],
                ['Tags inserted', number_format($tagsInserted)],
                ['Avg tags per job', $processed > 0 ? round($tagsInserted / $processed, 2) : 0],
                ['Errors', $errors],
            ]
        );

        // Show optimization tip
        if ($aggregator->supportsEfficientJsonOperations()) {
            $this->line('');
            $this->info('✓ Your database now supports efficient tag aggregation!');
            $this->line('  Dashboard tag queries will be ~100x faster for large datasets.');
        } else {
            $this->line('');
            $this->warn('Note: Your database driver does not support efficient JSON operations.');
            $this->line('  The tags table will be used as a fallback for fast aggregation.');
        }

        return self::SUCCESS;
    }

    /**
     * Get the database connection name.
     */
    protected function getConnectionName(): ?string
    {
        return config('vantage.database_connection');
    }
}
