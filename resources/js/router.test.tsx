import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createRouter } from './router';
import { expectModules, failedModules, registeredModules, registerModule, resetModules } from './modules/registry';

let consoleError: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    resetModules();
    consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => consoleError.mockRestore());

function register(name: string, id: string) {
    registerModule({ name, routes: [{ id, path: `/${name}`, element: null }], catalogues: { en: {}, fi: {} } });
}

describe('createRouter', () => {
    it('builds the router with every registered module', () => {
        expectModules([{ name: 'alpha', route: '/alpha' }]);
        register('alpha', 'alpha-list');
        const router = createRouter();
        expect(router.routes).toBeDefined();
        expect(registeredModules().map((m) => m.name)).toEqual(['alpha']);
        router.dispose();
    });

    it('leaves out a module whose route ids repeat another module\'s, and keeps the console starting', () => {
        expectModules([
            { name: 'alpha', route: '/alpha' },
            { name: 'beta', route: '/beta' },
        ]);
        register('alpha', 'list');
        register('beta', 'list');
        expect(registeredModules()).toHaveLength(2);

        let router: ReturnType<typeof createRouter> | undefined;
        expect(() => {
            router = createRouter();
        }).not.toThrow();
        expect(registeredModules().map((m) => m.name)).toEqual(['alpha']);
        expect(failedModules()).toEqual([{ name: 'beta', error: 'route_clash' }]);
        router?.dispose();
    });
});
