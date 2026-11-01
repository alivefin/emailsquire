import type { ComponentType } from 'react';
import type { RouteObject } from 'react-router';
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

function isDefinition(def: unknown): def is ModuleDefinition {
    if (typeof def !== 'object' || def === null) return false;
    const d = def as Record<string, unknown>;
    const catalogues = d.catalogues as Record<string, unknown> | null | undefined;
    return (
        typeof d.name === 'string' &&
        Array.isArray(d.routes) &&
        typeof catalogues === 'object' &&
        catalogues !== null &&
        typeof catalogues.en === 'object' &&
        typeof catalogues.fi === 'object' &&
        (d.dashboardCards === undefined || typeof d.dashboardCards === 'object')
    );
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
    if (!isDefinition(def)) return recordFailure(name, 'invalid_definition');
    const paths = def.routes.map((r) => r.path);
    if (!paths.every((p) => typeof p === 'string' && equalOrUnder(p, route) && !clashes(p))) return recordFailure(name, 'route_clash');

    for (const lang of ['en', 'fi'] as const) {
        // One namespace per module, a top-level key per file: `example:nav.notes` is `nav.json`'s `notes`.
        // i18next splits a key at the first `:`, so an `example:nav` namespace would be unreachable.
        i18next.addResourceBundle(lang, name, def.catalogues[lang], true, true);
    }
    registered.push(def);
    registeredRoutes.push(route);
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
