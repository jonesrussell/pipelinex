import { readFileSync, readdirSync, existsSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
    CallToolRequestSchema,
    ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
// Repo root is two levels up from tools/spec-retrieval/
const SPECS_DIR = join(__dirname, '..', '..', 'docs', 'specs');

// One-line descriptions for each spec (keyed by filename without .md)
const SPEC_DESCRIPTIONS = {
    'api-pipeline': 'REST API endpoints, crawl job flow, NorthCloudClient integration',
    'auth-tenancy': 'API key authentication, tenant model, middleware chain',
    'dashboard': 'Inertia/Vue dashboard pages, Fortify auth, Stripe billing',
    'cli': 'CLI tool commands, direct north-cloud integration mode',
};

function listSpecs() {
    if (!existsSync(SPECS_DIR)) return [];
    return readdirSync(SPECS_DIR)
        .filter((f) => f.endsWith('.md'))
        .map((f) => {
            const name = f.replace(/\.md$/, '');
            return {
                name,
                description: SPEC_DESCRIPTIONS[name] ?? 'No description',
            };
        });
}

function getSpec(name) {
    const filePath = join(SPECS_DIR, `${name}.md`);
    if (!existsSync(filePath)) return null;
    return readFileSync(filePath, 'utf-8');
}

function searchSpecs(query) {
    const lower = query.toLowerCase();
    const results = [];
    for (const { name } of listSpecs()) {
        const content = getSpec(name);
        if (!content) continue;
        const lines = content.split('\n');
        const matchingLines = lines.filter((l) => l.toLowerCase().includes(lower));
        if (matchingLines.length > 0) {
            results.push({ name, excerpt: matchingLines.slice(0, 5).join('\n') });
        }
    }
    return results;
}

const server = new Server(
    { name: 'spec-retrieval', version: '1.0.0' },
    { capabilities: { tools: {} } },
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: [
        {
            name: 'list_specs',
            description: 'List all available subsystem spec files with one-line descriptions',
            inputSchema: { type: 'object', properties: {} },
        },
        {
            name: 'get_spec',
            description: 'Get the full content of a subsystem spec by name',
            inputSchema: {
                type: 'object',
                properties: {
                    name: { type: 'string', description: 'Spec name, e.g. "api-pipeline"' },
                },
                required: ['name'],
            },
        },
        {
            name: 'search_specs',
            description: 'Search all specs for a keyword, returns matching spec names and excerpts',
            inputSchema: {
                type: 'object',
                properties: {
                    query: { type: 'string', description: 'Search term' },
                },
                required: ['query'],
            },
        },
    ],
}));

server.setRequestHandler(CallToolRequestSchema, async (req) => {
    const { name, arguments: args } = req.params;

    if (name === 'list_specs') {
        const specs = listSpecs();
        return {
            content: [
                {
                    type: 'text',
                    text: specs.map((s) => `**${s.name}**: ${s.description}`).join('\n'),
                },
            ],
        };
    }

    if (name === 'get_spec') {
        const content = getSpec(args.name);
        if (!content) {
            return {
                content: [{ type: 'text', text: `Spec "${args.name}" not found.` }],
                isError: true,
            };
        }
        return { content: [{ type: 'text', text: content }] };
    }

    if (name === 'search_specs') {
        const results = searchSpecs(args.query);
        if (results.length === 0) {
            return {
                content: [{ type: 'text', text: `No matches for "${args.query}".` }],
            };
        }
        const text = results
            .map((r) => `**${r.name}**:\n${r.excerpt}`)
            .join('\n\n---\n\n');
        return { content: [{ type: 'text', text }] };
    }

    return {
        content: [{ type: 'text', text: `Unknown tool: ${name}` }],
        isError: true,
    };
});

const transport = new StdioServerTransport();
await server.connect(transport);
