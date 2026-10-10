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
import { LAURA, LAURA_DETAIL, usersServer } from '../../test/usersServer';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    onAccessRefused: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const URL = '/users/7';

type User = ReturnType<typeof userEvent.setup>;

async function section(name: string) {
    return screen.findByRole('region', { name });
}

function detailGets(id: number) {
    return vi.mocked(apiFetch).mock.calls.filter(([path, init]) => path === `/users/${id}` && (init?.method ?? 'GET') === 'GET');
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

describe('a user’s page', () => {
    it('shows the profile, role, status, two-factor, domains and pending invitations', async () => {
        usersServer();
        renderRoutes(routes, URL);

        expect(await screen.findByRole('heading', { name: 'Laura Kivi', level: 2 })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /All users/ })).toHaveAttribute('href', '/users');
        const profile = await section('Profile');
        expect(within(profile).getByLabelText('Name')).toHaveValue('Laura Kivi');
        expect(within(profile).getByText('laura@example.com')).toBeInTheDocument();
        expect(within(await section('Role')).getByRole('switch', { name: 'Server admin' })).toHaveAttribute('aria-checked', 'false');
        expect(within(await section('Status')).getByText('Active')).toBeInTheDocument();
        expect(within(await section('Two-factor authentication')).getByText('On')).toBeInTheDocument();

        const domains = await section('Domains');
        expect(within(domains).getByRole('combobox', { name: 'Role on example.org' })).toHaveValue('manager');
        expect(within(domains).getByText(/by Anna Admin · Oct 8, 2026/)).toBeInTheDocument();

        const invitations = await section('Pending invitations');
        expect(within(invitations).getByText('Viewer of zeta.example')).toBeInTheDocument();
        expect(within(invitations).getAllByText('Pending').length).toBeGreaterThan(0);
        expect(within(await section('Danger zone')).getByRole('button', { name: 'Delete user…' })).toBeInTheDocument();
    });

    it('says when the user does not exist', async () => {
        usersServer();
        renderRoutes(routes, '/users/404');
        expect(await screen.findByText("This user doesn't exist.")).toBeInTheDocument();
    });
});

describe('the profile', () => {
    it('saves a new name without step-up and refreshes the user', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => ({ ...LAURA, name: 'Laura K' });
        renderRoutes(routes, URL);

        const name = within(await section('Profile')).getByLabelText('Name');
        await user.clear(name);
        await user.type(name, 'Laura K ');
        await user.click(within(await section('Profile')).getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('Name saved.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/7', { method: 'PATCH', json: { name: 'Laura K' } }]]);
        await waitFor(() => expect(detailGets(7).length).toBeGreaterThan(1));
    });

    it('refreshes me when you rename yourself', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => ({ id: 1, name: 'Anna A' });
        const { client } = renderRoutes(routes, '/users/1');
        const invalidate = vi.spyOn(client, 'invalidateQueries');

        const name = within(await section('Profile')).getByLabelText('Name');
        await user.clear(name);
        await user.type(name, 'Anna A');
        await user.click(within(await section('Profile')).getByRole('button', { name: 'Save' }));
        await screen.findByText('Name saved.');
        const keys = invalidate.mock.calls.map(([filters]) => JSON.stringify(filters?.queryKey));
        expect(keys).toContain(JSON.stringify(['me']));
    });

    it('changes the email in a dialog', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => ({ ...LAURA, email: 'laura@example.org' });
        renderRoutes(routes, URL);

        await user.click(within(await section('Profile')).getByRole('button', { name: 'Change email' }));
        const dialog = await screen.findByRole('dialog', { name: "Change Laura Kivi's email" });
        await user.type(within(dialog).getByLabelText('New email'), 'laura@example.org');
        await user.click(within(dialog).getByRole('button', { name: 'Change email' }));

        expect(await screen.findByText('Email changed to laura@example.org.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/7', { method: 'PATCH', json: { email: 'laura@example.org' } }]]);
    });

    it('shows a duplicate email under the field', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () =>
            Promise.reject(new ApiProblem({ status: 422, title: 'Invalid', errors: { email: ['The email has already been taken.'] } }));
        renderRoutes(routes, URL);

        await user.click(within(await section('Profile')).getByRole('button', { name: 'Change email' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('New email'), 'anna@example.org');
        await user.click(within(dialog).getByRole('button', { name: 'Change email' }));
        await waitFor(() => expect(within(dialog).getByLabelText('New email')).toHaveAccessibleDescription('The email has already been taken.'));
    });
});

describe('the actions behind a confirmation', () => {
    async function confirmIn(user: User, dialogName: string | RegExp, button: string) {
        const dialog = await screen.findByRole('dialog', { name: dialogName });
        expect(writes()).toEqual([]);
        await user.click(within(dialog).getByRole('button', { name: button }));
        return dialog;
    }

    it('makes them a server admin', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => ({ ...LAURA, is_server_admin: true });
        renderRoutes(routes, URL);

        await user.click(within(await section('Role')).getByRole('switch', { name: 'Server admin' }));
        await confirmIn(user, 'Make Laura Kivi a server admin?', 'Make server admin');
        expect(await screen.findByText('Laura Kivi is now a server admin.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/7', { method: 'PATCH', json: { is_server_admin: true } }]]);
    });

    it('deactivates them, saying it signs them out everywhere', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => ({ ...LAURA, status: 'deactivated' });
        renderRoutes(routes, URL);

        await user.click(within(await section('Status')).getByRole('button', { name: 'Deactivate…' }));
        const dialog = await screen.findByRole('dialog', { name: 'Deactivate Laura Kivi?' });
        expect(dialog).toHaveTextContent(/signs them out everywhere/);
        await confirmIn(user, 'Deactivate Laura Kivi?', 'Deactivate');
        expect(await screen.findByText('Laura Kivi was deactivated.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/7', { method: 'PATCH', json: { status: 'deactivated' } }]]);
    });

    it('reactivates a deactivated user', async () => {
        const user = userEvent.setup();
        const server = usersServer({ details: { 7: { ...LAURA_DETAIL, status: 'deactivated' } } });
        server.write = () => ({ ...LAURA, status: 'active' });
        renderRoutes(routes, URL);

        await user.click(within(await section('Status')).getByRole('button', { name: 'Reactivate…' }));
        await confirmIn(user, 'Reactivate Laura Kivi?', 'Reactivate');
        await waitFor(() => expect(writes()).toEqual([['/users/7', { method: 'PATCH', json: { status: 'active' } }]]));
    });

    it('resets two-factor', async () => {
        const user = userEvent.setup();
        usersServer();
        renderRoutes(routes, URL);

        await user.click(within(await section('Two-factor authentication')).getByRole('button', { name: 'Reset two-factor…' }));
        await confirmIn(user, 'Reset two-factor for Laura Kivi?', 'Reset two-factor');
        expect(await screen.findByText('Two-factor authentication was reset for Laura Kivi.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/7/two-factor/reset', { method: 'POST' }]]);
    });

    it('deletes them after the email is typed, then goes back to the list', async () => {
        const user = userEvent.setup();
        usersServer();
        const { router, client } = renderRoutes(routes, URL);
        const invalidate = vi.spyOn(client, 'invalidateQueries');

        await user.click(within(await section('Danger zone')).getByRole('button', { name: 'Delete user…' }));
        const dialog = await screen.findByRole('dialog', { name: 'Delete Laura Kivi?' });
        const confirm = within(dialog).getByRole('button', { name: 'Delete user' });
        expect(confirm).toBeDisabled();
        await user.type(within(dialog).getByLabelText(/Type laura@example\.com to confirm/), 'laura@example');
        expect(confirm).toBeDisabled();
        await user.type(within(dialog).getByLabelText(/Type laura@example\.com to confirm/), '.com');
        await user.click(confirm);

        await waitFor(() => expect(router.state.location.pathname).toBe('/users'));
        expect(writes()).toEqual([['/users/7', { method: 'DELETE' }]]);
        expect(await screen.findByText('Deleted Laura Kivi.')).toBeInTheDocument();
        const keys = invalidate.mock.calls.map(([filters]) => JSON.stringify(filters?.queryKey));
        expect(keys).toContain(JSON.stringify(['users']));
    });

    it('shows a 409 last_server_admin in the dialog', async () => {
        const user = userEvent.setup();
        const server = usersServer({ details: { 7: { ...LAURA_DETAIL, is_server_admin: true, grants: [] } } });
        server.write = () => Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code: 'last_server_admin' }));
        renderRoutes(routes, URL);

        await user.click(within(await section('Role')).getByRole('switch', { name: 'Server admin' }));
        const dialog = await screen.findByRole('dialog', { name: "Remove Laura Kivi's server admin role?" });
        await user.click(within(dialog).getByRole('button', { name: 'Remove server admin' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent('At least one active server admin must remain.');
    });

    it('sends nothing when a confirmation is cancelled', async () => {
        const user = userEvent.setup();
        usersServer();
        renderRoutes(routes, URL);

        const toggle = within(await section('Role')).getByRole('switch', { name: 'Server admin' });
        await user.click(toggle);
        await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(toggle).toHaveAttribute('aria-checked', 'false');
        expect(writes()).toEqual([]);
    });
});

describe('your own page', () => {
    it('hides the role switch, Deactivate, Reset two-factor and Delete', async () => {
        usersServer();
        renderRoutes(routes, '/users/1');

        expect(await screen.findByRole('heading', { name: /Anna Admin/, level: 2 })).toBeInTheDocument();
        expect(within(await section('Role')).getByText(/You are a server admin/)).toBeInTheDocument();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Deactivate…' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Reset two-factor…' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete user…' })).not.toBeInTheDocument();
        expect(screen.queryByRole('region', { name: 'Danger zone' })).not.toBeInTheDocument();
        // Name and email stay editable.
        expect(within(await section('Profile')).getByRole('button', { name: 'Change email' })).toBeInTheDocument();
        expect(within(await section('Domains')).getByText('Server admins have access to every domain.')).toBeInTheDocument();
    });
});

describe('domains', () => {
    it('offers only the domains they do not have, and adds one with a role', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = () => ({ domain: 'zeta.example', domain_unicode: 'zeta.example', role: 'viewer', granted_by_name: 'Anna Admin', granted_at: null });
        renderRoutes(routes, URL);

        const domains = await section('Domains');
        const picker = within(domains).getByRole('combobox', { name: 'Domain' });
        await waitFor(() => expect(within(picker).getAllByRole('option')).toHaveLength(3));
        const options = within(picker).getAllByRole('option').map((o) => o.textContent);
        expect(options).toEqual(['Choose a domain', 'bücher.example', 'zeta.example']);

        await user.selectOptions(picker, 'zeta.example');
        await user.selectOptions(within(domains).getByRole('combobox', { name: 'Role' }), 'viewer');
        await user.click(within(domains).getByRole('button', { name: 'Add' }));

        expect(await screen.findByText('Access to zeta.example added.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/7/grants', { method: 'POST', json: { domain: 'zeta.example', role: 'viewer' } }]]);
    });

    it('changes a role after a confirmation', async () => {
        const user = userEvent.setup();
        usersServer();
        renderRoutes(routes, URL);

        await user.selectOptions(within(await section('Domains')).getByRole('combobox', { name: 'Role on example.org' }), 'viewer');
        const dialog = await screen.findByRole('dialog', { name: 'Make Laura Kivi a viewer of example.org?' });
        expect(writes()).toEqual([]);
        await user.click(within(dialog).getByRole('button', { name: 'Change role' }));
        expect(await screen.findByText('Role on example.org changed.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/7/grants', { method: 'POST', json: { domain: 'example.org', role: 'viewer' } }]]);
    });

    it('removes a domain after a confirmation', async () => {
        const user = userEvent.setup();
        usersServer();
        renderRoutes(routes, URL);

        await user.click(within(await section('Domains')).getByRole('button', { name: 'Remove access to example.org' }));
        const dialog = await screen.findByRole('dialog', { name: "Remove Laura Kivi's access?" });
        await user.click(within(dialog).getByRole('button', { name: 'Remove access' }));
        expect(await screen.findByText('Access to example.org removed.')).toBeInTheDocument();
        expect(writes()).toEqual([['/users/7/grants/example.org', { method: 'DELETE' }]]);
    });
});

describe('pending invitations', () => {
    it('resends and revokes', async () => {
        const user = userEvent.setup();
        const server = usersServer();
        server.write = (path) => (path.endsWith('/resend') ? { id: 52 } : undefined);
        renderRoutes(routes, URL);

        const invitations = await section('Pending invitations');
        await user.click(within(invitations).getByRole('button', { name: 'Actions for the invitation: Viewer of zeta.example' }));
        await user.click(await screen.findByRole('menuitem', { name: 'Resend invitation' }));
        expect(await screen.findByText('Sent a new invitation to laura@example.com.')).toBeInTheDocument();

        await user.click(within(invitations).getByRole('button', { name: 'Actions for the invitation: Viewer of zeta.example' }));
        await user.click(await screen.findByRole('menuitem', { name: 'Revoke invitation' }));
        expect(await screen.findByText('Revoked the invitation to laura@example.com.')).toBeInTheDocument();

        expect(writes()).toEqual([
            ['/users/invitations/51/resend', { method: 'POST' }],
            ['/users/invitations/51', { method: 'DELETE' }],
        ]);
    });
});

describe('a user’s page in Finnish', () => {
    it('renders the sections and the deactivate confirmation in Finnish', async () => {
        await setLocale('fi');
        const user = userEvent.setup();
        usersServer();
        renderRoutes(routes, URL);

        expect(await screen.findByRole('region', { name: 'Profiili' })).toBeInTheDocument();
        expect(within(await section('Rooli')).getByRole('switch', { name: 'Palvelimen ylläpitäjä' })).toBeInTheDocument();
        expect(within(await section('Odottavat kutsut')).getByText('Katselija verkkotunnuksessa zeta.example')).toBeInTheDocument();
        await user.click(within(await section('Tila')).getByRole('button', { name: 'Poista käytöstä…' }));
        const dialog = await screen.findByRole('dialog', { name: 'Poistetaanko käyttäjä Laura Kivi käytöstä?' });
        expect(dialog).toHaveTextContent('Tämä kirjaa käyttäjän ulos kaikkialta.');
    });
});
