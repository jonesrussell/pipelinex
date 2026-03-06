export interface PipelinexConfig {
    apiKey?: string;
    apiUrl?: string;
}

export interface GlobalOptions {
    apiKey?: string;
    apiUrl?: string;
    format?: 'markdown' | 'json' | 'html';
}

export interface CrawlResponse {
    id: string;
    status: 'processing' | 'completed' | 'failed';
    url: string;
    final_url?: string;
    poll_url?: string;
    data?: CrawlData;
    meta?: CrawlMeta;
    error?: { code: string; message: string };
}

export interface CrawlData {
    title: string;
    author: string | null;
    published_date: string | null;
    body: string;
    word_count: number;
    quality_score: number | null;
    topics: string[];
    og: Record<string, string>;
    links: string[];
    images: string[];
}

export interface CrawlMeta {
    status_code: number;
    content_type: string;
    crawled_at: string;
    duration_ms: number;
}

export interface UsageResponse {
    plan: string;
    usage: {
        crawls_used: number;
        crawls_limit: number;
        period_start: string;
        period_end: string;
    };
    rate_limit: {
        requests_per_minute: number;
        current: number;
    };
}
