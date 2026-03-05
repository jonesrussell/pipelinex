<?php

namespace App\Console\Commands;

use App\Models\CrawlJob;
use App\Models\UsageRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AggregateUsageCommand extends Command
{
    protected $signature = 'pipelinex:aggregate-usage {--date=}';

    protected $description = 'Aggregate daily crawl usage per tenant';

    public function handle(): void
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now()->subDay();

        $stats = CrawlJob::selectRaw("tenant_id, COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as succeeded, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->whereDate('created_at', $date)
            ->groupBy('tenant_id')
            ->get();

        foreach ($stats as $stat) {
            UsageRecord::updateOrCreate(
                ['tenant_id' => $stat->tenant_id, 'date' => $date->toDateString()],
                [
                    'crawls_count' => $stat->total,
                    'crawls_succeeded' => $stat->succeeded ?? 0,
                    'crawls_failed' => $stat->failed ?? 0,
                ],
            );
        }

        $this->info("Aggregated usage for {$stats->count()} tenants on {$date->toDateString()}");
    }
}
