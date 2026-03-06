import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { readConfig, writeConfig, CONFIG_PATH } from '../../src/lib/config.js';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

vi.mock('node:os', () => ({
    default: { homedir: () => '/tmp/pipelinex-test' },
    homedir: () => '/tmp/pipelinex-test',
}));

describe('config', () => {
    const configDir = '/tmp/pipelinex-test/.pipelinex';
    const configFile = path.join(configDir, 'config.json');

    beforeEach(() => {
        fs.mkdirSync(configDir, { recursive: true });
    });

    afterEach(() => {
        fs.rmSync('/tmp/pipelinex-test/.pipelinex', {
            recursive: true,
            force: true,
        });
    });

    it('returns empty config when file does not exist', () => {
        fs.rmSync(configFile, { force: true });
        const config = readConfig();
        expect(config).toEqual({});
    });

    it('reads config from file', () => {
        fs.writeFileSync(
            configFile,
            JSON.stringify({ apiKey: 'px_test_abc123' })
        );
        const config = readConfig();
        expect(config.apiKey).toBe('px_test_abc123');
    });

    it('writes config to file', () => {
        writeConfig({ apiKey: 'px_test_xyz789' });
        const raw = fs.readFileSync(configFile, 'utf-8');
        expect(JSON.parse(raw).apiKey).toBe('px_test_xyz789');
    });

    it('creates config directory if it does not exist', () => {
        fs.rmSync(configDir, { recursive: true, force: true });
        writeConfig({ apiKey: 'px_test_newdir' });
        expect(fs.existsSync(configFile)).toBe(true);
    });
});
