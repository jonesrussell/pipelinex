# Direct North Cloud Integration — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add direct north-cloud mode to the CLI so it can call the crawler and classifier APIs at northcloud.one without going through the PipelineX Laravel API.

**Architecture:** Auto-detect mode from config — if `northCloudUrl`+`northCloudSecret` are set, use direct mode (fetch HTML via crawler, then classify via classifier). If `apiKey` is set, use existing PipelineX API mode. The scrape command is the only one that works in direct mode (crawl/usage require the PipelineX API). A new `NorthCloudDirectClient` handles the two-step fetch+extract flow and maps the response to the existing `CrawlResponse` type.

**Tech Stack:** TypeScript, Commander.js, node fetch (already used)

---

### Task 1: Extend Types and Config

**Files:**
- Modify: `cli/src/types.ts`
- Modify: `cli/src/lib/auth.ts`
- Modify: `cli/tests/lib/auth.test.ts`

**Step 1: Add north-cloud fields to PipelinexConfig**

In `cli/src/types.ts`, add fields to the existing `PipelinexConfig` interface:

```typescript
export interface PipelinexConfig {
    apiKey?: string;
    apiUrl?: string;
    northCloudUrl?: string;
    northCloudSecret?: string;
}
```

No other type changes needed — the existing `CrawlResponse`/`CrawlData`/`CrawlMeta` types are reused.

**Step 2: Add mode resolution to auth.ts**

In `cli/src/lib/auth.ts`, add a `resolveMode` function and north-cloud resolvers:

```typescript
import { readConfig } from './config.js';

export type ClientMode = 'direct' | 'api';

export function resolveMode(): ClientMode {
    const config = readConfig();
    if (config.northCloudUrl && config.northCloudSecret) return 'direct';
    if (config.apiKey || process.env.PIPELINEX_API_KEY) return 'api';
    return 'direct'; // default to direct if nothing configured (will fail with helpful error)
}

export function resolveApiKey(flagValue?: string): string | undefined {
    if (flagValue) return flagValue;
    if (process.env.PIPELINEX_API_KEY) return process.env.PIPELINEX_API_KEY;
    const config = readConfig();
    return config.apiKey;
}

export function resolveApiUrl(flagValue?: string): string {
    if (flagValue) return flagValue;
    if (process.env.PIPELINEX_API_URL) return process.env.PIPELINEX_API_URL;
    const config = readConfig();
    return config.apiUrl ?? 'https://pipelinex.dev/api/v1';
}

export function resolveNorthCloudUrl(): string {
    if (process.env.NORTH_CLOUD_URL) return process.env.NORTH_CLOUD_URL;
    const config = readConfig();
    return config.northCloudUrl ?? 'https://northcloud.one';
}

export function resolveNorthCloudSecret(): string | undefined {
    if (process.env.NORTH_CLOUD_SECRET) return process.env.NORTH_CLOUD_SECRET;
    const config = readConfig();
    return config.northCloudSecret;
}
```

**Step 3: Write test for resolveMode**

Add to `cli/tests/lib/auth.test.ts` a new describe block:

```typescript
describe('resolveMode', () => {
    it('returns direct when northCloudUrl and northCloudSecret are configured', () => {
        const { readConfig } = vi.mocked(
            await import('../../src/lib/config.js')
        );
        readConfig.mockReturnValueOnce({
            northCloudUrl: 'https://northcloud.one',
            northCloudSecret: 'secret123',
        });
        const { resolveMode } = await import('../../src/lib/auth.js');
        // Need to re-import to use unmocked version
        expect(resolveMode()).toBe('direct');
    });

    it('returns api when only apiKey is configured', () => {
        const { readConfig } = vi.mocked(
            await import('../../src/lib/config.js')
        );
        readConfig.mockReturnValueOnce({ apiKey: 'px_test_key' });
        const { resolveMode } = await import('../../src/lib/auth.js');
        expect(resolveMode()).toBe('api');
    });
});
```

Note: The existing mock at the top of the file mocks readConfig globally. The implementer should integrate these tests properly with the existing mock setup — use `mockReturnValueOnce` to override per-test.

**Step 4: Run tests**

Run: `cd cli && npx vitest run`
Expected: All pass

**Step 5: Commit**

```bash
git add cli/src/types.ts cli/src/lib/auth.ts cli/tests/lib/auth.test.ts
git commit -m "feat(cli): add north-cloud config fields and mode resolution"
```

---

### Task 2: NorthCloudDirectClient

**Files:**
- Create: `cli/src/lib/north-cloud-client.ts`
- Create: `cli/tests/lib/north-cloud-client.test.ts`

**Step 1: Write failing tests**

Create `cli/tests/lib/north-cloud-client.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { NorthCloudDirectClient } from '../../src/lib/north-cloud-client.js';

const mockFetch = vi.fn();
global.fetch = mockFetch;

describe('NorthCloudDirectClient', () => {
    let client: NorthCloudDirectClient;

    beforeEach(() => {
        client = new NorthCloudDirectClient(
            'https://northcloud.one',
            'test-secret'
        );
        mockFetch.mockReset();
    });

    it('scrapes a URL by calling fetch then extract', async () => {
        // First call: crawler /fetch
        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () =>
                Promise.resolve({
                    url: 'https://example.com',
                    final_url: 'https://example.com/',
                    status_code: 200,
                    content_type: 'text/html',
                    html: '<html><body>Hello</body></html>',
                    title: 'Example',
                    body: 'Hello',
                    author: '',
                    description: '',
                    og: null,
                    duration_ms: 150,
                }),
        });

        // Second call: classifier /extract
        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () =>
                Promise.resolve({
                    title: 'Example',
                    author: '',
                    published_date: null,
                    body: 'Hello world content',
                    word_count: 3,
                    quality_score: 80,
                    topics: ['general'],
                    topic_scores: { general: 0.9 },
                    content_type: 'article',
                    og: {},
                }),
        });

        const result = await client.scrape('https://example.com');

        // Verify fetch call
        expect(mockFetch).toHaveBeenNthCalledWith(
            1,
            'https://northcloud.one/api/crawler/internal/v1/fetch',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({
                    'X-Internal-Secret': 'test-secret',
                }),
            })
        );

        // Verify extract call
        expect(mockFetch).toHaveBeenNthCalledWith(
            2,
            'https://northcloud.one/api/classifier/internal/v1/extract',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({
                    'X-Internal-Secret': 'test-secret',
                }),
            })
        );

        // Verify response mapped to CrawlResponse
        expect(result.status).toBe('completed');
        expect(result.url).toBe('https://example.com');
        expect(result.data?.title).toBe('Example');
        expect(result.data?.body).toBe('Hello world content');
        expect(result.data?.word_count).toBe(3);
        expect(result.meta?.status_code).toBe(200);
    });

    it('returns failed status on fetch error', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: false,
            status: 502,
            json: () =>
                Promise.resolve({
                    error: 'fetch failed: connection refused',
                }),
        });

        const result = await client.scrape('https://bad-url.test');
        expect(result.status).toBe('failed');
        expect(result.error?.message).toContain('fetch failed');
    });

    it('returns failed status on extract error', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () =>
                Promise.resolve({
                    url: 'https://example.com',
                    final_url: 'https://example.com',
                    status_code: 200,
                    content_type: 'text/html',
                    html: '<html></html>',
                    title: '',
                    body: '',
                    author: '',
                    description: '',
                    og: null,
                    duration_ms: 100,
                }),
        });

        mockFetch.mockResolvedValueOnce({
            ok: false,
            status: 500,
            json: () =>
                Promise.resolve({
                    error: 'classification failed',
                }),
        });

        const result = await client.scrape('https://example.com');
        expect(result.status).toBe('failed');
        expect(result.error?.message).toContain('classification failed');
    });
});
```

**Step 2: Run tests to verify they fail**

Run: `cd cli && npx vitest run tests/lib/north-cloud-client.test.ts`
Expected: FAIL (module not found)

**Step 3: Implement NorthCloudDirectClient**

Create `cli/src/lib/north-cloud-client.ts`:

```typescript
import type { CrawlResponse } from '../types.js';

interface FetchResponse {
    url: string;
    final_url: string;
    status_code: number;
    content_type: string;
    html: string;
    title: string;
    body: string;
    author: string;
    description: string;
    og: Record<string, string> | null;
    duration_ms: number;
}

interface ExtractResponse {
    title: string;
    author: string;
    published_date: string | null;
    body: string;
    word_count: number;
    quality_score: number;
    topics: string[];
    content_type: string;
    og: Record<string, string>;
}

export class NorthCloudDirectClient {
    constructor(
        private baseUrl: string,
        private secret: string
    ) {}

    async scrape(url: string, timeout = 15): Promise<CrawlResponse> {
        // Step 1: Fetch the page via crawler
        const fetchResult = await this.fetchPage(url, timeout);
        if (fetchResult.error) {
            return {
                id: '',
                status: 'failed',
                url,
                error: fetchResult.error,
            };
        }

        // Step 2: Extract structured content via classifier
        const extractResult = await this.extractContent(
            fetchResult.data!.html,
            fetchResult.data!.url,
            fetchResult.data!.title
        );
        if (extractResult.error) {
            return {
                id: '',
                status: 'failed',
                url,
                error: extractResult.error,
            };
        }

        const fetched = fetchResult.data!;
        const extracted = extractResult.data!;

        return {
            id: `direct_${Date.now()}`,
            status: 'completed',
            url,
            final_url: fetched.final_url,
            data: {
                title: extracted.title || fetched.title,
                author: extracted.author || null,
                published_date: extracted.published_date,
                body: extracted.body,
                word_count: extracted.word_count,
                quality_score: extracted.quality_score,
                topics: extracted.topics,
                og: extracted.og ?? fetched.og ?? {},
                links: [],
                images: [],
            },
            meta: {
                status_code: fetched.status_code,
                content_type: fetched.content_type,
                crawled_at: new Date().toISOString(),
                duration_ms: fetched.duration_ms,
            },
        };
    }

    private async fetchPage(
        url: string,
        timeout: number
    ): Promise<{
        data?: FetchResponse;
        error?: { code: string; message: string };
    }> {
        try {
            const response = await fetch(
                `${this.baseUrl}/api/crawler/internal/v1/fetch`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Internal-Secret': this.secret,
                    },
                    body: JSON.stringify({ url, timeout }),
                }
            );

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                return {
                    error: {
                        code: 'fetch_failed',
                        message:
                            (body as Record<string, string>).error ??
                            `Crawler returned ${response.status}`,
                    },
                };
            }

            return { data: (await response.json()) as FetchResponse };
        } catch (err) {
            return {
                error: {
                    code: 'network_error',
                    message:
                        err instanceof Error
                            ? err.message
                            : String(err),
                },
            };
        }
    }

    private async extractContent(
        html: string,
        url: string,
        title: string
    ): Promise<{
        data?: ExtractResponse;
        error?: { code: string; message: string };
    }> {
        try {
            const response = await fetch(
                `${this.baseUrl}/api/classifier/internal/v1/extract`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Internal-Secret': this.secret,
                    },
                    body: JSON.stringify({
                        html,
                        url,
                        title,
                        source_name: 'pipelinex',
                    }),
                }
            );

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                return {
                    error: {
                        code: 'extract_failed',
                        message:
                            (body as Record<string, string>).error ??
                            `Classifier returned ${response.status}`,
                    },
                };
            }

            return {
                data: (await response.json()) as ExtractResponse,
            };
        } catch (err) {
            return {
                error: {
                    code: 'network_error',
                    message:
                        err instanceof Error
                            ? err.message
                            : String(err),
                },
            };
        }
    }
}
```

**Important note on URLs:** The north-cloud nginx routes `/api/crawler` to the crawler service and `/api/classifier` to the classifier service. The internal endpoints are at `/api/internal/v1/fetch` and `/api/internal/v1/extract` on each service. Through nginx, these become:
- `https://northcloud.one/api/crawler/internal/v1/fetch`
- `https://northcloud.one/api/classifier/internal/v1/extract`

However, check the nginx config — internal routes may not be exposed through the reverse proxy. If they're not, you may need to call the services directly on their ports or add nginx location blocks. The implementer should verify this and adjust URLs if needed. If internal routes are blocked at nginx, the implementer should ask the user how to proceed.

**Step 4: Run tests to verify they pass**

Run: `cd cli && npx vitest run tests/lib/north-cloud-client.test.ts`
Expected: All 3 PASS

**Step 5: Commit**

```bash
git add cli/src/lib/north-cloud-client.ts cli/tests/lib/north-cloud-client.test.ts
git commit -m "feat(cli): add NorthCloudDirectClient for direct north-cloud integration"
```

---

### Task 3: Update Auth Command for Direct Mode

**Files:**
- Modify: `cli/src/commands/auth.ts`

**Step 1: Add `--direct` option to auth command**

Replace the entire `cli/src/commands/auth.ts`:

```typescript
import { Command } from 'commander';
import chalk from 'chalk';
import readline from 'node:readline';

import { resolveApiKey, resolveNorthCloudSecret } from '../lib/auth.js';
import { readConfig, writeConfig } from '../lib/config.js';

function prompt(question: string): Promise<string> {
    const rl = readline.createInterface({
        input: process.stdin,
        output: process.stderr,
    });
    return new Promise((resolve) => {
        rl.question(question, (answer) => {
            rl.close();
            resolve(answer.trim());
        });
    });
}

export const authCommand = new Command('auth')
    .description('Configure authentication')
    .option(
        '--direct',
        'Configure direct north-cloud connection instead of PipelineX API'
    )
    .action(async (options) => {
        if (options.direct) {
            await configureDirect();
        } else {
            await configureApiKey();
        }
    });

async function configureApiKey(): Promise<void> {
    const existing = resolveApiKey();
    if (existing) {
        console.error(
            chalk.dim(`Current key: ${existing.substring(0, 20)}...`)
        );
    }

    const key = await prompt('Enter your PipelineX API key: ');

    if (!key) {
        console.error(chalk.red('No API key provided.'));
        process.exit(1);
    }

    if (!key.startsWith('px_')) {
        console.error(
            chalk.red('Invalid API key format. Keys start with "px_".')
        );
        process.exit(1);
    }

    const config = readConfig();
    config.apiKey = key;
    writeConfig(config);

    console.error(
        chalk.green('API key saved to ~/.pipelinex/config.json')
    );
}

async function configureDirect(): Promise<void> {
    const existingSecret = resolveNorthCloudSecret();
    if (existingSecret) {
        console.error(
            chalk.dim('North Cloud secret already configured.')
        );
    }

    const url = await prompt(
        'North Cloud URL [https://northcloud.one]: '
    );
    const secret = await prompt('North Cloud internal secret: ');

    if (!secret) {
        console.error(chalk.red('No secret provided.'));
        process.exit(1);
    }

    const config = readConfig();
    config.northCloudUrl = url || 'https://northcloud.one';
    config.northCloudSecret = secret;
    writeConfig(config);

    console.error(
        chalk.green('North Cloud config saved to ~/.pipelinex/config.json')
    );
}
```

**Step 2: Build and verify**

Run: `cd cli && npm run build && node bin/pipelinex.js auth --help`
Expected: Shows `--direct` option

**Step 3: Commit**

```bash
git add cli/src/commands/auth.ts
git commit -m "feat(cli): add --direct option to auth command for north-cloud setup"
```

---

### Task 4: Update Scrape Command for Direct Mode

**Files:**
- Modify: `cli/src/commands/scrape.ts`

**Step 1: Update scrape command to support both modes**

Replace the entire `cli/src/commands/scrape.ts`:

```typescript
import { Command } from 'commander';
import chalk from 'chalk';
import ora from 'ora';

import {
    resolveApiKey,
    resolveApiUrl,
    resolveMode,
    resolveNorthCloudSecret,
    resolveNorthCloudUrl,
} from '../lib/auth.js';
import { PipelinexClient } from '../lib/client.js';
import { NorthCloudDirectClient } from '../lib/north-cloud-client.js';
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
        const format = resolveFormat(globalOpts?.format);
        const mode = resolveMode();

        const spinner = process.stderr.isTTY
            ? ora({
                  text: `Scraping ${url}...`,
                  stream: process.stderr,
              }).start()
            : null;

        try {
            let result: CrawlResponse;

            if (mode === 'direct') {
                result = await scrapeDirect(url, options);
            } else {
                result = await scrapeViaApi(
                    url,
                    options,
                    globalOpts,
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

async function scrapeDirect(
    url: string,
    options: Record<string, string>
): Promise<CrawlResponse> {
    const ncUrl = resolveNorthCloudUrl();
    const ncSecret = resolveNorthCloudSecret();

    if (!ncSecret) {
        throw new Error(
            'No north-cloud secret configured. Run "pipelinex auth --direct".'
        );
    }

    const client = new NorthCloudDirectClient(ncUrl, ncSecret);
    const timeout = Math.floor(parseInt(options.timeout, 10) / 1000);
    return client.scrape(url, timeout);
}

async function scrapeViaApi(
    url: string,
    options: Record<string, string>,
    globalOpts: GlobalOptions,
    spinner: ReturnType<typeof ora> | null
): Promise<CrawlResponse> {
    const apiKey = resolveApiKey(globalOpts?.apiKey);

    if (!apiKey) {
        throw new Error(
            'No API key found. Run "pipelinex auth" or set PIPELINEX_API_KEY.'
        );
    }

    const apiUrl = resolveApiUrl(globalOpts?.apiUrl);
    const client = new PipelinexClient(apiUrl, apiKey);

    const crawlOptions: Record<string, unknown> = { limit: 1 };
    if (options.waitFor) crawlOptions.wait_for = options.waitFor;
    if (options.includeTags)
        crawlOptions.include_tags = options.includeTags.split(',');
    if (options.excludeTags)
        crawlOptions.exclude_tags = options.excludeTags.split(',');

    let result = await client.crawl(url, crawlOptions);

    if (result.status === 'processing' && result.id) {
        const timeout = parseInt(options.timeout, 10);
        result = await pollForResult(client, result.id, timeout, spinner);
    }

    return result;
}

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
```

**Step 2: Build and verify**

Run: `cd cli && npm run build && npm run lint`
Expected: No errors

**Step 3: Run all tests**

Run: `cd cli && npx vitest run`
Expected: All pass

**Step 4: Commit**

```bash
git add cli/src/commands/scrape.ts
git commit -m "feat(cli): support direct north-cloud mode in scrape command"
```

---

### Task 5: Update Crawl Command with Direct Mode Error

**Files:**
- Modify: `cli/src/commands/crawl.ts`

**Step 1: Add mode check to crawl command**

At the start of the crawl action handler (after getting globalOpts), add a mode check. If direct mode, print a clear error since multi-page crawl requires the PipelineX API:

Add after line 24 (`const globalOpts = ...`):

```typescript
const mode = resolveMode();
if (mode === 'direct') {
    console.error(
        chalk.yellow(
            'Multi-page crawl is not supported in direct mode. Use "pipelinex scrape" for single pages, or configure a PipelineX API key with "pipelinex auth".'
        )
    );
    process.exit(1);
}
```

Also add the import for `resolveMode` from `'../lib/auth.js'`.

**Step 2: Build and verify**

Run: `cd cli && npm run build && npm run lint`
Expected: No errors

**Step 3: Commit**

```bash
git add cli/src/commands/crawl.ts
git commit -m "feat(cli): show clear error for crawl in direct mode"
```

---

### Task 6: Verify End-to-End with Production

**Step 1: Build the CLI**

Run: `cd cli && npm run build`

**Step 2: Configure north-cloud credentials**

Run: `node bin/pipelinex.js auth --direct`
Enter: URL = `https://northcloud.one`, Secret = the production AUTH_INTERNAL_SECRET value

**Step 3: Test scrape against a real URL**

Run: `node bin/pipelinex.js scrape https://example.com`
Expected: Markdown output with title, body, metadata

Run: `node bin/pipelinex.js scrape https://example.com --format json`
Expected: JSON output with full CrawlResponse structure

**Step 4: If the nginx reverse proxy doesn't expose internal routes:**

The internal routes (`/api/internal/v1/fetch`, `/api/internal/v1/extract`) may not be proxied through nginx. If you get 404s, you have two options:

Option A: Add nginx location blocks for the internal routes (modify north-cloud nginx config)
Option B: Use direct service ports — but these aren't exposed publicly

If this happens, ask the user how to proceed. The most likely fix is adding nginx routes.

**Step 5: Run all tests one final time**

Run: `cd cli && npx vitest run`
Expected: All pass

**Step 6: Commit any fixes**

If any adjustments were needed (URL paths, nginx config), commit them.

**Step 7: Final commit**

```bash
git add -A
git commit -m "feat(cli): verify end-to-end north-cloud direct integration"
```
