import type { CrawlResponse, UsageResponse } from '../types.js';

export class PipelinexClient {
    constructor(
        private baseUrl: string,
        private apiKey: string
    ) {}

    async crawl(
        url: string,
        options: Record<string, unknown> = {}
    ): Promise<CrawlResponse> {
        return this.request<CrawlResponse>('/crawl', {
            method: 'POST',
            body: JSON.stringify({ url, options }),
        });
    }

    async getCrawl(id: string): Promise<CrawlResponse> {
        return this.request<CrawlResponse>(`/crawl/${id}`);
    }

    async getUsage(): Promise<UsageResponse> {
        return this.request<UsageResponse>('/usage');
    }

    private async request<T>(
        path: string,
        init: RequestInit = {}
    ): Promise<T> {
        const url = `${this.baseUrl}${path}`;
        const response = await fetch(url, {
            ...init,
            headers: {
                Authorization: `Bearer ${this.apiKey}`,
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...init.headers,
            },
        });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            const message =
                (body as Record<string, string>).message ??
                response.statusText;
            throw new Error(
                `API error (${response.status}): ${message}`
            );
        }

        return response.json() as Promise<T>;
    }
}
