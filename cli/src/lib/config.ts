import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import type { PipelinexConfig } from '../types.js';

export const CONFIG_DIR = path.join(os.homedir(), '.pipelinex');
export const CONFIG_PATH = path.join(CONFIG_DIR, 'config.json');

export function readConfig(): PipelinexConfig {
    try {
        const raw = fs.readFileSync(CONFIG_PATH, 'utf-8');
        return JSON.parse(raw) as PipelinexConfig;
    } catch {
        return {};
    }
}

export function writeConfig(config: PipelinexConfig): void {
    fs.mkdirSync(CONFIG_DIR, { recursive: true, mode: 0o700 });
    fs.writeFileSync(CONFIG_PATH, JSON.stringify(config, null, 2) + '\n', {
        mode: 0o600,
    });
}
