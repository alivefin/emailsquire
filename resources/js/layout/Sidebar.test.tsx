import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { apiFetch } from '../api/client';
import { renderRoutes } from '../test/routing';
import { Sidebar } from './Sidebar';

vi.mock('../api/client', () => ({ apiFetch: vi.fn() }));

const DASHBOARD = {
    counts: {
        domains: 2,
        domains_added_this_month: 0,
        mailboxes: 10,
        forwarding: 5,
        forwarding_local: 3,
        forwarding_external: 2,
    },
    near_full: [],
    recent_activity: [],
    server_storage: null,
    usage_checked_at: null,
};

function serve(dashboard: unknown) {
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/dashboard') return Promise.resolve(dashboard);
        if (path === '/me/pinned-domains')
            return Promise.resolve({ data: [{ name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 7 }] });
        return Promise.resolve({
            data: [
                { name: 'example.org', unicode_name: 'example.org', mailbox_count: 3 },
                { name: 'xn--bcher-kva.example', unicode_name: 'bücher.example', mailbox_count: 7 },
            ],
        });
    });
}

beforeEach(() => vi.mocked(apiFetch).mockReset());

describe('Sidebar', () => {
    it('lists pinned domains with their mailbox counts and the counts', async () => {
        serve(DASHBOARD);
        renderRoutes([{ path: '/', element: <Sidebar /> }], '/');

        const pinned = await screen.findByRole('link', { name: /bücher\.example/ });
        expect(pinned).toHaveAttribute('href', '/domains/xn--bcher-kva.example/mailboxes');
        expect(pinned).toHaveTextContent('7');
        expect(await screen.findByRole('link', { name: /All addresses/ })).toHaveTextContent('15');
        expect(screen.getByRole('link', { name: /^Domains/ })).toHaveTextContent('2');
    });

    it('says "Not checked yet" when the server storage is null', async () => {
        serve(DASHBOARD);
        renderRoutes([{ path: '/', element: <Sidebar /> }], '/');
        expect(await screen.findByText('Not checked yet')).toBeInTheDocument();
    });

    it('shows used and total storage when known', async () => {
        serve({
            ...DASHBOARD,
            server_storage: { total_bytes: 8 * 1073741824, free_bytes: 2 * 1073741824, used_bytes: 6 * 1073741824, checked_at: '2026-10-10T10:00:00Z' },
        });
        renderRoutes([{ path: '/', element: <Sidebar /> }], '/');
        expect(await screen.findByText('6 GB')).toBeInTheDocument();
        expect(screen.getByText(/of 8 GB used/)).toBeInTheDocument();
    });

    it('highlights Domains on domain pages', async () => {
        serve(DASHBOARD);
        renderRoutes([{ path: '/domains/:d/mailboxes', element: <Sidebar /> }], '/domains/example.org/mailboxes');
        expect(await screen.findByRole('link', { name: /^Domains/ })).toHaveAttribute('aria-current', 'page');
    });
});
