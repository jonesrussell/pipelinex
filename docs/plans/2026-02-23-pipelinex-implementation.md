# PipelineX Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build PipelineX MVP — a Crawl-as-a-Service API + dashboard backed by North Cloud's crawler and classifier.

**Architecture:** Laravel API gateway (auth, billing, rate limiting, dashboard) calls North Cloud's existing Go services over HTTP via two new internal endpoints. PipelineX owns its own PostgreSQL, Redis, and storage. Sync-first API with async fallback via Redis pub/sub.

**Tech Stack:** PHP 8.2 / Laravel 12 / Vue 3 / Inertia.js / PostgreSQL / Redis / Pest / Stripe

**Design doc:** `docs/plans/2026-02-23-pipelinex-design.md`

---

## Phase 1: North Cloud Internal Endpoints

### Task 1: Internal Auth Middleware (North Cloud)

Add a shared-secret middleware to the infrastructure package for internal service-to-service auth.

**Files:**
- Create: `north-cloud/infrastructure/gin/internal_auth.go`
- Test: `north-cloud/infrastructure/gin/internal_auth_test.go`

**Step 1: Write the failing test**

```go
// internal_auth_test.go
package gin_test

import (
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/gin-gonic/gin"
	infragin "github.com/north-cloud/infrastructure/gin"
	"github.com/stretchr/testify/assert"
)

func TestInternalAuthMiddleware(t *testing.T) {
	gin.SetMode(gin.TestMode)

	t.Run("rejects missing header", func(t *testing.T) {
		router := gin.New()
		router.Use(infragin.InternalAuthMiddleware("test-secret"))
		router.GET("/test", func(c *gin.Context) { c.JSON(200, gin.H{"ok": true}) })

		w := httptest.NewRecorder()
		req, _ := http.NewRequest("GET", "/test", nil)
		router.ServeHTTP(w, req)

		assert.Equal(t, http.StatusUnauthorized, w.Code)
	})

	t.Run("rejects wrong secret", func(t *testing.T) {
		router := gin.New()
		router.Use(infragin.InternalAuthMiddleware("test-secret"))
		router.GET("/test", func(c *gin.Context) { c.JSON(200, gin.H{"ok": true}) })

		w := httptest.NewRecorder()
		req, _ := http.NewRequest("GET", "/test", nil)
		req.Header.Set("X-Internal-Secret", "wrong-secret")
		router.ServeHTTP(w, req)

		assert.Equal(t, http.StatusUnauthorized, w.Code)
	})

	t.Run("allows correct secret", func(t *testing.T) {
		router := gin.New()
		router.Use(infragin.InternalAuthMiddleware("test-secret"))
		router.GET("/test", func(c *gin.Context) { c.JSON(200, gin.H{"ok": true}) })

		w := httptest.NewRecorder()
		req, _ := http.NewRequest("GET", "/test", nil)
		req.Header.Set("X-Internal-Secret", "test-secret")
		router.ServeHTTP(w, req)

		assert.Equal(t, http.StatusOK, w.Code)
	})
}
```

**Step 2: Run test to verify it fails**

Run: `cd /home/fsd42/dev/north-cloud/infrastructure && go test ./gin/ -run TestInternalAuthMiddleware -v`
Expected: FAIL — `InternalAuthMiddleware` not defined

**Step 3: Write implementation**

```go
// internal_auth.go
package gin

import (
	"crypto/subtle"
	"net/http"

	"github.com/gin-gonic/gin"
)

const internalAuthHeader = "X-Internal-Secret"

// InternalAuthMiddleware validates requests using a shared secret header.
// Used for internal service-to-service communication (not JWT).
func InternalAuthMiddleware(secret string) gin.HandlerFunc {
	return func(c *gin.Context) {
		provided := c.GetHeader(internalAuthHeader)
		if subtle.ConstantTimeCompare([]byte(provided), []byte(secret)) != 1 {
			c.JSON(http.StatusUnauthorized, gin.H{"error": "invalid internal auth"})
			c.Abort()
			return
		}
		c.Next()
	}
}
```

**Step 4: Run test to verify it passes**

Run: `cd /home/fsd42/dev/north-cloud/infrastructure && go test ./gin/ -run TestInternalAuthMiddleware -v`
Expected: PASS

**Step 5: Commit**

```bash
cd /home/fsd42/dev/north-cloud
git add infrastructure/gin/internal_auth.go infrastructure/gin/internal_auth_test.go
git commit -m "feat(infrastructure): add internal auth middleware for service-to-service communication"
```

---

### Task 2: Crawler Internal Fetch Endpoint (North Cloud)

Add `POST /api/internal/v1/fetch` to the crawler service. Accepts a URL, fetches it, extracts content, returns structured data.

**Files:**
- Create: `north-cloud/crawler/internal/api/internal_handler.go`
- Create: `north-cloud/crawler/internal/api/internal_handler_test.go`
- Modify: `north-cloud/crawler/internal/api/api.go` — add `setupInternalRoutes()`
- Modify: `north-cloud/crawler/internal/api/api.go:NewServer()` — wire internal routes
- Modify: `north-cloud/crawler/internal/config/config.go` — add `InternalSecret` field

**Step 1: Add config field**

In `crawler/internal/config/config.go`, add to the auth/server config struct:

```go
InternalSecret string `yaml:"internal_secret" env:"INTERNAL_SECRET"`
```

**Step 2: Write the internal handler**

```go
// internal_handler.go
package api

import (
	"context"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/PuerkitoBio/goquery"
	"github.com/gin-gonic/gin"
	infralogger "github.com/north-cloud/infrastructure/logger"
)

const (
	defaultFetchTimeout = 15 * time.Second
	maxFetchTimeout     = 30 * time.Second
	maxBodySize         = 10 * 1024 * 1024 // 10MB
)

// InternalHandler handles internal API requests from PipelineX.
type InternalHandler struct {
	httpClient *http.Client
	logger     infralogger.Logger
}

// NewInternalHandler creates a new internal handler.
func NewInternalHandler(logger infralogger.Logger) *InternalHandler {
	return &InternalHandler{
		httpClient: &http.Client{Timeout: maxFetchTimeout},
		logger:     logger,
	}
}

// FetchRequest is the request body for POST /api/internal/v1/fetch.
type FetchRequest struct {
	URL     string `binding:"required,url" json:"url"`
	Timeout int    `json:"timeout"` // seconds, 0 = default (15s)
}

// FetchResponse is the response body for POST /api/internal/v1/fetch.
type FetchResponse struct {
	URL         string            `json:"url"`
	FinalURL    string            `json:"final_url"`
	StatusCode  int               `json:"status_code"`
	ContentType string            `json:"content_type"`
	HTML        string            `json:"html"`
	Title       string            `json:"title"`
	Body        string            `json:"body"`
	Author      string            `json:"author"`
	Description string            `json:"description"`
	OG          map[string]string `json:"og"`
	DurationMs  int64             `json:"duration_ms"`
}

// Fetch handles POST /api/internal/v1/fetch.
func (h *InternalHandler) Fetch(c *gin.Context) {
	var req FetchRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}

	timeout := defaultFetchTimeout
	if req.Timeout > 0 {
		timeout = time.Duration(req.Timeout) * time.Second
		if timeout > maxFetchTimeout {
			timeout = maxFetchTimeout
		}
	}

	start := time.Now()

	ctx, cancel := context.WithTimeout(c.Request.Context(), timeout)
	defer cancel()

	httpReq, err := http.NewRequestWithContext(ctx, http.MethodGet, req.URL, http.NoBody)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "invalid url"})
		return
	}
	httpReq.Header.Set("User-Agent", "PipelineX/1.0 (compatible; crawler)")

	resp, err := h.httpClient.Do(httpReq)
	if err != nil {
		h.logger.Warn("Fetch failed", infralogger.String("url", req.URL), infralogger.Error(err))
		c.JSON(http.StatusBadGateway, gin.H{"error": "fetch failed: " + err.Error()})
		return
	}
	defer resp.Body.Close()

	bodyBytes, err := io.ReadAll(io.LimitReader(resp.Body, maxBodySize))
	if err != nil {
		c.JSON(http.StatusBadGateway, gin.H{"error": "read body failed"})
		return
	}

	// Parse HTML and extract content
	doc, parseErr := goquery.NewDocumentFromReader(strings.NewReader(string(bodyBytes)))

	result := &FetchResponse{
		URL:         req.URL,
		FinalURL:    resp.Request.URL.String(),
		StatusCode:  resp.StatusCode,
		ContentType: resp.Header.Get("Content-Type"),
		HTML:        string(bodyBytes),
		DurationMs:  time.Since(start).Milliseconds(),
	}

	if parseErr == nil {
		result.Title = extractTitle(doc)
		result.Body = extractCleanBody(doc)
		result.Author = extractAuthor(doc)
		result.Description = extractDescription(doc)
		result.OG = extractOpenGraph(doc)
	}

	c.JSON(http.StatusOK, result)
}

func extractTitle(doc *goquery.Document) string {
	if t := strings.TrimSpace(doc.Find("title").First().Text()); t != "" {
		return t
	}
	if og, exists := doc.Find("meta[property='og:title']").Attr("content"); exists {
		return strings.TrimSpace(og)
	}
	return ""
}

func extractCleanBody(doc *goquery.Document) string {
	// Clone to avoid modifying original
	clone := doc.Clone()
	clone.Find("script, style, nav, header, footer, aside, .sidebar, .menu, .nav").Remove()

	if article := clone.Find("article").First(); article.Length() > 0 {
		return strings.TrimSpace(article.Text())
	}
	if main := clone.Find("main").First(); main.Length() > 0 {
		return strings.TrimSpace(main.Text())
	}
	if body := clone.Find("body").First(); body.Length() > 0 {
		return strings.TrimSpace(body.Text())
	}
	return ""
}

func extractAuthor(doc *goquery.Document) string {
	if a, exists := doc.Find("meta[name='author']").Attr("content"); exists {
		return strings.TrimSpace(a)
	}
	return ""
}

func extractDescription(doc *goquery.Document) string {
	if d, exists := doc.Find("meta[name='description']").Attr("content"); exists {
		return strings.TrimSpace(d)
	}
	if d, exists := doc.Find("meta[property='og:description']").Attr("content"); exists {
		return strings.TrimSpace(d)
	}
	return ""
}

func extractOpenGraph(doc *goquery.Document) map[string]string {
	og := make(map[string]string)
	doc.Find("meta[property^='og:']").Each(func(_ int, s *goquery.Selection) {
		if prop, exists := s.Attr("property"); exists {
			if content, cExists := s.Attr("content"); cExists {
				key := strings.TrimPrefix(prop, "og:")
				og[key] = strings.TrimSpace(content)
			}
		}
	})
	return og
}
```

**Step 3: Wire into router**

In `crawler/internal/api/api.go`, add setup function and modify `setupCrawlerRoutes`:

```go
// Add to setupCrawlerRoutes, after existing routes:
func setupInternalRoutes(router *gin.Engine, internalSecret string, internalHandler *InternalHandler) {
	if internalHandler == nil {
		return
	}
	internal := router.Group("/api/internal/v1")
	if internalSecret != "" {
		internal.Use(infragin.InternalAuthMiddleware(internalSecret))
	}
	internal.POST("/fetch", internalHandler.Fetch)
}
```

Add `internalHandler *InternalHandler` parameter to `NewServer()` and call `setupInternalRoutes` inside the `WithRoutes` callback. Get `internalSecret` from config.

**Step 4: Write test**

Test the handler with a mock HTTP server that returns known HTML, verify extraction.

**Step 5: Run tests, commit**

```bash
cd /home/fsd42/dev/north-cloud
go test ./crawler/internal/api/ -run TestInternalFetch -v
git add crawler/
git commit -m "feat(crawler): add internal fetch endpoint for PipelineX"
```

---

### Task 3: Classifier Internal Extract Endpoint (North Cloud)

Add `POST /api/internal/v1/extract` to the classifier. Accepts raw HTML + URL, runs general-purpose classification, returns structured JSON.

**Files:**
- Create: `north-cloud/classifier/internal/api/internal_handler.go`
- Create: `north-cloud/classifier/internal/api/internal_handler_test.go`
- Modify: `north-cloud/classifier/internal/api/routes.go` — add internal routes
- Modify: `north-cloud/classifier/internal/config/config.go` — add `InternalSecret`

**Step 1: Write the internal handler**

```go
// internal_handler.go
package api

import (
	"net/http"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/jonesrussell/north-cloud/classifier/internal/domain"
	infralogger "github.com/north-cloud/infrastructure/logger"
)

// InternalExtractRequest is the request body for POST /api/internal/v1/extract.
type InternalExtractRequest struct {
	HTML       string `binding:"required" json:"html"`
	URL        string `binding:"required" json:"url"`
	SourceName string `json:"source_name"`
	Title      string `json:"title"`
}

// InternalExtractResponse is the general-purpose extraction response.
// Excludes domain-specific classifiers (crime, mining, etc.)
type InternalExtractResponse struct {
	Title         string            `json:"title"`
	Author        string            `json:"author,omitempty"`
	PublishedDate *time.Time        `json:"published_date,omitempty"`
	Body          string            `json:"body"`
	WordCount     int               `json:"word_count"`
	QualityScore  int               `json:"quality_score"`
	Topics        []string          `json:"topics"`
	TopicScores   map[string]float64 `json:"topic_scores,omitempty"`
	ContentType   string            `json:"content_type"`
	OG            map[string]string `json:"og"`
	Error         string            `json:"error,omitempty"`
}

// InternalExtract handles POST /api/internal/v1/extract.
// Runs general-purpose classification without domain-specific classifiers.
func (h *Handler) InternalExtract(c *gin.Context) {
	var req InternalExtractRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}

	// Build RawContent from request
	raw := &domain.RawContent{
		ID:                   "pipelinex-extract",
		URL:                  req.URL,
		SourceName:           req.SourceName,
		Title:                req.Title,
		RawHTML:              req.HTML,
		RawText:              req.HTML, // Classifier handles HTML→text
		CrawledAt:            time.Now(),
		ClassificationStatus: domain.StatusPending,
	}

	result, err := h.classifier.Classify(c.Request.Context(), raw)
	if err != nil {
		h.logger.Error("Internal extract failed",
			infralogger.String("url", req.URL),
			infralogger.Error(err),
		)
		c.JSON(http.StatusInternalServerError, InternalExtractResponse{
			Error: "classification failed: " + err.Error(),
		})
		return
	}

	// Build OG map from result
	og := make(map[string]string)
	if raw.OGTitle != "" {
		og["title"] = raw.OGTitle
	}
	if raw.OGDescription != "" {
		og["description"] = raw.OGDescription
	}
	if raw.OGImage != "" {
		og["image"] = raw.OGImage
	}
	if raw.OGURL != "" {
		og["url"] = raw.OGURL
	}

	resp := InternalExtractResponse{
		Title:        result.ContentID, // Will be set from extraction
		Body:         raw.RawText,
		WordCount:    raw.WordCount,
		QualityScore: result.QualityScore,
		Topics:       result.Topics,
		TopicScores:  result.TopicScores,
		ContentType:  result.ContentType,
		OG:           og,
	}

	// Use extracted title if available
	if raw.Title != "" {
		resp.Title = raw.Title
	}

	c.JSON(http.StatusOK, resp)
}
```

**Step 2: Wire into routes**

In `classifier/internal/api/routes.go`, add after existing v1 routes:

```go
// Internal routes for PipelineX (shared secret auth, not JWT)
internal := router.Group("/api/internal/v1")
if cfg != nil && cfg.Auth.InternalSecret != "" {
	internal.Use(infragin.InternalAuthMiddleware(cfg.Auth.InternalSecret))
}
internal.POST("/extract", handler.InternalExtract)
```

**Step 3: Test and commit**

```bash
cd /home/fsd42/dev/north-cloud
go test ./classifier/internal/api/ -run TestInternalExtract -v
git add classifier/ infrastructure/
git commit -m "feat(classifier): add internal extract endpoint for PipelineX"
```

---

## Phase 2: PipelineX Database Foundation

### Task 4: Switch to PostgreSQL + Create Tenant/ApiKey Migrations

Switch from SQLite to PostgreSQL for production readiness. Create the first migrations.

**Files:**
- Modify: `pipelinex/.env` — update DB_CONNECTION
- Modify: `pipelinex/.env.example` — update DB_CONNECTION
- Create: `pipelinex/database/migrations/2026_02_24_000001_create_tenants_table.php`
- Create: `pipelinex/database/migrations/2026_02_24_000002_create_api_keys_table.php`

**Step 1: Update .env for PostgreSQL**

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pipelinex
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

Also update Redis config:
```
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

**Step 2: Create tenants migration**

```php
// 2026_02_24_000001_create_tenants_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan')->default('free');
            $table->integer('monthly_crawl_limit')->default(100);
            $table->integer('rate_limit_rpm')->default(5);
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
```

**Step 3: Create api_keys migration**

```php
// 2026_02_24_000002_create_api_keys_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key_hash', 64)->unique();
            $table->string('key_prefix', 20);
            $table->string('environment', 10)->default('live');
            $table->string('name');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
```

**Step 4: Run migrations**

Run: `cd /home/fsd42/dev/pipelinex && php artisan migrate`
Expected: Migrations run successfully

**Step 5: Commit**

```bash
git add database/migrations/ .env.example
git commit -m "feat: add tenants and api_keys migrations, switch to PostgreSQL"
```

---

### Task 5: Create CrawlJob/CrawlResult/UsageRecord Migrations

**Files:**
- Create: `pipelinex/database/migrations/2026_02_24_000003_create_crawl_jobs_table.php`
- Create: `pipelinex/database/migrations/2026_02_24_000004_create_crawl_results_table.php`
- Create: `pipelinex/database/migrations/2026_02_24_000005_create_usage_records_table.php`

**Step 1: Create crawl_jobs migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_jobs', function (Blueprint $table) {
            $table->string('id', 18)->primary(); // crawl_ + 12 chars
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('api_key_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->text('final_url')->nullable();
            $table->string('status', 20)->default('processing');
            $table->jsonb('options')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->smallInteger('http_status_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_jobs');
    }
};
```

**Step 2: Create crawl_results migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('crawl_job_id', 18)->unique();
            $table->foreign('crawl_job_id')->references('id')->on('crawl_jobs')->cascadeOnDelete();
            $table->text('title')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_date')->nullable();
            $table->text('body');
            $table->integer('word_count')->default(0);
            $table->smallInteger('quality_score')->default(0);
            $table->jsonb('topics')->default('[]');
            $table->jsonb('og')->default('{}');
            $table->jsonb('links')->default('[]');
            $table->jsonb('images')->default('[]');
            $table->text('raw_html')->nullable();
            $table->string('content_type', 50)->default('text/html');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_results');
    }
};
```

**Step 3: Create usage_records migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->integer('crawls_count')->default(0);
            $table->integer('crawls_succeeded')->default(0);
            $table->integer('crawls_failed')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
```

**Step 4: Run migrations and commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan migrate
git add database/migrations/
git commit -m "feat: add crawl_jobs, crawl_results, usage_records migrations"
```

---

### Task 6: Create Eloquent Models

**Files:**
- Create: `pipelinex/app/Models/Tenant.php`
- Create: `pipelinex/app/Models/ApiKey.php`
- Create: `pipelinex/app/Models/CrawlJob.php`
- Create: `pipelinex/app/Models/CrawlResult.php`
- Create: `pipelinex/app/Models/UsageRecord.php`
- Modify: `pipelinex/app/Models/User.php` — add tenant relationship
- Create: `pipelinex/database/factories/TenantFactory.php`
- Create: `pipelinex/database/factories/ApiKeyFactory.php`
- Create: `pipelinex/database/factories/CrawlJobFactory.php`
- Test: `pipelinex/tests/Feature/Models/TenantTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Models/TenantTest.php
<?php

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\User;

test('tenant is created when user registers', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    expect($tenant)->toBeInstanceOf(Tenant::class);
    expect($tenant->plan)->toBe('free');
    expect($tenant->monthly_crawl_limit)->toBe(100);
    expect($tenant->rate_limit_rpm)->toBe(5);
});

test('tenant can generate api key', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    $result = $tenant->generateApiKey('Test Key', 'live');

    expect($result['key'])->toStartWith('px_live_');
    expect($result['apiKey'])->toBeInstanceOf(ApiKey::class);
    expect($result['apiKey']->name)->toBe('Test Key');
});

test('api key can be verified', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;

    $result = $tenant->generateApiKey('Test Key', 'live');
    $rawKey = $result['key'];

    $found = ApiKey::findByRawKey($rawKey);

    expect($found)->not->toBeNull();
    expect($found->tenant_id)->toBe($tenant->id);
});
```

**Step 2: Run test to verify it fails**

Run: `cd /home/fsd42/dev/pipelinex && php artisan test tests/Feature/Models/TenantTest.php`
Expected: FAIL — classes not found

**Step 3: Write models**

```php
// app/Models/Tenant.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'plan',
        'monthly_crawl_limit',
        'rate_limit_rpm',
        'webhook_url',
        'webhook_secret',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function crawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /**
     * Generate a new API key for this tenant.
     *
     * @return array{key: string, apiKey: ApiKey}
     */
    public function generateApiKey(string $name, string $environment = 'live'): array
    {
        $rawKey = 'px_' . $environment . '_' . Str::random(32);
        $hash = hash('sha256', $rawKey);
        $prefix = substr($rawKey, 0, 8) . '...' . substr($rawKey, -4);

        $apiKey = $this->apiKeys()->create([
            'key_hash' => $hash,
            'key_prefix' => $prefix,
            'environment' => $environment,
            'name' => $name,
        ]);

        return ['key' => $rawKey, 'apiKey' => $apiKey];
    }
}
```

```php
// app/Models/ApiKey.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'key_hash',
        'key_prefix',
        'environment',
        'name',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public static function findByRawKey(string $rawKey): ?self
    {
        $hash = hash('sha256', $rawKey);

        return self::where('key_hash', $hash)
            ->whereNull('revoked_at')
            ->first();
    }

    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
```

```php
// app/Models/CrawlJob.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class CrawlJob extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'api_key_id',
        'url',
        'final_url',
        'status',
        'options',
        'error_code',
        'error_message',
        'http_status_code',
        'duration_ms',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $job) {
            if (! $job->id) {
                $job->id = 'crawl_' . Str::random(12);
            }
            if (! $job->expires_at) {
                $job->expires_at = now()->addDays(30);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(CrawlResult::class);
    }

    public function markCompleted(int $statusCode, int $durationMs, ?string $finalUrl = null): void
    {
        $this->update([
            'status' => 'completed',
            'http_status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'final_url' => $finalUrl,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $errorCode, string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}
```

```php
// app/Models/CrawlResult.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlResult extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'crawl_job_id',
        'title',
        'author',
        'published_date',
        'body',
        'word_count',
        'quality_score',
        'topics',
        'og',
        'links',
        'images',
        'raw_html',
        'content_type',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'published_date' => 'datetime',
            'topics' => 'array',
            'og' => 'array',
            'links' => 'array',
            'images' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $result) {
            $result->created_at = $result->created_at ?? now();
        });
    }

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }
}
```

```php
// app/Models/UsageRecord.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'date',
        'crawls_count',
        'crawls_succeeded',
        'crawls_failed',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

**Step 4: Add tenant relationship to User model and auto-create on registration**

In `app/Models/User.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;

public function tenant(): HasOne
{
    return $this->hasOne(Tenant::class);
}
```

In `app/Actions/Fortify/CreateNewUser.php`, modify `create()` to auto-create tenant:

```php
public function create(array $input): User
{
    Validator::make($input, [
        ...$this->profileRules(),
        'password' => $this->passwordRules(),
    ])->validate();

    $user = User::create([
        'name' => $input['name'],
        'email' => $input['email'],
        'password' => $input['password'],
    ]);

    $user->tenant()->create([
        'plan' => 'free',
        'monthly_crawl_limit' => 100,
        'rate_limit_rpm' => 5,
    ]);

    return $user;
}
```

**Step 5: Create factories**

```php
// database/factories/TenantFactory.php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => 'free',
            'monthly_crawl_limit' => 100,
            'rate_limit_rpm' => 5,
        ];
    }

    public function starter(): static
    {
        return $this->state(['plan' => 'starter', 'monthly_crawl_limit' => 1000, 'rate_limit_rpm' => 20]);
    }

    public function pro(): static
    {
        return $this->state(['plan' => 'pro', 'monthly_crawl_limit' => 10000, 'rate_limit_rpm' => 60]);
    }
}
```

**Step 6: Run tests, commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan test tests/Feature/Models/TenantTest.php
git add app/Models/ app/Actions/ database/factories/ tests/Feature/Models/
git commit -m "feat: add Tenant, ApiKey, CrawlJob, CrawlResult, UsageRecord models"
```

---

## Phase 3: PipelineX API Infrastructure

### Task 7: API Key Authentication Middleware

Create middleware that authenticates API requests via `Authorization: Bearer px_...` header.

**Files:**
- Create: `pipelinex/app/Http/Middleware/AuthenticateApiKey.php`
- Test: `pipelinex/tests/Feature/Middleware/AuthenticateApiKeyTest.php`

**Step 1: Write failing test**

```php
// tests/Feature/Middleware/AuthenticateApiKeyTest.php
<?php

use App\Models\User;

test('rejects request without api key', function () {
    $this->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'missing_api_key']);
});

test('rejects request with invalid api key', function () {
    $this->withHeader('Authorization', 'Bearer px_live_invalidkey123')
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'invalid_api_key']);
});

test('rejects request with revoked api key', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $result = $tenant->generateApiKey('Test', 'live');
    $result['apiKey']->update(['revoked_at' => now()]);

    $this->withHeader('Authorization', 'Bearer ' . $result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertUnauthorized();
});

test('accepts request with valid api key', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $result = $tenant->generateApiKey('Test', 'live');

    // This will fail with 422 (validation) not 401 (auth) — proving auth passed
    $this->withHeader('Authorization', 'Bearer ' . $result['key'])
        ->postJson('/api/v1/crawl', [])
        ->assertStatus(422);
});
```

**Step 2: Write middleware**

```php
// app/Http/Middleware/AuthenticateApiKey.php
<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer || ! str_starts_with($bearer, 'px_')) {
            return response()->json(['error' => 'missing_api_key', 'message' => 'Provide API key via Authorization: Bearer px_...'], 401);
        }

        $apiKey = ApiKey::findByRawKey($bearer);

        if (! $apiKey) {
            return response()->json(['error' => 'invalid_api_key', 'message' => 'API key not found or revoked'], 401);
        }

        $apiKey->touchLastUsed();

        $request->merge([
            'tenant' => $apiKey->tenant,
            'api_key' => $apiKey,
        ]);

        return $next($request);
    }
}
```

**Step 3: Register API routes**

Create `routes/api.php`:

```php
<?php

use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(AuthenticateApiKey::class)->group(function () {
    Route::post('/crawl', [\App\Http\Controllers\Api\CrawlController::class, 'store']);
    Route::get('/crawl/{id}', [\App\Http\Controllers\Api\CrawlController::class, 'show']);
    Route::get('/usage', [\App\Http\Controllers\Api\UsageController::class, 'index']);
});
```

Register in `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

**Step 4: Run tests, commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan test tests/Feature/Middleware/AuthenticateApiKeyTest.php
git add app/Http/Middleware/ routes/api.php bootstrap/app.php tests/Feature/Middleware/
git commit -m "feat: add API key authentication middleware and API routes"
```

---

### Task 8: Rate Limiting + Quota Enforcement Middleware

**Files:**
- Create: `pipelinex/app/Http/Middleware/RateLimitApiKey.php`
- Create: `pipelinex/app/Http/Middleware/EnforceQuota.php`
- Test: `pipelinex/tests/Feature/Middleware/RateLimitApiKeyTest.php`
- Test: `pipelinex/tests/Feature/Middleware/EnforceQuotaTest.php`

**Step 1: Write rate limit middleware**

```php
// app/Http/Middleware/RateLimitApiKey.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class RateLimitApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->get('tenant');
        $rpm = $tenant->rate_limit_rpm;
        $key = 'rate_limit:' . $tenant->id;

        $current = (int) Redis::get($key);

        if ($current >= $rpm) {
            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many requests. Retry after 60 seconds.',
                'retry_after' => 60,
            ], 429)->withHeaders([
                'X-RateLimit-Limit' => $rpm,
                'X-RateLimit-Remaining' => 0,
                'Retry-After' => 60,
            ]);
        }

        Redis::incr($key);
        if ($current === 0) {
            Redis::expire($key, 60);
        }

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $rpm);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $rpm - $current - 1));

        return $response;
    }
}
```

**Step 2: Write quota enforcement middleware**

```php
// app/Http/Middleware/EnforceQuota.php
<?php

namespace App\Http\Middleware;

use App\Models\CrawlJob;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->get('tenant');
        $limit = $tenant->monthly_crawl_limit;

        $used = CrawlJob::where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        if ($used >= $limit) {
            return response()->json([
                'error' => 'quota_exceeded',
                'message' => 'Monthly crawl limit reached. Upgrade your plan.',
                'limit' => $limit,
                'used' => $used,
            ], 402);
        }

        return $next($request);
    }
}
```

**Step 3: Add middleware to crawl route**

In `routes/api.php`, update the crawl route:

```php
Route::post('/crawl', [\App\Http\Controllers\Api\CrawlController::class, 'store'])
    ->middleware([RateLimitApiKey::class, EnforceQuota::class]);
```

**Step 4: Test and commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan test tests/Feature/Middleware/
git add app/Http/Middleware/ routes/api.php tests/Feature/Middleware/
git commit -m "feat: add rate limiting and quota enforcement middleware"
```

---

### Task 9: North Cloud HTTP Client Service

A Laravel service that communicates with North Cloud's internal endpoints.

**Files:**
- Create: `pipelinex/app/Services/NorthCloudClient.php`
- Test: `pipelinex/tests/Feature/Services/NorthCloudClientTest.php`

**Step 1: Write the service**

```php
// app/Services/NorthCloudClient.php
<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class NorthCloudClient
{
    private string $crawlerUrl;
    private string $classifierUrl;
    private string $internalSecret;

    public function __construct()
    {
        $this->crawlerUrl = config('services.north_cloud.crawler_url');
        $this->classifierUrl = config('services.north_cloud.classifier_url');
        $this->internalSecret = config('services.north_cloud.internal_secret');
    }

    /**
     * Fetch a URL via North Cloud's crawler.
     *
     * @return array{url: string, final_url: string, status_code: int, content_type: string, html: string, title: string, body: string, author: string, description: string, og: array, duration_ms: int}
     *
     * @throws ConnectionException|RequestException
     */
    public function fetch(string $url, int $timeout = 15): array
    {
        $response = Http::withHeaders(['X-Internal-Secret' => $this->internalSecret])
            ->timeout($timeout + 5) // Buffer above crawl timeout
            ->post($this->crawlerUrl . '/api/internal/v1/fetch', [
                'url' => $url,
                'timeout' => $timeout,
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Extract structured data from HTML via North Cloud's classifier.
     *
     * @return array{title: string, body: string, word_count: int, quality_score: int, topics: string[], og: array}
     *
     * @throws ConnectionException|RequestException
     */
    public function extract(string $html, string $url, ?string $title = null): array
    {
        $response = Http::withHeaders(['X-Internal-Secret' => $this->internalSecret])
            ->timeout(30)
            ->post($this->classifierUrl . '/api/internal/v1/extract', [
                'html' => $html,
                'url' => $url,
                'title' => $title,
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Check if North Cloud services are healthy.
     */
    public function healthy(): bool
    {
        try {
            $crawler = Http::timeout(5)->get($this->crawlerUrl . '/health');
            $classifier = Http::timeout(5)->get($this->classifierUrl . '/health');

            return $crawler->ok() && $classifier->ok();
        } catch (\Throwable) {
            return false;
        }
    }
}
```

**Step 2: Add config**

In `config/services.php`, add:

```php
'north_cloud' => [
    'crawler_url' => env('NORTH_CLOUD_CRAWLER_URL', 'http://localhost:8060'),
    'classifier_url' => env('NORTH_CLOUD_CLASSIFIER_URL', 'http://localhost:8071'),
    'internal_secret' => env('NORTH_CLOUD_INTERNAL_SECRET', ''),
],
```

**Step 3: Add env vars to `.env` and `.env.example`**

```
NORTH_CLOUD_CRAWLER_URL=http://localhost:8060
NORTH_CLOUD_CLASSIFIER_URL=http://localhost:8071
NORTH_CLOUD_INTERNAL_SECRET=your-shared-secret-here
```

**Step 4: Test with Http::fake() and commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan test tests/Feature/Services/NorthCloudClientTest.php
git add app/Services/ config/services.php .env.example tests/Feature/Services/
git commit -m "feat: add NorthCloudClient service for crawler/classifier communication"
```

---

## Phase 4: Crawl API

### Task 10: POST /api/v1/crawl Controller + ProcessCrawlJob

The core crawl endpoint and the queue job that processes it.

**Files:**
- Create: `pipelinex/app/Http/Controllers/Api/CrawlController.php`
- Create: `pipelinex/app/Http/Requests/Api/CrawlRequest.php`
- Create: `pipelinex/app/Jobs/ProcessCrawlJob.php`
- Test: `pipelinex/tests/Feature/Api/CrawlTest.php`

**Step 1: Write the form request**

```php
// app/Http/Requests/Api/CrawlRequest.php
<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CrawlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by middleware
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:2048'],
            'options' => ['sometimes', 'array'],
            'options.timeout' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'options.wait_for_js' => ['sometimes', 'boolean'],
            'options.include_html' => ['sometimes', 'boolean'],
            'options.include_links' => ['sometimes', 'boolean'],
            'options.include_images' => ['sometimes', 'boolean'],
        ];
    }
}
```

**Step 2: Write the queue job**

```php
// app/Jobs/ProcessCrawlJob.php
<?php

namespace App\Jobs;

use App\Models\CrawlJob;
use App\Models\CrawlResult;
use App\Services\NorthCloudClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class ProcessCrawlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 45;

    public function __construct(
        public string $crawlJobId,
    ) {}

    public function handle(NorthCloudClient $nc): void
    {
        $crawlJob = CrawlJob::findOrFail($this->crawlJobId);
        $options = $crawlJob->options ?? [];
        $start = now();

        try {
            // Step 1: Fetch URL via North Cloud crawler
            $fetchResult = $nc->fetch(
                $crawlJob->url,
                $options['timeout'] ?? 15,
            );

            // Step 2: Extract structured data via North Cloud classifier
            $extractResult = $nc->extract(
                $fetchResult['html'],
                $crawlJob->url,
                $fetchResult['title'] ?? null,
            );

            $durationMs = (int) $start->diffInMilliseconds(now());

            // Step 3: Store result
            $crawlJob->markCompleted(
                $fetchResult['status_code'],
                $durationMs,
                $fetchResult['final_url'] ?? null,
            );

            CrawlResult::create([
                'crawl_job_id' => $crawlJob->id,
                'title' => $extractResult['title'] ?? $fetchResult['title'] ?? null,
                'author' => $fetchResult['author'] ?? null,
                'body' => $extractResult['body'] ?? '',
                'word_count' => $extractResult['word_count'] ?? 0,
                'quality_score' => $extractResult['quality_score'] ?? 0,
                'topics' => $extractResult['topics'] ?? [],
                'og' => $fetchResult['og'] ?? [],
                'links' => [], // Extracted from HTML if include_links
                'images' => [], // Extracted from HTML if include_images
                'raw_html' => ($options['include_html'] ?? false) ? $fetchResult['html'] : null,
                'content_type' => $fetchResult['content_type'] ?? 'text/html',
            ]);

            // Step 4: Notify waiting sync request via Redis pub/sub
            $this->publishResult($crawlJob);

        } catch (\Throwable $e) {
            $crawlJob->markFailed(
                'processing_error',
                $e->getMessage(),
            );

            // Notify waiting sync request of failure
            $this->publishResult($crawlJob);

            throw $e;
        }
    }

    private function publishResult(CrawlJob $crawlJob): void
    {
        $crawlJob->load('result');

        Redis::publish('crawl:' . $crawlJob->id, json_encode([
            'id' => $crawlJob->id,
            'status' => $crawlJob->status,
        ]));
    }
}
```

**Step 3: Write the controller**

```php
// app/Http/Controllers/Api/CrawlController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CrawlRequest;
use App\Jobs\ProcessCrawlJob;
use App\Models\CrawlJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;

class CrawlController extends Controller
{
    public function store(CrawlRequest $request): JsonResponse
    {
        $tenant = $request->get('tenant');
        $apiKey = $request->get('api_key');

        $crawlJob = CrawlJob::create([
            'tenant_id' => $tenant->id,
            'api_key_id' => $apiKey->id,
            'url' => $request->url,
            'status' => 'processing',
            'options' => $request->options ?? [],
        ]);

        ProcessCrawlJob::dispatch($crawlJob->id);

        // Sync-wait: subscribe to Redis and wait up to 30s for result
        $result = $this->waitForResult($crawlJob->id, 30);

        if ($result) {
            $crawlJob->refresh();
            $crawlJob->load('result');

            if ($crawlJob->status === 'completed' && $crawlJob->result) {
                return response()->json($this->formatResponse($crawlJob), 200);
            }

            if ($crawlJob->status === 'failed') {
                return response()->json([
                    'id' => $crawlJob->id,
                    'status' => 'failed',
                    'error' => [
                        'code' => $crawlJob->error_code,
                        'message' => $crawlJob->error_message,
                    ],
                ], 502);
            }
        }

        // Async fallback: return 202 with poll URL
        return response()->json([
            'id' => $crawlJob->id,
            'status' => 'processing',
            'url' => $crawlJob->url,
            'poll_url' => '/api/v1/crawl/' . $crawlJob->id,
        ], 202);
    }

    public function show(string $id): JsonResponse
    {
        $crawlJob = CrawlJob::with('result')->findOrFail($id);

        // Verify tenant ownership
        $tenant = request()->get('tenant');
        if ($crawlJob->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'not_found', 'message' => 'Crawl result not found or expired'], 404);
        }

        if ($crawlJob->status === 'processing') {
            return response()->json([
                'id' => $crawlJob->id,
                'status' => 'processing',
                'url' => $crawlJob->url,
                'poll_url' => '/api/v1/crawl/' . $crawlJob->id,
            ], 200);
        }

        if ($crawlJob->status === 'failed') {
            return response()->json([
                'id' => $crawlJob->id,
                'status' => 'failed',
                'url' => $crawlJob->url,
                'error' => [
                    'code' => $crawlJob->error_code,
                    'message' => $crawlJob->error_message,
                ],
            ], 200);
        }

        return response()->json($this->formatResponse($crawlJob), 200);
    }

    private function waitForResult(string $jobId, int $timeoutSeconds): bool
    {
        $channel = 'crawl:' . $jobId;

        try {
            // Use Redis SUBSCRIBE with timeout
            // Laravel's Redis doesn't support subscribe with timeout natively,
            // so we poll instead (simpler, reliable)
            $deadline = now()->addSeconds($timeoutSeconds);

            while (now()->lt($deadline)) {
                $job = CrawlJob::find($jobId);
                if ($job && $job->status !== 'processing') {
                    return true;
                }
                usleep(250_000); // 250ms
            }
        } catch (\Throwable) {
            // Timeout or error — fall through to async
        }

        return false;
    }

    private function formatResponse(CrawlJob $crawlJob): array
    {
        $result = $crawlJob->result;

        return [
            'id' => $crawlJob->id,
            'status' => $crawlJob->status,
            'url' => $crawlJob->url,
            'final_url' => $crawlJob->final_url,
            'data' => [
                'title' => $result->title,
                'author' => $result->author,
                'published_date' => $result->published_date?->toISOString(),
                'body' => $result->body,
                'word_count' => $result->word_count,
                'quality_score' => $result->quality_score,
                'topics' => $result->topics,
                'og' => $result->og,
                'links' => $result->links,
                'images' => $result->images,
            ],
            'meta' => [
                'status_code' => $crawlJob->http_status_code,
                'content_type' => $result->content_type,
                'crawled_at' => $crawlJob->completed_at?->toISOString(),
                'duration_ms' => $crawlJob->duration_ms,
            ],
        ];
    }
}
```

**Step 4: Write feature test**

```php
// tests/Feature/Api/CrawlTest.php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('POST /api/v1/crawl requires api key', function () {
    $this->postJson('/api/v1/crawl', ['url' => 'https://example.com'])
        ->assertUnauthorized();
});

test('POST /api/v1/crawl validates url', function () {
    $user = User::factory()->create();
    $tenant = $user->tenant;
    $result = $tenant->generateApiKey('Test', 'live');

    $this->withHeader('Authorization', 'Bearer ' . $result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'not-a-url'])
        ->assertUnprocessable();
});

test('POST /api/v1/crawl dispatches job and returns 202', function () {
    Queue::fake();

    $user = User::factory()->create();
    $tenant = $user->tenant;
    $result = $tenant->generateApiKey('Test', 'live');

    $response = $this->withHeader('Authorization', 'Bearer ' . $result['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com/article']);

    // With queue faked, job won't run, so we get 202 async fallback
    $response->assertStatus(202)
        ->assertJsonStructure(['id', 'status', 'poll_url']);

    Queue::assertPushed(\App\Jobs\ProcessCrawlJob::class);
});
```

**Step 5: Run tests, commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan test tests/Feature/Api/CrawlTest.php
git add app/Http/Controllers/Api/ app/Http/Requests/Api/ app/Jobs/ tests/Feature/Api/
git commit -m "feat: add POST /api/v1/crawl endpoint with ProcessCrawlJob"
```

---

### Task 11: GET /api/v1/usage Endpoint

**Files:**
- Create: `pipelinex/app/Http/Controllers/Api/UsageController.php`
- Test: `pipelinex/tests/Feature/Api/UsageTest.php`

**Step 1: Write the controller**

```php
// app/Http/Controllers/Api/UsageController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrawlJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class UsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->get('tenant');

        $used = CrawlJob::where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $rateLimitKey = 'rate_limit:' . $tenant->id;
        $currentRate = (int) Redis::get($rateLimitKey);

        return response()->json([
            'plan' => $tenant->plan,
            'period' => [
                'start' => now()->startOfMonth()->toISOString(),
                'end' => now()->endOfMonth()->toISOString(),
            ],
            'crawls' => [
                'used' => $used,
                'limit' => $tenant->monthly_crawl_limit,
                'remaining' => max(0, $tenant->monthly_crawl_limit - $used),
            ],
            'rate_limit' => [
                'requests_per_minute' => $tenant->rate_limit_rpm,
                'current' => $currentRate,
            ],
        ]);
    }
}
```

**Step 2: Test and commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan test tests/Feature/Api/UsageTest.php
git add app/Http/Controllers/Api/UsageController.php tests/Feature/Api/
git commit -m "feat: add GET /api/v1/usage endpoint"
```

---

## Phase 5: Dashboard Pages

### Task 12: Dashboard Navigation + Layout Updates

Update sidebar navigation to include PipelineX dashboard pages.

**Files:**
- Modify: `pipelinex/resources/js/components/AppSidebar.vue` — add nav items
- Modify: `pipelinex/routes/web.php` — add dashboard routes

**Step 1: Add web routes for dashboard pages**

In `routes/web.php`, add:

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('dashboard/crawl', function () {
        return Inertia::render('dashboard/CrawlPlayground');
    })->name('dashboard.crawl');

    Route::get('dashboard/history', function () {
        return Inertia::render('dashboard/History');
    })->name('dashboard.history');

    Route::get('dashboard/api-keys', [\App\Http\Controllers\Dashboard\ApiKeyController::class, 'index'])
        ->name('dashboard.api-keys');

    Route::post('dashboard/api-keys', [\App\Http\Controllers\Dashboard\ApiKeyController::class, 'store'])
        ->name('dashboard.api-keys.store');

    Route::delete('dashboard/api-keys/{apiKey}', [\App\Http\Controllers\Dashboard\ApiKeyController::class, 'destroy'])
        ->name('dashboard.api-keys.destroy');

    Route::get('dashboard/usage', [\App\Http\Controllers\Dashboard\UsageDashboardController::class, 'index'])
        ->name('dashboard.usage');
});
```

**Step 2: Update sidebar navigation**

Add nav items for: Dashboard, Crawl Playground, History, API Keys, Usage.
Use Lucide icons: `LayoutDashboard`, `Play`, `Clock`, `Key`, `BarChart3`.

**Step 3: Commit**

```bash
git add routes/web.php resources/js/components/AppSidebar.vue
git commit -m "feat: add dashboard navigation and route structure"
```

---

### Task 13: API Keys Management Page (Dashboard)

**Files:**
- Create: `pipelinex/app/Http/Controllers/Dashboard/ApiKeyController.php`
- Create: `pipelinex/resources/js/pages/dashboard/ApiKeys.vue`
- Test: `pipelinex/tests/Feature/Dashboard/ApiKeyTest.php`

**Step 1: Write the controller**

```php
// app/Http/Controllers/Dashboard/ApiKeyController.php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant;

        return Inertia::render('dashboard/ApiKeys', [
            'apiKeys' => $tenant->apiKeys()
                ->whereNull('revoked_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (ApiKey $key) => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'key_prefix' => $key->key_prefix,
                    'environment' => $key->environment,
                    'last_used_at' => $key->last_used_at?->diffForHumans(),
                    'created_at' => $key->created_at->toFormattedDateString(),
                ]),
            'newKey' => session('newKey'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:live,test'],
        ]);

        $tenant = $request->user()->tenant;
        $result = $tenant->generateApiKey($request->name, $request->environment);

        return to_route('dashboard.api-keys')->with('newKey', $result['key']);
    }

    public function destroy(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        if ($apiKey->tenant_id !== $tenant->id) {
            abort(403);
        }

        $apiKey->update(['revoked_at' => now()]);

        return to_route('dashboard.api-keys');
    }
}
```

**Step 2: Create Vue page**

Create `resources/js/pages/dashboard/ApiKeys.vue` following the existing page patterns (AppLayout, Head, breadcrumbs). Show table of keys with create/revoke actions. Flash the raw key once on creation with a copy button.

**Step 3: Test and commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan test tests/Feature/Dashboard/ApiKeyTest.php
git add app/Http/Controllers/Dashboard/ resources/js/pages/dashboard/
git commit -m "feat: add API keys management dashboard page"
```

---

### Task 14: Dashboard Overview Page

**Files:**
- Modify: `pipelinex/resources/js/pages/Dashboard.vue` — replace placeholder with stats
- Create: `pipelinex/app/Http/Controllers/Dashboard/DashboardController.php`
- Modify: `pipelinex/routes/web.php` — point dashboard to controller

**Step 1: Write the controller**

```php
// app/Http/Controllers/Dashboard/DashboardController.php
<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CrawlJob;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant;
        $monthStart = now()->startOfMonth();

        $totalCrawls = CrawlJob::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart)
            ->count();

        $successfulCrawls = CrawlJob::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart)
            ->where('status', 'completed')
            ->count();

        $successRate = $totalCrawls > 0 ? round(($successfulCrawls / $totalCrawls) * 100) : 0;

        $avgSpeed = CrawlJob::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $monthStart)
            ->where('status', 'completed')
            ->avg('duration_ms') ?? 0;

        $recentCrawls = CrawlJob::where('tenant_id', $tenant->id)
            ->with('result')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $firstApiKey = $tenant->apiKeys()->whereNull('revoked_at')->first();

        return Inertia::render('Dashboard', [
            'stats' => [
                'crawls_used' => $successfulCrawls,
                'crawl_limit' => $tenant->monthly_crawl_limit,
                'success_rate' => $successRate,
                'avg_speed_ms' => round($avgSpeed),
            ],
            'recentCrawls' => $recentCrawls,
            'apiKeyPrefix' => $firstApiKey?->key_prefix,
            'plan' => $tenant->plan,
        ]);
    }
}
```

**Step 2: Update Dashboard.vue**

Replace the placeholder content with the stats cards (Crawls Used, Success Rate, Avg Speed), Quick Start section with cURL example, and Recent Crawls table. Follow the design from Section 4 of the design doc.

**Step 3: Commit**

```bash
git add app/Http/Controllers/Dashboard/ resources/js/pages/Dashboard.vue routes/web.php
git commit -m "feat: add dashboard overview with stats and quick start"
```

---

### Task 15: Crawl Playground Page

**Files:**
- Create: `pipelinex/resources/js/pages/dashboard/CrawlPlayground.vue`

**Step 1: Build the playground**

Interactive page with:
- URL input + Crawl button
- Options toggles (JS rendering, include HTML, etc.)
- Result display with 3 tabs: Preview, JSON, cURL
- Uses `fetch()` to call the internal crawl API (through an Inertia action or direct API call with the user's API key)

The playground should call a dedicated web route that acts as a proxy (so the user doesn't need to handle their API key in the browser):

```php
// In routes/web.php
Route::post('dashboard/crawl', [\App\Http\Controllers\Dashboard\CrawlPlaygroundController::class, 'crawl'])
    ->name('dashboard.crawl.execute');
```

The controller uses the user's first API key internally to call the API endpoint.

**Step 2: Commit**

```bash
git add resources/js/pages/dashboard/ app/Http/Controllers/Dashboard/
git commit -m "feat: add crawl playground dashboard page"
```

---

### Task 16: Crawl History + Usage Pages

**Files:**
- Create: `pipelinex/resources/js/pages/dashboard/History.vue`
- Create: `pipelinex/resources/js/pages/dashboard/HistoryDetail.vue`
- Create: `pipelinex/resources/js/pages/dashboard/Usage.vue`
- Create: `pipelinex/app/Http/Controllers/Dashboard/UsageDashboardController.php`

**Step 1: History page**

Paginated table of crawl jobs with columns: ID, URL, Status, Score, Time. Uses Inertia pagination. Clicking a row navigates to detail view showing full extraction result.

**Step 2: Usage page**

Shows plan info, usage progress bar, daily breakdown chart (simple bar chart), rate limit info, success rate breakdown.

**Step 3: Commit**

```bash
git add resources/js/pages/dashboard/ app/Http/Controllers/Dashboard/
git commit -m "feat: add crawl history and usage dashboard pages"
```

---

## Phase 6: Billing & Scheduled Jobs

### Task 17: Stripe Subscription Integration

**Files:**
- Run: `composer require laravel/cashier`
- Run: `php artisan vendor:publish --tag=cashier-migrations`
- Modify: `pipelinex/app/Models/User.php` — add Billable trait
- Create: `pipelinex/app/Http/Controllers/Dashboard/BillingController.php`
- Add `.env` vars: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`

**Step 1: Install Cashier and publish migrations**

```bash
cd /home/fsd42/dev/pipelinex
composer require laravel/cashier
php artisan vendor:publish --tag=cashier-migrations
php artisan migrate
```

**Step 2: Add Billable trait to User**

```php
use Laravel\Cashier\Billable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, TwoFactorAuthenticatable, Billable;
    // ...
}
```

**Step 3: Create billing controller**

Handle subscription checkout, portal redirect, plan upgrade. When plan changes, update tenant's `plan`, `monthly_crawl_limit`, and `rate_limit_rpm`.

**Step 4: Create Stripe webhook handler**

Listen for `customer.subscription.updated` and `customer.subscription.deleted` to sync plan changes back to the tenant model.

**Step 5: Commit**

```bash
git add app/Models/User.php app/Http/Controllers/Dashboard/BillingController.php config/ database/migrations/ routes/
git commit -m "feat: add Stripe subscription billing with Cashier"
```

---

### Task 18: Scheduled Jobs (Aggregation + Cleanup)

**Files:**
- Create: `pipelinex/app/Console/Commands/AggregateUsageCommand.php`
- Create: `pipelinex/app/Console/Commands/CleanupExpiredResultsCommand.php`
- Modify: `pipelinex/routes/console.php` — schedule commands

**Step 1: Usage aggregation command**

```php
// app/Console/Commands/AggregateUsageCommand.php
<?php

namespace App\Console\Commands;

use App\Models\CrawlJob;
use App\Models\UsageRecord;
use Illuminate\Console\Command;

class AggregateUsageCommand extends Command
{
    protected $signature = 'pipelinex:aggregate-usage {--date=}';
    protected $description = 'Aggregate daily crawl usage per tenant';

    public function handle(): void
    {
        $date = $this->option('date') ? \Carbon\Carbon::parse($this->option('date')) : now()->subDay();

        $stats = CrawlJob::selectRaw('tenant_id, count(*) as total, sum(status = "completed") as succeeded, sum(status = "failed") as failed')
            ->whereDate('created_at', $date)
            ->groupBy('tenant_id')
            ->get();

        foreach ($stats as $stat) {
            UsageRecord::updateOrCreate(
                ['tenant_id' => $stat->tenant_id, 'date' => $date->toDateString()],
                [
                    'crawls_count' => $stat->total,
                    'crawls_succeeded' => $stat->succeeded ?? 0,
                    'crawls_failed' => $stat->failed ?? 0,
                ],
            );
        }

        $this->info("Aggregated usage for {$stats->count()} tenants on {$date->toDateString()}");
    }
}
```

**Step 2: Cleanup command**

```php
// app/Console/Commands/CleanupExpiredResultsCommand.php
<?php

namespace App\Console\Commands;

use App\Models\CrawlJob;
use Illuminate\Console\Command;

class CleanupExpiredResultsCommand extends Command
{
    protected $signature = 'pipelinex:cleanup-expired';
    protected $description = 'Delete expired crawl jobs and results';

    public function handle(): void
    {
        $deleted = CrawlJob::where('expires_at', '<', now())->delete();
        $this->info("Deleted {$deleted} expired crawl jobs and their results");
    }
}
```

**Step 3: Schedule in routes/console.php**

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('pipelinex:aggregate-usage')->dailyAt('02:00');
Schedule::command('pipelinex:cleanup-expired')->dailyAt('03:00');
```

**Step 4: Test and commit**

```bash
cd /home/fsd42/dev/pipelinex
php artisan test tests/Feature/Commands/
git add app/Console/ routes/console.php tests/Feature/Commands/
git commit -m "feat: add daily usage aggregation and expired result cleanup commands"
```

---

## Phase 7: Hardening & Deploy

### Task 19: Error Handling + API Response Consistency

**Files:**
- Modify: `pipelinex/bootstrap/app.php` — customize exception rendering for API routes
- Create: `pipelinex/app/Exceptions/NorthCloudUnavailableException.php`

Ensure all API routes return consistent JSON error format:
```json
{"error": "error_code", "message": "Human readable message"}
```

Handle North Cloud being down with 503 + Retry-After header.

**Commit**

```bash
git add bootstrap/app.php app/Exceptions/
git commit -m "feat: add consistent API error handling and NC health check"
```

---

### Task 20: End-to-End Integration Test

**Files:**
- Create: `pipelinex/tests/Feature/Api/CrawlIntegrationTest.php`

Write a full integration test using `Http::fake()` to mock North Cloud responses. Test the complete flow: API key auth → rate limit → quota check → dispatch job → sync wait → return result.

```php
test('full crawl flow returns structured data', function () {
    Http::fake([
        '*/api/internal/v1/fetch' => Http::response([
            'url' => 'https://example.com/article',
            'final_url' => 'https://example.com/article/',
            'status_code' => 200,
            'content_type' => 'text/html',
            'html' => '<html><head><title>Test</title></head><body><article>Hello world</article></body></html>',
            'title' => 'Test',
            'body' => 'Hello world',
            'author' => 'Jane',
            'description' => 'A test article',
            'og' => ['title' => 'Test', 'description' => 'A test article'],
            'duration_ms' => 500,
        ]),
        '*/api/internal/v1/extract' => Http::response([
            'title' => 'Test',
            'body' => 'Hello world',
            'word_count' => 2,
            'quality_score' => 75,
            'topics' => ['technology'],
            'content_type' => 'article',
            'og' => [],
        ]),
    ]);

    $user = User::factory()->create();
    $tenant = $user->tenant;
    $key = $tenant->generateApiKey('Test', 'live');

    $response = $this->withHeader('Authorization', 'Bearer ' . $key['key'])
        ->postJson('/api/v1/crawl', ['url' => 'https://example.com/article']);

    // With sync queue driver in testing, job runs immediately
    $response->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('data.title', 'Test')
        ->assertJsonPath('data.quality_score', 75);
});
```

**Commit**

```bash
git add tests/Feature/Api/CrawlIntegrationTest.php
git commit -m "test: add end-to-end crawl integration test"
```

---

## Summary

| Phase | Tasks | Description |
|-------|-------|-------------|
| 1 | 1-3 | North Cloud internal endpoints (fetch + extract + auth) |
| 2 | 4-6 | PipelineX database migrations + Eloquent models |
| 3 | 7-9 | API infrastructure (auth middleware, rate limiting, NC client) |
| 4 | 10-11 | Core crawl API (POST /crawl, GET /crawl/{id}, GET /usage) |
| 5 | 12-16 | Dashboard pages (nav, overview, API keys, playground, history, usage) |
| 6 | 17-18 | Billing (Stripe Cashier) + scheduled jobs (aggregation, cleanup) |
| 7 | 19-20 | Error handling + integration testing |

**Total: 20 tasks across 7 phases**

**Environment variables to add to `.env`:**
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pipelinex
DB_USERNAME=postgres
DB_PASSWORD=postgres
QUEUE_CONNECTION=redis
CACHE_STORE=redis
NORTH_CLOUD_CRAWLER_URL=http://localhost:8060
NORTH_CLOUD_CLASSIFIER_URL=http://localhost:8071
NORTH_CLOUD_INTERNAL_SECRET=your-shared-secret-here
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

**Packages to install:**
```bash
composer require laravel/cashier
```
