<?php

namespace App\Jobs;

use App\Models\CrawlJob;
use App\Models\CrawlResult;
use App\Services\NorthCloudClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessCrawlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 45;

    public function __construct(
        public string $crawlJobId,
    ) {}

    public function handle(NorthCloudClient $nc): void
    {
        $crawlJob = CrawlJob::findOrFail($this->crawlJobId);
        $options = $crawlJob->options ?? [];
        $start = now();

        try {
            // Step 1: Fetch URL via North Cloud crawler
            $fetchResult = $nc->fetch(
                $crawlJob->url,
                $options['timeout'] ?? 15,
            );

            // Step 2: Extract structured data via North Cloud classifier
            $extractResult = $nc->extract(
                $fetchResult['html'],
                $crawlJob->url,
                $fetchResult['title'] ?? null,
            );

            $durationMs = (int) $start->diffInMilliseconds(now());

            // Step 3: Store result
            $crawlJob->markCompleted(
                $fetchResult['status_code'],
                $durationMs,
                $fetchResult['final_url'] ?? null,
            );

            CrawlResult::create([
                'crawl_job_id' => $crawlJob->id,
                'title' => $extractResult['title'] ?? $fetchResult['title'] ?? null,
                'author' => $extractResult['author'] ?? $fetchResult['author'] ?? null,
                'published_date' => $extractResult['published_date'] ?? null,
                'body' => $extractResult['body'] ?? '',
                'word_count' => $extractResult['word_count'] ?? 0,
                'quality_score' => $extractResult['quality_score'] ?? 0,
                'topics' => $extractResult['topics'] ?? [],
                'og' => $fetchResult['og'] ?? [],
                'links' => [],
                'images' => [],
                'raw_html' => ($options['include_html'] ?? false) ? $fetchResult['html'] : null,
                'content_type' => $fetchResult['content_type'] ?? 'text/html',
            ]);

        } catch (\Throwable $e) {
            $crawlJob->markFailed(
                'processing_error',
                $e->getMessage(),
            );

            throw $e;
        }
    }
}
