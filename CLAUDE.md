# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PipelineX is a web scraping/crawling API platform built with Laravel 12 and Vue 3. It provides a REST API for submitting crawl jobs and a dashboard for managing API keys, viewing history, and tracking usage. Crawl jobs are processed asynchronously via Redis queues, using the North Cloud external services (crawler + classifier).

## Development Commands

```bash
# Full dev environment (server, queue worker, log tail, Vite HMR)
composer dev

# Run all tests (clears config, lint check, then Pest)
composer test

# Run a single test file
./vendor/bin/pest tests/Feature/Api/CrawlTest.php

# Run a single test by name
./vendor/bin/pest --filter="test name here"

# PHP code style (Laravel Pint, laravel preset)
composer lint              # fix
composer test:lint         # check only

# Frontend linting and formatting
npm run lint               # ESLint with auto-fix
npm run format             # Prettier write
npm run format:check       # Prettier check only

# Build frontend assets
npm run build
npm run build:ssr          # with SSR
```

## Architecture

### Dual Interface Pattern

The app has two interfaces sharing the same models and services:

1. **REST API** (`routes/api.php`) — Bearer token auth via `AuthenticateApiKey` middleware, prefixed `/api/v1/`. Three endpoints: `POST /crawl`, `GET /crawl/{id}`, `GET /usage`.
2. **Dashboard** (`routes/web.php`) — Inertia.js (Vue 3) SPA with Fortify auth. All dashboard routes are under `/dashboard`.

### Request Flow for Crawl Jobs

`API Request → AuthenticateApiKey → RateLimitApiKey → EnforceQuota → CrawlController@store → dispatches ProcessCrawlJob → NorthCloudClient::fetch() → NorthCloudClient::extract() → CrawlResult created`

### Key Models and Relationships

- **User** → has one **Tenant** (1:1, created on registration)
- **Tenant** → has many **ApiKey**, **CrawlJob**, **UsageRecord**
- **CrawlJob** → has one **CrawlResult**

Notable: `Tenant` and `ApiKey` use UUIDs. `CrawlJob` uses a custom string ID (`crawl_` + random 12 chars, non-incrementing).

### API Authentication

API keys follow the format `px_{environment}_{random32}`. Only the SHA-256 hash is stored (`key_hash`). The `key_prefix` (first 20 chars) is stored for display. Authentication is handled by `AuthenticateApiKey` middleware which hashes the Bearer token and looks up the hash.

### External Services

`NorthCloudClient` (singleton service) integrates with two external microservices configured in `config/services.php`:
- **Crawler** (`NORTH_CLOUD_CRAWLER_URL`) — fetches web pages
- **Classifier** (`NORTH_CLOUD_CLASSIFIER_URL`) — extracts structured content from HTML

### Billing

Stripe integration via Laravel Cashier. Subscription model on `User` (Cashier's `Billable` trait). Plans: `starter`, `pro`. Each plan sets `monthly_crawl_limit` and `rate_limit_rpm` on the tenant.

### Frontend

- Vue 3 + TypeScript + Inertia.js (pages in `resources/js/pages/`)
- Tailwind CSS 4 with Reka UI component library
- UI components in `resources/js/components/ui/` (excluded from ESLint)
- Import alias: `@/*` maps to `resources/js/*`
- Wayfinder generates typed route helpers from Laravel routes

## Testing

- **Pest** with `RefreshDatabase` trait on all Feature tests (configured in `tests/Pest.php`)
- Tests use in-memory SQLite (configured in `phpunit.xml`)
- Queue set to `sync` driver in tests
- Tests organized: `tests/Feature/Api/`, `tests/Feature/Dashboard/`, `tests/Feature/Jobs/`, `tests/Feature/Services/`, `tests/Feature/Middleware/`, `tests/Feature/Commands/`, `tests/Unit/`

## Code Style

- **PHP**: Laravel Pint with `laravel` preset (PSR-12 based)
- **TypeScript/Vue**: ESLint enforces `consistent-type-imports` (use `import type` for type-only imports) and alphabetical `import/order`
- **Prettier**: single quotes, 4-space indent, 80 char width, Tailwind class sorting via plugin

## Infrastructure

- **Database**: PostgreSQL (production), SQLite (testing)
- **Cache/Queue/Session**: Redis for cache and queues, database for sessions
- **CI**: GitHub Actions runs Pest on PHP 8.4 + 8.5, plus Pint/ESLint/Prettier lint checks
