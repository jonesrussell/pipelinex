import { Command } from 'commander';

const program = new Command();

program
    .name('pipelinex')
    .description('PipelineX CLI - Web scraping from the command line')
    .version('0.1.0');

program.parse();
