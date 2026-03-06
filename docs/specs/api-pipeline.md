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
