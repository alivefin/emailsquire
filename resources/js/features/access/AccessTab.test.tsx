import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { setLocale } from '../../i18n';
import { useIsPhone } from '../../lib/useIsPhone';
import { routes } from '../../router';
import { fakeServer, writes, type AccessInvitation, type AccessPerson, type FakeServer } from '../../test/domainServer';
import { renderRoutes } from '../../test/routing';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));
vi.mock('../../lib/useIsPhone', () => ({ useIsPhone: vi.fn(() => false) }));

const LAURA: AccessPerson = {
    id: 7,
    name: 'Laura Kivi',
    email: 'laura@example.com',
    role: 'manager',
    status: 'active',
    granted_by_name: 'Anna Admin',
    granted_at: '2026-10-08T09:00:00Z',
};

/** The signed-in user (`ME`, id 1) as a person on the domain. */
const SELF: AccessPerson = { ...LAURA, id: 1, name: 'Anna Admin', email: 'anna@example.org', role: 'viewer' };

const TOMI: AccessInvitation = {
    id: 31,
    email: 'tomi@example.com',
    role: 'viewer',
    invited_by_name: 'Laura Kivi',
    created_at: '2026-10-09T09:00:00Z',
    expires_at: '2026-10-12T09:00:00Z',
    state: 'valid',
};

const URL = '/domains/example.org/access';

function serve(over: Partial<FakeServer> = {}) {
    return fakeServer({ access: { people: [LAURA], invitations: [TOMI] }, ...over });
}

/** The row of the person or invitation named `name`. */
async function row(name: string) {
    const label = await screen.findByText(name, { selector: '[data-person]' });
    return label.closest('li') as HTMLElement;
}

async function openMenu(user: ReturnType<typeof userEvent.setup>, name: string) {
    await user.click(await screen.findByRole('button', { name: `Actions for ${name}` }));
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    vi.mocked(useIsPhone).mockReturnValue(false);
});

describe('the Access tab', () => {
    it('is a tab after Forwarding on the domain page', async () => {
        serve();
        renderRoutes(routes, '/domains/example.org/mailboxes');
        const nav = await screen.findByRole('navigation', { name: 'Domain sections' });
        const links = within(nav).getAllByRole('link').map((l) => l.textContent ?? '');
        expect(links).toHaveLength(3);
        expect(links[2]).toMatch(/^Access/);
        expect(within(nav).getByRole('link', { name: /^Access/ })).toHaveAttribute('href', URL);
    });

    it('lists the people with role, who added them and when, and status, and pending invitations with their expiry', async () => {
        serve();
        renderRoutes(routes, URL);

        expect(await screen.findByRole('heading', { name: 'People with access to example.org' })).toBeInTheDocument();
        const laura = await row('Laura Kivi');
        expect(within(laura).getByText('laura@example.com')).toBeInTheDocument();
        expect(within(laura).getByRole('combobox', { name: 'Role of Laura Kivi' })).toHaveValue('manager');
        expect(within(laura).getAllByText(/^by Anna Admin · Oct 8, 2026$/).length).toBeGreaterThan(0);
        expect(within(laura).getAllByText('Active').length).toBeGreaterThan(0);

        const tomi = await row('tomi@example.com');
        expect(within(tomi).getAllByText('Pending').length).toBeGreaterThan(0);
        expect(within(tomi).getAllByText(/^Expires Oct 12, 2026/).length).toBeGreaterThan(0);
        expect(within(tomi).getAllByText(/^by Laura Kivi · Oct 9, 2026$/).length).toBeGreaterThan(0);
        expect(within(tomi).getByText('Viewer')).toBeInTheDocument();
    });

    it('marks expired and no-longer-valid invitations and deactivated people', async () => {
        serve({
            access: {
                people: [{ ...LAURA, status: 'deactivated' }],
                invitations: [
                    { ...TOMI, state: 'expired' },
                    { ...TOMI, id: 32, email: 'old@example.com', state: 'revoked' },
                ],
            },
        });
        renderRoutes(routes, URL);
        expect(within(await row('Laura Kivi')).getAllByText('Deactivated').length).toBeGreaterThan(0);
        expect(within(await row('tomi@example.com')).getAllByText('Expired').length).toBeGreaterThan(0);
        expect(within(await row('old@example.com')).getAllByText('No longer valid').length).toBeGreaterThan(0);
    });

    it('shows an admin the empty state when nobody else has access', async () => {
        fakeServer();
        renderRoutes(routes, URL);
        expect(await screen.findByText('Only you can manage this domain')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Share access' }).length).toBeGreaterThan(0);
    });

    it('shows a load failure with Retry', async () => {
        const server = serve();
        const base = vi.mocked(apiFetch).getMockImplementation()!;
        let fail = true;
        vi.mocked(apiFetch).mockImplementation(((path: string, init?: object) => {
            if (fail && path.endsWith('/access')) return Promise.reject(new ApiProblem({ status: 500, title: 'Server Error' }));
            return base(path, init);
        }) as typeof apiFetch);
        const user = userEvent.setup();
        renderRoutes(routes, URL);
        expect(await screen.findByText('Server Error')).toBeInTheDocument();
        fail = false;
        await user.click(screen.getByRole('button', { name: 'Retry' }));
        expect(await row('Laura Kivi')).toBeInTheDocument();
        expect(server).toBeDefined();
    });
});

describe('sharing', () => {
    it('posts the email, role, note and the console language, then closes with a toast and refetches', async () => {
        const user = userEvent.setup();
        const server = serve();
        server.write = () => ({ id: 40, email: 'new@example.com', role: 'manager', domain: 'example.org', state: 'valid', expires_at: '2026-10-14T09:00:00Z' });
        renderRoutes(routes, URL);

        await user.click(await screen.findByRole('button', { name: 'Share access' }));
        const dialog = await screen.findByRole('dialog', { name: 'Share access to example.org' });
        expect(within(dialog).getByRole('combobox', { name: 'Email language' })).toHaveValue('en');
        expect(within(dialog).getByRole('radio', { name: /^Viewer/ })).toBeChecked();
        expect(within(dialog).getByText('Can see the mailboxes and forwarding but not change them.')).toBeInTheDocument();

        await user.type(within(dialog).getByLabelText('Email'), '  new@example.com ');
        await user.click(within(dialog).getByRole('radio', { name: /^Manager/ }));
        await user.type(within(dialog).getByLabelText('Note (optional)'), 'Welcome aboard');
        expect(within(dialog).getByText('14 / 500')).toBeInTheDocument();
        await user.selectOptions(within(dialog).getByRole('combobox', { name: 'Email language' }), 'fi');
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));

        expect(await screen.findByText('Invitation sent to new@example.com.')).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(writes()).toEqual([
            [
                '/domains/example.org/access/invitations',
                { method: 'POST', json: { email: 'new@example.com', role: 'manager', note: 'Welcome aboard', locale: 'fi' } },
            ],
        ]);
        const accessGets = vi.mocked(apiFetch).mock.calls.filter(([p]) => p === '/domains/example.org/access');
        expect(accessGets.length).toBeGreaterThan(1);
    });

    it('leaves out an empty note and defaults the email language to the console language', async () => {
        await setLocale('fi');
        const user = userEvent.setup();
        const server = serve();
        server.write = () => ({ id: 40 });
        renderRoutes(routes, URL);

        await user.click(await screen.findByRole('button', { name: 'Jaa käyttöoikeus' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Sähköposti'), 'new@example.com');
        await user.click(within(dialog).getByRole('button', { name: 'Lähetä kutsu' }));
        await waitFor(() => expect(writes()).toHaveLength(1));
        expect(writes()[0]![1].json).toEqual({ email: 'new@example.com', role: 'viewer', locale: 'fi' });
    });

    it.each(['already_has_access', 'user_deactivated'])('shows a 409 %s under the email field', async (code) => {
        const user = userEvent.setup();
        const server = serve();
        server.write = () =>
            Promise.reject(new ApiProblem({ status: 409, title: 'Conflict', code, detail: 'new@example.com already has access to this domain.' }));
        renderRoutes(routes, URL);

        await user.click(await screen.findByRole('button', { name: 'Share access' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Email'), 'new@example.com');
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));

        const email = within(dialog).getByLabelText('Email');
        await waitFor(() => expect(email).toHaveAttribute('aria-invalid', 'true'));
        expect(email).toHaveAccessibleDescription('new@example.com already has access to this domain.');
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('keeps the dialog open and quiet when the step-up is cancelled', async () => {
        const user = userEvent.setup();
        const server = serve();
        server.write = () =>
            Promise.reject(new ApiProblem({ status: 423, title: 'Locked', code: 'step_up_required', cancelled: true }));
        renderRoutes(routes, URL);

        await user.click(await screen.findByRole('button', { name: 'Share access' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Email'), 'new@example.com');
        await user.click(within(dialog).getByRole('button', { name: 'Send invitation' }));

        await waitFor(() => expect(writes()).toHaveLength(1));
        await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Send invitation' })).toBeEnabled());
        expect(within(dialog).queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText("Confirm it's you to continue.")).not.toBeInTheDocument();
    });
});

describe('changing a role and removing someone', () => {
    it('confirms a role change, then patches it', async () => {
        const user = userEvent.setup();
        serve();
        renderRoutes(routes, URL);

        await user.selectOptions(await screen.findByRole('combobox', { name: 'Role of Laura Kivi' }), 'viewer');
        const dialog = await screen.findByRole('dialog', { name: 'Make Laura Kivi a viewer?' });
        expect(writes()).toEqual([]);
        await user.click(within(dialog).getByRole('button', { name: 'Change role' }));

        expect(await screen.findByText('Laura Kivi is now a viewer.')).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/example.org/access/7', { method: 'PATCH', json: { role: 'viewer' } }]]);
    });

    it('sends nothing when the role change is cancelled, and keeps the old role', async () => {
        const user = userEvent.setup();
        serve();
        renderRoutes(routes, URL);

        const select = await screen.findByRole('combobox', { name: 'Role of Laura Kivi' });
        await user.selectOptions(select, 'viewer');
        await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(select).toHaveValue('manager');
        expect(writes()).toEqual([]);
    });

    it('confirms a removal naming what they lose, then deletes the grant', async () => {
        const user = userEvent.setup();
        serve();
        renderRoutes(routes, URL);

        await openMenu(user, 'Laura Kivi');
        await user.click(await screen.findByRole('menuitem', { name: 'Remove access…' }));
        const dialog = await screen.findByRole('dialog', { name: "Remove Laura Kivi's access?" });
        expect(within(dialog).getByText('Laura Kivi will no longer see example.org or its mailboxes.')).toBeInTheDocument();
        expect(writes()).toEqual([]);
        await user.click(within(dialog).getByRole('button', { name: 'Remove access' }));

        expect(await screen.findByText("Removed Laura Kivi's access.")).toBeInTheDocument();
        expect(writes()).toEqual([['/domains/example.org/access/7', { method: 'DELETE' }]]);
    });

    it('shows a failed removal in the dialog', async () => {
        const user = userEvent.setup();
        const server = serve();
        server.write = () => Promise.reject(new ApiProblem({ status: 500, title: 'Server Error' }));
        renderRoutes(routes, URL);

        await openMenu(user, 'Laura Kivi');
        await user.click(await screen.findByRole('menuitem', { name: 'Remove access…' }));
        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Remove access' }));
        expect(await within(dialog).findByRole('alert')).toHaveTextContent('Server Error');
    });
});

describe('invitations', () => {
    it('resends one and revokes one', async () => {
        const user = userEvent.setup();
        const server = serve();
        server.write = (path) => (path.endsWith('/resend') ? { ...TOMI, id: 33 } : undefined);
        renderRoutes(routes, URL);

        await openMenu(user, 'tomi@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Resend invitation' }));
        expect(await screen.findByText('Sent a new invitation to tomi@example.com.')).toBeInTheDocument();

        await openMenu(user, 'tomi@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Revoke invitation' }));
        expect(await screen.findByText('Revoked the invitation to tomi@example.com.')).toBeInTheDocument();

        expect(writes()).toEqual([
            ['/domains/example.org/access/invitations/31/resend', { method: 'POST' }],
            ['/domains/example.org/access/invitations/31', { method: 'DELETE' }],
        ]);
    });

    it('shows a 409 on resend as an error on the row', async () => {
        const user = userEvent.setup();
        const server = serve();
        server.write = () =>
            Promise.reject(
                new ApiProblem({ status: 409, title: 'Conflict', code: 'invitation_closed', detail: 'This invitation has already been accepted or revoked.' }),
            );
        renderRoutes(routes, URL);

        await openMenu(user, 'tomi@example.com');
        await user.click(await screen.findByRole('menuitem', { name: 'Resend invitation' }));
        const tomi = await row('tomi@example.com');
        expect(await within(tomi).findByRole('alert')).toHaveTextContent('This invitation has already been accepted or revoked.');
    });
});

describe('who sees which actions', () => {
    it('gives a viewer no sharing controls, but their own Remove', async () => {
        const user = userEvent.setup();
        serve({ role: 'viewer', access: { people: [SELF, LAURA], invitations: [TOMI] } });
        renderRoutes(routes, URL);

        const self = await row('Anna Admin');
        expect(within(self).getByText('you')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Share access' })).not.toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: /^Role of/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Actions for Laura Kivi' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Actions for tomi@example.com' })).not.toBeInTheDocument();
        expect(within(await row('Laura Kivi')).getByText('Manager')).toBeInTheDocument();

        await openMenu(user, 'Anna Admin');
        expect(await screen.findByRole('menuitem', { name: 'Remove my access…' })).toBeInTheDocument();
    });

    it('gives a manager no sharing controls unless managers may share', async () => {
        serve({ role: 'manager', access: { people: [SELF, LAURA], invitations: [TOMI] } });
        renderRoutes(routes, URL);
        await row('Laura Kivi');
        expect(screen.queryByRole('button', { name: 'Share access' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Actions for Laura Kivi' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Actions for Anna Admin' })).toBeInTheDocument();
    });

    it('gives a manager the sharing controls when managers may share, but no role select on their own row', async () => {
        serve({ role: 'manager', me: { managers_can_share: true }, access: { people: [{ ...SELF, role: 'manager' }, LAURA], invitations: [TOMI] } });
        renderRoutes(routes, URL);
        expect(await screen.findByRole('button', { name: 'Share access' })).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: 'Role of Laura Kivi' })).toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Role of Anna Admin' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Actions for tomi@example.com' })).toBeInTheDocument();
    });

    it('removes your own access after a confirmation, leaves the domain and refreshes domains and me', async () => {
        const user = userEvent.setup();
        serve({ role: 'viewer', access: { people: [SELF, LAURA], invitations: [] } });
        const { router, client } = renderRoutes(routes, URL);
        const invalidate = vi.spyOn(client, 'invalidateQueries');

        await openMenu(user, 'Anna Admin');
        await user.click(await screen.findByRole('menuitem', { name: 'Remove my access…' }));
        const dialog = await screen.findByRole('dialog', { name: 'Remove your access to example.org?' });
        expect(within(dialog).getByText(/^You will no longer see example\.org or its mailboxes\./)).toBeInTheDocument();
        await user.click(within(dialog).getByRole('button', { name: 'Remove access' }));

        await waitFor(() => expect(router.state.location.pathname).toBe('/'));
        expect(writes()).toEqual([['/domains/example.org/access/1', { method: 'DELETE' }]]);
        const keys = invalidate.mock.calls.map(([filters]) => JSON.stringify(filters?.queryKey));
        expect(keys).toContain(JSON.stringify(['domains']));
        expect(keys).toContain(JSON.stringify(['me']));
        expect(await screen.findByText('You no longer have access to example.org.')).toBeInTheDocument();
    });
});

describe('the Access tab in Finnish', () => {
    it('renders the list and the remove confirmation in Finnish', async () => {
        await setLocale('fi');
        const user = userEvent.setup();
        serve();
        renderRoutes(routes, URL);

        expect(await screen.findByRole('heading', { name: 'Henkilöt, joilla on käyttöoikeus verkkotunnukseen example.org' })).toBeInTheDocument();
        expect(within(await row('tomi@example.com')).getAllByText('Odottaa hyväksymistä').length).toBeGreaterThan(0);
        await openMenu_fi(user, 'Laura Kivi');
        await user.click(await screen.findByRole('menuitem', { name: 'Poista käyttöoikeus…' }));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText('Laura Kivi ei enää näe verkkotunnusta example.org eikä sen postilaatikoita.')).toBeInTheDocument();
    });
});

async function openMenu_fi(user: ReturnType<typeof userEvent.setup>, name: string) {
    await user.click(await screen.findByRole('button', { name: `Toiminnot: ${name}` }));
}
