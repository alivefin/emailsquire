import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { routes } from '../../router';
import { queryKeys } from '../../api/types';
import { CONSOLE_ME as CONSOLE, PORTAL_ME, portalApi } from '../../test/portalServer';
import { renderRoutes, testQueryClient } from '../../test/routing';

vi.mock('../../api/client', () => ({
    apiFetch: vi.fn(),
    onSessionExpired: vi.fn(() => () => {}),
    onTwoFactorRequired: vi.fn(() => () => {}),
    onStepUpRequired: vi.fn(() => () => {}),
    ensureCsrf: vi.fn(),
}));

beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
});

function calledPaths(): string[] {
    return vi.mocked(apiFetch).mock.calls.map(([path]) => path);
}

describe('session kinds', () => {
    it('sends a portal session that opens the console to /my, without console chrome', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        const { router } = renderRoutes(routes, '/domains/example.org/mailboxes');

        expect(await screen.findByRole('heading', { level: 1, name: 'My mailbox' })).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/my');
        expect(screen.queryByRole('link', { name: /dashboard/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Account menu' })).not.toBeInTheDocument();
        // The console's /me said no, then the portal's said yes; no console data was asked for.
        expect(calledPaths().filter((p) => p !== '/portal/forwarding' && p !== '/portal/aliases')).toEqual([
            '/me',
            '/portal/me',
        ]);
    });

    it('sends a portal session at / to /my', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        const { router } = renderRoutes(routes, '/');

        expect(await screen.findByRole('heading', { level: 1, name: 'My mailbox' })).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/my');
    });

    it('opens /my for a portal session with one request and no console probe', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        renderRoutes(routes, '/my/settings');

        expect(await screen.findByRole('heading', { level: 1, name: 'Email app settings' })).toBeInTheDocument();
        expect(calledPaths()).toEqual(['/portal/me']);
    });

    it('sends a console session that opens /my to the console, without the portal', async () => {
        // The console's own pages may load whatever they need; they never answer here.
        vi.mocked(apiFetch).mockImplementation((path, init) =>
            path === '/me' || path === '/portal/me' ? portalApi('console')(path, init) : new Promise(() => {}),
        );
        const { router } = renderRoutes(routes, '/my/password');

        await waitFor(() => expect(router.state.location.pathname).toBe('/'));
        expect(await screen.findByRole('button', { name: 'Account menu' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Change password' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();
    });

    it('sends a signed-out visitor at /my to sign in, keeping the page as next', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('none'));
        const { router } = renderRoutes(routes, '/my/settings');

        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/signin');
        expect(new URLSearchParams(router.state.location.search).get('next')).toBe('/my/settings');
    });

    it('sends a portal session that opens the sign-in page to /my', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        const { router } = renderRoutes(routes, '/signin');

        expect(await screen.findByRole('heading', { level: 1, name: 'My mailbox' })).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/my');
    });

    it('takes a mailbox sign-in to the portal home, and to next when it is a portal page', async () => {
        let signedIn = false;
        vi.mocked(apiFetch).mockImplementation((path, init) => {
            if (path === '/auth/login') {
                signedIn = true;
                return Promise.resolve({ kind: 'mailbox' });
            }
            return portalApi(signedIn ? 'mailbox' : 'none')(path, init);
        });
        const { router } = renderRoutes(routes, '/signin?next=%2Fmy%2Fhelp');
        const user = userEvent.setup();
        await user.type(await screen.findByLabelText('Email'), 'anna@example.org');
        await user.type(screen.getByLabelText('Password'), 'summersun');
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByRole('heading', { level: 1, name: 'Help and contact' })).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/my/help');
        expect(screen.getByText('anna@example.org', { selector: 'header *' })).toBeInTheDocument();
    });

    it('shows a page-not-found inside the portal for an unknown /my page', async () => {
        vi.mocked(apiFetch).mockImplementation(portalApi('mailbox'));
        renderRoutes(routes, '/my/domains');

        expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to My mailbox' })).toHaveAttribute('href', '/my');
    });
});

describe('a session that ends quietly', () => {
    const OLD_TARGET = 'old-target@elsewhere.example';
    const OLD_ALIAS = 'oldalias';
    const BOB = { ...PORTAL_ME, address: 'bob@example.org', self_service: { ...PORTAL_ME.self_service, aliases: true } };

    function deferred<T>() {
        let resolve: (value: T) => void = () => {};
        const promise = new Promise<T>((r) => {
            resolve = r;
        });
        return { promise, resolve };
    }

    /** Records every text that ever appears in the document, so a flash of old data can't slip by. */
    function watchText(): { seen: () => string } {
        let seen = document.body.textContent ?? '';
        const observer = new MutationObserver(() => {
            seen += `\n${document.body.textContent ?? ''}`;
        });
        observer.observe(document.body, { subtree: true, childList: true, characterData: true });
        return {
            seen: () => {
                observer.disconnect();
                return seen;
            },
        };
    }

    it("never shows the previous mailbox's forwarding or aliases to the next mailbox", async () => {
        let who: 'anna' | 'nobody' | 'bob' = 'anna';
        const bobForwarding = deferred<unknown>();
        const bobAliases = deferred<unknown>();
        vi.mocked(apiFetch).mockImplementation((path, init) => {
            if (path === '/auth/login') {
                who = 'bob';
                return Promise.resolve({ kind: 'mailbox' });
            }
            if (who === 'anna') {
                return portalApi('mailbox', {
                    '/portal/forwarding': () => ({ targets: [OLD_TARGET], keep_copy: true }),
                    '/portal/aliases': () => ({ data: [{ local: OLD_ALIAS, address: `${OLD_ALIAS}@example.org` }] }),
                })(path, init);
            }
            if (who === 'nobody') return portalApi('none')(path, init);
            return portalApi(
                'mailbox',
                { '/portal/forwarding': () => bobForwarding.promise, '/portal/aliases': () => bobAliases.promise },
                BOB,
            )(path, init);
        });
        const client = testQueryClient();
        const { router } = renderRoutes(routes, '/my', client);
        expect(await screen.findByText(OLD_TARGET)).toBeInTheDocument();
        expect(await screen.findByText(`${OLD_ALIAS}@example.org`)).toBeInTheDocument();

        // The session ends without a 401 from a write: the next look at /portal/me just says "nobody".
        who = 'nobody';
        await act(() => client.invalidateQueries({ queryKey: queryKeys.portalMe }));

        expect(await screen.findByText('Your session has expired. Sign in again to continue.')).toBeInTheDocument();
        expect(router.state.location.pathname).toBe('/signin');
        expect(new URLSearchParams(router.state.location.search).get('next')).toBe('/my');
        expect(client.getQueryData(queryKeys.portalForwarding)).toBeUndefined();
        expect(client.getQueryData(queryKeys.portalAliases)).toBeUndefined();

        const watch = watchText();
        const user = userEvent.setup();
        await user.type(screen.getByLabelText('Email'), 'bob@example.org');
        await user.type(screen.getByLabelText('Password'), 'summersun');
        await user.click(screen.getByRole('button', { name: 'Continue' }));
        expect(await screen.findByText('bob@example.org', { selector: 'header *' })).toBeInTheDocument();
        await act(async () => {
            bobForwarding.resolve({ targets: ['bob-new@elsewhere.example'], keep_copy: false });
            bobAliases.resolve({ data: [{ local: 'bobalias', address: 'bobalias@example.org' }] });
        });
        expect(await screen.findByText('bob-new@elsewhere.example')).toBeInTheDocument();
        expect(screen.getByText('bobalias@example.org')).toBeInTheDocument();

        const seen = watch.seen();
        expect(seen).not.toContain(OLD_TARGET);
        expect(seen).not.toContain(OLD_ALIAS);
    });

    it("drops another mailbox's cached data on a mailbox sign-in, whatever the guard saw", async () => {
        let signedIn = false;
        vi.mocked(apiFetch).mockImplementation((path, init) => {
            if (path === '/auth/login') {
                signedIn = true;
                return Promise.resolve({ kind: 'mailbox' });
            }
            if (!signedIn) return portalApi('none')(path, init);
            return portalApi(
                'mailbox',
                { '/portal/forwarding': () => new Promise(() => {}), '/portal/aliases': () => new Promise(() => {}) },
                BOB,
            )(path, init);
        });
        const client = testQueryClient();
        // Left by an earlier mailbox in this tab, which the portal's guard never got to clear.
        client.setQueryData(queryKeys.portalForwarding, { targets: [OLD_TARGET], keep_copy: true });
        client.setQueryData(queryKeys.portalAliases, { data: [{ local: OLD_ALIAS, address: `${OLD_ALIAS}@example.org` }] });
        renderRoutes(routes, '/signin', client);
        const watch = watchText();
        const user = userEvent.setup();
        await user.type(await screen.findByLabelText('Email'), 'bob@example.org');
        await user.type(screen.getByLabelText('Password'), 'summersun');
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByText('bob@example.org', { selector: 'header *' })).toBeInTheDocument();
        expect(await screen.findAllByText('Loading…')).toHaveLength(2);
        const seen = watch.seen();
        expect(seen).not.toContain(OLD_TARGET);
        expect(seen).not.toContain(OLD_ALIAS);
    });

    it('leaves no portal data behind when the console signs in after it', async () => {
        vi.mocked(apiFetch).mockImplementation((path, init) => {
            if (path === '/auth/login') return Promise.resolve({ kind: 'console', ...CONSOLE });
            return path === '/me' || path === '/portal/me' ? portalApi('none')(path, init) : new Promise(() => {});
        });
        const client = testQueryClient();
        // What a portal session left in this browser before it ended.
        client.setQueryData(queryKeys.portalForwarding, { targets: [OLD_TARGET], keep_copy: true });
        client.setQueryData(queryKeys.portalAliases, { data: [{ local: OLD_ALIAS, address: `${OLD_ALIAS}@example.org` }] });
        client.setQueryData(['domains', {}], { data: [{ name: 'stale.example' }] });
        renderRoutes(routes, '/signin', client);
        const user = userEvent.setup();
        await user.type(await screen.findByLabelText('Email'), 'anna@example.org');
        await user.type(screen.getByLabelText('Password'), 'correct horse battery');
        await user.click(screen.getByRole('button', { name: 'Continue' }));

        expect(await screen.findByRole('button', { name: 'Account menu' })).toBeInTheDocument();
        const portalQueries = client.getQueryCache().findAll({ queryKey: ['portal'] });
        expect(portalQueries.map((q) => [q.queryKey, q.state.data ?? null])).toEqual([[queryKeys.portalMe, null]]);
        expect(client.getQueryData(['domains', {}])).toBeUndefined();
        expect(client.getQueryData(queryKeys.me)).toMatchObject({ email: 'anna@example.org' });
    });
});
