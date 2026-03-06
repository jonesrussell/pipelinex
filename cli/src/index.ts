import { Command } from 'commander';

import { authCommand } from './commands/auth.js';
import { scrapeCommand } from './commands/scrape.js';

const program = new Command();

program
    .name('pipelinex')
    .description('PipelineX CLI - Web scraping from the command line')
    .version('0.1.0')
    .option('--api-key <key>', 'API key (overrides env and config)')
    .option(
        '--format <format>',
        'Output format: markdown, json, html'
    )
    .option('--api-url <url>', 'Custom API base URL')
    .option('--verbose', 'Show request/response details')
    .option('--no-color', 'Disable colored output');

program.addCommand(authCommand);
program.addCommand(scrapeCommand);

program.parse();
