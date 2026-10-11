import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { writes } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';
import { PENDING, usersServer } from '../../test/usersServer';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    onAccessRefused: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

/** The table row holding `text`. */
async function rowOf(text: string) {
    return (await screen.findByText(text)).closest('tr') as HTMLElement;
}

/** The list's requests (the sidebar's count asks `/users` too, without a page). */
function userListCalls() {
    return vi.mocked(apiFetch).mock.calls.filter(([path, init]) => path === '/users' && init?.query?.page !== undefined);
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

describe('the Users list', () => {
    it('lists each user with role, last sign-in, status and two-factor', async () => {
        usersServer();
        renderRoutes(routes, '/users');

        const laura = await rowOf('Laura Kivi');
        expect(within(laura).getByText('laura@example.com')).toBeInTheDocument();
        expect(within(laura).getByText('Manager of 2 · Viewer of 1')).toBeInTheDocument();
        expect(within(laura).getByText('Oct 2')).toBeInTheDocument();
        expect(within(laura).getByText('Active')).toBeInTheDocument();
        expect(within(laura).getByText('2FA on')).toBeInTheDocument();
        expect(within(laura).getByRole('link', { name: 'Laura Kivi' })).toHaveAttribute('href', '/users/7');

        const anna = await rowOf('Anna Admin');
        expect(within(anna).getByText('Server admin')).toBeInTheDocument();
        expect(within(anna).getByText('you')).toBeInTheDocument();
        // A space keeps the tag a word of its own for screen readers.
        expect(within(anna).getByRole('cell', { name: /^Anna Admin you\b/ })).toBeInTheDocument();

        // A user without a name shows the email as the name.
        const mia = (await screen.findByRole('link', { name: 'mia@example.net' })).closest('tr') as HTMLElement;
        expect(within(mia).getByText('No domains')).toBeInTheDocument();
        expect(within(mia).getByText('Never')).toBeInTheDocument();
        expect(within(mia).getByText('Deactivated')).toBeInTheDocument();
        expect(within(mia).getByText('2FA off')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Users', level: 1 })).toBeInTheDocument();
    });

    it('asks the API with the search after a pause, from page 1', async () => {
        const user = userEvent.setup();
        usersServer();
        const { router } = renderRoutes(routes, '/users?page=2');
        await rowOf('Laura Kivi');
        expect(userListCalls()[0]![1]).toEqual({ query: { q: undefined, page: 2, per_page: 10 } });

        await user.type(screen.getByRole('searchbox', { name: 'Search users' }), 'laura');
        await waitFor(() => expect(router.state.location.search).toBe('?q=laura'));
        await waitFor(() => expect(userListCalls().at(-1)![1]).toEqual({ query: { q: 'laura', page: 1, per_page: 10 } }));
    });

    it('opens a user from the row', async () => {
        const user = userEvent.setup();
        usersServer();
        const { router } = renderRoutes(routes, '/users');
        await user.click(await screen.findByRole('button', { name: 'Open Laura Kivi' }));
        await waitFor(() => expect(router.state.location.pathname).toBe('/users/7'));
    });

    it('shows cards on a phone', async () => {
        vi.mocked(useIsPhone).mockReturnValue(true);
        usersServer();
        renderRoutes(routes, '/users');
        expect(await screen.findByRole('link', { name: 'Laura Kivi' })).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(screen.getByText('Manager of 2 · Viewer of 1')).toBeInTheDocument();
    });

    it('gives each phone card a full-width Open button, a finger-sized target', async () => {
        const user = userEvent.setup();
        vi.mocked(useIsPhone).mockReturnValue(true);
        usersServer();
        const { router } = renderRoutes(routes, '/users');
        const open = await screen.findByRole('button', { name: 'Open Laura Kivi' });
        expect(open).toHaveClass('w-full');
        await user.click(open);
        await waitFor(() => expect(router.state.location.pathname).toBe('/users/7'));
    });

    it('shows a load failure with Retry', async () => {
        const user = userEvent.setup();
        usersServer();
        const base = vi.mocked(apiFetch).getMockImplementation()!;
        let fail = true;
        vi.mocked(apiFetch).mockImplementation(((path: string, init?: object) => {
            if (fail && path === '/users') return Promise.reject(new ApiProblem({ status: 500, title: 'Server Error' }));
            return base(path, init);
        }) as typeof apiFetch);
        renderRoutes(routes, '/users');
        expect(await screen.findByText('Server Error')).toBeInTheDocument();
        fail = false;
        await user.click(screen.getByRole('button', { name: 'Retry' }));
        expect(await rowOf('Laura Kivi')).toBeInTheDocument();
    });

    it.each(['/users', '/users/7'])('is Page not found at %s for someone who is not a server admin', async (path) => {
        usersServer({ role: 'manager' });
        renderRoutes(routes, path);
        expect(await screen.findByText('Page not found', { selector: 'h2' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Page not found', level: 1 })).toBeInTheDocument();
        expect(screen.queryByText('Laura Kivi')).not.toBeInTheDocument();
        expect(vi.mocked(apiFetch).mock.calls.some(([p]) => p.startsWith('/users'))).toBe(false);
    });
});

describe('inviting a user', () => {
    async function openDialog(user: ReturnType<typeof userEvent.setup>) {
        await user.click(await screen.findByRole('button', { name: 'Invite user' }));
        return screen.findByRole('dialog', { name: 'Invite user' });
    }

    it('needs a domain for a domain role, then posts it', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => ({ id: 60, email: 'new@example.com', role: 'manager', domain: 'xn--bcher-kva.example', state: 'valid', expires_at: '2026-10-14T09:00:00Z' });
        renderRoutes(routes, '/users');

        const dialog = await openDialog(user);
        expect(within(dialog).getByRole('radio', { name: /^Viewer/ })).toBeChecked();
        expect(within(dialog).getByRole('combobox', { name: 'Email language' })).toHaveValue('en');
        await user.type(within(dialog).getByLabelText('Email'), 'new@example.com');
        await user.click(within(dialog).getByRole('radio', { name: /^Manager/ }));
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));

        const domain = within(dialog).getByRole('combobox', { name: 'Domain' });
        await waitFor(() => expect(domain).toHaveAccessibleDescription('Choose a domain.'));
        expect(writes()).toEqual([]);

        await user.selectOptions(domain, 'xn--bcher-kva.example');
        expect(within(domain).getByRole('option', { name: 'bücher.example' })).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));

        expect(await screen.findByText('Invitation sent to new@example.com.')).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(writes()).toEqual([
            ['/users/invitations', { method: 'POST', json: { email: 'new@example.com', role: 'manager', domain: 'xn--bcher-kva.example', locale: 'en' } }],
        ]);
    });

    it('invites a server admin with no domain, with the note, name and email language', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => ({ id: 61 });
        renderRoutes(routes, '/users');

        const dialog = await openDialog(user);
        await user.type(within(dialog).getByLabelText('Email'), ' boss@example.com ');
        await user.click(within(dialog).getByRole('radio', { name: /^Server admin/ }));
        expect(within(dialog).queryByRole('combobox', { name: 'Domain' })).not.toBeInTheDocument();
        await user.type(within(dialog).getByLabelText('Name (optional)'), 'Big Boss');
        await user.type(within(dialog).getByLabelText('Note (optional)'), 'Welcome');
        expect(within(dialog).getByText('7 / 500')).toBeInTheDocument();
        await user.selectOptions(within(dialog).getByRole('combobox', { name: 'Email language' }), 'fi');
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));

        await waitFor(() => expect(writes()).toHaveLength(1));
        expect(writes()[0]).toEqual([
            '/users/invitations',
            { method: 'POST', json: { email: 'boss@example.com', role: 'server_admin', note: 'Welcome', locale: 'fi', name: 'Big Boss' } },
        ]);
    });

    it('shows a 409 already_has_access under the email', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () =>
            Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'already_has_access', detail: 'laura@example.com already has that access.' }));
        renderRoutes(routes, '/users');

        const dialog = await openDialog(user);
        await user.type(within(dialog).getByLabelText('Email'), 'laura@example.com');
        await user.click(within(dialog).getByRole('radio', { name: /^Server admin/ }));
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));

        const email = within(dialog).getByLabelText('Email');
        await waitFor(() => expect(email).toHaveAccessibleDescription('laura@example.com already has that access.'));
    });

    it('keeps the dialog open and quiet when the step-up is cancelled', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => Promise.reject(new ApiProblem({ status: 423, title: 'Locked', code: 'step_up_required', cancelled: true }));
        renderRoutes(routes, '/users');

        const dialog = await openDialog(user);
        await user.type(within(dialog).getByLabelText('Email'), 'boss@example.com');
        await user.click(within(dialog).getByRole('radio', { name: /^Server admin/ }));
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));
        await waitFor(() => expect(writes()).toHaveLength(1));
        await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Send invitation' })).toBeEnabled());
        expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument();
    });
});

describe('pending invitations', () => {
    /** The pending list's row for `email`. */
    async function pendingRow(email: string) {
        const section = await screen.findByRole('region', { name: 'Pending invitations' });
        return within(section).getByText(email).closest('li') as HTMLElement;
    }

    function pendingCalls() {
        return vi.mocked(apiFetch).mock.calls.filter(([path, init]) => path === '/users/invitations' && (init?.method ?? 'GET') === 'GET');
    }

    async function openMenu(user: ReturnType<typeof userEvent.setup>, email: string) {
        await user.click(await screen.findByRole('button', { name: `Actions for the invitation to ${email}` }));
    }

    it('lists every pending invitation under the users, a server-admin one included, with its state', async () => {
        usersServer({ pending: [...PENDING] });
        renderRoutes(routes, '/users');

        const boss = await pendingRow('boss@example.com');
        expect(within(boss).getByText('Server admin')).toBeInTheDocument();
        expect(within(boss).getByText(/^Invitation sent .* by Anna Admin$/)).toBeInTheDocument();
        expect(within(boss).getByText('Pending')).toBeInTheDocument();
        expect(within(boss).getByText(/^Expires /)).toBeInTheDocument();

        const sam = await pendingRow('sam@example.net');
        expect(within(sam).getByText('Manager of bücher.example')).toBeInTheDocument();
        expect(within(sam).getByText('Expired')).toBeInTheDocument();
        expect(pendingCalls()[0]![1]).toEqual({ query: { page: 1, per_page: 10 } });
        // Not mixed into the users table.
        expect(screen.queryByRole('row', { name: /boss@example\.com/ })).not.toBeInTheDocument();
    });

    it('shows no section while nobody has a pending invitation', async () => {
        usersServer();
        renderRoutes(routes, '/users');
        await rowOf('Laura Kivi');
        await waitFor(() => expect(pendingCalls()).toHaveLength(1));
        expect(screen.queryByRole('region', { name: 'Pending invitations' })).not.toBeInTheDocument();
    });

    it('resends an invitation and refreshes the list', async () => {
        const user = userEvent.setup();
        usersServer({ pending: [...PENDING] });
        renderRoutes(routes, '/users');

        await openMenu(user, 'boss@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Resend invitation' }));

        expect(await screen.findByText('Sent a new invitation to boss@example.com.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/invitations/81/resend', { method: 'POST' }]]);
        await waitFor(() => expect(pendingCalls().length).toBeGreaterThan(1));
    });

    it('revokes an invitation after a confirmation, and the row goes', async () => {
        const user = userEvent.setup();
        const server = usersServer({ pending: [...PENDING] });
        server.write = () => {
            server.pending = server.pending.filter((i) => i.id !== 81);
            return undefined;
        };
        renderRoutes(routes, '/users');

        await openMenu(user, 'boss@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Revoke invitation…' }));
        const dialog = await screen.findByRole('dialog', { name: 'Revoke the invitation to boss@example.com?' });
        expect(writes()).toEqual([]);
        await user.click(within(dialog).getByRole('button', { name: 'Revoke invitation' }));

        expect(await screen.findByText('Revoked the invitation to boss@example.com.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/invitations/81', { method: 'DELETE' }]]);
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        await waitFor(() => expect(screen.queryByText('boss@example.com')).not.toBeInTheDocument());
        expect(await pendingRow('sam@example.net')).toBeInTheDocument();
    });

    it('cancels a revoke without a request', async () => {
        const user = userEvent.setup();
        usersServer({ pending: [...PENDING] });
        renderRoutes(routes, '/users');

        await openMenu(user, 'boss@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Revoke invitation…' }));
        await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(writes()).toEqual([]);
    });

    it('offers only Revoke for an invitation that can no longer be accepted', async () => {
        const user = userEvent.setup();
        usersServer({ pending: [{ ...PENDING[0]!, state: 'revoked' }] });
        renderRoutes(routes, '/users');

        const boss = await pendingRow('boss@example.com');
        expect(within(boss).getByText('No longer valid')).toBeInTheDocument();
        await openMenu(user, 'boss@example.com');
        expect(await screen.findByRole('menuitem', { name: 'Revoke invitation…' })).toBeInTheDocument();
        expect(screen.queryByRole('menuitem', { name: 'Resend invitation' })).not.toBeInTheDocument();
    });

    it('shows a 409 invitation_closed on the row', async () => {
        const user = userEvent.setup();
        const server = usersServer({ pending: [...PENDING] });
        server.write = () =>
            Promise.reject(
                new ApiProblem({ status: 409, title: 'Conflict', code: 'invitation_closed', detail: 'This invitation has already been accepted or revoked.' }),
            );
        renderRoutes(routes, '/users');

        await openMenu(user, 'boss@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Revoke invitation…' }));
        await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Revoke invitation' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        const boss = await pendingRow('boss@example.com');
        expect(await within(boss).findByRole('alert')).toHaveTextContent('This invitation has already been accepted or revoked.');
    });

    it('stays quiet when the step-up is cancelled, keeping the confirmation open', async () => {
        const user = userEvent.setup();
        const server = usersServer({ pending: [...PENDING] });
        server.write = () => Promise.reject(new ApiProblem({ status: 423, title: 'Locked', code: 'step_up_required', cancelled: true }));
        renderRoutes(routes, '/users');

        await openMenu(user, 'boss@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Revoke invitation…' }));
        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Revoke invitation' }));
        await waitFor(() => expect(writes()).toHaveLength(1));
        await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Revoke invitation' })).toBeEnabled());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();

        await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await openMenu(user, 'boss@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Resend invitation' }));
        await waitFor(() => expect(writes()).toHaveLength(2));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('shows a new invitation after "Invite user"', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => {
            server.pending = [PENDING[0]!];
            return { id: 81 };
        };
        renderRoutes(routes, '/users');
        await rowOf('Laura Kivi');
        expect(screen.queryByRole('region', { name: 'Pending invitations' })).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Invite user' }));
        const dialog = await screen.findByRole('dialog', { name: 'Invite user' });
        await user.type(within(dialog).getByLabelText('Email'), 'boss@example.com');
        await user.click(within(dialog).getByRole('radio', { name: /^Server admin/ }));
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));

        expect(await pendingRow('boss@example.com')).toBeInTheDocument();
    });

    it('shows a load failure with Retry', async () => {
        const user = userEvent.setup();
        usersServer({ pending: [...PENDING] });
        const base = vi.mocked(apiFetch).getMockImplementation()!;
        let fail = true;
        vi.mocked(apiFetch).mockImplementation(((path: string, init?: object) => {
            if (fail && path === '/users/invitations') return Promise.reject(new ApiProblem({ status: 500, title: 'Server Error' }));
            return base(path, init);
        }) as typeof apiFetch);
        renderRoutes(routes, '/users');

        const section = await screen.findByRole('region', { name: 'Pending invitations' });
        expect(await within(section).findByText('Server Error', {}, { timeout: 5000 })).toBeInTheDocument();
        fail = false;
        await user.click(within(section).getByRole('button', { name: 'Retry' }));
        expect(await pendingRow('boss@example.com')).toBeInTheDocument();
    });
});

describe('the Users list in Finnish', () => {
    it('renders the list in Finnish', async () => {
        await setLocale('fi');
        usersServer();
        renderRoutes(routes, '/users');
        const laura = await rowOf('Laura Kivi');
        expect(within(laura).getByText('Hallinnoija, 2 verkkotunnusta · Katselija, 1 verkkotunnus')).toBeInTheDocument();
        expect(within(laura).getByText('2FA käytössä')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Käyttäjät', level: 1 })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Kutsu käyttäjä' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Viimeisin kirjautuminen' })).toBeInTheDocument();
    });

    it('renders the pending invitations in Finnish', async () => {
        const user = userEvent.setup();
        await setLocale('fi');
        usersServer({ pending: [...PENDING] });
        renderRoutes(routes, '/users');

        const section = await screen.findByRole('region', { name: 'Odottavat kutsut' });
        const boss = within(section).getByText('boss@example.com').closest('li') as HTMLElement;
        expect(within(boss).getByText('Palvelimen ylläpitäjä')).toBeInTheDocument();
        expect(within(boss).getByText('Odottaa hyväksymistä')).toBeInTheDocument();
        await user.click(within(boss).getByRole('button', { name: 'Kutsun toiminnot: boss@example.com' }));
        await user.click(await screen.findByRole('menuitem', { name: 'Peru kutsu…' }));
        expect(await screen.findByRole('dialog', { name: 'Perutaanko kutsu osoitteeseen boss@example.com?' })).toBeInTheDocument();
    });
});
