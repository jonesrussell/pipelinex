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
