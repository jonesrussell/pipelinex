import readline from 'node:readline';
import chalk from 'chalk';
import { Command } from 'commander';

import { resolveApiKey, resolveNorthCloudSecret } from '../lib/auth.js';
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
    .description('Configure authentication')
    .option(
        '--direct',
        'Configure direct north-cloud connection instead of PipelineX API'
    )
    .action(async (options) => {
        if (options.direct) {
            await configureDirect();
        } else {
            await configureApiKey();
        }
    });

async function configureApiKey(): Promise<void> {
    const existing = resolveApiKey();
    if (existing) {
        console.error(
            chalk.dim(`Current key: ${existing.substring(0, 20)}...`)
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
}

async function configureDirect(): Promise<void> {
    const existingSecret = resolveNorthCloudSecret();
    if (existingSecret) {
        console.error(
            chalk.dim('North Cloud secret already configured.')
        );
    }

    const url = await prompt(
        'North Cloud URL [https://northcloud.one]: '
    );
    const secret = await prompt('North Cloud internal secret: ');

    if (!secret) {
        console.error(chalk.red('No secret provided.'));
        process.exit(1);
    }

    const config = readConfig();
    config.northCloudUrl = url || 'https://northcloud.one';
    config.northCloudSecret = secret;
    writeConfig(config);

    console.error(
        chalk.green(
            'North Cloud config saved to ~/.pipelinex/config.json'
        )
    );
}
