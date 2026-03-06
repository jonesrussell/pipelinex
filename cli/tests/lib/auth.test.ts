import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { resolveApiKey, resolveMode } from '../../src/lib/auth.js';

vi.mock('../../src/lib/config.js', () => ({
    readConfig: vi.fn(() => ({ apiKey: 'px_test_fromconfig' })),
}));

describe('resolveApiKey', () => {
    const originalEnv = process.env;

    beforeEach(() => {
        process.env = { ...originalEnv };
        delete process.env.PIPELINEX_API_KEY;
    });

    afterEach(() => {
        process.env = originalEnv;
    });

    it('returns flag value when provided', () => {
        process.env.PIPELINEX_API_KEY = 'px_test_fromenv';
        const key = resolveApiKey('px_test_fromflag');
        expect(key).toBe('px_test_fromflag');
    });

    it('returns env var when no flag provided', () => {
        process.env.PIPELINEX_API_KEY = 'px_test_fromenv';
        const key = resolveApiKey(undefined);
        expect(key).toBe('px_test_fromenv');
    });

    it('returns config value when no flag or env var', () => {
        const key = resolveApiKey(undefined);
        expect(key).toBe('px_test_fromconfig');
    });

    it('returns undefined when nothing is configured', async () => {
        const { readConfig } = vi.mocked(
            await import('../../src/lib/config.js')
        );
        readConfig.mockReturnValueOnce({});
        const key = resolveApiKey(undefined);
        expect(key).toBeUndefined();
    });
});

describe('resolveMode', () => {
    const originalEnv = process.env;

    beforeEach(() => {
        process.env = { ...originalEnv };
        delete process.env.PIPELINEX_API_KEY;
    });

    afterEach(() => {
        process.env = originalEnv;
    });

    it('returns direct when northCloudUrl and northCloudSecret are configured', async () => {
        const { readConfig } = vi.mocked(
            await import('../../src/lib/config.js')
        );
        readConfig.mockReturnValueOnce({
            northCloudUrl: 'https://northcloud.one',
            northCloudSecret: 'secret123',
        });
        expect(resolveMode()).toBe('direct');
    });

    it('returns api when only apiKey is configured', async () => {
        const { readConfig } = vi.mocked(
            await import('../../src/lib/config.js')
        );
        readConfig.mockReturnValueOnce({ apiKey: 'px_test_key' });
        expect(resolveMode()).toBe('api');
    });

    it('returns api when PIPELINEX_API_KEY env var is set', async () => {
        const { readConfig } = vi.mocked(
            await import('../../src/lib/config.js')
        );
        readConfig.mockReturnValueOnce({});
        process.env.PIPELINEX_API_KEY = 'px_test_env';
        expect(resolveMode()).toBe('api');
    });

    it('returns api when nothing is configured', async () => {
        const { readConfig } = vi.mocked(
            await import('../../src/lib/config.js')
        );
        readConfig.mockReturnValueOnce({});
        expect(resolveMode()).toBe('api');
    });
});
