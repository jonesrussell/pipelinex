<?php

namespace App\Console\Commands;

use App\Models\CrawlJob;
use Illuminate\Console\Command;

class CleanupExpiredResultsCommand extends Command
{
    protected $signature = 'pipelinex:cleanup-expired';

    protected $description = 'Delete expired crawl jobs and results';

    public function handle(): void
    {
        $deleted = CrawlJob::where('expires_at', '<', now())->delete();
        $this->info("Deleted {$deleted} expired crawl jobs");
    }
}
