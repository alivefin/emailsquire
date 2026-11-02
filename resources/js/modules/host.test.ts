import { describe, expect, it } from 'vitest';
import * as React from 'react';
import * as ReactQuery from '@tanstack/react-query';
import { QueryClient } from '@tanstack/react-query';
import { SHARED } from '../../../packages/module-sdk/vite.mjs';
import { apiFetch } from '../api/client';
import { installHost, type Host } from './host';
import { registerModule } from './registry';

/** Every key of `T`: a missing key or an extra one fails the type check. */
type Keys<T> = { [K in keyof T]: true };

const HOST: Keys<Host> = { apiVersion: true, libs: true, api: true, hooks: true, format: true, ui: true, queryClient: true, registerModule: true };
const LIBS: Keys<Host['libs']> = {
    react: true,
    'react/jsx-runtime': true,
    'react-dom': true,
    'react-router': true,
    '@tanstack/react-query': true,
    i18next: true,
    'react-i18next': true,
};
const API: Keys<Host['api']> = { apiFetch: true, ApiProblem: true, problemMessage: true, fieldErrors: true, isStepUpCancelled: true };
const HOOKS: Keys<Host['hooks']> = { useMe: true };
const FORMAT: Keys<Host['format']> = { formatBytes: true, formatBytesOf: true, formatRelative: true, formatDay: true, formatDate: true };
const UI: Keys<Host['ui']> = {
    Button: true,
    CardList: true,
    Chip: true,
    ConfirmDialog: true,
    DataTable: true,
    Dialog: true,
    Drawer: true,
    EmptyState: true,
    Field: true,
    PageSpinner: true,
    Select: true,
    Switch: true,
    TextInput: true,
    Pager: true,
    StatCard: true,
    useToast: true,
};

function installed(): Host {
    return (globalThis as { __emailsquire?: Host }).__emailsquire!;
}

describe('installHost', () => {
    it('sets a frozen host object on globalThis', () => {
        const client = new QueryClient();
        const host = installHost(client);
        expect(installed()).toBe(host);
        expect(Object.isFrozen(host)).toBe(true);
        for (const group of [host.libs, host.api, host.hooks, host.format, host.ui]) expect(Object.isFrozen(group)).toBe(true);
        expect(host.queryClient).toBe(client);
    });

    it('is version 1.1.0 and shares the core\'s own library objects', () => {
        const host = installHost(new QueryClient());
        expect(host.apiVersion).toBe('1.1.0');
        expect(host.libs.react).toBe(React);
        expect(host.libs['@tanstack/react-query']).toBe(ReactQuery);
        expect(host.api.apiFetch).toBe(apiFetch);
        expect(host.registerModule).toBe(registerModule);
    });

    it('has every key of Host and no other', () => {
        const host = installHost(new QueryClient());
        const keys = (o: object) => Object.keys(o).sort();
        expect(keys(host)).toEqual(keys(HOST));
        expect(keys(host.libs)).toEqual(keys(LIBS));
        expect(keys(host.api)).toEqual(keys(API));
        expect(keys(host.hooks)).toEqual(keys(HOOKS));
        expect(keys(host.format)).toEqual(keys(FORMAT));
        expect(keys(host.ui)).toEqual(keys(UI));
        for (const value of [...Object.values(host.api), ...Object.values(host.hooks), ...Object.values(host.format), ...Object.values(host.ui)]) {
            expect(value).toBeTypeOf('function');
        }
    });

    it('shares exactly the libraries the module SDK leaves out of a bundle', () => {
        expect([...SHARED].sort()).toEqual(Object.keys(installHost(new QueryClient()).libs).sort());
    });
});
