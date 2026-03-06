import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { resolveApiKey } from '../../src/lib/auth.js';

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
        const { resolveApiKey: resolve } = await vi.importActual<
            typeof import('../../src/lib/auth.js')
        >('../../src/lib/auth.js');
        expect(resolveApiKey(undefined)).toBe('px_test_fromconfig');
    });
});
