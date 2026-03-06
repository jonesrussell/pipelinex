import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import readline from 'node:readline';

vi.mock('node:readline');
vi.mock('../../src/lib/config.js', () => ({
    readConfig: vi.fn(() => ({})),
    writeConfig: vi.fn(),
}));
vi.mock('../../src/lib/auth.js', () => ({
    resolveApiKey: vi.fn(() => undefined),
    resolveNorthCloudSecret: vi.fn(() => undefined),
}));

describe('auth --direct', () => {
    let mockQuestion: ReturnType<typeof vi.fn>;
    let writeConfig: ReturnType<typeof vi.fn>;
    let readConfig: ReturnType<typeof vi.fn>;
    const originalExit = process.exit;

    beforeEach(async () => {
        mockQuestion = vi.fn();
        vi.mocked(readline.createInterface).mockReturnValue({
            question: mockQuestion,
            close: vi.fn(),
        } as unknown as readline.Interface);

        const configMod = await import('../../src/lib/config.js');
        writeConfig = vi.mocked(configMod.writeConfig);
        readConfig = vi.mocked(configMod.readConfig);
        writeConfig.mockReset();
        readConfig.mockReturnValue({});

        process.exit = vi.fn() as never;
    });

    afterEach(() => {
        process.exit = originalExit;
        vi.restoreAllMocks();
    });

    it('saves northCloudUrl and northCloudSecret to config', async () => {
        // First prompt: URL, second prompt: secret
        mockQuestion
            .mockImplementationOnce((_q: string, cb: (answer: string) => void) => cb('https://northcloud.test'))
            .mockImplementationOnce((_q: string, cb: (answer: string) => void) => cb('my-secret'));

        const { authCommand } = await import('../../src/commands/auth.js');
        await authCommand.parseAsync(['node', 'pipelinex', '--direct']);

        expect(writeConfig).toHaveBeenCalledWith({
            northCloudUrl: 'https://northcloud.test',
            northCloudSecret: 'my-secret',
        });
    });

    it('uses default URL when user presses enter', async () => {
        mockQuestion
            .mockImplementationOnce((_q: string, cb: (answer: string) => void) => cb(''))
            .mockImplementationOnce((_q: string, cb: (answer: string) => void) => cb('my-secret'));

        const { authCommand } = await import('../../src/commands/auth.js');
        await authCommand.parseAsync(['node', 'pipelinex', '--direct']);

        expect(writeConfig).toHaveBeenCalledWith({
            northCloudUrl: 'https://northcloud.one',
            northCloudSecret: 'my-secret',
        });
    });

    it('exits with error when no secret provided', async () => {
        mockQuestion
            .mockImplementationOnce((_q: string, cb: (answer: string) => void) => cb('https://northcloud.test'))
            .mockImplementationOnce((_q: string, cb: (answer: string) => void) => cb(''));

        const { authCommand } = await import('../../src/commands/auth.js');
        await authCommand.parseAsync(['node', 'pipelinex', '--direct']);

        expect(process.exit).toHaveBeenCalledWith(1);
    });
});
