import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { apiFetch } from './client';
import { refreshAccessOnRefusal } from './accessRefresh';
import { queryKeys } from './types';

const refused = (status: number, code: string) =>
    new Response(JSON.stringify({ title: 'T', status, code }), { status, headers: { 'Content-Type': 'application/problem+json' } });

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
    document.cookie = 'XSRF-TOKEN=a%3Db; path=/';
});
afterEach(() => vi.unstubAllGlobals());

describe('refreshAccessOnRefusal', () => {
    it.each([
        [403, 'forbidden'],
        [404, 'not_found'],
    ])('refetches the domain names and lists after a %i on a domain write, so stale controls drop', async (status, code) => {
        const client = new QueryClient();
        client.setQueryData(queryKeys.domainNames, { data: [] });
        client.setQueryData(queryKeys.domains({ page: 1 }), { data: [] });
        client.setQueryData(queryKeys.me, { id: 1 });
        const off = refreshAccessOnRefusal(client);
        vi.mocked(fetch).mockResolvedValueOnce(refused(status, code));

        await expect(apiFetch('/domains/example.org/mailboxes/anna/disable', { method: 'POST' })).rejects.toMatchObject({ status });

        expect(client.getQueryState(queryKeys.domainNames)?.isInvalidated).toBe(true);
        expect(client.getQueryState(queryKeys.domains({ page: 1 }))?.isInvalidated).toBe(true);
        expect(client.getQueryState(queryKeys.me)?.isInvalidated).toBe(false);
        off();
    });

    it('stops after the returned unsubscribe', async () => {
        const client = new QueryClient();
        client.setQueryData(queryKeys.domainNames, { data: [] });
        refreshAccessOnRefusal(client)();
        vi.mocked(fetch).mockResolvedValueOnce(refused(403, 'forbidden'));
        await expect(apiFetch('/domains/example.org/mailboxes', { method: 'POST', json: {} })).rejects.toMatchObject({ status: 403 });
        expect(client.getQueryState(queryKeys.domainNames)?.isInvalidated).toBe(false);
    });
});
