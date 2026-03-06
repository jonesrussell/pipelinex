# @pipelinex/cli

Web scraping from the command line. A CLI for the PipelineX API.

## Install

```bash
npm install -g @pipelinex/cli
```

## Quick Start

```bash
# Authenticate
pipelinex auth

# Scrape a single page
pipelinex scrape https://example.com

# Crawl multiple pages
pipelinex crawl https://example.com --limit 10

# Check usage
pipelinex usage
```

## Output Formats

Default: markdown (terminal) / JSON (piped). Override with `--format`:

```bash
pipelinex scrape https://example.com --format json
pipelinex scrape https://example.com --format html
pipelinex scrape https://example.com | jq .  # auto-JSON when piped
```

## Authentication

Priority: `--api-key` flag > `PIPELINEX_API_KEY` env var > `~/.pipelinex/config.json`

```bash
# Interactive setup
pipelinex auth

# Environment variable
export PIPELINEX_API_KEY=px_prod_your_key_here

# Per-command
pipelinex scrape https://example.com --api-key px_prod_your_key
```
