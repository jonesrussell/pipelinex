import { readConfig } from './config.js';

export function resolveApiKey(flagValue?: string): string | undefined {
    if (flagValue) return flagValue;
    if (process.env.PIPELINEX_API_KEY) return process.env.PIPELINEX_API_KEY;
    const config = readConfig();
    return config.apiKey;
}

export function resolveApiUrl(flagValue?: string): string {
    if (flagValue) return flagValue;
    if (process.env.PIPELINEX_API_URL) return process.env.PIPELINEX_API_URL;
    const config = readConfig();
    return config.apiUrl ?? 'https://pipelinex.dev/api/v1';
}
