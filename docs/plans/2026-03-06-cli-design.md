# PipelineX CLI Design

## Overview

A TypeScript CLI tool (`@pipelinex/cli`) that lives in `cli/` within the pipelinex monorepo. It provides a Firecrawl-competitive command-line interface to the PipelineX crawling API.

## Commands

| Command | Description | API Mapping |
|---|---|---|
| `pipelinex auth` | Save API key to `~/.pipelinex/config.json` | None (local config) |
| `pipelinex scrape <url>` | Scrape single page (sync UX, polls internally) | `POST /api/v1/crawl` + poll `GET /crawl/{id}` |
| `pipelinex crawl <url>` | Crawl multiple pages (async with progress) | `POST /api/v1/crawl` + poll `GET /crawl/{id}` |
| `pipelinex usage` | Show current quota and usage stats | `GET /api/v1/usage` |

`scrape` vs `crawl`: `scrape` targets a single page with `limit: 1` and waits for the result. `crawl` supports `--limit`, `--depth`, and shows a progress spinner while polling.

## Authentication

Priority order (highest to lowest):

1. `--api-key` flag
2. `PIPELINEX_API_KEY` env var
3. `~/.pipelinex/config.json`

`pipelinex auth` prompts for the API key and writes it to `~/.pipelinex/config.json`.

## Output

- Default: markdown when TTY, JSON when piped
- Override with `--format markdown|json|html`
- Pretty terminal output using `chalk` for colors and `ora` for spinners

## Global Flags

| Flag | Description |
|---|---|
| `--api-key <key>` | API key override |
| `--format <fmt>` | Output format: `markdown`, `json`, `html` |
| `--api-url <url>` | Custom API base URL (default from config or `https://pipelinex.dev/api/v1`) |
| `--no-color` | Disable colored output |
| `--verbose` | Show request/response details |

## Scrape Flags

| Flag | Description |
|---|---|
| `--timeout <ms>` | Request timeout (default: 30000) |
| `--wait-for <selector>` | Wait for CSS selector before extracting |
| `--include-tags <tags>` | Only include specific HTML tags |
| `--exclude-tags <tags>` | Exclude specific HTML tags |

## Crawl Flags

| Flag | Description |
|---|---|
| `--limit <n>` | Max pages to crawl |
| `--depth <n>` | Max crawl depth |
| `--poll-interval <ms>` | Polling interval (default: 2000) |

## Package Structure

```
cli/
  package.json          (@pipelinex/cli)
  tsconfig.json
  tsup.config.ts        (bundler)
  src/
    index.ts            (entry point, Commander setup)
    commands/
      auth.ts
      scrape.ts
      crawl.ts
      usage.ts
    lib/
      client.ts         (PipelineX API client)
      config.ts         (read/write ~/.pipelinex/config.json)
      output.ts         (format + TTY detection)
      auth.ts           (resolve key: flag > env > config)
    types.ts
  bin/
    pipelinex.js        (shebang entry)
  tests/
```

## Dependencies

- `commander` - CLI framework
- `chalk` - Terminal colors
- `ora` - Spinners for async operations
- `tsup` - TypeScript bundler
- `vitest` - Testing

## Tech Decisions

- Language: TypeScript (Node.js)
- Framework: Commander.js (same as Firecrawl)
- npm package: `@pipelinex/cli` (scoped)
- Location: `cli/` directory in pipelinex monorepo
- No API changes needed for v1 - CLI polls existing endpoints

## Future Commands (post-v1)

- `pipelinex map <url>` - Discover URLs on a site
- `pipelinex extract <url>` - Structured data extraction with LLM
