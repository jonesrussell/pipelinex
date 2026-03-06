import type { CrawlResponse, UsageResponse } from '../types.js';

export type OutputFormat = 'markdown' | 'json' | 'html';

export function detectFormat(
    explicit?: string
): OutputFormat | undefined {
    if (
        explicit === 'json' ||
        explicit === 'markdown' ||
        explicit === 'html'
    ) {
        return explicit;
    }
    return undefined;
}

export function resolveFormat(explicit?: string): OutputFormat {
    const detected = detectFormat(explicit);
    if (detected) return detected;
    return process.stdout.isTTY ? 'markdown' : 'json';
}

export function formatCrawlResult(
    result: CrawlResponse,
    format: OutputFormat
): string {
    switch (format) {
        case 'json':
            return JSON.stringify(result, null, 2);
        case 'html':
            return result.data?.body ?? '';
        case 'markdown':
            return formatAsMarkdown(result);
    }
}

function formatAsMarkdown(result: CrawlResponse): string {
    const lines: string[] = [];
    const data = result.data;

    if (data?.title) {
        lines.push(`# ${data.title}`);
        lines.push('');
    }

    const metaParts: string[] = [];
    if (data?.author) metaParts.push(`**Author:** ${data.author}`);
    if (data?.published_date)
        metaParts.push(
            `**Published:** ${new Date(data.published_date).toLocaleDateString()}`
        );
    if (result.url) metaParts.push(`**Source:** ${result.url}`);

    if (metaParts.length > 0) {
        lines.push(metaParts.join(' | '));
        lines.push('');
    }

    if (data?.body) {
        lines.push(data.body);
        lines.push('');
    }

    if (result.meta) {
        lines.push('---');
        lines.push(
            `*Crawled in ${result.meta.duration_ms}ms | ${data?.word_count ?? 0} words*`
        );
    }

    return lines.join('\n');
}

export function formatUsage(
    usage: UsageResponse,
    format: OutputFormat
): string {
    if (format === 'json') {
        return JSON.stringify(usage, null, 2);
    }

    const u = usage.usage;
    const pct = Math.round((u.crawls_used / u.crawls_limit) * 100);
    const lines = [
        `Plan: ${usage.plan}`,
        `Usage: ${u.crawls_used} / ${u.crawls_limit} crawls (${pct}%)`,
        `Rate limit: ${usage.rate_limit.requests_per_minute} req/min`,
        `Period: ${new Date(u.period_start).toLocaleDateString()} - ${new Date(u.period_end).toLocaleDateString()}`,
    ];
    return lines.join('\n');
}
