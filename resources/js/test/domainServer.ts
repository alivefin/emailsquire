import { vi } from 'vitest';
import { apiFetch, type ApiInit } from '../api/client';

export type Role = 'admin' | 'manager' | 'viewer';

export const ME = {
    id: 1,
    name: 'Anna Admin',
    email: 'anna@example.org',
    is_server_admin: true,
    managers_can_share: false,
    landing: null as string | null,
};

export const NAMES: { name: string; unicode_name: string; mailbox_count: number; role: Role }[] = [
    { name: 'example.org', unicode_name: 'example.org', mailbox_count: 3, role: 'admin' },
    { name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 1, role: 'admin' },
    { name: 'zeta.example', unicode_name: 'zeta.example', mailbox_count: 0, role: 'admin' },
];

export const DOMAIN_ROWS = NAMES.map((n) => ({
    ...n,
    forwarding_count: 2,
    used_bytes: null,
    quota_bytes_allocated: 0,
    max_mailboxes: null as number | null,
    max_quota_bytes_total: null as number | null,
    people_count: 0,
    people: [] as string[],
}));

const GIB = 1073741824;

export function mailbox(local: string, over: Record<string, unknown> = {}) {
    return {
        local,
        address: `${local}@example.org`,
        display_name: null,
        quota_bytes: 5 * GIB,
        quota_is_default: true,
        status: 'active',
        used_bytes: GIB,
        usage_checked_at: '2026-10-10T08:00:00Z',
        forwarding_count: 0,
        ...over,
    };
}

export const MAILBOXES = [
    mailbox('anna', { display_name: 'Anna Admin', forwarding_count: 2 }),
    mailbox('bob', { used_bytes: null, usage_checked_at: null, quota_is_default: false, quota_bytes: 10 * GIB }),
    mailbox('sales', { status: 'disabled' }),
];

export interface FakeServer {
    pinned: Set<string>;
    mailboxes: typeof MAILBOXES;
    meta: { page: number; per_page: number; total: number; last_page: number };
    /** Overrides for writes: return a value or a promise; undefined falls back to a 204. */
    write?: (path: string, init: ApiInit) => unknown;
    /** The requester's role on every domain; a non-admin role makes `me` a non-admin too. */
    role: Role;
    /** Overrides for the domain rows (`GET /domains`) and the settings (`GET /domains/{d}/settings`). */
    rows: Partial<(typeof DOMAIN_ROWS)[number]>;
    settings: { max_mailboxes: number | null; max_quota_bytes_total: number | null; usage: { mailboxes: number; quota_bytes_allocated: number } };
}

/** Routes `apiFetch` calls to an in-memory server so the page renders end to end. */
export function fakeServer(over: Partial<FakeServer> = {}): FakeServer {
    const server: FakeServer = {
        pinned: new Set<string>(),
        mailboxes: MAILBOXES,
        meta: { page: 1, per_page: 10, total: MAILBOXES.length, last_page: 1 },
        role: 'admin',
        rows: {},
        settings: { max_mailboxes: null, max_quota_bytes_total: null, usage: { mailboxes: 3, quota_bytes_allocated: 15 * GIB } },
        ...over,
    };
    const me = { ...ME, is_server_admin: server.role === 'admin' };
    const names = NAMES.map((n) => ({ ...n, role: server.role }));
    const rows = DOMAIN_ROWS.map((r) => ({ ...r, role: server.role, ...server.rows }));
    vi.mocked(apiFetch).mockImplementation(((path: string, init: ApiInit = {}) => {
        const method = init.method ?? 'GET';
        if (method !== 'GET') {
            if (path.startsWith('/me/pinned-domains/')) {
                const name = decodeURIComponent(path.slice('/me/pinned-domains/'.length));
                if (method === 'PUT') server.pinned.add(name);
                else server.pinned.delete(name);
            }
            return Promise.resolve(server.write?.(path, init));
        }
        if (path === '/me') return Promise.resolve(me);
        if (path === '/domains/names') return Promise.resolve({ data: names });
        if (path === '/me/pinned-domains') {
            return Promise.resolve({ data: NAMES.filter((n) => server.pinned.has(n.name)) });
        }
        if (path === '/domains') return Promise.resolve({ data: rows, meta: { page: 1, per_page: 200, total: 3, last_page: 1 } });
        if (/^\/domains\/[^/]+\/settings$/.test(path)) return Promise.resolve(server.settings);
        if (path === '/dashboard') {
            return Promise.resolve({ counts: { mailboxes: 4, forwarding: 6 }, server_storage: null });
        }
        if (/^\/domains\/[^/]+\/mailboxes$/.test(path)) return Promise.resolve({ data: server.mailboxes, meta: server.meta });
        const one = /^\/domains\/[^/]+\/mailboxes\/([^/]+)$/.exec(path);
        const found = one ? server.mailboxes.find((m) => m.local === decodeURIComponent(one[1] ?? '')) : undefined;
        if (found) return Promise.resolve(found);
        return Promise.resolve({ data: [], meta: { page: 1, per_page: 10, total: 0, last_page: 1 } });
    }) as typeof apiFetch);
    return server;
}

/** The non-GET calls made so far, as `[path, init]` pairs. */
export function writes(): [string, ApiInit][] {
    return vi
        .mocked(apiFetch)
        .mock.calls.filter(([, init]) => (init?.method ?? 'GET') !== 'GET')
        .map(([path, init]) => [path, init ?? {}]);
}
