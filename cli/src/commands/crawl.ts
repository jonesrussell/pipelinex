import chalk from 'chalk';
import { Command } from 'commander';
import ora from 'ora';

import {
    resolveApiKey,
    resolveApiUrl,
    resolveMode,
} from '../lib/auth.js';
import { PipelinexClient } from '../lib/client.js';
import { formatCrawlResult, resolveFormat } from '../lib/output.js';
import type { CrawlResponse, GlobalOptions } from '../types.js';

export const crawlCommand = new Command('crawl')
    .description(
        'Crawl a website and return content from multiple pages'
    )
    .argument('<url>', 'URL to crawl')
    .option('--limit <n>', 'Maximum pages to crawl')
    .option('--depth <n>', 'Maximum crawl depth')
    .option(
        '--poll-interval <ms>',
        'Polling interval in milliseconds',
        '2000'
    )
    .option('--timeout <ms>', 'Request timeout in ms', '120000')
    .action(async (url: string, options, command: Command) => {
        const globalOpts = command.parent?.opts() as GlobalOptions;
        const mode = resolveMode();
        if (mode === 'direct') {
            console.error(
                chalk.yellow(
                    'Multi-page crawl is not supported in direct mode. Use "pipelinex scrape" for single pages, or configure a PipelineX API key with "pipelinex auth".'
                )
            );
            process.exit(1);
        }

        const apiKey = resolveApiKey(globalOpts?.apiKey);

        if (!apiKey) {
            console.error(
                chalk.red(
                    'No API key found. Run "pipelinex auth" or set PIPELINEX_API_KEY.'
                )
            );
            process.exit(1);
        }

        const apiUrl = resolveApiUrl(globalOpts?.apiUrl);
        const client = new PipelinexClient(apiUrl, apiKey);
        const format = resolveFormat(globalOpts?.format);

        const spinner = process.stderr.isTTY
            ? ora({
                  text: `Crawling ${url}...`,
                  stream: process.stderr,
              }).start()
            : null;

        try {
            const crawlOptions: Record<string, unknown> = {};
            if (options.limit)
                crawlOptions.limit = parseInt(options.limit, 10);
            if (options.depth)
                crawlOptions.depth = parseInt(options.depth, 10);

            let result = await client.crawl(url, crawlOptions);

            if (result.status === 'processing' && result.id) {
                const timeout = parseInt(options.timeout, 10);
                const interval = parseInt(
                    options.pollInterval,
                    10
                );
                result = await pollForResult(
                    client,
                    result.id,
                    timeout,
                    interval,
                    spinner
                );
            }

            spinner?.stop();

            if (result.status === 'failed') {
                console.error(
                    chalk.red(
                        `Crawl failed: ${result.error?.message ?? 'Unknown error'}`
                    )
                );
                process.exit(1);
            }

            console.log(formatCrawlResult(result, format));
        } catch (error) {
            spinner?.stop();
            console.error(
                chalk.red(
                    `Error: ${error instanceof Error ? error.message : String(error)}`
                )
            );
            process.exit(1);
        }
    });

async function pollForResult(
    client: PipelinexClient,
    id: string,
    timeoutMs: number,
    intervalMs: number,
    spinner: ReturnType<typeof ora> | null
): Promise<CrawlResponse> {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, intervalMs));
        spinner?.start(`Crawling in progress (${id})...`);
        const result = await client.getCrawl(id);
        if (result.status !== 'processing') return result;
    }

    throw new Error(
        `Timed out waiting for crawl after ${timeoutMs}ms`
    );
}
