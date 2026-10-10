import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch, type ApiInit } from '../../api/client';
import { ApiProblem } from '../../api/problems';
import { queryKeys } from '../../api/types';
import { setLocale } from '../../i18n';
import { renderRoutes } from '../../test/routing';
import { INVITATION_TOKEN_KEY, InvitationPage } from './InvitationPage';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

const TOKEN = 'tok-AbC_123';
const STRONG = 'tidy-Harbor-42-lamp';
const routes = [{ path: '/invite', element: <InvitationPage /> }];

const INVITE = {
    email: 'mia@example.com',
    name: null as string | null,
    inviter_name: 'Laura Kivi' as string | null,
    domain: 'example.org' as string | null,
    domain_unicode: 'example.org' as string | null,
    role: 'manager' as 'manager' | 'viewer' | 'server_admin',
    note: 'Welcome to the team!' as string | null,
    state: 'valid' as 'valid' | 'expired' | 'used' | 'revoked',
    existing_user: false,
};

function me(email: string, over: Record<string, unknown> = {}) {
    return {
        id: 5,
        name: 'Mia Virtanen',
        email,
        is_server_admin: false,
        locale: 'en',
        two_factor: { enabled: false, confirmed: false, recovery_codes_left: 0 },
        two_factor_required: false,
        managers_can_share: false,
        landing: 'example.org',
        ...over,
    };
}

interface Server {
    lookup?: () => Promise<unknown>;
    accept?: (init: ApiInit) => Promise<unknown>;
    me?: ReturnType<typeof me> | null;
}

function serve({ lookup = () => Promise.resolve(INVITE), accept, me: signedIn = null }: Server = {}) {
    vi.mocked(apiFetch).mockImplementation(((path: string, init: ApiInit = {}) => {
        if (path === '/me') {
            return signedIn === null ? Promise.reject(new ApiProblem({ status: 401, title: 'Unauthorized' })) : Promise.resolve(signedIn);
        }
        if (path === '/auth/invitations/lookup') return lookup();
        if (path === '/auth/invitations/accept') return accept ? accept(init) : Promise.resolve(me(INVITE.email));
        return Promise.resolve(undefined);
    }) as typeof apiFetch);
}

function calls(path: string): ApiInit[] {
    return vi
        .mocked(apiFetch)
        .mock.calls.filter(([p]) => p === path)
        .map(([, init]) => init ?? {});
}

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    window.sessionStorage.clear();
});

afterEach(() => {
    window.sessionStorage.clear();
});

describe('InvitationPage: the link', () => {
    it('reads the token from the fragment, looks it up in a POST body and drops it from the address', async () => {
        serve();
        const { router, client } = renderRoutes(routes, `/invite#${TOKEN}`);

        expect(await screen.findByRole('heading', { name: "You're invited" })).toBeInTheDocument();
        expect(calls('/auth/invitations/lookup')).toEqual([{ method: 'POST', json: { token: TOKEN } }]);
        await waitFor(() => expect(router.state.location.hash).toBe(''));
        expect(router.state.location.pathname).toBe('/invite');
        for (const query of client.getQueryCache().getAll()) {
            expect(JSON.stringify(query.queryKey)).not.toContain(TOKEN);
        }
    });

    it('shows who invited them, to what, as what, and the note', async () => {
        serve();
        renderRoutes(routes, `/invite#${TOKEN}`);

        expect(await screen.findByText('Laura Kivi')).toBeInTheDocument();
        expect(screen.getByText('Invited by')).toBeInTheDocument();
        expect(screen.getByText('example.org')).toBeInTheDocument();
        expect(screen.getByText('Domain manager')).toBeInTheDocument();
        expect(screen.getByText('Welcome to the team!')).toBeInTheDocument();
    });

    it('names the whole server for a server-admin invitation', async () => {
        serve({ lookup: () => Promise.resolve({ ...INVITE, role: 'server_admin', domain: null, domain_unicode: null, note: null }) });
        renderRoutes(routes, `/invite#${TOKEN}`);
        expect(await screen.findByText('The whole server')).toBeInTheDocument();
        expect(screen.getByText('Server admin')).toBeInTheDocument();
    });

    it.each([
        ['expired', 'This invitation has expired'],
        ['used', 'This invitation has already been used'],
        ['revoked', 'This invitation is no longer valid'],
    ] as const)('shows its own message for an %s invitation, with a link to sign in', async (state, title) => {
        window.sessionStorage.setItem(INVITATION_TOKEN_KEY, 'stale');
        serve({ lookup: () => Promise.resolve({ ...INVITE, state }) });
        renderRoutes(routes, `/invite#${TOKEN}`);

        expect(await screen.findByRole('heading', { name: title })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Go to sign in' })).toHaveAttribute('href', '/signin');
        expect(screen.queryByLabelText('Your name')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Accept invitation' })).not.toBeInTheDocument();
        expect(window.sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBeNull();
    });

    it('says the link is not valid for an unknown token', async () => {
        serve({ lookup: () => Promise.reject(new ApiProblem({ status: 404, title: 'Not Found', code: 'invitation_not_found' })) });
        renderRoutes(routes, `/invite#${TOKEN}`);
        expect(await screen.findByRole('heading', { name: 'This invitation link is not valid' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Go to sign in' })).toHaveAttribute('href', '/signin');
    });

    it('says the link is not valid without a token, and looks nothing up', async () => {
        serve();
        renderRoutes(routes, '/invite');
        expect(await screen.findByRole('heading', { name: 'This invitation link is not valid' })).toBeInTheDocument();
        expect(calls('/auth/invitations/lookup')).toEqual([]);
    });

    it('offers a retry when the lookup fails for another reason', async () => {
        let fail = true;
        serve({
            lookup: () => (fail ? Promise.reject(new ApiProblem({ status: 429, title: 'Too Many', code: 'too_many_requests' })) : Promise.resolve(INVITE)),
        });
        const user = userEvent.setup();
        renderRoutes(routes, `/invite#${TOKEN}`);
        expect(await screen.findByText('Too many attempts. Wait a minute and try again.')).toBeInTheDocument();
        fail = false;
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByRole('heading', { name: "You're invited" })).toBeInTheDocument();
        expect(calls('/auth/invitations/lookup').every((init) => (init.json as { token: string }).token === TOKEN)).toBe(true);
    });
});

describe('InvitationPage: a new person', () => {
    it('posts the token, name and passwords, stores the signed-in user and opens their landing', async () => {
        serve();
        const user = userEvent.setup();
        const { client } = renderRoutes(routes, `/invite#${TOKEN}`);

        const email = await screen.findByLabelText('Email');
        expect(email).toHaveValue('mia@example.com');
        expect(email).toHaveAttribute('readonly');
        const submit = screen.getByRole('button', { name: 'Accept and create account' });
        expect(submit).toBeDisabled();

        await user.type(screen.getByLabelText('Your name'), 'Mia Virtanen');
        await user.type(screen.getByLabelText('Password'), STRONG);
        await user.type(screen.getByLabelText('Confirm password'), STRONG);
        await user.click(submit);

        expect(await screen.findByTestId('location')).toHaveTextContent('/domains/example.org/mailboxes');
        expect(calls('/auth/invitations/accept')).toEqual([
            { method: 'POST', json: { token: TOKEN, name: 'Mia Virtanen', password: STRONG, password_confirmation: STRONG } },
        ]);
        expect(client.getQueryData(queryKeys.me)).toEqual(me('mia@example.com'));
    });

    it('prefills the name the inviter gave', async () => {
        serve({ lookup: () => Promise.resolve({ ...INVITE, name: 'Mia V.' }) });
        renderRoutes(routes, `/invite#${TOKEN}`);
        expect(await screen.findByLabelText('Your name')).toHaveValue('Mia V.');
    });

    it('shows a field error from the server under its field', async () => {
        serve({
            accept: () =>
                Promise.reject(
                    new ApiProblem({ status: 422, title: 'Unprocessable', errors: { password: ['This password has appeared in a data breach.'] } }),
                ),
        });
        const user = userEvent.setup();
        renderRoutes(routes, `/invite#${TOKEN}`);
        await user.type(await screen.findByLabelText('Your name'), 'Mia');
        await user.type(screen.getByLabelText('Password'), STRONG);
        await user.type(screen.getByLabelText('Confirm password'), STRONG);
        await user.click(screen.getByRole('button', { name: 'Accept and create account' }));
        expect(await screen.findByText('This password has appeared in a data breach.')).toBeInTheDocument();
    });

    it('switches to the state screen when accepting finds the invitation gone', async () => {
        serve({
            accept: () => Promise.reject(new ApiProblem({ status: 410, title: 'Gone', code: 'invitation_invalid', state: 'expired' })),
        });
        const user = userEvent.setup();
        renderRoutes(routes, `/invite#${TOKEN}`);
        await user.type(await screen.findByLabelText('Your name'), 'Mia');
        await user.type(screen.getByLabelText('Password'), STRONG);
        await user.type(screen.getByLabelText('Confirm password'), STRONG);
        await user.click(screen.getByRole('button', { name: 'Accept and create account' }));
        expect(await screen.findByRole('heading', { name: 'This invitation has expired' })).toBeInTheDocument();
    });
});

describe('InvitationPage: an existing user', () => {
    const EXISTING = { ...INVITE, existing_user: true };

    it('signed out: links to sign in with next=/invite and keeps the token out of the URL', async () => {
        serve({ lookup: () => Promise.resolve(EXISTING) });
        const user = userEvent.setup();
        renderRoutes([...routes, { path: '/signin', element: <p>sign-in page</p> }], `/invite#${TOKEN}`);

        expect(await screen.findByText('This invitation is for mia@example.com. Sign in as that address to accept it.')).toBeInTheDocument();
        const link = screen.getByRole('link', { name: 'Sign in to accept' });
        expect(link).toHaveAttribute('href', '/signin?next=/invite');
        expect(screen.queryByLabelText('Password')).not.toBeInTheDocument();

        await user.click(link);
        expect(await screen.findByText('sign-in page')).toBeInTheDocument();
        expect(window.sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBe(TOKEN);
    });

    it('signed in as the invited email (back from signing in): accepts, refreshes the domains and opens the domain', async () => {
        window.sessionStorage.setItem(INVITATION_TOKEN_KEY, TOKEN);
        serve({ lookup: () => Promise.resolve(EXISTING), me: me('Mia@Example.com', { landing: null }), accept: () => Promise.resolve(me('mia@example.com')) });
        const user = userEvent.setup();
        const { client } = renderRoutes(routes, '/invite');
        const invalidate = vi.spyOn(client, 'invalidateQueries');

        await user.click(await screen.findByRole('button', { name: 'Accept invitation' }));

        expect(await screen.findByTestId('location')).toHaveTextContent('/domains/example.org/mailboxes');
        expect(calls('/auth/invitations/lookup')).toEqual([{ method: 'POST', json: { token: TOKEN } }]);
        expect(calls('/auth/invitations/accept')).toEqual([{ method: 'POST', json: { token: TOKEN } }]);
        expect(invalidate.mock.calls.map(([f]) => JSON.stringify(f?.queryKey))).toContain(JSON.stringify(['domains']));
        expect(window.sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBeNull();
    });

    it('signed in as the invited email to a server-admin invitation: goes to the landing', async () => {
        serve({
            lookup: () => Promise.resolve({ ...EXISTING, role: 'server_admin', domain: null, domain_unicode: null }),
            me: me('mia@example.com'),
            accept: () => Promise.resolve(me('mia@example.com', { is_server_admin: true, landing: null })),
        });
        const user = userEvent.setup();
        renderRoutes(routes, `/invite#${TOKEN}`);
        await user.click(await screen.findByRole('button', { name: 'Accept invitation' }));
        expect(await screen.findByTestId('location')).toHaveTextContent(/^\/$/);
    });

    it('signed in as someone else: says so and offers Sign out, then Sign in to accept', async () => {
        serve({ lookup: () => Promise.resolve(EXISTING), me: me('anna@example.org') });
        const user = userEvent.setup();
        renderRoutes(routes, `/invite#${TOKEN}`);

        expect(await screen.findByText("You're signed in as anna@example.org.")).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Accept invitation' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Sign out' }));

        expect(await screen.findByRole('link', { name: 'Sign in to accept' })).toBeInTheDocument();
        expect(calls('/auth/logout')).toEqual([{ method: 'POST' }]);
    });

    it('clears a stored token when the page is left without accepting', async () => {
        window.sessionStorage.setItem(INVITATION_TOKEN_KEY, TOKEN);
        serve({ lookup: () => Promise.resolve(EXISTING), me: me('mia@example.com') });
        const { router } = renderRoutes(routes, '/invite');
        await screen.findByRole('button', { name: 'Accept invitation' });
        await router.navigate('/elsewhere');
        await screen.findByTestId('location');
        expect(window.sessionStorage.getItem(INVITATION_TOKEN_KEY)).toBeNull();
    });
});

describe('InvitationPage in Finnish', () => {
    it('renders the invitation and the new-person form in Finnish', async () => {
        await setLocale('fi');
        serve();
        renderRoutes(routes, `/invite#${TOKEN}`);
        expect(await screen.findByRole('heading', { name: 'Sinut on kutsuttu' })).toBeInTheDocument();
        expect(screen.getByText('Verkkotunnuksen hallinnoija')).toBeInTheDocument();
        expect(screen.getByText('Kutsuja')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Hyväksy ja luo tili' })).toBeInTheDocument();
    });

    it('renders an expired invitation in Finnish', async () => {
        await setLocale('fi');
        serve({ lookup: () => Promise.resolve({ ...INVITE, state: 'expired' }) });
        renderRoutes(routes, `/invite#${TOKEN}`);
        expect(await screen.findByRole('heading', { name: 'Tämä kutsu on vanhentunut' })).toBeInTheDocument();
    });
});
