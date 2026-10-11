import { vi } from 'vitest';
import { apiFetch, type ApiInit } from '../api/client';
import { ME, NAMES, type Role } from './domainServer';

/** A real-looking DKIM TXT value, long enough to wrap. */
export const DKIM_TXT =
    'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeLezV6ffAt0gunVTLw7onLRnrq0' +
    '/IzW7yWR7QkrmBL7jTKEn5u+qKhbwKfBstIs+bMY2Zkp18gnTxKLxoS2tFczGkPLPgizskuemMghRniWaoLcyehkd3qqGElvW/VDL5AaWTg0nLVkjRo9z+40R' +
    'QtuMKPnOW+3fZxGJHPIL0TfDtaBV3o5KoW+jJJE2aOZ5r5O3Q7c7Q';

export function publish(name: string, type: string, value: string) {
    return { name, type, value, zone: `${name} 3600 IN ${type} "${value}"` };
}

export type CheckFixture = {
    status: 'ok' | 'warn' | 'fail' | 'info';
    found: string[];
    expected?: string;
    hint_key: string;
    publish?: ReturnType<typeof publish>;
    selectors?: { selector: string; state: 'pending' | 'active'; published: boolean; p_sha256: string; publish: ReturnType<typeof publish> }[];
};

/** One check of each status: MX and PTR ok, SPF warn, DKIM and DMARC fail, MTA-STS and autoconfig info. */
export function checks(): Record<string, CheckFixture> {
    return {
        mx: { status: 'ok', found: ['10 mail.example.org'], expected: 'mail.example.org', hint_key: 'mx.ok' },
        spf: {
            status: 'warn',
            found: ['v=spf1 include:_spf.example.net ~all'],
            hint_key: 'spf.include_only',
            publish: publish('example.org.', 'TXT', 'v=spf1 mx include:_spf.example.net ~all'),
        },
        dkim: {
            status: 'fail',
            found: [],
            expected: DKIM_TXT,
            hint_key: 'dkim.missing',
            publish: publish('es20261001._domainkey.example.org.', 'TXT', DKIM_TXT),
            selectors: [
                { selector: 'es20261001', state: 'active', published: false, p_sha256: 'a1', publish: publish('es20261001._domainkey.example.org.', 'TXT', DKIM_TXT) },
            ],
        },
        dmarc: {
            status: 'fail',
            found: [],
            hint_key: 'dmarc.missing',
            publish: publish('_dmarc.example.org.', 'TXT', 'v=DMARC1; p=none; rua=mailto:postmaster@example.org'),
        },
        ptr: { status: 'ok', found: ['192.0.2.10 → mail.example.org'], expected: 'mail.example.org', hint_key: 'ptr.ok' },
        mta_sts: { status: 'info', found: [], hint_key: 'mta_sts.missing' },
        autoconfig: { status: 'info', found: ['CNAME mail.example.org'], hint_key: 'autoconfig.found' },
    };
}

export interface DkimKeyFixture {
    selector: string;
    state: 'pending' | 'active' | 'retired';
    txt: string | null;
    created_at: string | null;
}

export interface DkimFixture {
    active: string | null;
    keys: DkimKeyFixture[];
    pending_task: { type: string; status: 'pending' | 'running' | 'failed'; error: string | null } | null;
}

export function dkim(over: Partial<DkimFixture> = {}): DkimFixture {
    return {
        active: 'es20261001',
        keys: [
            { selector: 'es20261011', state: 'pending', txt: DKIM_TXT, created_at: '2026-10-11T08:00:00Z' },
            { selector: 'es20261001', state: 'active', txt: DKIM_TXT, created_at: '2026-10-01T08:00:00Z' },
            { selector: 'es20250101', state: 'retired', txt: DKIM_TXT, created_at: '2025-01-01T08:00:00Z' },
        ],
        pending_task: null,
        ...over,
    };
}

export interface DnsState {
    role: Role;
    dns: { result: { checked_at: string; checks: Record<string, CheckFixture> } | null; checking: boolean };
    /** Answers to successive `GET …/dkim` calls; the last one repeats. */
    dkim: DkimFixture[];
    write: (path: string, init: ApiInit) => Promise<unknown>;
}

export function dnsState(over: Partial<DnsState> = {}): DnsState {
    return {
        role: 'admin',
        dns: { result: { checked_at: new Date(Date.now() - 5 * 60_000).toISOString(), checks: checks() }, checking: false },
        dkim: [dkim()],
        write: () => Promise.resolve(undefined),
        ...over,
    };
}

/** Routes `apiFetch` for the DNS tab: names, me, the DNS check and the DKIM keys. */
export function dnsServer(state: DnsState) {
    let dkimReads = 0;
    vi.mocked(apiFetch).mockImplementation(((path: string, init: ApiInit = {}) => {
        if ((init.method ?? 'GET') !== 'GET') return state.write(path, init);
        if (path === '/domains/names') return Promise.resolve({ data: NAMES.map((n) => ({ ...n, role: state.role })) });
        if (path === '/me') return Promise.resolve({ ...ME, is_server_admin: state.role === 'admin' });
        if (/^\/domains\/[^/]+\/dns$/.test(path)) return Promise.resolve(state.dns);
        if (/^\/domains\/[^/]+\/dkim$/.test(path)) {
            const answer = state.dkim[Math.min(dkimReads, state.dkim.length - 1)];
            dkimReads++;
            return Promise.resolve(answer);
        }
        return Promise.reject(new Error(`unexpected ${path}`));
    }) as typeof apiFetch);
}

/** The non-GET calls made so far, as `[path, init]` pairs. */
export function dnsWrites(): [string, ApiInit][] {
    return vi
        .mocked(apiFetch)
        .mock.calls.filter(([, init]) => (init?.method ?? 'GET') !== 'GET')
        .map(([path, init]) => [path, init ?? {}]);
}

export function reads(pattern: RegExp): number {
    return vi.mocked(apiFetch).mock.calls.filter(([path, init]) => (init?.method ?? 'GET') === 'GET' && pattern.test(path)).length;
}
