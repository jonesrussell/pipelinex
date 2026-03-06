import { describe, it, expect } from 'vitest';
import { formatCrawlResult, detectFormat } from '../../src/lib/output.js';
import type { CrawlResponse } from '../../src/types.js';

const mockResult: CrawlResponse = {
    id: 'crawl_abc123',
    status: 'completed',
    url: 'https://example.com',
    final_url: 'https://example.com/',
    data: {
        title: 'Example Page',
        author: 'Test Author',
        published_date: '2026-01-01T00:00:00.000Z',
        body: 'This is the page content.',
        word_count: 5,
        quality_score: 0.9,
        topics: ['tech'],
        og: {},
        links: ['https://example.com/about'],
        images: [],
    },
    meta: {
        status_code: 200,
        content_type: 'text/html',
        crawled_at: '2026-03-06T00:00:00.000Z',
        duration_ms: 1234,
    },
};

describe('formatCrawlResult', () => {
    it('formats as markdown', () => {
        const output = formatCrawlResult(mockResult, 'markdown');
        expect(output).toContain('# Example Page');
        expect(output).toContain('This is the page content.');
        expect(output).toContain('example.com');
    });

    it('formats as json', () => {
        const output = formatCrawlResult(mockResult, 'json');
        const parsed = JSON.parse(output);
        expect(parsed.id).toBe('crawl_abc123');
        expect(parsed.data.title).toBe('Example Page');
    });

    it('formats as html', () => {
        const output = formatCrawlResult(mockResult, 'html');
        expect(output).toContain('This is the page content.');
    });
});

describe('detectFormat', () => {
    it('returns explicit format when provided', () => {
        expect(detectFormat('json')).toBe('json');
        expect(detectFormat('markdown')).toBe('markdown');
    });

    it('returns undefined when no format specified', () => {
        expect(detectFormat(undefined)).toBeUndefined();
    });
});
