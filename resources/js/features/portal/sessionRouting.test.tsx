import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../../api/client';
import { routes } from '../../router';
import { portalApi } from '../../test/portalServer';
import { renderRoutes } from '../../test/routing';

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
