import chalk from 'chalk';
import { Command } from 'commander';
import { resolveApiKey, resolveApiUrl } from '../lib/auth.js';
import { PipelinexClient } from '../lib/client.js';
import { resolveFormat, formatUsage } from '../lib/output.js';
import type { GlobalOptions } from '../types.js';

export const usageCommand = new Command('usage')
    .description('Show current API usage and quota')
    .action(async (_options, command: Command) => {
        const globalOpts = command.parent?.opts() as GlobalOptions;
        const apiKey = resolveApiKey(globalOpts?.apiKey);

        if (!apiKey) {
            console.error(
                chalk.red(
                    'No API key found. Run "pipelinex auth" or set PIPELINEX_API_KEY.'
                )
            );
            process.exit(1);
        }

        const apiUrl = resolveApiUrl(globalOpts?.apiUrl);
        const client = new PipelinexClient(apiUrl, apiKey);
        const format = resolveFormat(globalOpts?.format);

        try {
            const usage = await client.getUsage();
            console.log(formatUsage(usage, format));
        } catch (error) {
            console.error(
                chalk.red(
                    `Error: ${error instanceof Error ? error.message : String(error)}`
                )
            );
            process.exit(1);
        }
    });
