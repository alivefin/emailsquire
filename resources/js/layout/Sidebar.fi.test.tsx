import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { apiFetch } from '../api/client';
import { setLocale } from '../i18n';
import { renderRoutes } from '../test/routing';
import { Sidebar } from './Sidebar';

vi.mock('../api/client', () => ({ apiFetch: vi.fn() }));

const DASHBOARD = {
    counts: { domains: 0, domains_added_this_month: 0, mailboxes: 0, forwarding: 0, forwarding_local: 0, forwarding_external: 0 },
    near_full: { items: [] },
    recent_activity: [],
    server_storage: null,
    usage_checked_at: null,
};

function serve(domainCount: number) {
    const data = Array.from({ length: domainCount }, (_, i) => ({ name: `d${i}.example`, unicode_name: `d${i}.example`, mailbox_count: 1 }));
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/dashboard') return Promise.resolve(DASHBOARD);
        if (path === '/me/pinned-domains') return Promise.resolve({ data: [] });
        return Promise.resolve({ data });
    });
}

beforeEach(() => vi.mocked(apiFetch).mockReset());

describe('Sidebar in Finnish', () => {
    it('uses Finnish labels', async () => {
        await setLocale('fi');
        serve(1);
        renderRoutes([{ path: '/', element: <Sidebar /> }], '/');
        expect(await screen.findByRole('link', { name: /^Verkkotunnukset/ })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Kaikki osoitteet/ })).toBeInTheDocument();
        expect(screen.getByText('Ei vielä tarkistettu')).toBeInTheDocument();
    });

    it.each([
        [0, '0 verkkotunnusta'],
        [1, '1 verkkotunnus'],
        [2, '2 verkkotunnusta'],
    ])('pluralises the domain count for %i', async (count, title) => {
        await setLocale('fi');
        serve(count);
        renderRoutes([{ path: '/', element: <Sidebar /> }], '/');
        expect(await screen.findByTitle(title)).toBeInTheDocument();
    });

    it.each([
        [0, '0 domains'],
        [1, '1 domain'],
        [2, '2 domains'],
    ])('pluralises the domain count in English for %i', async (count, title) => {
        serve(count);
        renderRoutes([{ path: '/', element: <Sidebar /> }], '/');
        expect(await screen.findByTitle(title)).toBeInTheDocument();
    });
});
