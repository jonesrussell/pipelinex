<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'plan',
        'monthly_crawl_limit',
        'rate_limit_rpm',
        'webhook_url',
        'webhook_secret',
    ];

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function crawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    // ---------------------------------------------------------------
    // Methods
    // ---------------------------------------------------------------

    /**
     * Generate a new API key for this tenant.
     *
     * @return array{key: string, apiKey: ApiKey}
     */
    public function generateApiKey(string $name, string $environment = 'live'): array
    {
        $rawKey = 'px_'.$environment.'_'.Str::random(32);

        $apiKey = $this->apiKeys()->create([
            'key_hash' => hash('sha256', $rawKey),
            'key_prefix' => Str::substr($rawKey, 0, 20),
            'environment' => $environment,
            'name' => $name,
        ]);

        return [
            'key' => $rawKey,
            'apiKey' => $apiKey,
        ];
    }
}
