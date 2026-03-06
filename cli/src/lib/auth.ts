import { readConfig } from './config.js';

export type ClientMode = 'direct' | 'api';

export function resolveMode(): ClientMode {
    const config = readConfig();
    if (config.northCloudUrl && config.northCloudSecret) return 'direct';
    if (config.apiKey || process.env.PIPELINEX_API_KEY) return 'api';
    return 'direct';
}

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

export function resolveNorthCloudUrl(): string {
    if (process.env.NORTH_CLOUD_URL) return process.env.NORTH_CLOUD_URL;
    const config = readConfig();
    return config.northCloudUrl ?? 'https://northcloud.one';
}

export function resolveNorthCloudSecret(): string | undefined {
    if (process.env.NORTH_CLOUD_SECRET) return process.env.NORTH_CLOUD_SECRET;
    const config = readConfig();
    return config.northCloudSecret;
}
