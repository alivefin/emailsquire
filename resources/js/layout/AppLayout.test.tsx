import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { apiFetch } from '../api/client';
import { ApiProblem } from '../api/problems';
import { routes } from '../router';
import { renderRoutes } from '../test/routing';

vi.mock('../api/client', () => ({ apiFetch: vi.fn(), onSessionExpired: vi.fn(() => () => {}), onTwoFactorRequired: vi.fn(() => () => {}), onStepUpRequired: vi.fn(() => () => {}), ensureCsrf: vi.fn() }));

const ME = {
    id: 1,
    name: 'Anna Admin',
    email: 'anna@example.org',
    is_server_admin: true,
    locale: 'en',
    two_factor: { enabled: true, confirmed: true, recovery_codes_left: 8 },
    two_factor_required: true,
    second_factor: true,
    passkeys: 0,
};

let signedOut = false;

beforeEach(() => {
    signedOut = false;
    vi.mocked(apiFetch).mockReset();
    vi.mocked(apiFetch).mockImplementation((path: string) => {
        if (path === '/me')
            return signedOut ? Promise.reject(new ApiProblem({ status: 401, title: 'Unauthorized' })) : Promise.resolve(ME);
        // No mailbox portal session in this browser.
        if (path === '/portal/me') return Promise.reject(new ApiProblem({ status: 401, title: 'Unauthorized' }));
        if (path === '/auth/logout') {
            signedOut = true;
            return Promise.reject(new Error('offline'));
        }
        if (path === '/dashboard')
            return Promise.resolve({
                counts: {
                    domains: 0,
                    domains_added_this_month: 0,
                    mailboxes: 0,
                    forwarding: 0,
                    forwarding_local: 0,
                    forwarding_external: 0,
                },
                near_full: { count: 0, items: [] },
                recent_activity: [],
                server_storage: null,
                usage_checked_at: null,
            });
        return Promise.resolve({ data: [] });
    });
});

describe('AppLayout', () => {
    it('redirects a domain without a tab to its mailboxes', async () => {
        const { router } = renderRoutes(routes, '/domains/example.org');
        await waitFor(() => expect(router.state.location.pathname).toBe('/domains/example.org/mailboxes'));
    });

    it('renders Page not found inside the layout', async () => {
        renderRoutes(routes, '/nope');
        expect(await screen.findByText('Page not found', { selector: 'h2' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to the dashboard' })).toBeInTheDocument();
    });

    it('routes /activity to the activity log, titled Activity', async () => {
        const fallback = vi.mocked(apiFetch).getMockImplementation()!;
        vi.mocked(apiFetch).mockImplementation((path: string, init) =>
            path === '/audit-log' ? Promise.resolve({ data: [], meta: { page: 1, per_page: 10, total: 0, last_page: 1 } }) : fallback(path, init),
        );
        renderRoutes(routes, '/activity');
        expect(await screen.findByText('Activity', { selector: 'h1' })).toBeInTheDocument();
        expect(await screen.findByRole('combobox', { name: 'Action' })).toBeInTheDocument();
        expect(await screen.findByText('No activity yet')).toBeInTheDocument();
    });

    it('signs out even when the request fails: clears the cache and goes to /signin', async () => {
        renderRoutes(routes, '/');
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Account menu' }));
        expect(screen.getByText('anna@example.org')).toBeInTheDocument();
        expect(screen.getByText('Server admin')).toBeInTheDocument();
        await user.click(screen.getByRole('menuitem', { name: 'Sign out' }));

        await waitFor(() => expect(apiFetch).toHaveBeenCalledWith('/auth/logout', { method: 'POST' }));
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('opens My profile from the account menu, with its title in the header', async () => {
        const { router } = renderRoutes(routes, '/');
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Account menu' }));
        await user.click(screen.getByRole('menuitem', { name: 'My profile' }));

        await waitFor(() => expect(router.state.location.pathname).toBe('/profile'));
        expect(await screen.findByRole('heading', { level: 1, name: 'My profile' })).toBeInTheDocument();
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });
});

describe('AppLayout for an admin who must set up two-factor first', () => {
    const BLOCKED = {
        ...ME,
        second_factor: false,
        two_factor: { enabled: false, confirmed: false, recovery_codes_left: 0 },
        two_factor_required: true,
    };
    const paths = () => vi.mocked(apiFetch).mock.calls.map((c) => c[0]);

    beforeEach(() => {
        vi.mocked(apiFetch).mockImplementation((path: string) =>
            path === '/me'
                ? Promise.resolve(BLOCKED)
                : path === '/me/passkeys' ? Promise.resolve({ data: [] })
                : Promise.reject(new ApiProblem({ status: 403, title: 'Forbidden', code: 'two_factor_required' })),
        );
    });

    it('sends them from any page to My profile\'s two-factor section', async () => {
        const { router } = renderRoutes(routes, '/domains');
        await waitFor(() => expect(router.state.location.pathname).toBe('/profile'));
        expect(router.state.location.hash).toBe('#two-factor');
    });

    it('keeps My profile usable: the layout asks for nothing the server refuses', async () => {
        renderRoutes(routes, '/profile#two-factor');
        expect(await screen.findByText('Set up two-factor sign-in to continue.')).toBeInTheDocument();
        expect(screen.getByRole('navigation', { name: 'Main' })).toBeInTheDocument();
        expect(paths()).toEqual(['/me', '/me/passkeys']);
        expect(screen.queryByTestId('toast')).not.toBeInTheDocument();
    });

    it('starts the layout queries once me says two-factor is confirmed', async () => {
        const { client } = renderRoutes(routes, '/profile#two-factor');
        await screen.findByText('Set up two-factor sign-in to continue.');
        expect(paths()).not.toContain('/domains/names');

        vi.mocked(apiFetch).mockImplementation((path: string) =>
            Promise.resolve(path === '/me' ? ME : path === '/dashboard' ? { counts: null, server_storage: null } : { data: [] }),
        );
        await act(() => client.invalidateQueries({ queryKey: ['me'] }));

        await waitFor(() => expect(paths()).toEqual(expect.arrayContaining(['/domains/names', '/me/pinned-domains', '/dashboard'])));
        expect(screen.queryByText('Set up two-factor sign-in to continue.')).not.toBeInTheDocument();
    });
});
