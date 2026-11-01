import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadModules, readModuleList } from './load';
import { failedModules, registeredModules, registerModule, resetModules } from './registry';

function shell(block: string | null): Document {
    const doc = document.implementation.createHTMLDocument('shell');
    if (block !== null) {
        const script = doc.createElement('script');
        script.type = 'application/json';
        script.id = 'emailsquire-modules';
        script.textContent = block;
        doc.head.append(script);
    }
    return doc;
}

const EXAMPLE = { name: 'example', route: '/example', entry: '/modules/example/entry.js?v=1.0.0' };

let consoleError: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    resetModules();
    consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
    consoleError.mockRestore();
    vi.useRealTimers();
});

describe('readModuleList', () => {
    it('parses the shell block', () => {
        expect(readModuleList(shell(JSON.stringify([EXAMPLE])))).toEqual([EXAMPLE]);
    });

    it('drops entries missing a string field', () => {
        const list = [EXAMPLE, { name: 'noroute', entry: '/x.js' }, { name: 'n', route: '/n', entry: 7 }, null, 'x'];
        expect(readModuleList(shell(JSON.stringify(list)))).toEqual([EXAMPLE]);
    });

    it('returns [] when the block is missing', () => {
        expect(readModuleList(shell(null))).toEqual([]);
    });

    it('returns [] when the block is not valid JSON or not a list', () => {
        expect(readModuleList(shell('[{"name":'))).toEqual([]);
        expect(readModuleList(shell('{"name":"example"}'))).toEqual([]);
    });
});

describe('loadModules', () => {
    it('registers a module whose entry calls registerModule', async () => {
        const importer = vi.fn(() => {
            registerModule({ name: 'example', routes: [{ path: '/example', element: null }], catalogues: { en: {}, fi: {} } });
            return Promise.resolve({});
        });
        await loadModules([EXAMPLE], { timeoutMs: 5000, importer });
        expect(importer).toHaveBeenCalledWith(EXAMPLE.entry);
        expect(registeredModules().map((m) => m.name)).toEqual(['example']);
        expect(failedModules()).toEqual([]);
    });

    it('records a failed import and resolves', async () => {
        const importer = vi.fn(() => Promise.reject(new TypeError('Failed to fetch dynamically imported module')));
        await expect(loadModules([EXAMPLE], { timeoutMs: 5000, importer })).resolves.toBeUndefined();
        expect(failedModules()).toEqual([{ name: 'example', error: 'import_failed' }]);
        expect(consoleError).toHaveBeenCalled();
    });

    it('records a timeout when the entry never settles', async () => {
        vi.useFakeTimers();
        let settled = false;
        const loading = loadModules([EXAMPLE], { timeoutMs: 5000, importer: () => new Promise(() => {}) }).then(() => {
            settled = true;
        });
        await vi.advanceTimersByTimeAsync(4999);
        expect(settled).toBe(false);
        await vi.advanceTimersByTimeAsync(1);
        await loading;
        expect(failedModules()).toEqual([{ name: 'example', error: 'timeout' }]);
    });

    it('records not_registered when the entry loads without registering', async () => {
        await loadModules([EXAMPLE], { timeoutMs: 5000, importer: () => Promise.resolve({}) });
        expect(failedModules()).toEqual([{ name: 'example', error: 'not_registered' }]);
    });

    it('loads the others when one fails, and refuses a module registering after its timeout', async () => {
        vi.useFakeTimers();
        let late: () => void = () => {};
        const other = { name: 'other', route: '/other', entry: '/modules/other/entry.js' };
        const importer = (entry: string) => {
            if (entry === other.entry) {
                registerModule({ name: 'other', routes: [{ path: '/other', element: null }], catalogues: { en: {}, fi: {} } });
                return Promise.resolve({});
            }
            return new Promise<unknown>((resolve) => {
                late = () => {
                    registerModule({ name: 'example', routes: [{ path: '/example', element: null }], catalogues: { en: {}, fi: {} } });
                    resolve({});
                };
            });
        };
        const loading = loadModules([EXAMPLE, other], { timeoutMs: 5000, importer });
        await vi.advanceTimersByTimeAsync(5000);
        await loading;
        late();
        expect(registeredModules().map((m) => m.name)).toEqual(['other']);
        expect(failedModules()).toEqual([{ name: 'example', error: 'timeout' }]);
    });
});
