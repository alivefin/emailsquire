import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { ME, NAMES, fakeServer } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const mocked = vi.mocked(apiFetch);

const ROWS = [
    { address: 'anna@example.org', type: 'mailbox', domain: 'example.org', display_name: 'Anna', quota_bytes: 1, used_bytes: 1, targets: null },
    { address: 'info@example.org', type: 'forwarding', domain: 'example.org', display_name: null, quota_bytes: null, used_bytes: null, targets: ['anna@example.org'] },
    { address: 'kirja@xn--bcher-kva.example', type: 'mailbox', domain: 'xn--bcher-kva.example', display_name: null, quota_bytes: 1, used_bytes: 1, targets: null },
];

function serve(rows: unknown[] = ROWS) {
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        if (path === '/addresses') return Promise.resolve({ data: rows, meta: { page: 1, per_page: 10, total: rows.length, last_page: 1 } });
        if (path === '/me') return Promise.resolve(ME);
        if (path === '/me/pinned-domains') return Promise.resolve({ data: [] });
        if (path === '/domains/names') return Promise.resolve({ data: NAMES });
        return Promise.resolve({ data: [], meta: { page: 1, per_page: 10, total: 0, last_page: 1 }, init });
    }) as typeof apiFetch);
}

function addressCalls() {
    return mocked.mock.calls.filter(([p]) => p === '/addresses');
}

beforeEach(() => {
    mocked.mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

describe('AddressesPage', () => {
    it('lists addresses with the domain in unicode and a type chip', async () => {
        serve();
        renderRoutes(routes, '/addresses');
        const row = (await screen.findByText('kirja@bücher.example')).closest('tr') as HTMLElement;
        expect(within(row).getByText('Mailbox')).toBeInTheDocument();
        const fwd = within(screen.getByText('info@example.org').closest('tr') as HTMLElement);
        expect(fwd.getByText('Forwarding')).toBeInTheDocument();
    });

    it('asks the API with the URL state', async () => {
        serve();
        renderRoutes(routes, '/addresses?q=an&type=mailbox&domain=example.org&page=2');
        await waitFor(() => expect(addressCalls().length).toBeGreaterThan(0));
        expect(addressCalls()[0]?.[1]).toEqual({
            query: { q: 'an', type: 'mailbox', domain: 'example.org', page: 2, limit: 10 },
        });
    });

    it('maps the Forwarding filter to type=forward and keeps all out of the URL', async () => {
        const user = userEvent.setup();
        serve();
        const { router } = renderRoutes(routes, '/addresses');
        await screen.findByText('kirja@bücher.example');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Type' }), 'Forwarding');
        await waitFor(() => expect(router.state.location.search).toBe('?type=forward'));
        await waitFor(() => expect(addressCalls().some(([, i]) => i?.query?.type === 'forward')).toBe(true));
        await user.selectOptions(screen.getByRole('combobox', { name: 'Type' }), 'All');
        await waitFor(() => expect(router.state.location.search).toBe(''));
    });

    it('filters by domain', async () => {
        const user = userEvent.setup();
        serve();
        const { router } = renderRoutes(routes, '/addresses');
        await screen.findByText('kirja@bücher.example');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Domain' }), 'zeta.example');
        await waitFor(() => expect(router.state.location.search).toBe('?domain=zeta.example'));
    });

    it('opens a mailbox row in the domain mailboxes tab with open=<local>', async () => {
        const user = userEvent.setup();
        fakeServer();
        const domainServer = mocked.getMockImplementation() as typeof apiFetch;
        mocked.mockImplementation(((path: string, init: ApiInit = {}) =>
            path === '/addresses'
                ? Promise.resolve({ data: ROWS, meta: { page: 1, per_page: 10, total: 3, last_page: 1 } })
                : domainServer(path, init)) as typeof apiFetch);
        const { router } = renderRoutes(routes, '/addresses');
        const row = (await screen.findAllByText('anna@example.org'))[0]?.closest('tr') as HTMLElement;
        await user.click(within(row).getByRole('button', { name: /^Open/ }));
        // The edit drawer opens, and the one-shot open= parameter is gone again.
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        await waitFor(() => expect(router.state.location.search).toBe('?q=anna'));
        expect(router.state.location.pathname).toBe('/domains/example.org/mailboxes');
    });

    it('opens a forwarding row in the forwarding tab searching for its local part', async () => {
        const user = userEvent.setup();
        serve();
        const { router } = renderRoutes(routes, '/addresses');
        const row = (await screen.findByText('info@example.org')).closest('tr') as HTMLElement;
        await user.click(within(row).getByRole('button', { name: /^Open/ }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/example.org/forwarding'));
        expect(router.state.location.search).toBe('?q=info');
    });

    it('the empty state links to the blocked list', async () => {
        serve([]);
        renderRoutes(routes, '/addresses?q=nope');
        const link = await screen.findByRole('link', { name: 'Blocked addresses' });
        expect(link).toHaveAttribute('href', '/blocked');
    });

    it('opens the edit drawer from ?open= and then drops the parameter', async () => {
        fakeServer();
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes?open=anna&q=an');
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        await waitFor(() => expect(router.state.location.search).toBe('?q=an'));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });
});
