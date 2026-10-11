import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { useIsPhone } from '../../lib/useIsPhone';
import { setLocale } from '../../i18n';
import { ME, NAMES, type Role } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import type { ForwardingRow } from './ForwardingDrawer';
import { ForwardingTab } from './ForwardingTab';

vi.mock('../../api/client', () => ({ apiFetch: vi.fn() }));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const mocked = vi.mocked(apiFetch);

function row(local: string, targets: [string, 'mailbox' | 'server' | 'external'][], over: Partial<ForwardingRow> = {}): ForwardingRow {
    return {
        local,
        source: `${local}@example.org`,
        source_is_mailbox: false,
        catch_all: false,
        keep_copy: false,
        targets: targets.map(([address, kind]) => ({ address, kind })),
        ...over,
    };
}

const ROWS = [
    row('', [['anna@example.org', 'mailbox']], { source: '@example.org', catch_all: true }),
    row('anna', [['anna@gmail.com', 'external']], { source_is_mailbox: true, keep_copy: true }),
    row('bob', [['bob@home.net', 'external']], { source_is_mailbox: true, keep_copy: false }),
    row('sales', [
        ['anna@example.org', 'mailbox'],
        ['staff@zeta.example', 'server'],
    ]),
];

interface State {
    rows: ForwardingRow[];
    total?: number;
    write: (path: string, init: ApiInit) => Promise<unknown>;
    /** `capabilities.catch_all` in `me`. */
    catchAll: boolean;
    role: Role;
}

let state: State;

function server() {
    mocked.mockImplementation(((path: string, init: ApiInit = {}) => {
        if ((init.method ?? 'GET') !== 'GET') return state.write(path, init);
        if (path === '/domains/names') return Promise.resolve({ data: NAMES.map((n) => ({ ...n, role: state.role })) });
        if (path === '/me') {
            return Promise.resolve({
                ...ME,
                is_server_admin: state.role === 'admin',
                capabilities: { schema_extension: { receiving: false, sendonly: false }, catch_all: state.catchAll },
            });
        }
        if (path.endsWith('/addresses/check')) return Promise.resolve({ address: '', status: 'invalid', message: '' });
        if (path.endsWith('/forwarding')) {
            const total = state.total ?? state.rows.length;
            return Promise.resolve({ data: state.rows, meta: { page: 1, per_page: 10, total, last_page: Math.max(1, Math.ceil(total / 10)) } });
        }
        if (path.endsWith('/mailboxes')) return Promise.resolve({ data: [], meta: { page: 1, per_page: 200, total: 0, last_page: 1 } });
        return Promise.reject(new Error(`unexpected ${path}`));
    }) as typeof apiFetch);
}

function writes(): [string, ApiInit][] {
    return mocked.mock.calls.filter(([, init]) => (init?.method ?? 'GET') !== 'GET').map(([p, init]) => [p, init ?? {}]);
}

function listCalls() {
    return mocked.mock.calls.filter(([p]) => p.endsWith('/forwarding')).map(([, init]) => init?.query);
}

function renderTab(path = '/domains/example.org/forwarding') {
    return renderRoutes([{ path: '/domains/:domain/forwarding', element: <ForwardingTab /> }], path);
}

beforeEach(() => {
    mocked.mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
    state = { rows: ROWS, write: () => Promise.resolve(undefined), catchAll: false, role: 'admin' };
    server();
});

describe('ForwardingTab', () => {
    it('lists each source with its target chips and the keep-copy state of mailbox sources', async () => {
        renderTab();
        const anna = (await screen.findByText('anna@example.org', { selector: 'div' })).closest('tr');
        expect(anna).not.toBeNull();
        expect(within(anna as HTMLElement).getByText('Mailbox · keeps a copy')).toBeInTheDocument();
        expect(within(anna as HTMLElement).getByText('anna@gmail.com')).toBeInTheDocument();

        const bob = screen.getByText('bob@example.org').closest('tr') as HTMLElement;
        expect(within(bob).getByText('Mailbox · forward only')).toBeInTheDocument();

        const sales = screen.getByText('sales@example.org').closest('tr') as HTMLElement;
        expect(within(sales).getByText('Delivers to 2 places')).toBeInTheDocument();
        expect(within(sales).getByText('staff@zeta.example')).toBeInTheDocument();

        const catchAll = screen.getByText('Catch-all @example.org').closest('tr') as HTMLElement;
        expect(within(catchAll).getByText('Catch-all · can only be removed')).toBeInTheDocument();
    });

    it('keeps the search and the kind filter in the URL', async () => {
        const user = userEvent.setup();
        const { router } = renderTab();
        await screen.findByText('sales@example.org');
        await user.type(screen.getByRole('searchbox', { name: 'Search forwarding' }), 'gmail');
        await waitFor(() => expect(router.state.location.search).toBe('?q=gmail'));
        await user.selectOptions(screen.getByRole('combobox', { name: 'Kind' }), 'external');
        await waitFor(() => expect(router.state.location.search).toBe('?q=gmail&kind=external'));
        await waitFor(() => expect(listCalls()).toContainEqual({ q: 'gmail', kind: 'external', page: 1, limit: 10 }));
    });

    it('reads the search and kind from the URL', async () => {
        renderTab('/domains/example.org/forwarding?q=anna&kind=local');
        expect(await screen.findByRole('searchbox', { name: 'Search forwarding' })).toHaveValue('anna');
        expect(screen.getByRole('combobox', { name: 'Kind' })).toHaveValue('local');
        await waitFor(() => expect(listCalls()).toContainEqual({ q: 'anna', kind: 'local', page: 1, limit: 10 }));
    });

    it('removes a source and Undo puts the old targets back', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Actions for sales@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Remove' }));
        expect(await screen.findByText('Removed sales@example.org.')).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/example.org/forwarding/sales', { method: 'DELETE' }]]);

        await user.click(screen.getByRole('button', { name: 'Undo' }));
        await waitFor(() => expect(writes()).toHaveLength(2));
        expect(writes()[1]).toEqual([
            '/domains/example.org/forwarding/sales',
            { method: 'PUT', json: { targets: ['anna@example.org', 'staff@zeta.example'], keep_copy: false } },
        ]);
        expect(await screen.findByText('Restored sales@example.org.')).toBeInTheDocument();
    });

    it('confirms the redirect on Undo for a forward-only mailbox', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Actions for bob@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Remove' }));
        await user.click(await screen.findByRole('button', { name: 'Undo' }));
        await waitFor(() => expect(writes()).toHaveLength(2));
        expect(writes()[1]?.[1].json).toEqual({ targets: ['bob@home.net'], keep_copy: false, redirect_mailbox: true });
    });

    it('shows a failed Undo in words', async () => {
        const user = userEvent.setup();
        state.write = (_p, init) =>
            init.method === 'PUT' ? Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'busy' })) : Promise.resolve(undefined);
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Actions for sales@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Remove' }));
        await user.click(await screen.findByRole('button', { name: 'Undo' }));
        expect(await screen.findByText('Someone else is changing this address. Try again.')).toBeInTheDocument();
    });

    it('shows a failed remove in words', async () => {
        const user = userEvent.setup();
        state.write = () => Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'archiving' }));
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Actions for sales@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Remove' }));
        expect(await screen.findByText('This address is still being archived. Try again in a few minutes.')).toBeInTheDocument();
    });

    it('opens the drawer with the row from Edit destinations', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Actions for anna@example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Edit destinations' }));
        const drawer = screen.getByRole('dialog', { name: 'Edit forwarding' });
        expect(within(drawer).getByLabelText('Address that receives the mail')).toHaveValue('anna');
        expect(within(drawer).getByText('anna@gmail.com')).toBeInTheDocument();
    });

    it('opens the new forwarding drawer from the add button', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.click(await screen.findByRole('button', { name: '+ Add forwarding address' }));
        expect(screen.getByRole('dialog', { name: 'New forwarding address' })).toBeInTheDocument();
    });

    it('uses the prototype copy for an empty domain and for no matches', async () => {
        state.rows = [];
        const user = userEvent.setup();
        renderTab();
        expect(await screen.findByText('No forwarding addresses in example.org yet')).toBeInTheDocument();
        expect(screen.getByText('A forwarding address delivers to a mailbox here, to another address, or to several at once.')).toBeInTheDocument();

        await user.type(screen.getByRole('searchbox', { name: 'Search forwarding' }), 'zz');
        expect(await screen.findByText('Nothing matches')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Clear search' }));
        expect(await screen.findByText('No forwarding addresses in example.org yet')).toBeInTheDocument();
    });

    it('renders cards on a phone', async () => {
        vi.mocked(useIsPhone).mockReturnValue(true);
        renderTab();
        expect(await screen.findByText('sales@example.org')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add forwarding address' })).toHaveTextContent('+');
    });
});

describe('ForwardingTab catch-all', () => {
    const catchAllRow = () => screen.findByText('Catch-all @example.org').then((el) => el.closest('tr') as HTMLElement);

    it('offers only Remove for the catch-all, with a note, where the server does not support it', async () => {
        const user = userEvent.setup();
        renderTab();
        const row = await catchAllRow();
        expect(within(row).getByText('Catch-all · can only be removed')).toBeInTheDocument();
        expect(await screen.findByText(/Catch-all forwarding can't be changed on this server.*You can still remove the catch-all\./)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Set catch-all forwarding' })).not.toBeInTheDocument();

        // Removing a stray catch-all only gives existing mailboxes their mail back, so it stays allowed.
        await user.click(within(row).getByRole('button', { name: 'Actions for Catch-all @example.org' }));
        expect(screen.queryByRole('menuitem', { name: 'Edit destinations' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('menuitem', { name: 'Remove' }));
        expect(await screen.findByText('Removed catch-all forwarding for example.org.')).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/example.org/forwarding/@', { method: 'DELETE' }]]);
        // No Undo: putting it back would be refused here.
        expect(screen.queryByRole('button', { name: 'Undo' })).not.toBeInTheDocument();
    });

    it('keeps the drawer open with the error when the server refuses a new catch-all with 409', async () => {
        const user = userEvent.setup();
        state.catchAll = true;
        state.rows = ROWS.slice(1);
        state.write = () => {
            // The server lost support meanwhile: /me now says so too.
            state.catchAll = false;
            return Promise.reject(
                new ApiProblem({
                    status: 409,
                    title: 'Conflict',
                    code: 'catch_all_unsupported',
                    detail: 'Catch-all forwarding is off on this server: Postfix’s virtual_alias_maps has no email2email map.',
                }),
            );
        };
        renderTab();
        await user.click(await screen.findByRole('button', { name: '+ Set catch-all forwarding' }));
        const drawer = screen.getByRole('dialog', { name: 'Catch-all forwarding' });
        await user.type(within(drawer).getByLabelText('Deliver to'), 'anna@example.org');
        const save = within(drawer).getByRole('button', { name: 'Set catch-all' });
        await waitFor(() => expect(save).toBeEnabled());
        await user.click(save);

        expect(await within(drawer).findByText(/has no email2email map/)).toBeInTheDocument();
        // /me has caught up (no more "Set catch-all" offer), and the drawer is still there with its error.
        await waitFor(() => expect(screen.queryByRole('button', { name: '+ Set catch-all forwarding' })).not.toBeInTheDocument());
        expect(screen.getByRole('dialog', { name: 'Catch-all forwarding' })).toBeInTheDocument();
        expect(within(screen.getByRole('dialog', { name: 'Catch-all forwarding' })).getByText(/has no email2email map/)).toBeInTheDocument();
    });

    it('lets an admin edit the catch-all where the server supports it', async () => {
        const user = userEvent.setup();
        state.catchAll = true;
        state.write = (_p, init) => Promise.resolve({ ...ROWS[0], targets: (init.json as { targets: string[] }).targets.map((address) => ({ address, kind: 'mailbox' })) });
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Actions for Catch-all @example.org' }));
        expect(within(await catchAllRow()).getByText('Catch-all · mail to any other address')).toBeInTheDocument();
        expect(screen.queryByText(/can't be changed on this server/)).not.toBeInTheDocument();
        await user.click(screen.getByRole('menuitem', { name: 'Edit destinations' }));
        const drawer = screen.getByRole('dialog', { name: 'Catch-all forwarding' });
        expect(within(drawer).getByText('Mail to any address of example.org that has no mailbox or forwarding of its own.')).toBeInTheDocument();
        expect(within(drawer).queryByRole('switch', { name: 'Keep a copy in the mailbox' })).not.toBeInTheDocument();
        await user.type(within(drawer).getByLabelText('Deliver to'), 'bob@example.org{Enter}');
        await user.click(within(drawer).getByRole('button', { name: 'Save destinations' }));
        await waitFor(() =>
            expect(writes()).toEqual([['/domains/example.org/forwarding/@', { method: 'PUT', json: { targets: ['anna@example.org', 'bob@example.org'] } }]]),
        );
        expect(await screen.findByText('Catch-all forwarding for example.org now delivers to 2 places.')).toBeInTheDocument();
    });

    it('lets a manager remove the catch-all, and Undo puts it back', async () => {
        const user = userEvent.setup();
        state.catchAll = true;
        state.role = 'manager';
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Actions for Catch-all @example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Remove' }));
        expect(await screen.findByText('Removed catch-all forwarding for example.org.')).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/example.org/forwarding/@', { method: 'DELETE' }]]);
        await user.click(screen.getByRole('button', { name: 'Undo' }));
        await waitFor(() => expect(writes()).toHaveLength(2));
        expect(writes()[1]).toEqual(['/domains/example.org/forwarding/@', { method: 'PUT', json: { targets: ['anna@example.org'] } }]);
    });

    it('gives a viewer no catch-all actions and no note', async () => {
        state.catchAll = true;
        state.role = 'viewer';
        renderTab();
        const row = await catchAllRow();
        expect(within(row).queryByRole('button', { name: /Actions/ })).not.toBeInTheDocument();
        expect(screen.queryByText(/can't be changed on this server/)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '+ Set catch-all forwarding' })).not.toBeInTheDocument();
    });

    it('sets a new catch-all', async () => {
        const user = userEvent.setup();
        state.catchAll = true;
        state.rows = ROWS.slice(1);
        state.write = (_p, init) => Promise.resolve({ ...ROWS[0], targets: (init.json as { targets: string[] }).targets.map((address) => ({ address, kind: 'mailbox' })) });
        renderTab();
        await user.click(await screen.findByRole('button', { name: '+ Set catch-all forwarding' }));
        const drawer = screen.getByRole('dialog', { name: 'Catch-all forwarding' });
        await user.type(within(drawer).getByLabelText('Deliver to'), 'anna@example.org');
        const save = within(drawer).getByRole('button', { name: 'Set catch-all' });
        await waitFor(() => expect(save).toBeEnabled());
        await user.click(save);
        await waitFor(() => expect(writes()).toEqual([['/domains/example.org/forwarding/@', { method: 'PUT', json: { targets: ['anna@example.org'] } }]]));
    });

    it('shows the server refusing a catch-all target in the drawer', async () => {
        const user = userEvent.setup();
        state.catchAll = true;
        state.write = () =>
            Promise.reject(
                new ApiProblem({
                    status: 422,
                    title: 'Unprocessable',
                    code: 'validation_failed',
                    errors: { 'targets.0': ['nobody@example.org has no mailbox or forwarding on this domain, so the catch-all would catch its mail again. Use an existing address.'] },
                }),
            );
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Actions for Catch-all @example.org' }));
        await user.click(screen.getByRole('menuitem', { name: 'Edit destinations' }));
        const drawer = screen.getByRole('dialog', { name: 'Catch-all forwarding' });
        await user.click(within(drawer).getByRole('button', { name: 'Save destinations' }));
        expect(await within(drawer).findByText(/so the catch-all would catch its mail again/)).toBeInTheDocument();
    });

    it('words the note in Finnish', async () => {
        await setLocale('fi');
        renderTab();
        expect(await screen.findByText(/^Catch-all-edelleenlähetystä ei voi muuttaa tällä palvelimella.*Voit silti poistaa catch-all-edelleenlähetyksen\./)).toBeInTheDocument();
        expect(screen.getByText('Catch-all · vain poistettavissa')).toBeInTheDocument();
    });
});
