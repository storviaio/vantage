<?php

namespace Storvia\Vantage\Models;

use Storvia\Vantage\Database\Factories\VantageJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VantageJob extends Model
{
    use HasFactory;

    protected $table = 'vantage_jobs';

    protected static $unguarded = true;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): VantageJobFactory
    {
        return VantageJobFactory::new();
    }

    /**
     * Get the database connection for the model.
     *
     * @return string|null
     */
    public function getConnectionName()
    {
        return config('vantage.database_connection') ?? parent::getConnectionName();
    }

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'job_tags' => 'array',
        'payload' => 'array',
        // Telemetry numeric casts
        'duration_ms' => 'integer',
        'memory_start_bytes' => 'integer',
        'memory_end_bytes' => 'integer',
        'memory_peak_start_bytes' => 'integer',
        'memory_peak_end_bytes' => 'integer',
        'memory_peak_delta_bytes' => 'integer',
        'cpu_user_ms' => 'integer',
        'cpu_sys_ms' => 'integer',
    ];

    /**
     * Get the job that this was retried from
     */
    public function retriedFrom()
    {
        return $this->belongsTo(self::class, 'retried_from_id');
    }

    /**
     * Get all retry attempts of this job
     */
    public function retries()
    {
        return $this->hasMany(self::class, 'retried_from_id');
    }

    /**
     * Get payload as decoded array
     */
    public function getDecodedPayloadAttribute(): ?array
    {
        if (! $this->payload) {
            return null;
        }

        return json_decode($this->payload, true);
    }

    /**
     * Scope: Filter by tag
     */
    public function scopeWithTag($query, string $tag)
    {
        return $query->whereJsonContains('job_tags', strtolower($tag));
    }

    /**
     * Scope: Filter by any of multiple tags
     */
    public function scopeWithAnyTag($query, array $tags)
    {
        return $query->where(function ($q) use ($tags) {
            foreach ($tags as $tag) {
                $q->orWhereJsonContains('job_tags', strtolower($tag));
            }
        });
    }

    /**
     * Scope: Filter by all tags (must have all)
     */
    public function scopeWithAllTags($query, array $tags)
    {
        foreach ($tags as $tag) {
            $query->whereJsonContains('job_tags', strtolower($tag));
        }

        return $query;
    }

    /**
     * Scope: Exclude jobs with specific tag
     */
    public function scopeWithoutTag($query, string $tag)
    {
        return $query->where(function ($q) use ($tag) {
            $q->whereNull('job_tags')
                ->orWhereJsonDoesntContain('job_tags', strtolower($tag));
        });
    }

    /**
     * Scope: Filter by job class
     */
    public function scopeOfClass($query, string $class)
    {
        return $query->where('job_class', $class);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Failed jobs only
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: Successful jobs only
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'processed');
    }

    /**
     * Scope: Processing jobs only
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Check if job has specific tag
     */
    public function hasTag(string $tag): bool
    {
        if (! $this->job_tags) {
            return false;
        }

        return in_array(strtolower($tag), array_map('strtolower', $this->job_tags));
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        if (! $this->duration_ms) {
            return 'N/A';
        }

        if ($this->duration_ms < 1000) {
            return $this->duration_ms.'ms';
        }

        return round($this->duration_ms / 1000, 2).'s';
    }

    /**
     * Format bytes to human-readable format (bytes, MB, GB)
     */
    protected function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'N/A';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    /**
     * Format milliseconds to human-readable format (ms, seconds)
     */
    protected function formatMilliseconds(?int $ms): string
    {
        if ($ms === null) {
            return 'N/A';
        }

        if ($ms < 1000) {
            return $ms.'ms';
        }

        return round($ms / 1000, 2).'s';
    }

    /**
     * Get formatted memory start
     */
    public function getFormattedMemoryStartAttribute(): string
    {
        return $this->formatBytes($this->memory_start_bytes);
    }

    /**
     * Get formatted memory end
     */
    public function getFormattedMemoryEndAttribute(): string
    {
        return $this->formatBytes($this->memory_end_bytes);
    }

    /**
     * Get formatted memory peak start
     */
    public function getFormattedMemoryPeakStartAttribute(): string
    {
        return $this->formatBytes($this->memory_peak_start_bytes);
    }

    /**
     * Get formatted memory peak end
     */
    public function getFormattedMemoryPeakEndAttribute(): string
    {
        return $this->formatBytes($this->memory_peak_end_bytes);
    }

    /**
     * Get formatted memory peak delta (with +/- sign)
     */
    public function getFormattedMemoryPeakDeltaAttribute(): string
    {
        if ($this->memory_peak_delta_bytes === null) {
            return 'N/A';
        }

        $formatted = $this->formatBytes(abs($this->memory_peak_delta_bytes));
        $sign = $this->memory_peak_delta_bytes >= 0 ? '+' : '-';

        return $sign.$formatted;
    }

    /**
     * Get formatted CPU user time
     */
    public function getFormattedCpuUserAttribute(): string
    {
        return $this->formatMilliseconds($this->cpu_user_ms);
    }

    /**
     * Get formatted CPU system time
     */
    public function getFormattedCpuSysAttribute(): string
    {
        return $this->formatMilliseconds($this->cpu_sys_ms);
    }

    /**
     * Get total CPU time (user + sys)
     */
    public function getCpuTotalMsAttribute(): ?int
    {
        if ($this->cpu_user_ms === null && $this->cpu_sys_ms === null) {
            return null;
        }

        return ($this->cpu_user_ms ?? 0) + ($this->cpu_sys_ms ?? 0);
    }

    /**
     * Get formatted total CPU time
     */
    public function getFormattedCpuTotalAttribute(): string
    {
        return $this->formatMilliseconds($this->cpu_total_ms);
    }

    /**
     * Calculate memory delta (end - start)
     */
    public function getMemoryDeltaBytesAttribute(): ?int
    {
        if ($this->memory_start_bytes === null || $this->memory_end_bytes === null) {
            return null;
        }

        return $this->memory_end_bytes - $this->memory_start_bytes;
    }

    /**
     * Get formatted memory delta (with +/- sign)
     */
    public function getFormattedMemoryDeltaAttribute(): string
    {
        $delta = $this->memory_delta_bytes;

        if ($delta === null) {
            return 'N/A';
        }

        $formatted = $this->formatBytes(abs($delta));
        $sign = $delta >= 0 ? '+' : '-';

        return $sign.$formatted;
    }
}
