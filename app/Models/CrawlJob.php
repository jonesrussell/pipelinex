<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CrawlJob extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'api_key_id',
        'url',
        'final_url',
        'status',
        'options',
        'error_code',
        'error_message',
        'http_status_code',
        'duration_ms',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------

    protected static function booted(): void
    {
        static::creating(function (CrawlJob $job) {
            if (empty($job->id)) {
                $job->id = 'crawl_'.Str::random(12);
            }

            if (is_null($job->expires_at)) {
                $job->expires_at = now()->addDays(30);
            }
        });
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function crawlResult(): HasOne
    {
        return $this->hasOne(CrawlResult::class);
    }

    // ---------------------------------------------------------------
    // Methods
    // ---------------------------------------------------------------

    /**
     * Mark this crawl job as completed.
     */
    public function markCompleted(int $statusCode, int $durationMs, ?string $finalUrl = null): void
    {
        $this->update([
            'status' => 'completed',
            'http_status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'final_url' => $finalUrl,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark this crawl job as failed.
     */
    public function markFailed(string $errorCode, string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}
