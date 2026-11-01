import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import i18next from 'i18next';
import { setLocale } from '../i18n';
import { expectModules, failedModules, registeredModules, registerModule, resetModules, type ModuleDefinition } from './registry';

function example(over: Partial<ModuleDefinition> = {}): ModuleDefinition {
    return {
        name: 'example',
        routes: [{ path: '/example', element: null }],
        catalogues: { en: { nav: { notes: 'Notes' } }, fi: { nav: { notes: 'Muistiinpanot' } } },
        ...over,
    };
}

let consoleError: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    resetModules();
    expectModules([
        { name: 'example', route: '/example' },
        { name: 'other', route: '/other' },
    ]);
    consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => consoleError.mockRestore());

describe('registerModule', () => {
    it('registers a listed module', () => {
        registerModule(example());
        expect(registeredModules().map((m) => m.name)).toEqual(['example']);
        expect(failedModules()).toEqual([]);
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('refuses a second module with the same name', () => {
        registerModule(example());
        registerModule(example());
        expect(registeredModules()).toHaveLength(1);
        // The first one runs, so the admin notice doesn't name it; the console says why the second was ignored.
        expect(failedModules()).toEqual([]);
        expect(consoleError).toHaveBeenCalledOnce();
    });

    it('refuses a module the shell did not list', () => {
        registerModule(example({ name: 'stranger', routes: [{ path: '/stranger', element: null }] }));
        expect(registeredModules()).toEqual([]);
        expect(failedModules()).toEqual([{ name: 'stranger', error: 'not_listed' }]);
    });

    it.each([['/domains/x'], ['/domains'], ['/my/notes'], ['/signin']])('refuses route %s with route_clash', (path) => {
        expectModules([{ name: 'example', route: '/domains' }]);
        registerModule(example({ routes: [{ path, element: null }] }));
        expect(registeredModules()).toEqual([]);
        expect(failedModules()).toEqual([{ name: 'example', error: 'route_clash' }]);
    });

    it('refuses a route outside the module\'s own route', () => {
        registerModule(example({ routes: [{ path: '/example', element: null }, { path: '/elsewhere', element: null }] }));
        expect(failedModules()).toEqual([{ name: 'example', error: 'route_clash' }]);
    });

    it('refuses a top-level route without a path', () => {
        registerModule(example({ routes: [{ index: true, element: null }] }));
        expect(failedModules()).toEqual([{ name: 'example', error: 'route_clash' }]);
    });

    it('accepts paths below its route, and a route that only starts like a core path', () => {
        expectModules([{ name: 'domainsx', route: '/domainsx' }]);
        registerModule(example({ name: 'domainsx', routes: [{ path: '/domainsx', element: null }, { path: '/domainsx/:id', element: null }] }));
        expect(registeredModules().map((m) => m.name)).toEqual(['domainsx']);
    });

    it('refuses a route equal to or under another module\'s', () => {
        expectModules([
            { name: 'example', route: '/example' },
            { name: 'twin', route: '/example' },
        ]);
        registerModule(example());
        registerModule(example({ name: 'twin', routes: [{ path: '/example/x', element: null }] }));
        expect(registeredModules().map((m) => m.name)).toEqual(['example']);
        expect(failedModules()).toEqual([{ name: 'twin', error: 'route_clash' }]);
    });

    it('keeps checking against registered routes after a new list', () => {
        registerModule(example());
        expectModules([{ name: 'other', route: '/other' }]);
        registerModule(example({ name: 'other', routes: [{ path: '/other', element: null }] }));
        expect(registeredModules().map((m) => m.name)).toEqual(['example', 'other']);
    });

    it('refuses a malformed definition', () => {
        registerModule({ name: 'example' } as unknown as ModuleDefinition);
        expect(failedModules()).toEqual([{ name: 'example', error: 'invalid_definition' }]);
        registerModule(null as unknown as ModuleDefinition);
        expect(failedModules()).toHaveLength(1);
        expect(registeredModules()).toEqual([]);
    });

    it('makes the catalogues reachable in English and Finnish', async () => {
        registerModule(example());
        expect(i18next.t('example:nav.notes')).toBe('Notes');
        await setLocale('fi');
        expect(i18next.t('example:nav.notes')).toBe('Muistiinpanot');
    });
});
