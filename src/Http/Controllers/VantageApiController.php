<?php

namespace Storvia\Vantage\Http\Controllers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\JsonResponse;
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

class VantageApiController extends Controller
{
    /**
     * Root of the JSON API (helps verify routing and auth in demos).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'message' => 'Vantage JSON API',
                'endpoints' => [
                    'stats' => route('vantage.api.stats', [], false),
                    'jobs' => route('vantage.api.jobs', [], false),
                    'tags' => route('vantage.api.tags', [], false),
                    'failed' => route('vantage.api.failed', [], false),
                    'queue_depths' => route('vantage.api.queue-depths', [], false),
                    'batches' => route('vantage.api.batches', [], false),
                ],
            ],
        ]);
    }

    /**
     * Dashboard-level statistics.
     *
     * GET /stats?period=30d
     */
    public function stats(Request $request): JsonResponse
    {
        $period = $request->get('period', '30d');
        $since = $this->getSinceDate($period);

        $stats = [
            'total' => VantageJob::where('created_at', '>', $since)->count(),
            'processed' => VantageJob::where('created_at', '>', $since)->where('status', 'processed')->count(),
            'failed' => VantageJob::where('created_at', '>', $since)->where('status', 'failed')->count(),
            'processing' => VantageJob::where('status', 'processing')
                ->where('created_at', '>', now()->subHour())
                ->count(),
            'avg_duration_ms' => VantageJob::where('created_at', '>', $since)
                ->whereNotNull('duration_ms')
                ->avg('duration_ms'),
        ];

        $completedJobs = $stats['processed'] + $stats['failed'];
        $stats['success_rate'] = $completedJobs > 0
            ? round(($stats['processed'] / $completedJobs) * 100, 1)
            : 0;

        $connectionName = (new VantageJob)->getConnectionName();
        $driver = DB::connection($connectionName)->getDriverName();

        $dateFormat = match ($driver) {
            'mysql' => DB::raw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour'),
            'sqlite' => DB::raw('strftime("%Y-%m-%d %H:00:00", created_at) as hour'),
            'pgsql' => DB::raw("to_char(created_at, 'YYYY-MM-DD HH24:00:00') as hour"),
            default => DB::raw('DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour'),
        };

        $jobsByHour = VantageJob::select(
            $dateFormat,
            DB::raw('count(*) as count'),
            DB::raw("sum(case when status = 'failed' then 1 else 0 end) as failed_count")
        )
            ->where('created_at', '>', $since)
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $topFailingJobs = VantageJob::select('job_class', DB::raw('count(*) as failure_count'))
            ->where('created_at', '>', $since)
            ->where('status', 'failed')
            ->groupBy('job_class')
            ->orderByDesc('failure_count')
            ->limit(5)
            ->get();

        $topExceptions = VantageJob::select('exception_class', DB::raw('count(*) as count'))
            ->where('created_at', '>', $since)
            ->whereNotNull('exception_class')
            ->groupBy('exception_class')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        $slowestJobs = VantageJob::select(
            'job_class',
            DB::raw('AVG(duration_ms) as avg_duration'),
            DB::raw('MAX(duration_ms) as max_duration'),
            DB::raw('count(*) as count')
        )
            ->where('created_at', '>', $since)
            ->whereNotNull('duration_ms')
            ->groupBy('job_class')
            ->orderByDesc('avg_duration')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'stats' => $stats,
                'jobs_by_hour' => $jobsByHour,
                'top_failing_jobs' => $topFailingJobs,
                'top_exceptions' => $topExceptions,
                'slowest_jobs' => $slowestJobs,
            ],
            'meta' => [
                'period' => $period,
                'since' => $since->toIso8601String(),
            ],
        ]);
    }

    /**
     * Paginated job listing with the same filters as the web controller.
     *
     * GET /jobs?status=failed&queue=default&job_class=Send&tags=user,billing&tag_mode=any&since=...&page=1&per_page=50
     */
    public function jobs(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 100);

        $query = VantageJob::select([
            'id', 'uuid', 'job_class', 'queue', 'connection', 'attempt',
            'status', 'started_at', 'finished_at', 'duration_ms',
            'exception_class', 'exception_message', 'job_tags', 'retried_from_id',
            'memory_start_bytes', 'memory_end_bytes', 'memory_peak_start_bytes',
            'memory_peak_end_bytes', 'memory_peak_delta_bytes',
            'cpu_user_ms', 'cpu_sys_ms',
            'created_at', 'updated_at',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('job_class')) {
            $query->where('job_class', 'like', '%'.$request->job_class.'%');
        }

        if ($request->filled('queue')) {
            $query->where('queue', $request->queue);
        }

        $this->applyTagFilters($query, $request);

        if ($request->filled('since')) {
            $query->where('created_at', '>', $request->since);
        }

        $paginator = $query->latest('id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Single job detail.
     *
     * GET /jobs/{id}
     */
    public function show($id): JsonResponse
    {
        $job = VantageJob::findOrFail($id);

        $retryChain = [];
        $current = $job->retriedFrom;
        while ($current) {
            $retryChain[] = $current;
            $current = $current->retriedFrom;
        }

        return response()->json([
            'data' => $job,
            'meta' => [
                'retry_chain' => array_reverse($retryChain),
            ],
        ]);
    }

    /**
     * Tag statistics.
     *
     * GET /tags?period=7d
     */
    public function tags(Request $request): JsonResponse
    {
        $period = $request->get('period', '7d');
        $since = $this->getSinceDate($period);

        $tagAggregator = new TagAggregator;
        $tagStats = $tagAggregator->getTagStats($since);

        return response()->json([
            'data' => $tagStats,
            'meta' => [
                'period' => $period,
                'since' => $since->toIso8601String(),
            ],
        ]);
    }

    /**
     * Failed jobs (paginated).
     *
     * GET /failed?page=1&per_page=50
     */
    public function failed(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 100);

        $paginator = VantageJob::select([
            'id', 'uuid', 'job_class', 'queue', 'connection', 'attempt',
            'status', 'started_at', 'finished_at', 'duration_ms',
            'exception_class', 'exception_message', 'stack', 'job_tags',
            'retried_from_id', 'created_at', 'updated_at',
        ])
            ->where('status', 'failed')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Retry a failed job.
     *
     * POST /jobs/{id}/retry
     */
    public function retry($id): JsonResponse
    {
        $run = VantageJob::findOrFail($id);

        if ($run->status !== 'failed') {
            return response()->json(['error' => 'Only failed jobs can be retried.'], 422);
        }

        $jobClass = $run->job_class;

        if (! class_exists($jobClass)) {
            return response()->json(['error' => "Job class {$jobClass} not found."], 422);
        }

        try {
            if (! is_string($jobClass) ||
                (! is_subclass_of($jobClass, ShouldQueue::class) &&
                 ! is_subclass_of($jobClass, Job::class))) {
                return response()->json(['error' => "Invalid job class: {$jobClass}"], 422);
            }

            $job = app(JobRestorer::class)->restore($run, $jobClass);

            if (! $job) {
                return response()->json([
                    'error' => 'Unable to restore job. Payload might be missing or corrupted.',
                ], 422);
            }

            $job->queueMonitorRetryOf = $run->id;

            dispatch($job)
                ->onQueue($run->queue ?? 'default')
                ->onConnection($run->connection ?? config('queue.default'));

            return response()->json(['message' => 'Job queued for retry.']);
        } catch (\Throwable $e) {
            VantageLogger::error('Vantage API: Retry failed', [
                'job_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Retry failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Real-time queue depths.
     *
     * GET /queue-depths
     */
    public function queueDepths(): JsonResponse
    {
        try {
            $depths = QueueDepthChecker::getQueueDepthWithMetadataAlways();
        } catch (\Throwable $e) {
            VantageLogger::warning('Failed to get queue depths', ['error' => $e->getMessage()]);
            $depths = [];
        }

        return response()->json(['data' => $depths]);
    }

    /**
     * Recent batches (if the Laravel job_batches table exists).
     *
     * GET /batches?limit=10
     */
    public function batches(Request $request): JsonResponse
    {
        if (! Schema::hasTable('job_batches')) {
            return response()->json(['data' => [], 'meta' => ['available' => false]]);
        }

        $limit = min((int) $request->get('limit', 10), 100);

        $batches = DB::table('job_batches')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $batches]);
    }

    // ------------------------------------------------------------------
    // Helpers (shared with the web controller)
    // ------------------------------------------------------------------

    protected function getSinceDate(string $period)
    {
        return match ($period) {
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            'all' => now()->subYears(100),
            default => now()->subDays(30),
        };
    }

    protected function applyTagFilters($query, Request $request): void
    {
        $tagsParam = $request->get('tags');

        if (! empty($tagsParam) && trim($tagsParam) !== '') {
            $tags = is_array($tagsParam) ? $tagsParam : explode(',', $tagsParam);
            $tags = array_filter(array_map(fn ($t) => strtolower(trim($t)), $tags));

            if (! empty($tags)) {
                $connectionName = (new VantageJob)->getConnectionName();
                $driver = DB::connection($connectionName)->getDriverName();

                if ($request->filled('tag_mode') && $request->tag_mode === 'any') {
                    $query->where(function ($q) use ($tags, $driver) {
                        foreach ($tags as $tag) {
                            if ($driver === 'sqlite') {
                                $q->orWhereRaw('EXISTS (SELECT 1 FROM json_each(vantage_jobs.job_tags) WHERE json_each.value = ?)', [$tag]);
                            } else {
                                $q->orWhereJsonContains('job_tags', $tag);
                            }
                        }
                    });
                } else {
                    foreach ($tags as $tag) {
                        if ($driver === 'sqlite') {
                            $query->whereRaw('EXISTS (SELECT 1 FROM json_each(vantage_jobs.job_tags) WHERE json_each.value = ?)', [$tag]);
                        } else {
                            $query->whereJsonContains('job_tags', $tag);
                        }
                    }
                }
            }
        } elseif ($request->filled('tag')) {
            $tag = strtolower(trim($request->tag));
            $connectionName = (new VantageJob)->getConnectionName();
            $driver = DB::connection($connectionName)->getDriverName();

            if ($driver === 'sqlite') {
                $query->whereRaw('EXISTS (SELECT 1 FROM json_each(vantage_jobs.job_tags) WHERE json_each.value = ?)', [$tag]);
            } else {
                $query->whereJsonContains('job_tags', $tag);
            }
        }
    }
}
