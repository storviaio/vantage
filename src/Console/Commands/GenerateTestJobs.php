<?php

namespace Storvia\Vantage\Console\Commands;

use Illuminate\Bus\Queueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateTestJobs extends Command
{
    protected $signature = 'vantage:generate-test-jobs 
                            {--count=100 : Number of jobs to generate}
                            {--success-rate=80 : Percentage of jobs that should succeed (0-100)}
                            {--tags= : Comma-separated tags to apply to jobs}
                            {--queue=default : Queue name}
                            {--duration-min=10 : Minimum duration in milliseconds}
                            {--duration-max=5000 : Maximum duration in milliseconds}
                            {--batch-size=50 : Number of jobs to dispatch at once}';

    protected $description = 'Generate and dispatch test jobs to verify queue monitoring functionality under load';

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $successRate = (int) $this->option('success-rate');
        $tags = $this->option('tags')
            ? explode(',', $this->option('tags'))
            : ['test', 'load-test', 'generated'];
        $queue = $this->option('queue');
        $durationMin = (int) $this->option('duration-min');
        $durationMax = (int) $this->option('duration-max');
        $batchSize = (int) $this->option('batch-size');

        if ($count < 1) {
            $this->error('Count must be at least 1');

            return self::FAILURE;
        }

        if ($successRate < 0 || $successRate > 100) {
            $this->error('Success rate must be between 0 and 100');

            return self::FAILURE;
        }

        $this->info("[INFO] Generating {$count} test jobs...");
        $this->info("   Success rate: {$successRate}%");
        $this->info("   Queue: {$queue}");
        $this->info('   Tags: '.implode(', ', $tags));
        $this->newLine();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $dispatched = 0;
        $batches = ceil($count / $batchSize);

        for ($batch = 0; $batch < $batches; $batch++) {
            $batchJobs = [];
            $remaining = $count - $dispatched;
            $currentBatchSize = min($batchSize, $remaining);

            for ($i = 0; $i < $currentBatchSize; $i++) {
                $job = new TestLoadJob(
                    shouldFail: (random_int(1, 100) > $successRate),
                    durationMs: random_int($durationMin, $durationMax),
                    tags: $tags,
                    jobNumber: $dispatched + $i + 1
                );

                $batchJobs[] = $job;
            }

            // Dispatch batch
            foreach ($batchJobs as $job) {
                dispatch($job)->onQueue($queue);
                $bar->advance();
                $dispatched++;
            }

            // Small delay between batches to avoid overwhelming the queue
            if ($batch < $batches - 1) {
                usleep(100000); // 0.1 second
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("[OK] Dispatched {$dispatched} jobs to queue '{$queue}'");
        $this->info('   Expected failures: ~'.round($dispatched * (100 - $successRate) / 100));
        $this->info('   Expected successes: ~'.round($dispatched * $successRate / 100));
        $this->newLine();
        $this->info('[TIP] Monitor progress at: /vantage');
        $this->info('[TIP] Run queue worker: php artisan queue:work');

        return self::SUCCESS;
    }
}

/**
 * Test job for load testing
 */
class TestLoadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public bool $shouldFail = false,
        public int $durationMs = 100,
        public array $tags = [],
        public int $jobNumber = 0
    ) {}

    public function tags(): array
    {
        return $this->tags;
    }

    public function handle(): void
    {
        // Simulate work duration
        $startTime = microtime(true);
        $targetDuration = $this->durationMs / 1000; // Convert to seconds

        // Do some actual CPU work to simulate processing
        $iterations = 0;
        while ((microtime(true) - $startTime) < $targetDuration) {
            // Simple CPU-bound operation
            $iterations++;
            if ($iterations % 1000 === 0) {
                // Yield occasionally to avoid blocking
                usleep(100);
            }
        }

        // Simulate failure if requested
        if ($this->shouldFail) {
            throw new \Exception("Test failure for job #{$this->jobNumber} (simulated)");
        }

        // Small chance of random failure even if not intended
        if (random_int(1, 1000) === 1) {
            throw new \Exception("Random failure for job #{$this->jobNumber}");
        }
    }
}
