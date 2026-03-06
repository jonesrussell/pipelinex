import { describe, it, expect, vi, beforeEach } from 'vitest';
import { NorthCloudDirectClient } from '../../src/lib/north-cloud-client.js';

const mockFetch = vi.fn();
global.fetch = mockFetch;

describe('NorthCloudDirectClient', () => {
    let client: NorthCloudDirectClient;

    beforeEach(() => {
        client = new NorthCloudDirectClient(
            'https://northcloud.one',
            'test-secret'
        );
        mockFetch.mockReset();
    });

    it('scrapes a URL by calling fetch then extract', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () =>
                Promise.resolve({
                    url: 'https://example.com',
                    final_url: 'https://example.com/',
                    status_code: 200,
                    content_type: 'text/html',
                    html: '<html><body>Hello</body></html>',
                    title: 'Example',
                    body: 'Hello',
                    author: '',
                    description: '',
                    og: null,
                    duration_ms: 150,
                }),
        });

        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () =>
                Promise.resolve({
                    title: 'Example',
                    author: '',
                    published_date: null,
                    body: 'Hello world content',
                    word_count: 3,
                    quality_score: 80,
                    topics: ['general'],
                    topic_scores: { general: 0.9 },
                    content_type: 'article',
                    og: {},
                }),
        });

        const result = await client.scrape('https://example.com');

        expect(mockFetch).toHaveBeenNthCalledWith(
            1,
            'https://northcloud.one/api/crawler/internal/v1/fetch',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({
                    'X-Internal-Secret': 'test-secret',
                }),
            })
        );

        expect(mockFetch).toHaveBeenNthCalledWith(
            2,
            'https://northcloud.one/api/classifier/internal/v1/extract',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({
                    'X-Internal-Secret': 'test-secret',
                }),
            })
        );

        expect(result.status).toBe('completed');
        expect(result.url).toBe('https://example.com');
        expect(result.data?.title).toBe('Example');
        expect(result.data?.body).toBe('Hello world content');
        expect(result.data?.word_count).toBe(3);
        expect(result.meta?.status_code).toBe(200);
    });

    it('returns failed status on fetch error', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: false,
            status: 502,
            json: () =>
                Promise.resolve({
                    error: 'fetch failed: connection refused',
                }),
        });

        const result = await client.scrape('https://bad-url.test');
        expect(result.status).toBe('failed');
        expect(result.error?.message).toContain('fetch failed');
    });

    it('returns failed status on extract error', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: true,
            json: () =>
                Promise.resolve({
                    url: 'https://example.com',
                    final_url: 'https://example.com',
                    status_code: 200,
                    content_type: 'text/html',
                    html: '<html></html>',
                    title: '',
                    body: '',
                    author: '',
                    description: '',
                    og: null,
                    duration_ms: 100,
                }),
        });

        mockFetch.mockResolvedValueOnce({
            ok: false,
            status: 500,
            json: () =>
                Promise.resolve({
                    error: 'classification failed',
                }),
        });

        const result = await client.scrape('https://example.com');
        expect(result.status).toBe('failed');
        expect(result.error?.message).toContain(
            'classification failed'
        );
    });
});
