<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlResult extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'crawl_job_id',
        'title',
        'author',
        'published_date',
        'body',
        'word_count',
        'quality_score',
        'topics',
        'og',
        'links',
        'images',
        'raw_html',
        'content_type',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'published_date' => 'datetime',
            'topics' => 'array',
            'og' => 'array',
            'links' => 'array',
            'images' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------

    protected static function booted(): void
    {
        static::creating(function (CrawlResult $result) {
            if (is_null($result->created_at)) {
                $result->created_at = now();
            }
        });
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }
}
