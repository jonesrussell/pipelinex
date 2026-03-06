import { Command } from 'commander';
import chalk from 'chalk';
import readline from 'node:readline';

import { resolveApiKey } from '../lib/auth.js';
import { readConfig, writeConfig } from '../lib/config.js';

function prompt(question: string): Promise<string> {
    const rl = readline.createInterface({
        input: process.stdin,
        output: process.stderr,
    });
    return new Promise((resolve) => {
        rl.question(question, (answer) => {
            rl.close();
            resolve(answer.trim());
        });
    });
}

export const authCommand = new Command('auth')
    .description('Configure API key authentication')
    .action(async () => {
        const existing = resolveApiKey();
        if (existing) {
            console.error(
                chalk.dim(
                    `Current key: ${existing.substring(0, 20)}...`
                )
            );
        }

        const key = await prompt('Enter your PipelineX API key: ');

        if (!key) {
            console.error(chalk.red('No API key provided.'));
            process.exit(1);
        }

        if (!key.startsWith('px_')) {
            console.error(
                chalk.red(
                    'Invalid API key format. Keys start with "px_".'
                )
            );
            process.exit(1);
        }

        const config = readConfig();
        config.apiKey = key;
        writeConfig(config);

        console.error(
            chalk.green('API key saved to ~/.pipelinex/config.json')
        );
    });
