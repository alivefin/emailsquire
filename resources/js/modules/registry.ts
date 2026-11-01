import type { ComponentType } from 'react';
import { createMemoryRouter, type RouteObject } from 'react-router';
import i18next from 'i18next';

/** What a module's entry passes to `registerModule()`. */
export type ModuleDefinition = {
    name: string;
    /** Top-level paths are the module's manifest route or below it. */
    routes: RouteObject[];
    /** Catalogue file name → content; a key reads `t('<module>:<file>.<key>')`. */
    catalogues: { en: Record<string, object>; fi: Record<string, object> };
    /** Card components by the keys `/me` lists in `dashboard_cards`. */
    dashboardCards?: Record<string, ComponentType>;
};

/** Why a module isn't running. Stable snake_case codes, like the server's refusals. */
export type ModuleError = 'not_listed' | 'invalid_definition' | 'route_clash' | 'import_failed' | 'timeout' | 'not_registered';

export type ModuleFailure = { name: string; error: ModuleError };

// The console's own top-level paths. `/` clashes only by equality; the rest also with every path below them.
const CORE_PATHS = ['/domains', '/addresses', '/blocked', '/users', '/activity', '/profile', '/my', '/signin', '/setup', '/forgot-password', '/reset-password', '/invite'];

let listed = new Map<string, string>();
const registered: ModuleDefinition[] = [];
// Each registered module's manifest route, kept apart from `listed` so later lists can't change it.
const registeredRoutes: string[] = [];
const failed: ModuleFailure[] = [];

/** The modules the shell lists, by name, with their manifest routes. Only these may register. */
export function expectModules(list: { name: string; route: string }[]): void {
    listed = new Map(list.map((m) => [m.name, m.route]));
}

export function registeredModules(): ModuleDefinition[] {
    return [...registered];
}

export function failedModules(): ModuleFailure[] {
    return [...failed];
}

/** True once the module registered or failed: loading checks it after the entry has run. */
export function moduleSettled(name: string): boolean {
    return registered.some((m) => m.name === name) || failed.some((f) => f.name === name);
}

/** Records a module that can't run and logs why, for the browser console the admin notice points to. */
export function recordFailure(name: string, error: ModuleError, cause?: unknown): void {
    failed.push({ name, error });
    console.error(`EmailSquire module ${name}: ${error}`, ...(cause === undefined ? [] : [cause]));
}

function equalOrUnder(path: string, base: string): boolean {
    return path === base || path.startsWith(`${base}/`);
}

function clashes(path: string): boolean {
    const p = path.toLowerCase();
    return p === '/' || CORE_PATHS.some((core) => equalOrUnder(p, core)) || registeredRoutes.some((route) => equalOrUnder(p, route.toLowerCase()));
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function isDefinition(def: unknown): def is ModuleDefinition {
    if (!isObject(def)) return false;
    const { catalogues } = def;
    return (
        typeof def.name === 'string' &&
        Array.isArray(def.routes) &&
        def.routes.every(isObject) &&
        isObject(catalogues) &&
        [catalogues.en, catalogues.fi].every((files) => isObject(files) && Object.values(files).every(isObject)) &&
        (def.dashboardCards === undefined || isObject(def.dashboardCards))
    );
}

// The console wraps a module's top-level elements in its error boundary, which would hide a lazy route's
// own element; modules split their code with `import()` inside a component instead.
function hasLazy(routes: RouteObject[]): boolean {
    return routes.some((r) => isObject(r) && (r.lazy !== undefined || (Array.isArray(r.children) && hasLazy(r.children))));
}

/**
 * Whether React Router accepts the routes on their own: it throws on an index route with children, an
 * absolute child path outside its parent, a repeated id or a malformed child. Checked here because the
 * same error in the console's router would stop it from starting.
 */
export function routerAccepts(routes: RouteObject[]): boolean {
    try {
        createMemoryRouter(routes).dispose();
        return true;
    } catch {
        return false;
    }
}

/** Adds a module's catalogues to i18next. Shared with the SDK's `renderWithHost`. */
export function registerCatalogues(name: string, catalogues: ModuleDefinition['catalogues']): void {
    for (const lang of ['en', 'fi'] as const) {
        // One namespace per module, a top-level key per file: `example:nav.notes` is `nav.json`'s `notes`.
        // i18next splits a key at the first `:`, so an `example:nav` namespace would be unreachable.
        i18next.addResourceBundle(lang, name, catalogues[lang], true, true);
    }
}

/**
 * Called by a module's entry. A refusal is recorded and logged, never thrown, so one module can't stop
 * the console from starting.
 */
export function registerModule(def: ModuleDefinition): void {
    const name: unknown = (def as { name?: unknown } | null)?.name;
    if (typeof name !== 'string') {
        // Nameless: the listed module it came from is reported as not registered.
        console.error('EmailSquire module: registerModule() needs a definition with a name', def);
        return;
    }
    // A second registration, or one after a timeout: the router is already built with or without it.
    if (moduleSettled(name)) {
        console.error(`EmailSquire module ${name}: registered more than once or too late; ignored`);
        return;
    }
    const route = listed.get(name);
    if (route === undefined) return recordFailure(name, 'not_listed');
    if (!isDefinition(def) || hasLazy(def.routes)) return recordFailure(name, 'invalid_definition');
    const paths = def.routes.map((r) => r.path);
    if (!paths.every((p) => typeof p === 'string' && equalOrUnder(p, route) && !clashes(p))) return recordFailure(name, 'route_clash');
    if (!routerAccepts(def.routes)) return recordFailure(name, 'invalid_definition');

    registerCatalogues(name, def.catalogues);
    registered.push(def);
    registeredRoutes.push(route);
}

/** Takes a registered module out again: the router couldn't be built with it. */
export function refuseModule(name: string, error: ModuleError): void {
    const index = registered.findIndex((m) => m.name === name);
    if (index === -1) return;
    registered.splice(index, 1);
    registeredRoutes.splice(index, 1);
    for (const lang of ['en', 'fi']) i18next.removeResourceBundle(lang, name);
    recordFailure(name, error);
}

/** Forgets every module and its catalogues. For tests. */
export function resetModules(): void {
    for (const m of registered) {
        for (const lang of ['en', 'fi']) i18next.removeResourceBundle(lang, m.name);
    }
    registered.length = 0;
    registeredRoutes.length = 0;
    failed.length = 0;
    listed = new Map();
}
