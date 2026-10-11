import { vi } from 'vitest';
import { apiFetch, type ApiInit } from '../api/client';
import { ApiProblem } from '../api/problems';
import { fakeServer, type FakeServer } from './domainServer';

export interface UserRow {
    id: number;
    name: string;
    email: string;
    is_server_admin: boolean;
    status: 'active' | 'deactivated';
    two_factor_confirmed: boolean;
    manager_of: number;
    viewer_of: number;
    last_login_at: string | null;
}

export interface UserGrant {
    domain: string;
    domain_unicode: string;
    role: 'manager' | 'viewer';
    granted_by_name: string | null;
    granted_at: string | null;
}

export interface UserInvitation {
    id: number;
    role: 'server_admin' | 'manager' | 'viewer';
    domain: string | null;
    domain_unicode: string | null;
    invited_by_name: string | null;
    created_at: string | null;
    expires_at: string;
    state: 'valid' | 'expired' | 'revoked';
}

export type UserDetail = UserRow & { grants: UserGrant[]; invitations: UserInvitation[] };

/** A row of `GET /users/invitations`: an invitation to anyone, with its email. */
export type ListedInvitation = UserInvitation & { email: string };

/** A server-admin invitation to an address with no account, and an expired domain invitation. */
export const PENDING: ListedInvitation[] = [
    {
        id: 81,
        email: 'boss@example.com',
        role: 'server_admin',
        domain: null,
        domain_unicode: null,
        invited_by_name: 'Anna Admin',
        created_at: '2026-10-09T10:00:00Z',
        expires_at: '2026-10-12T10:00:00Z',
        state: 'valid',
    },
    {
        id: 82,
        email: 'sam@example.net',
        role: 'manager',
        domain: 'xn--bcher-kva.example',
        domain_unicode: 'bücher.example',
        invited_by_name: null,
        created_at: '2026-10-08T10:00:00Z',
        expires_at: '2026-10-11T10:00:00Z',
        state: 'expired',
    },
];

/** The signed-in admin (`ME`, id 1). */
export const ANNA: UserRow = {
    id: 1,
    name: 'Anna Admin',
    email: 'anna@example.org',
    is_server_admin: true,
    status: 'active',
    two_factor_confirmed: true,
    manager_of: 0,
    viewer_of: 0,
    last_login_at: '2026-10-01T09:00:00Z',
};

export const LAURA: UserRow = {
    id: 7,
    name: 'Laura Kivi',
    email: 'laura@example.com',
    is_server_admin: false,
    status: 'active',
    two_factor_confirmed: true,
    manager_of: 2,
    viewer_of: 1,
    last_login_at: '2026-10-02T09:00:00Z',
};

export const MIA: UserRow = {
    id: 9,
    name: '',
    email: 'mia@example.net',
    is_server_admin: false,
    status: 'deactivated',
    two_factor_confirmed: false,
    manager_of: 0,
    viewer_of: 0,
    last_login_at: null,
};

export const LAURA_DETAIL: UserDetail = {
    ...LAURA,
    grants: [
        { domain: 'example.org', domain_unicode: 'example.org', role: 'manager', granted_by_name: 'Anna Admin', granted_at: '2026-10-08T09:00:00Z' },
    ],
    invitations: [
        {
            id: 51,
            role: 'viewer',
            domain: 'zeta.example',
            domain_unicode: 'zeta.example',
            invited_by_name: 'Anna Admin',
            created_at: '2026-10-09T09:00:00Z',
            expires_at: '2026-10-12T09:00:00Z',
            state: 'valid',
        },
    ],
};

export const ANNA_DETAIL: UserDetail = { ...ANNA, grants: [], invitations: [] };

export interface UsersServerOptions extends Partial<FakeServer> {
    list?: UserRow[];
    details?: Record<number, UserDetail>;
    /** `GET /users/invitations`, read on each request: a test may change it. None by default. */
    pending?: ListedInvitation[];
}

/**
 * `fakeServer` plus `GET /users`, `GET /users/{id}` and `GET /users/invitations` (from `server.pending`);
 * writes still go to `server.write`.
 */
export function usersServer({ list = [ANNA, LAURA, MIA], details = { 1: ANNA_DETAIL, 7: LAURA_DETAIL }, pending = [], ...over }: UsersServerOptions = {}) {
    const server = Object.assign(fakeServer(over), { pending });
    const base = vi.mocked(apiFetch).getMockImplementation()!;
    vi.mocked(apiFetch).mockImplementation(((path: string, init: ApiInit = {}) => {
        if ((init.method ?? 'GET') === 'GET') {
            if (path === '/users') {
                return Promise.resolve({ data: list, meta: { page: 1, per_page: 10, total: list.length, last_page: 1 } });
            }
            if (path === '/users/invitations') {
                const rows = server.pending;
                return Promise.resolve({ data: rows, meta: { page: 1, per_page: 10, total: rows.length, last_page: 1 } });
            }
            const one = /^\/users\/(\d+)$/.exec(path);
            if (one) {
                const found = details[Number(one[1])];
                return found ? Promise.resolve(found) : Promise.reject(new ApiProblem({ status: 404, title: 'Not Found' }));
            }
        }
        return base(path, init);
    }) as typeof apiFetch);
    return server;
}
