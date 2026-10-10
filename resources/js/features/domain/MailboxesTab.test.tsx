import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { MAILBOXES, fakeServer, writes } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { MailboxesTab } from './MailboxesTab';
import { DomainActionsContext } from './shared';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const mocked = vi.mocked(apiFetch);

beforeEach(() => {
    mocked.mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

function listCalls() {
    return mocked.mock.calls.filter(([p, init]) => p === '/domains/example.org/mailboxes' && (init?.method ?? 'GET') === 'GET');
}

describe('MailboxesTab', () => {
    it('renders each row: address, display name, status, storage, default limit and forwarding count', async () => {
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        const anna = (await screen.findByText('anna@example.org')).closest('tr');
        expect(anna).not.toBeNull();
        const row = within(anna as HTMLElement);
        expect(row.getByText('Anna Admin')).toBeInTheDocument();
        expect(row.getByText('Active')).toBeInTheDocument();
        expect(row.getByText('1 GB of 5 GB (default)')).toBeInTheDocument();
        expect(row.getByText('2')).toBeInTheDocument();

        const bob = within(screen.getByText('bob@example.org').closest('tr') as HTMLElement);
        expect(bob.getByText('Not checked yet · 10 GB limit')).toBeInTheDocument();

        const sales = within(screen.getByText('sales@example.org').closest('tr') as HTMLElement);
        expect(sales.getByText('Disabled')).toBeInTheDocument();
        expect(sales.getByRole('progressbar')).toHaveAttribute('data-level', 'off');
    });

    it('asks the API with the URL state', async () => {
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes?q=an&status=disabled&sort=-address&page=2');
        await waitFor(() => expect(listCalls().length).toBeGreaterThan(0));
        expect(listCalls()[0]?.[1]).toEqual({
            query: { q: 'an', status: 'disabled', sort: 'desc', page: 2, limit: 10 },
        });
    });

    it('resets the page when the status filter changes', async () => {
        const user = userEvent.setup();
        fakeServer({ meta: { page: 2, per_page: 10, total: 23, last_page: 3 } });
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes?page=2');
        await screen.findByText('anna@example.org');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'disabled');
        await waitFor(() => expect(router.state.location.search).toBe('?status=disabled'));
    });

    it('toggles the address sort in the URL and resets the page', async () => {
        const user = userEvent.setup();
        fakeServer({ meta: { page: 2, per_page: 10, total: 23, last_page: 3 } });
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes?page=2');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('button', { name: /Sort by address/ }));
        await waitFor(() => expect(router.state.location.search).toBe('?sort=-address'));
        await user.click(screen.getByRole('button', { name: /Sort by address/ }));
        await waitFor(() => expect(router.state.location.search).toBe(''));
    });

    it('debounces the search into the URL and resets the page', async () => {
        const user = userEvent.setup();
        fakeServer({ meta: { page: 2, per_page: 10, total: 23, last_page: 3 } });
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes?page=2');
        await screen.findByText('anna@example.org');
        await user.type(screen.getByRole('searchbox', { name: 'Search mailboxes' }), 'an');
        expect(router.state.location.search).toBe('?page=2');
        await waitFor(() => expect(router.state.location.search).toBe('?q=an'));
    });

    it('select-all then Disable posts the page locals once, even on a double click, toasts and clears the selection', async () => {
        const user = userEvent.setup();
        const server = fakeServer();
        let release: (v: unknown) => void = () => {};
        server.write = () => new Promise((resolve) => (release = resolve));
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('checkbox', { name: 'Select all on this page' }));
        expect(screen.getByText('3 selected')).toBeInTheDocument();
        await user.dblClick(screen.getByRole('button', { name: 'Disable' }));
        release({ changed: 2 });
        expect(await screen.findByText('Disabled 3 mailboxes.')).toBeInTheDocument();
        expect(writes()).toEqual([
            [
                '/domains/example.org/mailboxes/bulk',
                { method: 'POST', json: { locals: MAILBOXES.map((m) => m.local), action: 'disable' } },
            ],
        ]);
        await waitFor(() => expect(screen.queryByText('3 selected')).not.toBeInTheDocument());
    });

    it('bulk Enable posts the selected locals', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('checkbox', { name: 'Select sales' }));
        await user.click(screen.getByRole('button', { name: 'Enable' }));
        expect(await screen.findByText('Enabled 1 mailbox.')).toBeInTheDocument();
        expect(writes()).toEqual([
            ['/domains/example.org/mailboxes/bulk', { method: 'POST', json: { locals: ['sales'], action: 'enable' } }],
        ]);
    });

    it('keeps the selection and shows the readable message when a bulk action is busy', async () => {
        const user = userEvent.setup();
        const server = fakeServer();
        server.write = () => Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'busy' }));
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('checkbox', { name: 'Select anna' }));
        await user.click(screen.getByRole('button', { name: 'Disable' }));
        expect(await screen.findByText('Someone else is changing this address. Try again.')).toBeInTheDocument();
        expect(screen.getByText('1 selected')).toBeInTheDocument();
    });

    it('Change limit… opens the dialog for the selection', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('checkbox', { name: 'Select anna' }));
        await user.click(screen.getByRole('checkbox', { name: 'Select bob' }));
        await user.click(screen.getByRole('button', { name: 'Change limit…' }));
        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('Change the limit of 2 mailboxes');
        await user.click(within(dialog).getByRole('button', { name: '10 GB' }));
        await user.click(within(dialog).getByRole('button', { name: 'Set limit' }));
        expect(await screen.findByText('Limit set to 10 GB for 2 mailboxes.')).toBeInTheDocument();
        expect(writes()).toEqual([
            [
                '/domains/example.org/mailboxes/bulk',
                { method: 'POST', json: { locals: ['anna', 'bob'], action: 'quota', quota_bytes: 10737418240 } },
            ],
        ]);
        await waitFor(() => expect(screen.queryByText('2 selected')).not.toBeInTheDocument());
    });

    it('disables a row from its menu and toasts the address', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('anna@example.org');
        await user.click(screen.getByRole('button', { name: 'Actions for anna@example.org' }));
        expect(screen.getAllByRole('menuitem').map((i) => i.textContent)).toEqual([
            'Edit',
            'Reset password',
            'Disable',
            'Delete…',
        ]);
        await user.click(screen.getByRole('menuitem', { name: 'Disable' }));
        expect(await screen.findByText('Disabled anna@example.org.')).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/example.org/mailboxes/anna/disable', { method: 'POST' }]]);
    });

    it('enables a disabled row from its menu', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('sales@example.org');
        await user.click(screen.getByRole('button', { name: 'Actions for sales@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Enable' }));
        expect(await screen.findByText('Enabled sales@example.org.')).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/example.org/mailboxes/sales/enable', { method: 'POST' }]]);
    });

    it('deletes a row through the dialog', async () => {
        const user = userEvent.setup();
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        await screen.findByText('bob@example.org');
        await user.click(screen.getByRole('button', { name: 'Actions for bob@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Delete…' }));
        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('Delete bob@example.org?');
        await user.click(within(dialog).getByRole('button', { name: 'Delete mailbox' }));
        expect(await screen.findByText('Deleted bob@example.org. Its mail is moved to trash.')).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/example.org/mailboxes/bob', { method: 'DELETE' }]]);
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('steps back a page when the current one is past the end', async () => {
        fakeServer({ mailboxes: [], meta: { page: 3, per_page: 10, total: 20, last_page: 2 } });
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes?page=3');
        await waitFor(() => expect(router.state.location.search).toBe('?page=2'));
    });

    it('shows the first-mailbox empty state with Add mailbox', async () => {
        fakeServer({ mailboxes: [], meta: { page: 1, per_page: 10, total: 0, last_page: 1 } });
        renderRoutes(routes, '/domains/zeta.example/mailboxes');
        expect(await screen.findByText('No mailboxes in zeta.example yet')).toBeInTheDocument();
        expect(screen.getByText('Add the first one; forwarding addresses can point to it afterwards.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add mailbox' })).toBeInTheDocument();
    });

    it('shows Nothing matches for a filtered empty list and clears the filters', async () => {
        const user = userEvent.setup();
        fakeServer({ mailboxes: [], meta: { page: 1, per_page: 10, total: 0, last_page: 1 } });
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes?q=zz&status=disabled');
        expect(await screen.findByText('Nothing matches')).toBeInTheDocument();
        expect(
            screen.getByText('Searched 3 mailboxes in example.org. Try fewer characters or clear the filter.'),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Clear search' }));
        await waitFor(() => expect(router.state.location.search).toBe(''));
    });

    it('opens the drawers through DomainActionsContext', async () => {
        const user = userEvent.setup();
        fakeServer();
        const actions = { openNewMailbox: vi.fn(), openEditMailbox: vi.fn() };
        renderRoutes(
            [
                {
                    path: '/domains/:domain/mailboxes',
                    element: (
                        <DomainActionsContext.Provider value={actions}>
                            <MailboxesTab />
                        </DomainActionsContext.Provider>
                    ),
                },
            ],
            '/domains/example.org/mailboxes',
        );
        await user.click(await screen.findByRole('button', { name: '+ Add mailbox' }));
        expect(actions.openNewMailbox).toHaveBeenCalledTimes(1);

        await user.click(screen.getByRole('button', { name: /^anna@example\.org/ }));
        expect(actions.openEditMailbox).toHaveBeenLastCalledWith('anna');

        await user.click(screen.getByRole('button', { name: 'Actions for bob@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Edit' }));
        expect(actions.openEditMailbox).toHaveBeenLastCalledWith('bob');

        await user.click(screen.getByRole('button', { name: 'Actions for bob@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Reset password' }));
        expect(actions.openEditMailbox).toHaveBeenLastCalledWith('bob', 'password');
    });

    it('shows the unicode domain in addresses while the API gets the punycode name', async () => {
        fakeServer({
            mailboxes: [{ ...MAILBOXES[0]!, address: 'anna@xn--bcher-kva.example' }],
            meta: { page: 1, per_page: 10, total: 1, last_page: 1 },
        });
        renderRoutes(routes, '/domains/xn--bcher-kva.example/mailboxes');
        expect(await screen.findByText('anna@bücher.example')).toBeInTheDocument();
        expect(mocked).toHaveBeenCalledWith('/domains/xn--bcher-kva.example/mailboxes', expect.anything());
    });

    it('renders cards without selection on a phone', async () => {
        vi.mocked(useIsPhone).mockReturnValue(true);
        fakeServer();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        expect(await screen.findByText('anna@example.org')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.getByText('Anna Admin · 2 forwarding')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Edit anna@example.org' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add mailbox' })).toBeInTheDocument();
    });
});
