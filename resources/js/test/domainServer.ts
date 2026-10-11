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
    /** What `GET /domains/{d}/access` answers. */
    access: { people: AccessPerson[]; invitations: AccessInvitation[] };
    /** Overrides for `me` (`managers_can_share`, say). */
    me: Partial<typeof ME>;
    /** The rows `GET /audit-log` answers (filtered by `domain`, cut to `per_page`). */
    audit: AuditFixtureRow[];
}

export interface AuditFixtureRow {
    id: number;
    occurred_at: string;
    actor_kind: 'you' | 'user' | 'deleted_user' | 'cli' | 'system';
    actor_name: string;
    action: string;
    object_type: string;
    object_key: string;
    domain_name: string | null;
    changes: Record<string, unknown> | null;
}

export interface AccessPerson {
    id: number;
    name: string;
    email: string;
    role: 'manager' | 'viewer';
    status: 'active' | 'deactivated';
    granted_by_name: string | null;
    granted_at: string | null;
}

export interface AccessInvitation {
    id: number;
    email: string;
    role: 'manager' | 'viewer';
    invited_by_name: string | null;
    created_at: string | null;
    expires_at: string;
    state: 'valid' | 'expired' | 'revoked';
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
        access: { people: [], invitations: [] },
        me: {},
        audit: [],
        ...over,
    };
    const me = { ...ME, is_server_admin: server.role === 'admin', ...server.me };
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
        if (/^\/domains\/[^/]+\/access$/.test(path)) return Promise.resolve(server.access);
        // The sidebar's user count (`/users?per_page=1`) for a server admin.
        if (path === '/users') return Promise.resolve({ data: [], meta: { page: 1, per_page: 1, total: 3, last_page: 3 } });
        if (path === '/dashboard') {
            // Enough for the dashboard to draw when a test lands on `/` (say, after leaving a domain).
            return Promise.resolve({
                counts: { domains: 3, domains_added_this_month: 0, mailboxes: 4, forwarding: 6, forwarding_local: 6, forwarding_external: 0 },
                near_full: { count: 0, items: [] },
                recent_activity: [],
                server_storage: null,
                usage_checked_at: null,
            });
        }
        if (path === '/audit-log') {
            const query = init.query ?? {};
            const rows = server.audit.filter((r) => query.domain === undefined || r.domain_name === query.domain);
            const perPage = Number(query.per_page ?? 10);
            return Promise.resolve({
                data: rows.slice(0, perPage),
                meta: { page: 1, per_page: perPage, total: rows.length, last_page: Math.max(1, Math.ceil(rows.length / perPage)) },
            });
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
