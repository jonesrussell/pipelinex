import type { CrawlResponse } from '../types.js';

interface FetchResponse {
    url: string;
    final_url: string;
    status_code: number;
    content_type: string;
    html: string;
    title: string;
    body: string;
    author: string;
    description: string;
    og: Record<string, string> | null;
    duration_ms: number;
}

interface ExtractResponse {
    title: string;
    author: string;
    published_date: string | null;
    body: string;
    word_count: number;
    quality_score: number;
    topics: string[];
    content_type: string;
    og: Record<string, string>;
}

export class NorthCloudDirectClient {
    constructor(
        private baseUrl: string,
        private secret: string
    ) {}

    async scrape(url: string, timeout = 15): Promise<CrawlResponse> {
        const fetchResult = await this.fetchPage(url, timeout);
        if (fetchResult.error) {
            return {
                id: '',
                status: 'failed',
                url,
                error: fetchResult.error,
            };
        }

        const extractResult = await this.extractContent(
            fetchResult.data!.html,
            fetchResult.data!.url,
            fetchResult.data!.title
        );
        if (extractResult.error) {
            return {
                id: '',
                status: 'failed',
                url,
                error: extractResult.error,
            };
        }

        const fetched = fetchResult.data!;
        const extracted = extractResult.data!;

        return {
            id: `direct_${Date.now()}`,
            status: 'completed',
            url,
            final_url: fetched.final_url,
            data: {
                title: extracted.title || fetched.title,
                author: extracted.author || null,
                published_date: extracted.published_date,
                body: extracted.body,
                word_count: extracted.word_count,
                quality_score: extracted.quality_score,
                topics: extracted.topics,
                og: extracted.og ?? fetched.og ?? {},
                links: [],
                images: [],
            },
            meta: {
                status_code: fetched.status_code,
                content_type: fetched.content_type,
                crawled_at: new Date().toISOString(),
                duration_ms: fetched.duration_ms,
            },
        };
    }

    private async fetchPage(
        url: string,
        timeout: number
    ): Promise<{
        data?: FetchResponse;
        error?: { code: string; message: string };
    }> {
        try {
            const response = await fetch(
                `${this.baseUrl}/api/crawler/internal/v1/fetch`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Internal-Secret': this.secret,
                    },
                    body: JSON.stringify({ url, timeout }),
                }
            );

            if (!response.ok) {
                const body = await response
                    .json()
                    .catch(() => ({}));
                return {
                    error: {
                        code: 'fetch_failed',
                        message:
                            (body as Record<string, string>).error ??
                            `Crawler returned ${response.status}`,
                    },
                };
            }

            return {
                data: (await response.json()) as FetchResponse,
            };
        } catch (err) {
            return {
                error: {
                    code: 'network_error',
                    message:
                        err instanceof Error
                            ? err.message
                            : String(err),
                },
            };
        }
    }

    private async extractContent(
        html: string,
        url: string,
        title: string
    ): Promise<{
        data?: ExtractResponse;
        error?: { code: string; message: string };
    }> {
        try {
            const response = await fetch(
                `${this.baseUrl}/api/classifier/internal/v1/extract`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Internal-Secret': this.secret,
                    },
                    body: JSON.stringify({
                        html,
                        url,
                        title,
                        source_name: 'pipelinex',
                    }),
                }
            );

            if (!response.ok) {
                const body = await response
                    .json()
                    .catch(() => ({}));
                return {
                    error: {
                        code: 'extract_failed',
                        message:
                            (body as Record<string, string>).error ??
                            `Classifier returned ${response.status}`,
                    },
                };
            }

            return {
                data: (await response.json()) as ExtractResponse,
            };
        } catch (err) {
            return {
                error: {
                    code: 'network_error',
                    message:
                        err instanceof Error
                            ? err.message
                            : String(err),
                },
            };
        }
    }
}
