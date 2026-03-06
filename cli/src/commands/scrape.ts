import { Command } from 'commander';
import chalk from 'chalk';
import ora from 'ora';

import { resolveApiKey, resolveApiUrl } from '../lib/auth.js';
import { PipelinexClient } from '../lib/client.js';
import { formatCrawlResult, resolveFormat } from '../lib/output.js';
import type { CrawlResponse, GlobalOptions } from '../types.js';

export const scrapeCommand = new Command('scrape')
    .description('Scrape a single page and return its content')
    .argument('<url>', 'URL to scrape')
    .option('--timeout <ms>', 'Request timeout in ms', '30000')
    .option('--wait-for <selector>', 'Wait for CSS selector')
    .option('--include-tags <tags>', 'Only include specific HTML tags')
    .option('--exclude-tags <tags>', 'Exclude specific HTML tags')
    .action(async (url: string, options, command: Command) => {
        const globalOpts = command.parent?.opts() as GlobalOptions;
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
                  text: `Scraping ${url}...`,
                  stream: process.stderr,
              }).start()
            : null;

        try {
            const crawlOptions: Record<string, unknown> = {
                limit: 1,
            };
            if (options.waitFor)
                crawlOptions.wait_for = options.waitFor;
            if (options.includeTags)
                crawlOptions.include_tags =
                    options.includeTags.split(',');
            if (options.excludeTags)
                crawlOptions.exclude_tags =
                    options.excludeTags.split(',');

            let result = await client.crawl(url, crawlOptions);

            // Poll if async
            if (result.status === 'processing' && result.id) {
                const timeout = parseInt(options.timeout, 10);
                result = await pollForResult(
                    client,
                    result.id,
                    timeout,
                    spinner
                );
            }

            spinner?.stop();

            if (result.status === 'failed') {
                console.error(
                    chalk.red(
                        `Scrape failed: ${result.error?.message ?? 'Unknown error'}`
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
    spinner: ReturnType<typeof ora> | null
): Promise<CrawlResponse> {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, 2000));
        spinner?.start(`Waiting for result (${id})...`);
        const result = await client.getCrawl(id);
        if (result.status !== 'processing') return result;
    }

    throw new Error(
        `Timed out waiting for result after ${timeoutMs}ms`
    );
}
