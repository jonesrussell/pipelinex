import { describe, it, expect, vi, beforeEach } from 'vitest';
import { PipelinexClient } from '../../src/lib/client.js';

const mockFetch = vi.fn();
global.fetch = mockFetch;

describe('PipelinexClient', () => {
    let client: PipelinexClient;

    beforeEach(() => {
        client = new PipelinexClient(
            'https://pipelinex.dev/api/v1',
            'px_test_key123'
        );
        mockFetch.mockReset();
    });

    it('submits a crawl job', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () =>
                Promise.resolve({
                    id: 'crawl_abc123',
                    status: 'completed',
                    url: 'https://example.com',
                    data: { title: 'Example', body: 'content' },
                }),
        });

        const result = await client.crawl('https://example.com');
        expect(mockFetch).toHaveBeenCalledWith(
            'https://pipelinex.dev/api/v1/crawl',
            expect.objectContaining({
                method: 'POST',
                headers: expect.objectContaining({
                    Authorization: 'Bearer px_test_key123',
                    'Content-Type': 'application/json',
                }),
            })
        );
        expect(result.id).toBe('crawl_abc123');
    });

    it('fetches crawl status', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () =>
                Promise.resolve({
                    id: 'crawl_abc123',
                    status: 'completed',
                }),
        });

        const result = await client.getCrawl('crawl_abc123');
        expect(result.status).toBe('completed');
    });

    it('fetches usage', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: () =>
                Promise.resolve({
                    plan: 'pro',
                    usage: { crawls_used: 50, crawls_limit: 10000 },
                }),
        });

        const result = await client.getUsage();
        expect(result.plan).toBe('pro');
    });

    it('throws on HTTP error', async () => {
        mockFetch.mockResolvedValueOnce({
            ok: false,
            status: 401,
            statusText: 'Unauthorized',
            json: () =>
                Promise.resolve({ message: 'Invalid API key' }),
        });

        await expect(
            client.crawl('https://example.com')
        ).rejects.toThrow('API error (401)');
    });
});
