import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { apiFetch } from '../api/client';
import { queryKeys } from '../api/types';
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

const ADMIN = { id: 1, name: 'Anna Admin', email: 'anna@example.org', is_server_admin: true, managers_can_share: false, landing: null };

function serve(dashboard: unknown, me: unknown = ADMIN) {
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/me') return Promise.resolve(me);
        if (path === '/dashboard') return Promise.resolve(dashboard);
        if (path === '/users') return Promise.resolve({ data: [], meta: { page: 1, per_page: 1, total: 2, last_page: 2 } });
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

    it('links a server admin to Users under Server', async () => {
        serve(DASHBOARD);
        renderRoutes([{ path: '/', element: <Sidebar /> }], '/');
        expect(await screen.findByRole('link', { name: /^Users/ })).toHaveAttribute('href', '/users');
        expect(screen.getByRole('link', { name: 'Blocked addresses' })).toBeInTheDocument();
    });

    it('counts the users next to Users for a server admin', async () => {
        serve(DASHBOARD);
        const base = vi.mocked(apiFetch).getMockImplementation()!;
        vi.mocked(apiFetch).mockImplementation((path: string, init?: object) =>
            path === '/users' ? Promise.resolve({ data: [], meta: { page: 1, per_page: 1, total: 38, last_page: 38 } }) : base(path, init),
        );
        renderRoutes([{ path: '/', element: <Sidebar /> }], '/');
        const link = await screen.findByRole('link', { name: /^Users/ });
        await waitFor(() => expect(link).toHaveTextContent('Users38'));
        expect(screen.getByTitle('38 users')).toBeInTheDocument();
        expect(apiFetch).toHaveBeenCalledWith('/users', { query: { per_page: 1 } });
    });

    it('shows no Users link and no server storage to someone who is not a server admin', async () => {
        const manager = { ...ADMIN, is_server_admin: false };
        serve(DASHBOARD, manager);
        const { client } = renderRoutes([{ path: '/', element: <Sidebar /> }], '/');
        expect(await screen.findByRole('link', { name: 'Blocked addresses' })).toBeInTheDocument();
        await waitFor(() => expect(client.getQueryData(queryKeys.me)).toEqual(manager));
        await screen.findByRole('link', { name: /bücher\.example/ });
        expect(screen.queryByRole('link', { name: /^Users/ })).not.toBeInTheDocument();
        expect(apiFetch).not.toHaveBeenCalledWith('/users', expect.anything());
        expect(screen.queryByText('Server storage')).not.toBeInTheDocument();
        expect(screen.queryByText('Not checked yet')).not.toBeInTheDocument();
    });

    it('highlights Users on the Users screen', async () => {
        serve(DASHBOARD);
        renderRoutes([{ path: '/users', element: <Sidebar /> }], '/users');
        expect(await screen.findByRole('link', { name: /^Users/ })).toHaveAttribute('aria-current', 'page');
    });

    it('highlights Domains on domain pages', async () => {
        serve(DASHBOARD);
        renderRoutes([{ path: '/domains/:d/mailboxes', element: <Sidebar /> }], '/domains/example.org/mailboxes');
        expect(await screen.findByRole('link', { name: /^Domains/ })).toHaveAttribute('aria-current', 'page');
    });
});
