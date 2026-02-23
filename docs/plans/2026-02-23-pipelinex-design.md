# PipelineX Design Document

**Date:** 2026-02-23
**Status:** Approved

---

## 1. Product Definition

**What PipelineX is:** A developer API that turns any URL into clean, structured data. Submit a URL, get back title, body text, metadata, topics, and quality scores — extracted by a production-grade distributed crawler and classifier.

**Tagline:** *"Any URL → Structured Data. One API call."*

**Who it's for:**
- Developers building content aggregators, news feeds, or research tools
- AI/ML engineers collecting training data from the web
- SEO teams analyzing competitor content at scale
- SaaS products that need "paste a URL, get content" functionality
- Agencies building content monitoring dashboards

**Core use cases:**
1. Content extraction — clean markdown from any article URL
2. Metadata enrichment — topics, quality score, Open Graph data
3. Lead generation — structured fields from job postings, listings
4. Content monitoring — crawl URLs and get structured results via API

**Differentiation:** Backed by a production crawler (North Cloud) that already processes thousands of pages daily. Full content pipeline with quality scoring, topic classification, and intelligent extraction — not a Puppeteer wrapper.

---

## 2. Key Decisions

| Decision | Choice |
|---|---|
| Product type | Crawl-as-a-Service |
| Extraction | General-purpose (text, metadata, OG, topics, quality score) |
| Crawl modes | Single URL only (MVP) |
| API model | Synchronous with async fallback (30s timeout) |
| Infrastructure | Separate from North Cloud (own Postgres, Redis) |
| Data persistence | Store + retrieve (30-day retention) |
| Architecture | Gateway pattern — Laravel API calls NC services over HTTP |

---

## 3. System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     PipelineX Stack                          │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │              Laravel API (public-facing)               │   │
│  │                                                        │   │
│  │  API Key Auth ──→ Rate Limiter ──→ Route Handler       │   │
│  │                                                        │   │
│  │  Endpoints:                                            │   │
│  │    POST /api/v1/crawl        (sync + async fallback)   │   │
│  │    GET  /api/v1/crawl/{id}   (retrieve stored result)  │   │
│  │    GET  /api/v1/usage        (billing/usage stats)     │   │
│  │    POST /api/v1/api-keys     (key management)          │   │
│  └──────────────┬───────────────────────────────────────┘   │
│                  │                                            │
│  ┌───────────────▼──────────────────────────────────────┐   │
│  │              Laravel Queue Worker                      │   │
│  │                                                        │   │
│  │  ProcessCrawlJob:                                      │   │
│  │    1. Call NC Crawler  ──→ get raw HTML                │   │
│  │    2. Call NC Classifier ──→ get structured data       │   │
│  │    3. Store result in PipelineX DB                     │   │
│  │    4. Fire webhook (if configured)                     │   │
│  └──────────────┬───────────────────────────────────────┘   │
│                  │                                            │
│  ┌───────────────▼──────────────────────────────────────┐   │
│  │              PipelineX Infrastructure                  │   │
│  │                                                        │   │
│  │  PostgreSQL ─── tenants, api_keys, crawl_jobs,        │   │
│  │                 crawl_results, usage_records           │   │
│  │  Redis ──────── job queue, rate limit counters,       │   │
│  │                 sync-wait pub/sub, result cache        │   │
│  │  Dashboard ──── Vue 3 + Inertia (already scaffolded)  │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP (internal network)
┌──────────────────────▼──────────────────────────────────────┐
│                     North Cloud (unchanged)                   │
│                                                              │
│  Crawler (8060) ─── fetch URL, return raw HTML + metadata   │
│  Classifier (8071) ─ classify content, return structured JSON│
│                                                              │
│  New endpoints (internal only):                              │
│    POST /api/internal/v1/fetch    ← fetch single URL        │
│    POST /api/internal/v1/extract  ← classify raw HTML       │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

**Data flow for a sync crawl request:**

1. Customer: `POST /api/v1/crawl { "url": "..." }`
2. Laravel: Validate API key → check rate limit → check quota
3. Laravel: Create crawl_job record (status: processing)
4. Laravel: Dispatch ProcessCrawlJob to queue
5. Worker: HTTP call to NC Crawler `/api/internal/v1/fetch`
6. NC Crawler: Fetch page with Colly, respect robots.txt, return raw HTML
7. Worker: HTTP call to NC Classifier `/api/internal/v1/extract`
8. NC Classifier: Extract title, body, metadata, topics, quality score
9. Worker: Store structured result in crawl_results table
10. Worker: Update crawl_job status to "completed"
11. Laravel: Return 200 with structured data to customer

**Sync-with-async-fallback mechanism:**

- Queue dispatches job
- HTTP request waits on Redis pub/sub channel (`crawl:{job_id}`)
- Worker completes → publishes result to channel
- If result arrives within 30s → `200 { data }`
- If timeout → `202 { "id": "...", "status": "processing" }`

**North Cloud changes required:**

| Service | Change | Effort |
|---|---|---|
| Crawler | `POST /api/internal/v1/fetch` — fetch single URL, return HTML + metadata | 1–2 days |
| Classifier | `POST /api/internal/v1/extract` — extract from raw HTML, return structured JSON | 2–3 days |
| Both | Internal auth middleware (shared secret header) | Half day |

---

## 4. API Design

### Authentication

API key in header: `Authorization: Bearer px_live_a1b2c3d4e5f6...`

Key format: `px_{environment}_{32-char-random}`
- `px_live_...` — production keys
- `px_test_...` — test keys (rate limited, no billing)

### `POST /api/v1/crawl`

**Request:**
```json
{
  "url": "https://example.com/blog/how-to-build-apis",
  "options": {
    "timeout": 15,
    "wait_for_js": false,
    "include_html": false,
    "include_links": true,
    "include_images": true
  }
}
```

**Response (200 — sync success):**
```json
{
  "id": "crawl_9f8e7d6c5b4a",
  "status": "completed",
  "url": "https://example.com/blog/how-to-build-apis",
  "final_url": "https://example.com/blog/how-to-build-apis/",
  "data": {
    "title": "How to Build APIs That Developers Love",
    "author": "Jane Smith",
    "published_date": "2026-02-10T14:30:00Z",
    "body": "# How to Build APIs...",
    "word_count": 1842,
    "quality_score": 87,
    "topics": ["technology", "software-engineering", "apis"],
    "og": {
      "title": "How to Build APIs That Developers Love",
      "description": "A practical guide to API design...",
      "image": "https://example.com/images/api-guide.jpg",
      "url": "https://example.com/blog/how-to-build-apis/"
    },
    "links": ["https://example.com/about"],
    "images": ["https://example.com/images/api-guide.jpg"]
  },
  "meta": {
    "status_code": 200,
    "content_type": "text/html",
    "crawled_at": "2026-02-23T10:15:32Z",
    "duration_ms": 1240
  }
}
```

**Response (202 — async fallback):**
```json
{
  "id": "crawl_9f8e7d6c5b4a",
  "status": "processing",
  "url": "https://example.com/blog/how-to-build-apis",
  "poll_url": "/api/v1/crawl/crawl_9f8e7d6c5b4a"
}
```

**Error responses:**
- `402` — quota exceeded
- `422` — invalid URL
- `429` — rate limited (with `Retry-After` header)
- `503` — North Cloud unavailable

### `GET /api/v1/crawl/{id}`

Retrieve stored crawl result. Same shape as sync success response. Returns `404` if expired or not found.

### `GET /api/v1/usage`

```json
{
  "plan": "starter",
  "period": { "start": "2026-02-01T00:00:00Z", "end": "2026-02-28T23:59:59Z" },
  "crawls": { "used": 342, "limit": 1000, "remaining": 658 },
  "rate_limit": { "requests_per_minute": 20, "current": 3 }
}
```

### Rate Limits

| Plan | Requests/min | Monthly crawls |
|---|---|---|
| Free | 5 | 100 |
| Starter | 20 | 1,000 |
| Pro | 60 | 10,000 |
| Enterprise | Custom | Custom |

Headers on every response: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`

---

## 5. Dashboard Design

Uses existing Vue 3 + Inertia.js + shadcn-vue scaffold.

### Pages

| Route | Purpose |
|---|---|
| `/dashboard` | Overview — stats, quick start, recent crawls |
| `/dashboard/crawl` | Crawl playground — paste URL, see result live |
| `/dashboard/history` | Crawl history — paginated table, filterable |
| `/dashboard/history/{id}` | Single crawl result detail |
| `/dashboard/api-keys` | API key management — create, list, revoke |
| `/dashboard/usage` | Usage & billing — plan info, usage chart, Stripe |
| `/settings/*` | Profile, password, 2FA (already built) |

### Key UX decisions

- **Overview IS the onboarding** — Quick Start section prominent until first crawl, then collapses
- **Playground has 3 tabs** — Preview (human-readable), JSON (raw response), cURL (copy-pasteable command)
- **API keys shown once** — raw key displayed at creation only, stored as SHA-256 hash
- **No separate onboarding wizard** — dashboard is self-explanatory

---

## 6. Database Schema

PipelineX owns its own PostgreSQL database.

### tenants
| Column | Type | Notes |
|---|---|---|
| id | uuid | PK |
| user_id | bigint | FK → users.id, unique |
| plan | varchar | "free", "starter", "pro", "enterprise" |
| monthly_crawl_limit | integer | default 100 |
| rate_limit_rpm | integer | default 5 |
| webhook_url | varchar | nullable (V1) |
| webhook_secret | varchar | nullable (V1) |
| created_at | timestamp | |
| updated_at | timestamp | |

### api_keys
| Column | Type | Notes |
|---|---|---|
| id | uuid | PK |
| tenant_id | uuid | FK → tenants.id |
| key_hash | varchar | SHA-256 hash (unique index) |
| key_prefix | varchar | "px_live_...d4e5" for display |
| environment | varchar | "live" or "test" |
| name | varchar | user-assigned label |
| last_used_at | timestamp | nullable |
| revoked_at | timestamp | nullable |
| created_at | timestamp | |
| updated_at | timestamp | |

### crawl_jobs
| Column | Type | Notes |
|---|---|---|
| id | varchar | "crawl_" + 12-char nanoid |
| tenant_id | uuid | FK → tenants.id |
| api_key_id | uuid | FK → api_keys.id |
| url | text | requested URL |
| final_url | text | nullable (after redirects) |
| status | varchar | "processing", "completed", "failed" |
| options | jsonb | { timeout, wait_for_js, ... } |
| error_code | varchar | nullable |
| error_message | text | nullable |
| http_status_code | smallint | nullable |
| duration_ms | integer | nullable |
| created_at | timestamp | |
| completed_at | timestamp | nullable |
| expires_at | timestamp | created_at + 30 days |
| updated_at | timestamp | |

### crawl_results
| Column | Type | Notes |
|---|---|---|
| id | uuid | PK |
| crawl_job_id | varchar | FK → crawl_jobs.id, unique |
| title | text | nullable |
| author | varchar | nullable |
| published_date | timestamp | nullable |
| body | text | extracted markdown |
| word_count | integer | |
| quality_score | smallint | 0-100 |
| topics | jsonb | ["technology", "apis"] |
| og | jsonb | { title, description, image, url } |
| links | jsonb | ["https://..."] |
| images | jsonb | ["https://..."] |
| raw_html | text | nullable (only if include_html=true) |
| content_type | varchar | "text/html" |
| created_at | timestamp | |

### usage_records
| Column | Type | Notes |
|---|---|---|
| id | uuid | PK |
| tenant_id | uuid | FK → tenants.id |
| date | date | aggregate date |
| crawls_count | integer | total crawls that day |
| crawls_succeeded | integer | |
| crawls_failed | integer | |
| created_at | timestamp | |
| updated_at | timestamp | |

**Design notes:**
- API keys stored as SHA-256 hash, never raw
- Failed crawls don't count toward quota (only `status=completed`)
- Daily usage aggregation via scheduled job
- 30-day TTL with daily cleanup job

---

## 7. Pricing Model

| | Free | Starter | Pro | Enterprise |
|---|---|---|---|---|
| Price | $0 | $29/mo | $99/mo | Custom |
| Crawls/month | 100 | 1,000 | 10,000 | Unlimited |
| Rate limit | 5/min | 20/min | 60/min | Custom |
| Retention | 7 days | 30 days | 30 days | 90 days |
| API keys | 1 | 5 | 20 | Unlimited |
| Support | Community | Email | Priority | Dedicated |

**Overage:** Hard stop in MVP (402 error). V1 adds optional overage packs ($10/500 crawls).

**Test keys:** 2/min rate limit, don't count toward quota, results expire after 1 hour.

---

## 8. Roadmap

### MVP (4–6 weeks)

- `POST /api/v1/crawl` — single URL, sync + async fallback
- `GET /api/v1/crawl/{id}` — retrieve stored result
- `GET /api/v1/usage` — usage stats
- API key auth, rate limiting, quota enforcement
- Dashboard: overview, playground, history, API keys, usage
- Stripe integration (Starter + Pro plans)
- 30-day result retention + cleanup job
- North Cloud: 2 new internal endpoints

**Week-by-week:**

| Week | Deliverable |
|---|---|
| 1 | Migrations + Tenant/ApiKey models + key generation. NC: internal fetch endpoint |
| 2 | NC: internal extract endpoint. ProcessCrawlJob worker + NC HTTP client |
| 3 | POST /crawl + sync-wait mechanism. GET /crawl/{id}. Rate limiting + quota |
| 4 | Dashboard: API keys + crawl playground. Overview + history |
| 5 | Dashboard: usage page + Stripe checkout. GET /usage. Aggregation + cleanup jobs |
| 6 | Testing, hardening, docs page, deploy |

### V1 (3 months post-MVP)

- `POST /api/v1/batch` — array of URLs
- Webhook delivery (HMAC-signed)
- Overage packs
- JavaScript rendering (headless browser)
- Public docs site + SDK packages (Node, Python)
- Crawl result caching (same URL within N hours)

### V2 (6–12 months)

- Recursive site crawl
- Scheduled crawls + change detection
- Search API over tenant's crawled corpus
- Custom extraction schemas (LLM-powered)
- Team accounts + RBAC
- Enterprise: SSO/SAML, dedicated infrastructure

---

## 9. Risks & Mitigations

### Abuse
- Rate limits per tenant + global per-domain limit (10 req/min to any single domain)
- ToS requires robots.txt compliance
- All crawled URLs logged per tenant. Ability to ban tenants.

### Crawl load on North Cloud
- Dedicated PipelineX queue, separate from NC internal jobs
- Configurable concurrency cap (start at 10 concurrent crawls)
- Per-crawl timeout: default 15s, max 30s

### Multi-tenant isolation
- All DB queries scoped by tenant_id
- API key auth resolves tenant at request boundary
- Per-tenant rate limits. Fair-share queue (round-robin, not FIFO).

### Billing accuracy
- Only completed crawls count toward quota
- Usage incremented atomically with job completion
- Daily aggregation job reconciles drift
- Full audit trail exportable via dashboard

### North Cloud dependency
- Health check pings NC endpoints. 503 with Retry-After if NC is down.
- Internal endpoints versioned (`/api/internal/v1/`)
- Circuit breaker: fast-fail if NC error rate > 50% over 1 minute

### Scaling
- At MVP scale (10k crawls/day), Postgres handles it easily
- Partition crawl_jobs by month as first optimization
- Redis keys TTL at 60s (rate limits) and 30s (sync-wait). Minimal memory.
- 30-day TTL keeps result storage bounded (~300k rows max)
