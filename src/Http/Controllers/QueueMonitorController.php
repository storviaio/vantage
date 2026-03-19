<?php

namespace Storvia\Vantage\Http\Controllers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storvia\Vantage\Models\VantageJob;
use Storvia\Vantage\Support\JobRestorer;
use Storvia\Vantage\Support\QueueDepthChecker;
use Storvia\Vantage\Support\TagAggregator;
use Storvia\Vantage\Support\VantageLogger;

class QueueMonitorController extends Controller
{
    /**
     * Dashboard - Overview of all jobs
     */
    public function index(Request $request)
    {
        $period = $request->get('period', '30d'); // Changed default to 30 days
        $since = $this->getSinceDate($period);

        // Overall statistics
        $stats = [
            'total' => VantageJob::where('created_at', '>', $since)->count(),
            'processed' => VantageJob::where('created_at', '>', $since)->where('status', 'processed')->count(),
            'failed' => VantageJob::where('created_at', '>', $since)->where('status', 'failed')->count(),
            'processing' => VantageJob::where('status', 'processing')
                ->where('created_at', '>', now()->subHour()) // Only recent processing jobs
                ->count(),
            'avg_duration' => VantageJob::where('created_at', '>', $since)
                ->whereNotNull('duration_ms')
                ->avg('duration_ms'),
        ];

        // Calculate success rate based on completed jobs only (processed + failed)
        $completedJobs = $stats['processed'] + $stats['failed'];
        $stats['success_rate'] = $completedJobs > 0
            ? round(($stats['processed'] / $completedJobs) * 100, 1)
            : 0;

        // Recent jobs - exclude large payload and stack columns
        $recentJobs = VantageJob::select([
            'id', 'uuid', 'job_class', 'queue', 'connection', 'attempt',
            'status', 'started_at', 'finished_at', 'duration_ms',
            'exception_class', 'exception_message', 'job_tags', 'retried_from_id',
            'created_at', 'updated_at',
            // Exclude: payload, stack (large text fields)
        ])
            ->latest('id')
            ->limit(20)
            ->get();

        // Jobs by status (for chart)
        $jobsByStatus = VantageJob::select('status', DB::raw('count(*) as count'))
            ->where('created_at', '>', $since)
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        // Jobs by hour (for trend chart)
        // Use database-agnostic date formatting
        $connectionName = (new VantageJob)->getConnectionName();
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $dateFormat = DB::raw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour');
        } elseif ($driver === 'sqlite') {
            $dateFormat = DB::raw('strftime("%Y-%m-%d %H:00:00", created_at) as hour');
        } elseif ($driver === 'pgsql') {
            $dateFormat = DB::raw("to_char(created_at, 'YYYY-MM-DD HH24:00:00') as hour");
        } else {
            // Fallback for other databases
            $dateFormat = DB::raw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour');
        }

        $jobsByHour = VantageJob::select(
            $dateFormat,
            DB::raw('count(*) as count'),
            DB::raw("sum(case when status = 'failed' then 1 else 0 end) as failed_count")
        )
            ->where('created_at', '>', $since)
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        // Top failing jobs
        $topFailingJobs = VantageJob::select('job_class', DB::raw('count(*) as failure_count'))
            ->where('created_at', '>', $since)
            ->where('status', 'failed')
            ->groupBy('job_class')
            ->orderByDesc('failure_count')
            ->limit(5)
            ->get();

        // Top exceptions
        $topExceptions = VantageJob::select('exception_class', DB::raw('count(*) as count'))
            ->where('created_at', '>', $since)
            ->whereNotNull('exception_class')
            ->groupBy('exception_class')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        // Slowest jobs
        $slowestJobs = VantageJob::select('job_class', DB::raw('AVG(duration_ms) as avg_duration'), DB::raw('MAX(duration_ms) as max_duration'), DB::raw('count(*) as count'))
            ->where('created_at', '>', $since)
            ->whereNotNull('duration_ms')
            ->groupBy('job_class')
            ->orderByDesc('avg_duration')
            ->limit(5)
            ->get();

        // Top tags - use optimized database-native queries for large datasets
        $tagAggregator = new TagAggregator;
        $topTags = $tagAggregator->getTopTags($since, 10);

        // Recent batches (if batch table exists)
        $recentBatches = collect();
        if (Schema::hasTable('job_batches')) {
            $recentBatches = DB::table('job_batches')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
        }

        // Queue depths (real-time)
        try {
            $queueDepths = QueueDepthChecker::getQueueDepthWithMetadataAlways();
        } catch (\Throwable $e) {
            VantageLogger::warning('Failed to get queue depths', ['error' => $e->getMessage()]);
            // Always show at least one queue entry even on error
            $queueDepths = [
                'default' => [
                    'depth' => 0,
                    'driver' => config('queue.default', 'unknown'),
                    'connection' => config('queue.default', 'unknown'),
                    'status' => 'healthy',
                ],
            ];
        }

        // Performance statistics
        $performanceStats = [
            'avg_memory_start_bytes' => VantageJob::where('created_at', '>', $since)
                ->whereNotNull('memory_start_bytes')
                ->avg('memory_start_bytes'),
            'avg_memory_end_bytes' => VantageJob::where('created_at', '>', $since)
                ->whereNotNull('memory_end_bytes')
                ->avg('memory_end_bytes'),
            'avg_memory_peak_end_bytes' => VantageJob::where('created_at', '>', $since)
                ->whereNotNull('memory_peak_end_bytes')
                ->avg('memory_peak_end_bytes'),
            'max_memory_peak_end_bytes' => VantageJob::where('created_at', '>', $since)
                ->whereNotNull('memory_peak_end_bytes')
                ->max('memory_peak_end_bytes'),
            'avg_cpu_user_ms' => VantageJob::where('created_at', '>', $since)
                ->whereNotNull('cpu_user_ms')
                ->avg('cpu_user_ms'),
            'avg_cpu_sys_ms' => VantageJob::where('created_at', '>', $since)
                ->whereNotNull('cpu_sys_ms')
                ->avg('cpu_sys_ms'),
        ];

        // Calculate average total CPU (user + sys)
        $avgCpuTotal = null;
        if ($performanceStats['avg_cpu_user_ms'] !== null || $performanceStats['avg_cpu_sys_ms'] !== null) {
            $avgCpuTotal = ($performanceStats['avg_cpu_user_ms'] ?? 0) + ($performanceStats['avg_cpu_sys_ms'] ?? 0);
        }
        $performanceStats['avg_cpu_total_ms'] = $avgCpuTotal;

        // Top memory-consuming jobs
        $topMemoryJobs = VantageJob::select('job_class', DB::raw('AVG(memory_peak_end_bytes) as avg_memory_peak'), DB::raw('MAX(memory_peak_end_bytes) as max_memory_peak'), DB::raw('count(*) as count'))
            ->where('created_at', '>', $since)
            ->whereNotNull('memory_peak_end_bytes')
            ->groupBy('job_class')
            ->orderByDesc('avg_memory_peak')
            ->limit(5)
            ->get();

        // Top CPU-consuming jobs
        $topCpuJobs = VantageJob::select(
            'job_class',
            DB::raw('AVG(cpu_user_ms) as avg_cpu_user'),
            DB::raw('AVG(cpu_sys_ms) as avg_cpu_sys'),
            DB::raw('AVG(COALESCE(cpu_user_ms, 0) + COALESCE(cpu_sys_ms, 0)) as avg_cpu_total'),
            DB::raw('count(*) as count')
        )
            ->where('created_at', '>', $since)
            ->where(function ($query) {
                $query->whereNotNull('cpu_user_ms')
                    ->orWhereNotNull('cpu_sys_ms');
            })
            ->groupBy('job_class')
            ->orderByDesc('avg_cpu_total')
            ->limit(5)
            ->get();

        return view('vantage::dashboard', compact(
            'stats',
            'recentJobs',
            'jobsByStatus',
            'jobsByHour',
            'topFailingJobs',
            'topExceptions',
            'slowestJobs',
            'topTags',
            'recentBatches',
            'queueDepths',
            'period',
            'performanceStats',
            'topMemoryJobs',
            'topCpuJobs'
        ));
    }

    /**
     * Jobs list with filtering
     */
    public function jobs(Request $request)
    {
        // Exclude large columns (payload, stack) from jobs list to improve performance
        $query = VantageJob::select([
            'id', 'uuid', 'job_class', 'queue', 'connection', 'attempt',
            'status', 'started_at', 'finished_at', 'duration_ms',
            'exception_class', 'exception_message', 'job_tags', 'retried_from_id',
            'created_at', 'updated_at',
            // Performance telemetry fields
            'memory_start_bytes', 'memory_end_bytes', 'memory_peak_start_bytes',
            'memory_peak_end_bytes', 'memory_peak_delta_bytes',
            'cpu_user_ms', 'cpu_sys_ms',
            // Exclude: payload, stack (large text fields not needed for list view)
        ]);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('job_class')) {
            $query->where('job_class', 'like', '%'.$request->job_class.'%');
        }

        if ($request->filled('queue')) {
            $query->where('queue', $request->queue);
        }

        // Advanced tag filtering
        $tagsParam = $request->get('tags');

        // Check if tags parameter exists and is not empty
        if (! empty($tagsParam) && trim($tagsParam) !== '') {
            $tags = is_array($tagsParam) ? $tagsParam : explode(',', $tagsParam);
            $tags = array_map('trim', $tags);
            $tags = array_map('strtolower', $tags);
            $tags = array_filter($tags); // Remove empty tags

            if (! empty($tags)) {
                // Get database driver for database-specific JSON queries
                $connectionName = (new VantageJob)->getConnectionName();
                $connection = DB::connection($connectionName);
                $driver = $connection->getDriverName();

                if ($request->filled('tag_mode') && $request->tag_mode === 'any') {
                    // Jobs that have ANY of the specified tags
                    $query->where(function ($q) use ($tags, $driver) {
                        foreach ($tags as $tag) {
                            if ($driver === 'sqlite') {
                                // SQLite: json_each().value returns the actual string, not JSON-encoded
                                $q->orWhereRaw('EXISTS (
                                    SELECT 1 FROM json_each(vantage_jobs.job_tags) 
                                    WHERE json_each.value = ?
                                )', [$tag]);
                            } else {
                                // MySQL and PostgreSQL support whereJsonContains
                                $q->orWhereJsonContains('job_tags', $tag);
                            }
                        }
                    });
                } else {
                    // Jobs that have ALL of the specified tags (default)
                    foreach ($tags as $tag) {
                        if ($driver === 'sqlite') {
                            // SQLite: json_each().value returns the actual string, not JSON-encoded
                            // So we compare directly to the tag value
                            $query->whereRaw('EXISTS (
                                SELECT 1 FROM json_each(vantage_jobs.job_tags) 
                                WHERE json_each.value = ?
                            )', [$tag]);
                        } else {
                            // MySQL and PostgreSQL
                            $query->whereJsonContains('job_tags', $tag);
                        }
                    }
                }
            }
        } elseif ($request->filled('tag')) {
            // Single tag filter (backward compatibility)
            $tag = strtolower(trim($request->tag));
            $connectionName = (new VantageJob)->getConnectionName();
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();

            if ($driver === 'sqlite') {
                // SQLite: json_each().value returns the actual string, not JSON-encoded
                $query->whereRaw('EXISTS (
                    SELECT 1 FROM json_each(vantage_jobs.job_tags) 
                    WHERE json_each.value = ?
                )', [$tag]);
            } else {
                $query->whereJsonContains('job_tags', $tag);
            }
        }

        if ($request->filled('since')) {
            $query->where('created_at', '>', $request->since);
        }

        // Get jobs
        $jobs = $query->latest('id')
            ->paginate(50)
            ->withQueryString();

        // Get filter options
        // Only show queues that actually have jobs in vantage_jobs table
        // This ensures filtering by a queue will return results
        $queues = VantageJob::distinct()
            ->whereNotNull('queue')
            ->where('queue', '!=', '')
            ->pluck('queue')
            ->filter()
            ->sort()
            ->values();

        $jobClasses = VantageJob::distinct()->pluck('job_class')->map(fn ($c) => class_basename($c))->filter();

        // Get all available tags with counts - use optimized queries
        // Only look at last 30 days to limit data size
        $tagAggregator = new TagAggregator;
        $allTags = $tagAggregator->getTopTags(now()->subDays(30), 50);

        return view('vantage::jobs', compact('jobs', 'queues', 'jobClasses', 'allTags'));
    }

    /**
     * Job details
     */
    public function show($id)
    {
        $job = VantageJob::findOrFail($id);

        // Get retry chain
        $retryChain = [];
        if ($job->retried_from_id) {
            $retryChain = $this->getRetryChain($job);
        }

        return view('vantage::show', compact('job', 'retryChain'));
    }

    /**
     * Tags statistics
     */
    public function tags(Request $request)
    {
        $period = $request->get('period', '7d');
        $since = $this->getSinceDate($period);

        // Use optimized database-native queries for large datasets
        $tagAggregator = new TagAggregator;
        $tagStats = $tagAggregator->getTagStats($since);

        return view('vantage::tags', compact('tagStats', 'period'));
    }

    /**
     * Failed jobs
     */
    public function failed(Request $request)
    {
        // Exclude large columns (payload) from failed jobs list
        // Keep stack for debugging, but exclude payload
        $jobs = VantageJob::select([
            'id', 'uuid', 'job_class', 'queue', 'connection', 'attempt',
            'status', 'started_at', 'finished_at', 'duration_ms',
            'exception_class', 'exception_message', 'stack', 'job_tags',
            'retried_from_id', 'created_at', 'updated_at',
            // Exclude: payload (very large, not needed for failed list)
        ])
            ->where('status', 'failed')
            ->latest('id')
            ->paginate(50);

        return view('vantage::failed', compact('jobs'));
    }

    /**
     * Retry a job - simple and works for all cases
     */
    public function retry($id)
    {
        $run = VantageJob::findOrFail($id);

        if ($run->status !== 'failed') {
            return back()->with('error', 'Only failed jobs can be retried.');
        }

        $jobClass = $run->job_class;

        if (! class_exists($jobClass)) {
            return back()->with('error', "Job class {$jobClass} not found.");
        }

        try {
            // Validate it's a valid job class before attempting to restore
            if (! is_string($jobClass) ||
                (! is_subclass_of($jobClass, ShouldQueue::class) &&
                 ! is_subclass_of($jobClass, Job::class))) {
                return back()->with('error', "Invalid job class: {$jobClass}");
            }

            // Safely restore job from payload with security checks
            $job = app(JobRestorer::class)->restore($run, $jobClass);

            if (! $job) {
                return back()->with('error', 'Unable to restore job. Payload might be missing or corrupted.');
            }

            // Mark as retry
            $job->queueMonitorRetryOf = $run->id;

            // Dispatch
            dispatch($job)
                ->onQueue($run->queue ?? 'default')
                ->onConnection($run->connection ?? config('queue.default'));

            return back()->with('success', 'Job queued for retry!');

        } catch (\Throwable $e) {
            VantageLogger::error('Vantage: Retry failed', [
                'job_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Retry failed: '.$e->getMessage());
        }
    }

    /**
     * Get retry chain
     */
    protected function getRetryChain($job)
    {
        $chain = [];
        $current = $job->retriedFrom;

        while ($current) {
            $chain[] = $current;
            $current = $current->retriedFrom;
        }

        return array_reverse($chain);
    }

    /**
     * Get since date from period string
     */
    protected function getSinceDate($period)
    {
        return match ($period) {
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            'all' => now()->subYears(100), // All time
            default => now()->subDays(30),
        };
    }
}
