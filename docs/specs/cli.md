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
