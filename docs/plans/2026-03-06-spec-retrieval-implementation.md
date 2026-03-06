# Spec-Retrieval MCP Server Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement the spec-retrieval MCP server, four subsystem spec docs, drift-detector script, and Claude settings config to complete the three-tier codified context architecture.

**Architecture:** Node.js stdio MCP server in `tools/spec-retrieval/server.js` reads markdown files from `docs/specs/` at request time and exposes three tools: `list_specs`, `get_spec`, `search_specs`. No build step, no TypeScript.

**Tech Stack:** Node.js, `@modelcontextprotocol/sdk` v1.27.1 (already installed in `tools/spec-retrieval/node_modules/`)

---

## Task 1: Create `tools/spec-retrieval/package.json`

The `node_modules/` exists but there's no `package.json` — Node won't resolve ESM imports without it.

**Files:**
- Create: `tools/spec-retrieval/package.json`

**Step 1: Create the file**

```json
{
  "name": "pipelinex-spec-retrieval",
  "version": "1.0.0",
  "description": "MCP server for retrieving PipelineX subsystem specs",
  "type": "module",
  "main": "server.js",
  "scripts": {
    "start": "node server.js"
  },
  "dependencies": {
    "@modelcontextprotocol/sdk": "^1.27.1"
  }
}
```

**Step 2: Verify Node resolves the SDK**

```bash
cd tools/spec-retrieval && node -e "import('@modelcontextprotocol/sdk/server/index.js').then(() => console.log('ok'))"
```

Expected: `ok`

**Step 3: Commit**

```bash
git add tools/spec-retrieval/package.json
git commit -m "chore(spec-retrieval): add package.json"
```

---

## Task 2: Create `tools/spec-retrieval/server.js`

The MCP stdio server. Reads `.md` files from `docs/specs/` relative to the repo root.

**Files:**
- Create: `tools/spec-retrieval/server.js`

**Step 1: Write the server**

```javascript
import { readFileSync, readdirSync, existsSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
    CallToolRequestSchema,
    ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
// Repo root is two levels up from tools/spec-retrieval/
const SPECS_DIR = join(__dirname, '..', '..', 'docs', 'specs');

// One-line descriptions for each spec (keyed by filename without .md)
const SPEC_DESCRIPTIONS = {
    'api-pipeline': 'REST API endpoints, crawl job flow, NorthCloudClient integration',
    'auth-tenancy': 'API key authentication, tenant model, middleware chain',
    'dashboard': 'Inertia/Vue dashboard pages, Fortify auth, Stripe billing',
    'cli': 'CLI tool commands, direct north-cloud integration mode',
};

function listSpecs() {
    if (!existsSync(SPECS_DIR)) return [];
    return readdirSync(SPECS_DIR)
        .filter((f) => f.endsWith('.md'))
        .map((f) => {
            const name = f.replace(/\.md$/, '');
            return {
                name,
                description: SPEC_DESCRIPTIONS[name] ?? 'No description',
            };
        });
}

function getSpec(name) {
    const filePath = join(SPECS_DIR, `${name}.md`);
    if (!existsSync(filePath)) return null;
    return readFileSync(filePath, 'utf-8');
}

function searchSpecs(query) {
    const lower = query.toLowerCase();
    const results = [];
    for (const { name } of listSpecs()) {
        const content = getSpec(name);
        if (!content) continue;
        const lines = content.split('\n');
        const matchingLines = lines.filter((l) => l.toLowerCase().includes(lower));
        if (matchingLines.length > 0) {
            results.push({ name, excerpt: matchingLines.slice(0, 5).join('\n') });
        }
    }
    return results;
}

const server = new Server(
    { name: 'spec-retrieval', version: '1.0.0' },
    { capabilities: { tools: {} } },
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: [
        {
            name: 'list_specs',
            description: 'List all available subsystem spec files with one-line descriptions',
            inputSchema: { type: 'object', properties: {} },
        },
        {
            name: 'get_spec',
            description: 'Get the full content of a subsystem spec by name',
            inputSchema: {
                type: 'object',
                properties: {
                    name: { type: 'string', description: 'Spec name, e.g. "api-pipeline"' },
                },
                required: ['name'],
            },
        },
        {
            name: 'search_specs',
            description: 'Search all specs for a keyword, returns matching spec names and excerpts',
            inputSchema: {
                type: 'object',
                properties: {
                    query: { type: 'string', description: 'Search term' },
                },
                required: ['query'],
            },
        },
    ],
}));

server.setRequestHandler(CallToolRequestSchema, async (req) => {
    const { name, arguments: args } = req.params;

    if (name === 'list_specs') {
        const specs = listSpecs();
        return {
            content: [
                {
                    type: 'text',
                    text: specs.map((s) => `**${s.name}**: ${s.description}`).join('\n'),
                },
            ],
        };
    }

    if (name === 'get_spec') {
        const content = getSpec(args.name);
        if (!content) {
            return {
                content: [{ type: 'text', text: `Spec "${args.name}" not found.` }],
                isError: true,
            };
        }
        return { content: [{ type: 'text', text: content }] };
    }

    if (name === 'search_specs') {
        const results = searchSpecs(args.query);
        if (results.length === 0) {
            return {
                content: [{ type: 'text', text: `No matches for "${args.query}".` }],
            };
        }
        const text = results
            .map((r) => `**${r.name}**:\n${r.excerpt}`)
            .join('\n\n---\n\n');
        return { content: [{ type: 'text', text }] };
    }

    return {
        content: [{ type: 'text', text: `Unknown tool: ${name}` }],
        isError: true,
    };
});

const transport = new StdioServerTransport();
await server.connect(transport);
```

**Step 2: Smoke-test the server starts**

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | node tools/spec-retrieval/server.js
```

Expected: JSON response with tools array containing `list_specs`, `get_spec`, `search_specs`.

**Step 3: Commit**

```bash
git add tools/spec-retrieval/server.js
git commit -m "feat(spec-retrieval): add MCP stdio server"
```

---

## Task 3: Create `docs/specs/api-pipeline.md`

**Files:**
- Create: `docs/specs/api-pipeline.md`

**Step 1: Create the spec**

```markdown
# API Pipeline Subsystem

## Overview

The API pipeline handles REST requests for crawl jobs. A client submits a URL via `POST /api/v1/crawl`, the job is processed synchronously (up to 30s timeout), and structured data is returned. If processing takes longer, a 202 is returned with a poll URL.

## Endpoints

All routes in `routes/api.php`, prefixed `/api/v1`, protected by `AuthenticateApiKey`.

| Method | Path | Middleware | Controller |
|---|---|---|---|
| POST | `/crawl` | AuthenticateApiKey, RateLimitApiKey, EnforceQuota | CrawlController@store |
| GET | `/crawl/{id}` | AuthenticateApiKey | CrawlController@show |
| GET | `/usage` | AuthenticateApiKey | UsageController@index |

## Request Flow

```
POST /api/v1/crawl
  → AuthenticateApiKey   (validates Bearer px_... token, injects tenant + api_key)
  → RateLimitApiKey      (checks tenant.rate_limit_rpm via Laravel RateLimiter)
  → EnforceQuota         (counts completed jobs this month vs tenant.monthly_crawl_limit)
  → CrawlController@store
      → CrawlJob::create (id = "crawl_" + 12 random chars, expires_at = +30 days)
      → ProcessCrawlJob::dispatch (sync or async depending on QUEUE_CONNECTION)
      → poll DB up to 30s (250ms intervals)
      → return 200 with result, 202 if timeout, 502 if failed
```

## CrawlController (`app/Http/Controllers/Api/CrawlController.php`)

- `store()` — creates CrawlJob, dispatches job, polls for result
- `show()` — returns status/result for an existing job (tenant-scoped)
- `waitForResult()` — private; polls DB with 250ms sleep until status != 'processing' or timeout
- `formatResponse()` — private; shapes the final JSON response

**Response shape (200):**
```json
{
  "id": "crawl_abc123def456",
  "status": "completed",
  "url": "https://example.com/article",
  "final_url": "https://example.com/article",
  "data": {
    "title": "...", "author": "...", "published_date": "ISO8601",
    "body": "...", "word_count": 500, "quality_score": 0.85,
    "topics": ["tech"], "og": {}, "links": [], "images": []
  },
  "meta": {
    "status_code": 200, "content_type": "text/html",
    "crawled_at": "ISO8601", "duration_ms": 1234
  }
}
```

**Error responses:**
- `401` missing/invalid API key
- `429` rate limit exceeded (`X-RateLimit-*` headers set)
- `402` monthly quota exceeded
- `502` crawl job failed (error_code + error_message in body)
- `202` still processing (poll_url in body)

## ProcessCrawlJob (`app/Jobs/ProcessCrawlJob.php`)

Implements `ShouldQueue`. `$tries = 1`, `$timeout = 45`.

1. Finds CrawlJob by ID
2. Calls `NorthCloudClient::fetch(url, timeout)` — returns `{html, status_code, final_url, title, og, content_type}`
3. Calls `NorthCloudClient::extract(html, url, title)` — returns `{title, author, published_date, body, word_count, quality_score, topics}`
4. Calls `CrawlJob::markCompleted(status_code, duration_ms, final_url)`
5. Creates `CrawlResult` with merged fetch + extract data
6. On any exception: calls `CrawlJob::markFailed(error_code, message)`, re-throws

## NorthCloudClient (`app/Services/NorthCloudClient.php`)

Singleton service. Configured via `config/services.php`:
- `services.north_cloud.crawler_url` (`NORTH_CLOUD_CRAWLER_URL`)
- `services.north_cloud.classifier_url` (`NORTH_CLOUD_CLASSIFIER_URL`)
- `services.north_cloud.internal_secret` (`NORTH_CLOUD_INTERNAL_SECRET`)

All requests include `X-Internal-Secret` header.

- `fetch(url, timeout)` → `POST {crawler_url}/api/internal/v1/fetch`
- `extract(html, url, title)` → `POST {classifier_url}/api/internal/v1/extract`
- `healthy()` → GET health endpoints for both services

## CrawlJob Model (`app/Models/CrawlJob.php`)

- Non-incrementing string PK: `crawl_` + 12 random chars (set in `booted()`)
- `expires_at` auto-set to +30 days on create
- `status`: `processing` | `completed` | `failed`
- `markCompleted(statusCode, durationMs, finalUrl)` — updates status + timestamps
- `markFailed(errorCode, errorMessage)` — updates status + error fields
- Belongs to `Tenant`, `ApiKey`; has one `CrawlResult`

## CrawlResult Model (`app/Models/CrawlResult.php`)

- Belongs to `CrawlJob`
- Fields: `title`, `author`, `published_date` (datetime cast), `body`, `word_count`, `quality_score`, `topics` (array), `og` (array), `links` (array), `images` (array), `raw_html` (nullable), `content_type`

## UsageController (`app/Http/Controllers/Api/UsageController.php`)

Returns current month crawl counts and plan limits for the authenticated tenant.

## Console Commands

- `AggregateUsageCommand` — aggregates daily usage into `usage_records` table
- `CleanupExpiredResultsCommand` — deletes CrawlJobs with `expires_at` in the past

## Configuration

```php
// config/services.php
'north_cloud' => [
    'crawler_url' => env('NORTH_CLOUD_CRAWLER_URL'),
    'classifier_url' => env('NORTH_CLOUD_CLASSIFIER_URL'),
    'internal_secret' => env('NORTH_CLOUD_INTERNAL_SECRET'),
],
'pipelinex' => [
    'crawl_wait_timeout' => env('CRAWL_WAIT_TIMEOUT', 30),
],
```

## Testing

Tests in `tests/Feature/Api/`, `tests/Feature/Jobs/`, `tests/Feature/Services/`, `tests/Feature/Middleware/`.
All use `RefreshDatabase` + SQLite. Queue driver set to `sync`.
`NorthCloudClient` is mocked via `Http::fake()` in job/service tests.
```

**Step 2: Commit**

```bash
git add docs/specs/api-pipeline.md
git commit -m "docs(specs): add api-pipeline subsystem spec"
```

---

## Task 4: Create `docs/specs/auth-tenancy.md`

**Files:**
- Create: `docs/specs/auth-tenancy.md`

**Step 1: Create the spec**

```markdown
# Auth & Tenancy Subsystem

## Overview

PipelineX uses a dual-auth model: web sessions (Fortify) for the dashboard, and API key tokens for the REST API. Every user gets exactly one Tenant on registration. The Tenant owns all resources (ApiKeys, CrawlJobs, UsageRecords).

## API Key Format

```
px_{environment}_{32 random chars}
```

Examples:
- `px_live_abc123...` — production key
- `px_test_xyz789...` — test key

Only the SHA-256 hash (`key_hash`) is stored in the database. The first 20 characters (`key_prefix`) are stored for display purposes (e.g. `px_live_abc123def456`).

## ApiKey Model (`app/Models/ApiKey.php`)

Uses `HasUuids` — UUID primary key.

Fields:
- `tenant_id` (UUID FK)
- `key_hash` — SHA-256 of raw key, used for lookup
- `key_prefix` — first 20 chars, for UI display only
- `environment` — `live` or `test`
- `name` — user-assigned label
- `last_used_at` — updated on every authenticated request
- `revoked_at` — non-null means revoked (soft-delete pattern)

Key methods:
- `findByRawKey(rawKey)` — hashes input, queries by hash, excludes revoked
- `touchLastUsed()` — sets `last_used_at = now()`
- `isRevoked()` — returns `revoked_at !== null`

## Tenant Model (`app/Models/Tenant.php`)

Uses `HasUuids` — UUID primary key.

Fields:
- `user_id` (FK to users)
- `plan` — `free` | `starter` | `pro`
- `monthly_crawl_limit` — max completed crawls per calendar month
- `rate_limit_rpm` — max API requests per minute
- `webhook_url` / `webhook_secret` — optional webhook config

Relationships: `user()`, `apiKeys()`, `crawlJobs()`, `usageRecords()`

Key method:
- `generateApiKey(name, environment)` — generates `px_{env}_{32}`, stores hash + prefix, returns `{key, apiKey}`. The raw key is only returned once.

## User Model (`app/Models/User.php`)

Standard Fortify auth + Cashier billing.
Traits: `Billable` (Cashier), `TwoFactorAuthenticatable` (Fortify), `Notifiable`.
Has one `Tenant` (created on registration).

## Registration Flow

`CreateNewUser` (`app/Actions/Fortify/CreateNewUser.php`):
1. Validates name, email, password
2. Creates `User`
3. Creates `Tenant` with `plan=free`, `monthly_crawl_limit=100`, `rate_limit_rpm=5`

## API Auth Middleware Chain

### AuthenticateApiKey (`app/Http/Middleware/AuthenticateApiKey.php`)

1. Reads `Authorization: Bearer px_...` header
2. Rejects if missing or not prefixed with `px_`
3. Calls `ApiKey::findByRawKey(bearer)` — hashes token, looks up by hash, excludes revoked
4. Calls `apiKey->touchLastUsed()`
5. Injects `tenant` and `api_key` into request for downstream middleware/controllers

Error responses:
- `401 missing_api_key` — no Bearer token or wrong prefix
- `401 invalid_api_key` — hash not found or key is revoked

### RateLimitApiKey (`app/Http/Middleware/RateLimitApiKey.php`)

- Key: `api_rate_limit:{tenant_id}`
- Limit: `tenant.rate_limit_rpm` per 60-second window (Laravel RateLimiter)
- Sets `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers
- Returns `429 rate_limited` with `Retry-After` header if exceeded

### EnforceQuota (`app/Http/Middleware/EnforceQuota.php`)

- Counts `CrawlJob` with `status=completed` for current tenant this calendar month
- Returns `402 quota_exceeded` if count >= `tenant.monthly_crawl_limit`

## Dashboard Auth (Fortify)

- `routes/web.php` uses `['auth', 'verified']` middleware group
- Login, register, password reset, email verification via Fortify routes
- 2FA supported (`TwoFactorAuthenticatable`)
- `FortifyServiceProvider` configures views, features, and user creation action

## Plan Limits

| Plan | monthly_crawl_limit | rate_limit_rpm |
|---|---|---|
| free | 100 | 5 |
| starter | (Stripe-managed) | (Stripe-managed) |
| pro | (Stripe-managed) | (Stripe-managed) |

Plan changes are triggered by Stripe webhook events (`StripeEventListener`).

## Testing

Tests in `tests/Feature/Middleware/`, `tests/Feature/Dashboard/`.
`RefreshDatabase` + SQLite. No external auth service needed.
```

**Step 2: Commit**

```bash
git add docs/specs/auth-tenancy.md
git commit -m "docs(specs): add auth-tenancy subsystem spec"
```

---

## Task 5: Create `docs/specs/dashboard.md`

**Files:**
- Create: `docs/specs/dashboard.md`

**Step 1: Create the spec**

```markdown
# Dashboard Subsystem

## Overview

The dashboard is a server-side rendered SPA using Inertia.js v2 with Vue 3. All routes under `/dashboard` require `auth` + `verified` middleware. Fortify handles authentication. Stripe/Cashier handles billing. The dashboard shares models and services with the REST API.

## Routes (`routes/web.php`)

| Method | Path | Controller | Inertia Page |
|---|---|---|---|
| GET | `/` | closure | `Welcome` |
| GET | `/dashboard` | DashboardController@index | `Dashboard` |
| GET | `/dashboard/crawl` | CrawlPlaygroundController@index | `dashboard/CrawlPlayground` |
| POST | `/dashboard/crawl` | CrawlPlaygroundController@crawl | (redirect/JSON) |
| GET | `/dashboard/history` | HistoryController@index | `dashboard/History` |
| GET | `/dashboard/history/{crawlJob}` | HistoryController@show | `dashboard/HistoryDetail` |
| GET | `/dashboard/api-keys` | ApiKeyController@index | `dashboard/ApiKeys` |
| POST | `/dashboard/api-keys` | ApiKeyController@store | (redirect) |
| DELETE | `/dashboard/api-keys/{apiKey}` | ApiKeyController@destroy | (redirect) |
| GET | `/dashboard/usage` | UsageDashboardController@index | `dashboard/Usage` |
| GET | `/dashboard/billing/checkout/{plan}` | BillingController@checkout | (Stripe redirect) |
| GET | `/dashboard/billing/portal` | BillingController@portal | (Stripe portal redirect) |

Settings routes in `routes/settings.php`: profile, password, 2FA.

## Vue Pages (`resources/js/pages/`)

- `Welcome.vue` — landing page, shows register link if registration enabled
- `Dashboard.vue` — overview: usage summary, recent jobs, plan status
- `dashboard/ApiKeys.vue` — list + create + revoke API keys
- `dashboard/CrawlPlayground.vue` — interactive URL crawl tester
- `dashboard/History.vue` — paginated list of crawl jobs
- `dashboard/HistoryDetail.vue` — full result for a single crawl job
- `dashboard/Usage.vue` — usage charts and plan limits
- `auth/` — login, register, forgot-password, reset-password, verify-email
- `settings/` — profile, password, appearance, 2FA

## Tech Stack

- **Inertia.js v2** — server renders initial props, Vue handles navigation
- **Vue 3 + TypeScript** — Composition API throughout
- **Tailwind CSS v4** — utility-first, configured in `vite.config.ts`
- **Reka UI** — component library (primitives in `resources/js/components/ui/`)
- **Wayfinder** — typed route helpers generated by `php artisan wayfinder:generate`
- **Import alias** — `@/*` maps to `resources/js/*`

## HandleInertiaRequests Middleware (`app/Http/Middleware/HandleInertiaRequests.php`)

Shares global props with all Inertia pages:
- `auth.user` — authenticated user (with tenant relationship)
- Flash messages, CSRF token

## Dashboard Controllers

All in `app/Http/Controllers/Dashboard/`.

- **DashboardController** — fetches usage stats + recent jobs for overview
- **ApiKeyController** — index (list tenant's keys), store (generate new key via `Tenant::generateApiKey()`), destroy (set `revoked_at`)
- **CrawlPlaygroundController** — index (render page), crawl (calls `ProcessCrawlJob` synchronously, returns result to Vue)
- **HistoryController** — paginated CrawlJob list, single job detail
- **UsageDashboardController** — aggregated usage from `UsageRecord` table
- **BillingController** — Cashier checkout session for plan upgrade, Stripe billing portal

## Billing (Stripe/Cashier)

- `User` has `Billable` trait (Cashier)
- Plans: `starter`, `pro` (Stripe product/price IDs in config)
- `BillingController::checkout($plan)` — creates Stripe Checkout Session, redirects user
- `BillingController::portal()` — creates Stripe Billing Portal session
- `StripeEventListener` — handles `customer.subscription.updated`/`deleted` webhooks to update `tenant.plan`, `monthly_crawl_limit`, `rate_limit_rpm`

## Testing

Tests in `tests/Feature/Dashboard/`. `RefreshDatabase` + SQLite.
Inertia responses tested via `$response->assertInertia(fn($page) => ...)`.
Stripe calls mocked via `Http::fake()` or test mode keys.
```

**Step 2: Commit**

```bash
git add docs/specs/dashboard.md
git commit -m "docs(specs): add dashboard subsystem spec"
```

---

## Task 6: Create `docs/specs/cli.md`

**Files:**
- Create: `docs/specs/cli.md`

**Step 1: Create the spec**

```markdown
# CLI Subsystem

## Overview

The PipelineX CLI (`cli/`) is a TypeScript Node.js tool that provides command-line access to the PipelineX API. It supports two modes: **API mode** (uses a PipelineX API key) and **direct mode** (calls North Cloud services directly, bypassing the PipelineX API).

## Build & Run

```bash
cd cli/
npm run build          # tsup bundles to dist/
npm run dev            # watch mode
npm run test           # vitest
node dist/index.js     # run built CLI
```

Binary: `bin/pipelinex` → `dist/index.js`

## Mode Detection (`src/lib/auth.ts`)

Mode is determined at runtime by reading `~/.config/pipelinex/config.json`:

- **direct mode**: config has `northCloudUrl` + `northCloudSecret` set
- **api mode**: config has `apiKey` or env `PIPELINEX_API_KEY` set

```typescript
export function resolveMode(): ClientMode {
    const config = readConfig();
    if (config.northCloudUrl && config.northCloudSecret) return 'direct';
    if (config.apiKey || process.env.PIPELINEX_API_KEY) return 'api';
    return 'api';
}
```

## Commands (`src/commands/`)

### `pipelinex auth`

Interactive setup — saves API key or North Cloud credentials to config file.

### `pipelinex scrape <url>`

Single-page scrape. Supported in both **api** and **direct** mode.

- **api mode**: `POST /api/v1/crawl` → polls if 202 returned
- **direct mode**: calls North Cloud crawler + classifier directly

Global options: `--api-key`, `--format <markdown|json|html>`, `--api-url`

### `pipelinex crawl <url>`

Multi-page crawl. **API mode only** — exits with error in direct mode.

Options: `--limit <n>`, `--depth <n>`, `--poll-interval <ms>`, `--timeout <ms>`

### `pipelinex usage`

Shows current month usage stats. **API mode only**.

## Direct Mode (`feat(cli): add direct north-cloud integration mode`, PR #3)

When configured with North Cloud credentials, the `scrape` command bypasses PipelineX entirely and calls North Cloud services directly:

1. `POST {northCloudUrl}/api/internal/v1/fetch` with `X-Internal-Secret` header
2. `POST {northCloudUrl}/api/internal/v1/extract` (or classifier URL if separate)

Config keys: `northCloudUrl`, `northCloudSecret`. Also via env: `NORTH_CLOUD_URL`, `NORTH_CLOUD_SECRET`.

## Key Libraries (`src/lib/`)

- **`auth.ts`** — `resolveMode()`, `resolveApiKey()`, `resolveApiUrl()`, `resolveNorthCloudUrl()`, `resolveNorthCloudSecret()`
- **`client.ts`** — `PipelinexClient` — wraps `fetch()` calls to PipelineX API with auth headers and polling
- **`config.ts`** — `readConfig()`, `writeConfig()` — reads/writes `~/.config/pipelinex/config.json`
- **`output.ts`** — `formatCrawlResult()`, `resolveFormat()` — formats result as markdown, JSON, or HTML

## Types (`src/types.ts`)

- `GlobalOptions` — `apiKey`, `format`, `apiUrl`
- `CrawlResponse` — `id`, `status`, `url`, `data`, `meta`, `poll_url`, `error`
- `ClientMode` — `'direct' | 'api'`

## Output Formats

- **markdown** (default) — clean readable output to stdout
- **json** — raw API response JSON
- **html** — rendered HTML content

Spinner on stderr (only when stderr is a TTY), so stdout remains pipe-friendly.

## Testing

Tests in `cli/tests/` using Vitest. Run from `cli/` directory:
```bash
cd cli && npm test
```

Note: ESLint is configured to ignore `cli/` from the root `eslint.config.js`.
```

**Step 2: Commit**

```bash
git add docs/specs/cli.md
git commit -m "docs(specs): add cli subsystem spec"
```

---

## Task 7: Create `.claude/settings.json`

**Files:**
- Create: `.claude/settings.json`

**Step 1: Create the file**

```json
{
  "mcpServers": {
    "spec-retrieval": {
      "type": "stdio",
      "command": "node",
      "args": ["tools/spec-retrieval/server.js"]
    }
  }
}
```

**Step 2: Verify it coexists with `settings.local.json`**

```bash
ls .claude/
```

Expected: both `settings.json` and `settings.local.json` present.

**Step 3: Commit**

```bash
git add .claude/settings.json
git commit -m "chore(mcp): configure spec-retrieval MCP server in .claude/settings.json"
```

---

## Task 8: Create `tools/drift-detector.sh`

**Files:**
- Create: `tools/drift-detector.sh`

**Step 1: Write the script**

```bash
#!/usr/bin/env bash
# drift-detector.sh — map recent git changes to affected subsystem specs
# Usage: ./tools/drift-detector.sh [num_commits]
#   num_commits: how many commits back to check (default: 1)

set -euo pipefail

NUM_COMMITS="${1:-1}"
CHANGED_FILES=$(git diff --name-only "HEAD~${NUM_COMMITS}" HEAD 2>/dev/null || git diff --name-only HEAD 2>/dev/null || true)

if [ -z "$CHANGED_FILES" ]; then
    echo "No changed files found."
    exit 0
fi

declare -A AFFECTED
AFFECTED["api-pipeline"]=""
AFFECTED["auth-tenancy"]=""
AFFECTED["dashboard"]=""
AFFECTED["cli"]=""

while IFS= read -r file; do
    case "$file" in
        app/Http/Controllers/Api/*|app/Jobs/*|app/Services/NorthCloud*)
            AFFECTED["api-pipeline"]+=" $file"
            ;;
        app/Http/Middleware/Auth*|app/Http/Middleware/EnforceQuota*|app/Http/Middleware/RateLimit*|app/Models/ApiKey*|app/Models/Tenant*|app/Actions/Fortify*)
            AFFECTED["auth-tenancy"]+=" $file"
            ;;
        resources/js/*|app/Http/Controllers/Dashboard/*|app/Http/Controllers/Settings/*|app/Listeners/Stripe*)
            AFFECTED["dashboard"]+=" $file"
            ;;
        cli/*)
            AFFECTED["cli"]+=" $file"
            ;;
    esac
done <<< "$CHANGED_FILES"

echo "Changed files in last ${NUM_COMMITS} commit(s) affect these specs:"
echo ""

FOUND=0
for spec in "api-pipeline" "auth-tenancy" "dashboard" "cli"; do
    if [ -n "${AFFECTED[$spec]}" ]; then
        FOUND=1
        echo "  → ${spec}"
        for f in ${AFFECTED[$spec]}; do
            echo "      ${f}"
        done
    fi
done

if [ "$FOUND" -eq 0 ]; then
    echo "  (no spec-related files changed)"
fi
```

**Step 2: Make executable and test**

```bash
chmod +x tools/drift-detector.sh
./tools/drift-detector.sh 3
```

Expected: output listing changed files mapped to affected specs (based on recent commits).

**Step 3: Commit**

```bash
git add tools/drift-detector.sh
git commit -m "chore: add drift-detector.sh to map file changes to affected specs"
```

---

## Task 9: Clean up `.gitignore` and update MEMORY.md

**Files:**
- Modify: `.gitignore` (remove duplicate `tools/spec-retrieval/node_modules/` line)
- Modify: `.claude/projects/.../memory/MEMORY.md` (update MCP server status)

**Step 1: Fix `.gitignore`**

The file has `tools/spec-retrieval/node_modules/` duplicated (lines 32–33). Remove one copy.

**Step 2: Update MEMORY.md**

In `/home/jones/.claude/projects/-home-jones-dev-pipelinex/memory/MEMORY.md`, update the Codified Context section to reflect that the MCP server and specs now exist and are functional.

**Step 3: Close issue #7**

```bash
gh issue close 7 --comment "Implemented: tools/spec-retrieval/server.js, docs/specs/*.md (4 files), tools/drift-detector.sh, .claude/settings.json. Refs #7."
```

**Step 4: Commit .gitignore fix**

```bash
git add .gitignore
git commit -m "chore: remove duplicate tools/spec-retrieval/node_modules gitignore entry"
```
