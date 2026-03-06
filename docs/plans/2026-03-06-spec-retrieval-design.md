# Spec-Retrieval MCP Server Design

**Date:** 2026-03-06
**Issue:** #7
**Status:** Approved

---

## Overview

Implement the spec-retrieval MCP server (`tools/spec-retrieval/server.js`) and supporting
infrastructure to complete the three-tier codified context architecture for PipelineX.

---

## File Structure

```
tools/spec-retrieval/
  server.js          ← MCP stdio server
  package.json       ← name, version, dependencies
  node_modules/      ← already exists

docs/specs/
  api-pipeline.md    ← REST API, crawl job flow, NorthCloudClient
  auth-tenancy.md    ← ApiKey auth, Tenant model, middleware chain
  dashboard.md       ← Inertia pages, Vue components, billing UI
  cli.md             ← CLI tool, direct north-cloud integration

.claude/
  settings.json      ← MCP server config (new, checked into git)
  settings.local.json ← existing (user permissions, not touched)

tools/
  drift-detector.sh  ← maps changed files to affected specs
```

---

## MCP Server

**Transport:** stdio (standard for Claude Code MCP servers)
**Runtime:** Node.js, using already-installed `@modelcontextprotocol/sdk`

**Tools exposed:**

| Tool | Params | Returns |
|---|---|---|
| `list_specs` | none | names + one-line descriptions of all specs |
| `get_spec` | `name: string` | full markdown content of the spec |
| `search_specs` | `query: string` | matching spec names + excerpts of matching lines |

Files are read from `docs/specs/` at request time (no caching). Path resolved relative to
repo root (two levels up from `tools/spec-retrieval/`).

---

## Spec File Content

Each file covers the subsystem from Claude's perspective: what exists, how it works, where
the code lives. Target ~150-300 lines per file.

- **`api-pipeline.md`** — REST endpoints, middleware chain, `ProcessCrawlJob`,
  `NorthCloudClient`, `CrawlJob`/`CrawlResult` models, error handling
- **`auth-tenancy.md`** — API key format, SHA-256 hash storage, `AuthenticateApiKey` →
  `RateLimitApiKey` → `EnforceQuota`, `Tenant`/`ApiKey` UUIDs, registration flow
- **`dashboard.md`** — Inertia page structure, Vue components, Fortify auth routes,
  Stripe/Cashier billing, plan management
- **`cli.md`** — CLI tool in `cli/`, direct north-cloud integration mode, commands and flags

Content written from codebase knowledge.

---

## Drift Detector

`tools/drift-detector.sh [num_commits]` — default 1 commit.

Runs `git diff --name-only HEAD~N HEAD`, maps paths to specs:

| File pattern | Spec |
|---|---|
| `app/Http/Controllers/Api/`, `app/Jobs/`, `app/Services/NorthCloud*` | `api-pipeline` |
| `app/Http/Middleware/Auth*`, `app/Models/ApiKey*`, `app/Models/Tenant*` | `auth-tenancy` |
| `resources/js/`, `app/Http/Controllers/Dashboard/` | `dashboard` |
| `cli/` | `cli` |

---

## Claude Settings

`.claude/settings.json` (repo-level, checked into git):

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
