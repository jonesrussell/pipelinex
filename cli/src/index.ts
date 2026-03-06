import { Command } from 'commander';

import { authCommand } from './commands/auth.js';
import { crawlCommand } from './commands/crawl.js';
import { scrapeCommand } from './commands/scrape.js';
import { usageCommand } from './commands/usage.js';

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
    .option('--api-url <url>', 'Custom API base URL');

program.addCommand(authCommand);
program.addCommand(crawlCommand);
program.addCommand(scrapeCommand);
program.addCommand(usageCommand);

program.parse();
